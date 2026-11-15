<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.edit');
flash_add('warning', "Household management has been retired under the revised MSIT requirements. Edit the resident's individual Address and Purok instead.");
redirect('residents/index.php');
