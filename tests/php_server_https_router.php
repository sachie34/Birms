<?php
/** CI-only HTTPS simulation for verifying Secure session-cookie flags. */
declare(strict_types=1);

$_SERVER['HTTPS'] = 'on';
return require __DIR__ . '/php_server_router.php';
