<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.view');
flash_add('warning', "The Visitor Logbook has been removed in the revised MSIT requirements. Resident visits will be recorded through the Blotter, Documents and Venue service queues; queue screens are still being implemented.");
redirect('residents/index.php');
