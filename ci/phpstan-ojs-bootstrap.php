<?php

declare(strict_types=1);

$ojs = getenv('OJS_ROOT') ?: throw new RuntimeException('Set OJS_ROOT');
require $ojs . '/lib/pkp/lib/vendor/autoload.php';
defined('PKP_STRICT_MODE') || define('PKP_STRICT_MODE', true);
defined('BASE_SYS_DIR') || define('BASE_SYS_DIR', $ojs);
require dirname(__DIR__) . '/tests/bootstrap.php';
