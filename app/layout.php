<?php
/**
 * app/layout.php  Page frame matching the ff.pptx reference: navy sidebar, breadcrumb bar, user chip.
 * No inline styles or scripts anywhere (strict Content-Security-Policy).
 */
declare(strict_types=1);

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) { $s .= mb_substr_safe($p, 1); }
    return strtoupper($s !== '' ? $s : '?');
}

/** @param array<int, array{0:string,1:?string}> $crumbs */
function render_header(string $title, array $crumbs = [], string $active = ''): void
{
    $user = auth_user();
    $menu = $user ? visible_menu() : [];
    $asset = fn(string $p): string => e(base_url('assets/' . $p));
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | <?= e((string) config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= $asset('vendor/bootstrap/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
  <link rel="stylesheet" href="<?= $asset('css/birms.css') ?>">
</head>
<body>
<?php if ($user): ?>
<div class="app-shell" id="appShell">
  <aside class="sidebar" aria-label="Main navigation">
    <div class="brand">
      <div class="brand-mark">B</div>
      <div class="brand-text"><strong>BIRMS</strong><span>Barangay Information and Records Management System</span></div>
    </div>
    <nav class="nav-scroll">
      <?php foreach ($menu as $group => $items): ?>
        <div class="nav-group"><?= e(strtoupper($group)) ?></div>
        <?php foreach ($items as $it): ?>
          <a class="nav-link-item<?= $it['key'] === $active ? ' active' : '' ?>" href="<?= e(base_url($it['path'])) ?>">
            <i class="bi <?= e($it['icon']) ?>"></i><span><?= e($it['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <button type="button" class="nav-link-item btn-reset" id="sidebarToggle"><i class="bi bi-layout-sidebar-inset"></i><span>Collapse sidebar</span></button>
    </div>
  </aside>
  <div class="app-main">
    <header class="topbar">
      <nav aria-label="breadcrumb"><ol class="crumbs">
        <li><a href="<?= e(base_url('dashboard.php')) ?>">BIRMS</a></li>
        <?php foreach ($crumbs as [$label, $url]): ?>
          <li><?php if ($url): ?><a href="<?= e(base_url($url)) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ol></nav>
      <form class="topbar-search" method="get" action="<?= e(base_url('search.php')) ?>" role="search" aria-label="Search records">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" maxlength="80" placeholder="Search records" aria-label="Search records">
        <button class="btn-reset topbar-search-submit" type="submit" aria-label="Submit search"><i class="bi bi-arrow-return-left" aria-hidden="true"></i></button>
      </form>
      <div class="dropdown user-chip">
        <button class="btn-reset d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false" type="button">
          <span class="avatar"><?= e(initials((string) $user['full_name'])) ?></span>
          <span class="d-inline d-md-none badge rounded-pill text-bg-secondary"><?= e(role_label((string) $user['role'])) ?></span>
          <span class="text-start d-none d-md-block"><span class="uname"><?= e((string) $user['full_name']) ?></span><span class="urole"><?= e(role_label((string) $user['role'])) ?></span></span>
          <i class="bi bi-chevron-down small"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= e(base_url('account/password.php')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="<?= e(base_url('logout.php')) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
        </ul>
      </div>
    </header>
    <main class="content">
      <?php foreach (flash_take() as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
          <?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endforeach; ?>
<?php else: ?>
<div class="bare">
<?php endif; ?>
<?php
}

function render_footer(): void
{
    $user = auth_user();
    $asset = fn(string $p): string => e(base_url('assets/' . $p));
    if ($user): ?>
    </main>
    <footer class="app-foot">BIRMS · Barangay Wawa, Balagtas, Bulacan · Permissions are <?= e(PERMISSIONS_STATUS) ?> pending validation</footer>
  </div>
</div>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5">Please confirm</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="confirmText"></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="confirmOk">Confirm</button></div>
  </div></div>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="<?= $asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= $asset('js/app.js') ?>"></script>
</body>
</html>
<?php
}

function render_error_page(int $code, string $title, string $message): void
{
    $user = auth_user();
    render_header($title, [[$title, null]]);
    echo '<div class="error-card"><div class="error-code">' . $code . '</div><h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
    echo '<a class="btn btn-primary" href="' . e(base_url($user ? 'dashboard.php' : 'index.php')) . '">' . ($user ? 'Back to Operations Overview' : 'Go to sign in') . '</a></div>';
    render_footer();
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed', 500 => 'Something went wrong'];
    render_error_page($code, $titles[$code] ?? 'Error', $message);
    exit;
}

function render_pagination(int $total, int $page, int $per, string $path, array $query): void
{
    $pages = (int) max(1, ceil($total / $per));
    if ($pages <= 1) { return; }
    $href = function (int $p) use ($path, $query): string {
        $q = $query; $q['page'] = $p;
        return e(base_url($path) . '?' . http_build_query($q));
    };
    echo '<nav class="d-flex justify-content-between align-items-center px-3 py-3 border-top" aria-label="Pagination">';
    echo '<small class="text-muted">Page ' . $page . ' of ' . $pages . ' (' . $total . ' records)</small><ul class="pagination pagination-sm mb-0">';
    echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $href(max(1, $page - 1)) . '">Previous</a></li>';
    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
        echo '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . $href($p) . '">' . $p . '</a></li>';
    }
    echo '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . $href(min($pages, $page + 1)) . '">Next</a></li>';
    echo '</ul></nav>';
}

function pill(string $text, string $color): string
{
    $c = in_array($color, ['blue', 'green', 'amber', 'grey', 'red'], true) ? $color : 'grey';
    return '<span class="pill pill-' . $c . '">' . e($text) . '</span>';
}
