<?php
/** Retired legacy route: the revised MSIT SRS replaces this workflow. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
flash_add('warning', "Household grouping has been retired under the revised MSIT requirements. Residents are now maintained individually by Address and Purok.");
redirect('residents/index.php');
