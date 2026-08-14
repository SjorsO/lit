<?php

/**
 * @var string $litBasePath
 */

$phpSourceFile = "$litBasePath/scripts/payloads/flush-opcache.php";
$actionLabel = 'flush OPcache';
$notIfFirstRelease = true;
$queryString = '';

require "$litBasePath/scripts/run-php-script.php";
