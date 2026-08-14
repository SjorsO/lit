<?php

/**
 * @var string $litBasePath
 * @var string[] $arguments
 */

$phpSourceFile = "$litBasePath/scripts/payloads/opcache-status.php";
$actionLabel = 'get OPcache status';
$notIfFirstRelease = false;
$queryString = ($arguments[1] ?? '') === '--json' ? 'json' : '';

require "$litBasePath/scripts/run-php-script.php";
