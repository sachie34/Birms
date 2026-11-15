<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.add');
flash_add('warning', "The older household-based CSV importer is temporarily disabled while the import format is being aligned with the revised MSIT resident fields.");
redirect('residents/index.php');
