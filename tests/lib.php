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
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
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
function expect_status(string $role, string $perm): int { return role_can($role, $perm) ? 200 : 403; }
/** Check one GET page for every role against the permission map (never hard-coded). */
function rbac_page(array $S, string $path, string $perm): array
{
    $bad = '';
    foreach ($S as $role => $h) { $r = $h->get($path); $want = expect_status($role, $perm); if ($r['status'] !== $want || leaks($r)) { $bad .= "{$role} got {$r['status']} want {$want}; "; } }
    return [$bad === '', $bad];
}
