<?php
/** tests/lib.php  Shared by the test runners: HTTP client with cookies, result recording, leak detection. */
declare(strict_types=1);

final class Http
{
    public string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'birmsjar'); }
    public function __destruct() { @unlink($this->jar); }
    /** @return array{status:int,headers:array,cookies:array,body:string,location:?string} */
    public function req(string $method, string $path, array $data = [], bool $absolute = false): array
    {
        $ch = curl_init(($absolute ? '' : $this->base) . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method]);
        if ($method === 'POST') {
            $multipart = false;
            foreach ($data as $value) { if ($value instanceof CURLFile) { $multipart = true; break; } }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $h = []; $cookies = [];
        foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
            if (strpos($line, ':') === false) { continue; }
            [$k, $v] = explode(':', $line, 2); $k = strtolower(trim($k)); $v = trim($v);
            if ($k === 'set-cookie') { $cookies[] = $v; } else { $h[$k] = $v; }
        }
        return ['status' => $status, 'headers' => $h, 'cookies' => $cookies, 'body' => substr($raw, $hs), 'location' => $h['location'] ?? null];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function post(string $p, array $d = []): array { return $this->req('POST', $p, $d); }
    public function upload(string $p, array $d, string $field, string $filePath, string $clientName, string $mime = 'application/pdf'): array
    {
        if (!is_file($filePath)) { throw new RuntimeException('Upload test file does not exist.'); }
        $d[$field] = new CURLFile($filePath, $mime, $clientName);
        return $this->req('POST', $p, $d);
    }
    public function token(string $page): string
    {
        $r = $this->get($page);
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
    }
    public function login(string $user, string $pass): array
    {
        return $this->post('/index.php', ['_csrf' => $this->token('/index.php'), 'username' => $user, 'password' => $pass]);
    }
}

$results = [];
function record(string $id, string $name, ?bool $ok, string $note = ''): void
{
    global $results;
    $results[] = [$id, $name, $ok === null ? 'NOT TESTED' : ($ok ? 'PASS' : 'FAIL'), $note];
    printf("%-5s %-10s %s%s\n", $ok === null ? 'SKIP' : ($ok ? 'PASS' : 'FAIL'), $id, $name, $note !== '' ? '  [' . $note . ']' : '');
}
function loc(array $r): string { return (string) ($r['location'] ?? ''); }
function leaks(array $r): bool { return (bool) preg_match('~Fatal error|Stack trace|Uncaught|Warning:|Notice:|/home/|[A-Z]:\\\\~', $r['body']); }


/** Create four role users (zz_test_*) and return a signed-in Http session for each. */
function fx_sessions(string $base, string $password): array
{
    db_exec("DELETE FROM users WHERE username LIKE 'zz\\_test\\_%'");
    $S = [];
    foreach (['admin', 'captain', 'secretary', 'clerk'] as $role) {
        db_exec("INSERT INTO users (username, password_hash, full_name, role, status, must_change_password) VALUES (?, ?, ?, ?, 'active', 0)",
            ['zz_test_' . $role, password_hash($password, PASSWORD_DEFAULT), 'Test ' . ucfirst($role), $role]);
        $S[$role] = new Http($base);
        $S[$role]->login('zz_test_' . $role, $password);
    }
    return $S;
}
/** Fetch a CSRF token from $tokenPage with this session, then POST. */
function post_as(Http $h, string $tokenPage, string $path, array $data = []): array
{
    return $h->post($path, ['_csrf' => $h->token($tokenPage)] + $data);
}

require_once __DIR__ . '/../app/signature_lib.php';

/** Create a deterministic RGB JPEG used only by Chairman signature integration tests. */
function fx_chairman_signature_jpeg(): string
{
    $bytes = base64_decode('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', true);
    if (!is_string($bytes) || $bytes === '') { throw new RuntimeException('Could not decode the test Chairman signature JPEG.'); }
    $path = tempnam(sys_get_temp_dir(), 'birms-signature-');
    if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
        if (is_string($path)) { @unlink($path); }
        throw new RuntimeException('Could not prepare the test Chairman signature JPEG.');
    }
    return $path;
}

/** Upload the test JPEG through the actual Admin-only route; preserve the previous configured path. */
function fx_upload_chairman_signature(Http $admin): array
{
    $previous = chairman_signature_current_relative();
    $jpeg = fx_chairman_signature_jpeg();
    try {
        $response = $admin->upload(
            '/admin/chairman_signature.php',
            ['_csrf' => $admin->token('/admin/chairman_signature.php'), 'do' => 'upload'],
            'signature_file',
            $jpeg,
            'ZZ-test-chairman-signature.jpg',
            'image/jpeg'
        );
    } finally {
        @unlink($jpeg);
    }
    return [
        'response' => $response,
        'previous' => $previous,
        'current' => chairman_signature_current_relative(),
    ];
}

/** Restore the pre-test setting and remove only the image created by this test fixture. */
function fx_restore_chairman_signature(array $fixture): void
{
    $previous = is_string($fixture['previous'] ?? null) ? $fixture['previous'] : null;
    $created = is_string($fixture['current'] ?? null) ? $fixture['current'] : null;
    db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'chairman_signature_path'", [$previous]);
    if ($created !== null && $created !== '' && $created !== $previous) {
        $absolute = chairman_signature_path_to_absolute($created);
        if ($absolute !== null) { @unlink($absolute); }
    }
}

function expect_status(string $role, string $perm): int { return role_can($role, $perm) ? 200 : 403; }
/** Check one GET page for every role against the permission map (never hard-coded). */
function rbac_page(array $S, string $path, string $perm): array
{
    $bad = '';
    foreach ($S as $role => $h) { $r = $h->get($path); $want = expect_status($role, $perm); if ($r['status'] !== $want || leaks($r)) { $bad .= "{$role} got {$r['status']} want {$want}; "; } }
    return [$bad === '', $bad];
}
