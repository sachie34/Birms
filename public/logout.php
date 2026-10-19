<?php
/** Sign out. POST with a valid token only; a plain link (GET) does nothing (finding L1). */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (!is_post()) { redirect('dashboard.php'); }
require_csrf();
logout_user();
redirect('index.php', ['m' => 'bye']);
