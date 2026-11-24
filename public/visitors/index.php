<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('queue.view');
flash_add('warning', "The Visitor Logbook has been removed. Resident visits are now handled through the Blotter, Documents and Venue service queues.");
redirect('queue/index.php');
