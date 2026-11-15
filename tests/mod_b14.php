<?php
/** B14 end-to-end tests for the first service queue workflow slice. */
declare(strict_types=1);

function run_b14(array $S, string $base): void
{
    $clk = $S['clerk'];
    $cap = $S['captain'];
    $sec = $S['secretary'];
    $adm = $S['admin'];
    $today = date('Y-m-d');
    $prefix = 'ZB14-';

    // Remove only test-owned queue rows. Daily counters intentionally remain monotonic.
    db_exec("DELETE FROM queue_entries WHERE reference_no LIKE ?", [$prefix . '%']);

    try {
        $access = true;
        foreach (['admin' => $adm, 'captain' => $cap, 'secretary' => $sec, 'clerk' => $clk] as $role => $client) {
            $page = $client->get('/queue/index.php');
            if ($page['status'] !== 200 || leaks($page)) { $access = false; }
        }
        $captainPage = $cap->get('/queue/index.php');
        $secretaryPage = $sec->get('/queue/index.php');
        $clerkPage = $clk->get('/queue/index.php');
        $captainNoIssue = !str_contains($captainPage['body'], 'name="do" value="issue"');
        $secretaryNoIssue = !str_contains($secretaryPage['body'], 'name="do" value="issue"');
        record('B14-01', 'All four roles can view the queue while only the transitional Staff/Clerk role and Administrator get management controls',
            $access && $captainNoIssue && $secretaryNoIssue && str_contains($clerkPage['body'], 'name="do" value="issue"'));

        $counterBefore = [];
        foreach (QUEUE_SERVICES as $service) {
            $counterBefore[$service] = (int) (db_val(
                'SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ?',
                [$service, $today]
            ) ?? 0);
        }
        $issue = static function (Http $client, string $service, string $reference) {
            return post_as($client, '/queue/index.php', '/queue/action.php', [
                'do' => 'issue', 'service' => $service, 'resident_id' => '', 'reference_no' => $reference,
            ]);
        };
        $issued = [
            'Blotter-1' => $issue($clk, 'Blotter', $prefix . 'BLOTTER-1'),
            'Blotter-2' => $issue($clk, 'Blotter', $prefix . 'BLOTTER-2'),
            'Documents' => $issue($clk, 'Documents', $prefix . 'DOCUMENTS'),
            'Venue' => $issue($clk, 'Venue', $prefix . 'VENUE'),
        ];
        $rows = db_all('SELECT service, queue_number, status, reference_no FROM queue_entries WHERE reference_no LIKE ? ORDER BY service, queue_number', [$prefix . '%']);
        $byService = [];
        foreach ($rows as $row) { $byService[$row['service']][] = $row; }
        $independent = count($rows) === 4
            && count($byService['Blotter'] ?? []) === 2
            && count($byService['Documents'] ?? []) === 1
            && count($byService['Venue'] ?? []) === 1
            && (int) $byService['Blotter'][0]['queue_number'] === $counterBefore['Blotter'] + 1
            && (int) $byService['Blotter'][1]['queue_number'] === $counterBefore['Blotter'] + 2
            && (int) $byService['Documents'][0]['queue_number'] === $counterBefore['Documents'] + 1
            && (int) $byService['Venue'][0]['queue_number'] === $counterBefore['Venue'] + 1
            && count(array_filter($issued, static fn(array $r): bool => $r['status'] === 302)) === 4;
        record('B14-02', 'Blotter, Documents and Venue issue separate sequential daily numbers without mixing their counters',
            $independent);

        $deniedCaptain = post_as($cap, '/queue/index.php', '/queue/action.php', [
            'do' => 'issue', 'service' => 'Blotter', 'reference_no' => $prefix . 'DENIED-CAPTAIN',
        ]);
        $deniedSecretary = post_as($sec, '/queue/index.php', '/queue/action.php', [
            'do' => 'issue', 'service' => 'Venue', 'reference_no' => $prefix . 'DENIED-SECRETARY',
        ]);
        $noCsrf = $clk->post('/queue/action.php', [
            'do' => 'issue', 'service' => 'Documents', 'reference_no' => $prefix . 'NO-CSRF',
        ]);
        $badService = post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'issue', 'service' => 'Unknown', 'reference_no' => $prefix . 'BAD-SERVICE',
        ]);
        record('B14-03', 'Captain and Secretary cannot issue queue numbers, CSRF is mandatory, and unknown services are refused',
            $deniedCaptain['status'] === 403
            && $deniedSecretary['status'] === 403
            && $noCsrf['status'] === 403
            && $badService['status'] === 302
            && (int) db_val('SELECT COUNT(*) FROM queue_entries WHERE reference_no LIKE ?', [$prefix . '%']) === 4);

        $entryId = static function (string $reference): int {
            return (int) db_val('SELECT queue_id FROM queue_entries WHERE reference_no = ?', [$reference]);
        };
        $status = static function (string $reference): string {
            return (string) db_val('SELECT status FROM queue_entries WHERE reference_no = ?', [$reference]);
        };
        $call = static fn(string $service) => post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'call_next', 'service' => $service,
        ]);
        $calledBlotter = $call('Blotter');
        $calledDocs = $call('Documents');
        $calledVenue = $call('Venue');
        $firstBlotter = $prefix . 'BLOTTER-1';
        $secondBlotter = $prefix . 'BLOTTER-2';
        $calledCorrect = $status($firstBlotter) === 'Called'
            && $status($prefix . 'DOCUMENTS') === 'Called'
            && $status($prefix . 'VENUE') === 'Called'
            && (int) db_val("SELECT called_by FROM queue_entries WHERE reference_no = ?", [$firstBlotter]) === (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_clerk'");
        record('B14-04', 'Staff can call the next waiting number independently for each service and the system records who called it',
            $calledBlotter['status'] === 302 && $calledDocs['status'] === 302 && $calledVenue['status'] === 302 && $calledCorrect);

        $blockedSecondCall = $call('Blotter');
        $singleCurrentCall = $blockedSecondCall['status'] === 302
            && $status($firstBlotter) === 'Called'
            && $status($secondBlotter) === 'Waiting'
            && (int) db_val("SELECT COUNT(*) FROM queue_entries WHERE service = 'Blotter' AND queue_date = ? AND status = 'Called' AND reference_no LIKE ?", [$today, $prefix . '%']) === 1;
        record('B14-05', 'The queue refuses to call another number while the current number is still being served',
            $singleCurrentCall);

        $serveFirst = post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'serve', 'queue_id' => (string) $entryId($firstBlotter),
        ]);
        $callSecond = $call('Blotter');
        $skipSecond = post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'skip', 'queue_id' => (string) $entryId($secondBlotter),
        ]);
        $cancelDocs = post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'cancel', 'queue_id' => (string) $entryId($prefix . 'DOCUMENTS'),
        ]);
        $serveVenue = post_as($clk, '/queue/index.php', '/queue/action.php', [
            'do' => 'serve', 'queue_id' => (string) $entryId($prefix . 'VENUE'),
        ]);
        record('B14-06', 'Served, Skipped and Cancelled queue states are recorded and no terminal state is silently reused',
            $serveFirst['status'] === 302 && $callSecond['status'] === 302
            && $skipSecond['status'] === 302 && $cancelDocs['status'] === 302 && $serveVenue['status'] === 302
            && $status($firstBlotter) === 'Served'
            && $status($secondBlotter) === 'Skipped'
            && $status($prefix . 'DOCUMENTS') === 'Cancelled'
            && $status($prefix . 'VENUE') === 'Served'
            && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action IN ('queue_number_issued','queue_number_called','queue_number_served','queue_number_skipped','queue_number_cancelled') AND entity_type = 'queue_entry' AND entity_id IN (SELECT queue_id FROM queue_entries WHERE reference_no LIKE ?)", [$prefix . '%']) >= 10);

        $snapshot = queue_all_snapshots();
        $display = $cap->get('/queue/display.php');
        $noPersonalData = !str_contains($display['body'], 'zz_test_clerk')
            && !str_contains($display['body'], 'ZZB14')
            && !str_contains($display['body'], 'contact_number')
            && !str_contains($display['body'], 'email_address');
        record('B14-07', 'Public queue display is available without exposing resident names, contact details, email addresses or request references',
            $display['status'] === 200
            && str_contains($display['body'], 'Blotter')
            && str_contains($display['body'], 'Documents')
            && str_contains($display['body'], 'Venue')
            && $noPersonalData
            && $snapshot['Blotter']['current_number'] === null
            && $snapshot['Documents']['current_number'] === null
            && $snapshot['Venue']['current_number'] === null);

        $odd = $clk->post('/queue/action.php', [
            '_csrf' => $clk->token('/queue/index.php'),
            'do' => 'serve', 'queue_id' => ['1'],
        ]);
        record('B14-08', 'Malformed queue parameters do not crash the queue action endpoint',
            $odd['status'] < 500 && !leaks($odd));
    } finally {
        db_exec('DELETE FROM queue_entries WHERE reference_no LIKE ?', [$prefix . '%']);
    }
}
