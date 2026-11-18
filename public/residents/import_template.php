<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.add');
flash_add('warning', "The older household-based CSV template is retired. A revised import template will be provided after the Resident ID, Address, Purok, status, type, email and notification fields are finalized.");
redirect('residents/index.php');
