<?php

echo function_exists('opcache_reset') && opcache_reset()
    ? "OPcache flushed successfully.\n"
    : "OPcache is not enabled.\n";
