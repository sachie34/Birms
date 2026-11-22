<?php
/** Add a party to an open case, or correct one. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotters.edit');
$pid = get_int('id'); $party = null; $caseId = get_int('case');
if ($pid !== null) {
    $party = db_one('SELECT * FROM blotter_parties WHERE party_id = ?', [$pid]);
    if ($party === null) { abort(404, 'That party was not found.'); }
    $caseId = (int) $party['blotter_id'];
}
$case = $caseId === null ? null : db_one('SELECT * FROM blotters WHERE blotter_id = ?', [$caseId]);
if ($case === null) { abort(404, 'That case was not found.'); }
if (!case_is_open($case)) { abort(403, 'Resolved, dismissed or archived cases cannot be changed.'); }

$v = ['party_role' => (string) ($party['party_role'] ?? 'Respondent'), 'name' => (string) ($party['full_name'] ?? ''), 'age' => (string) ($party['age'] ?? ''), 'address' => (string) ($party['address'] ?? ''), 'contact' => (string) ($party['contact_number'] ?? '')];
$errors = [];
if (is_post()) {
    require_csrf();
    foreach (array_keys($v) as $k) { $v[$k] = post_str($k); }
    if (!in_array($v['party_role'], ['Complainant', 'Respondent'], true)) { $errors[] = 'Choose Complainant or Respondent.'; }
    $c = party_clean($v['name'], $v['age'], $v['address'], $v['contact'], 'Party', $errors);
    if (!$errors && $c) {
        try {
            db_transaction(function () use ($v, $c, $party, $case): void {
                // Serialize party edits with case finalization so no party can be added after closure.
                $lockedCase = db_one(
                    'SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ? FOR UPDATE',
                    [$case['blotter_id']]
                );
                if ($lockedCase === null || !case_is_open($lockedCase)) {
                    throw new DomainException('This case is no longer editable because it has been resolved, dismissed or archived.');
                }
                if ($party) {
                    $lockedParty = db_one(
                        'SELECT party_id FROM blotter_parties WHERE party_id = ? AND blotter_id = ? FOR UPDATE',
                        [$party['party_id'], $lockedCase['blotter_id']]
                    );
                    if ($lockedParty === null) { throw new RuntimeException('That party is no longer attached to this case. Refresh and try again.'); }
                    $changed = db_exec(
                        'UPDATE blotter_parties SET party_role = ?, full_name = ?, age = ?, address = ?, contact_number = ? WHERE party_id = ? AND blotter_id = ?',
                        [$v['party_role'], $c['name'], $c['age'], $c['address'], $c['contact'], $party['party_id'], $lockedCase['blotter_id']]
                    );
                    if ($changed !== 1) { throw new RuntimeException('The party details changed before they could be saved. Refresh and try again.'); }
                    audit('blotter_party_updated', 'Blotter', 'Success', $lockedCase['case_no'] . ': ' . $v['party_role'] . ' ' . $c['name'] . ' corrected', 'blotter', (int) $lockedCase['blotter_id']);
                } else {
                    db_exec(
                        'INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number) VALUES (?, ?, ?, ?, ?, ?)',
                        [$lockedCase['blotter_id'], $v['party_role'], $c['name'], $c['age'], $c['address'], $c['contact']]
                    );
                    audit('blotter_party_added', 'Blotter', 'Success', $lockedCase['case_no'] . ': ' . $v['party_role'] . ' ' . $c['name'] . ' added', 'blotter', (int) $lockedCase['blotter_id']);
                }
            });
            flash_add('success', $party ? 'Party saved.' : 'Party added.');
            redirect('blotters/view.php', ['id' => (int) $case['blotter_id']]);
        } catch (DomainException | RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    audit_best_effort('blotter_rejected', 'Blotter', 'Failed', 'party: ' . implode(' ', array_slice($errors, 0, 3)));
}
$title = $party ? 'Edit party' : 'Add party';
render_header($title, [['Blotter', 'blotters/index.php'], [(string) $case['case_no'], 'blotters/view.php?id=' . (int) $case['blotter_id']], [$title, null]], 'blotters');
page_head($title, (string) $case['case_no']);
echo errors_box($errors);
echo '<form method="post" action="' . e(base_url('blotters/party.php?' . ($party ? 'id=' . (int) $party['party_id'] : 'case=' . (int) $case['blotter_id']))) . '" class="needs-validation" novalidate autocomplete="off">' . csrf_field();
card_start(); echo '<div class="row">';
echo f_select('party_role', 'Role', ['Complainant' => 'Complainant', 'Respondent' => 'Respondent'], $v['party_role'], ['col' => 'col-md-3']);
echo f_text('name', 'Full name', $v['name'], ['required' => true, 'maxlength' => 100, 'col' => 'col-md-5']) . f_text('age', 'Age', $v['age'], ['type' => 'number', 'min' => 0, 'max' => 120, 'col' => 'col-md-2']);
echo f_text('contact', 'Contact number', $v['contact'], ['maxlength' => 20, 'placeholder' => '09171234567', 'col' => 'col-md-6']) . f_text('address', 'Address', $v['address'], ['maxlength' => 255, 'col' => 'col-md-6']);
echo '</div>'; card_end();
echo '<div class="mt-3"><button class="btn btn-primary" type="submit">Save</button> <a class="btn btn-light border" href="' . e(base_url('blotters/view.php?id=' . (int) $case['blotter_id'])) . '">Cancel</a></div></form>';
render_footer();
