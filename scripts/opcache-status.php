<?php

if (! function_exists('opcache_get_status')) {
    echo "OPCache is not available.\n";
    exit;
}

$status = opcache_get_status(false);

if ($status === false) {
    echo "OPCache is not enabled.\n";
    exit;
}

if (isset($_GET['json'])) {
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
    exit;
}

$memory = $status['memory_usage'];
$stats = $status['opcache_statistics'];
$interned = $status['interned_strings_usage'];
$jit = $status['jit'];

$formatBytes = function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return sprintf('%.1f GB', $bytes / 1073741824);
    }
    if ($bytes >= 1048576) {
        return sprintf('%.1f MB', $bytes / 1048576);
    }

    return sprintf('%.1f KB', $bytes / 1024);
};

$formatBool = function (bool $value): string {
    return $value ? 'Yes' : 'No';
};

$formatTimeAgo = function (int $timestamp): string {
    if ($timestamp === 0) {
        return 'Never';
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return $diff === 1 ? '1 second ago' : $diff . ' seconds ago';
    }
    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }

    $days = (int) floor($diff / 86400);
    return $days === 1 ? '1 day ago' : $days . ' days ago';
};

echo "OPCache:\n";
echo sprintf("  Cache full:              %s\n", $formatBool($status['cache_full']));
echo sprintf("  Restart pending:         %s\n", $formatBool($status['restart_pending']));
echo sprintf("  Restart in progress:     %s\n", $formatBool($status['restart_in_progress']));
echo sprintf("  Memory:                  %s (max: %s, free: %s)\n", $formatBytes($memory['used_memory']), $formatBytes($memory['used_memory'] + $memory['free_memory']), $formatBytes($memory['free_memory']));
echo sprintf("  Wasted memory:           %s (%.1f%%)\n", $formatBytes($memory['wasted_memory']), $memory['current_wasted_percentage']);
echo sprintf("  Interned strings:        %s\n", number_format($interned['number_of_strings']));
echo sprintf("  Interned strings memory: %s (max: %s, free: %s)\n", $formatBytes($interned['used_memory']), $formatBytes($interned['buffer_size']), $formatBytes($interned['free_memory']));
echo sprintf("  Cached scripts:          %s\n", number_format($stats['num_cached_scripts']));
echo sprintf("  Cached keys:             %s (max: %s, free: %s)\n", number_format($stats['num_cached_keys']), number_format($stats['max_cached_keys']), number_format($stats['max_cached_keys'] - $stats['num_cached_keys']));
echo sprintf("  Hits:                    %s (misses: %s, hit rate: %.1f%%)\n", number_format($stats['hits']), number_format($stats['misses']), $stats['opcache_hit_rate']);
echo sprintf("  Blacklist misses:        %s (ratio: %.1f%%)\n", number_format($stats['blacklist_misses']), $stats['blacklist_miss_ratio']);
echo sprintf("  OOM restarts:            %s\n", number_format($stats['oom_restarts']));
echo sprintf("  Hash restarts:           %s\n", number_format($stats['hash_restarts']));
echo sprintf("  Manual restarts:         %s\n", number_format($stats['manual_restarts']));
echo sprintf("  Started:                 %s\n", $formatTimeAgo($stats['start_time']));
echo sprintf("  Last restart:            %s\n", $formatTimeAgo($stats['last_restart_time']));
echo "\n";
echo "JIT:\n";
echo sprintf("  Status:     %s\n", $jit['enabled'] ? ($jit['on'] ? 'Enabled (and turned on)' : 'Enabled (but not turned on)') : 'Disabled');
echo sprintf("  Kind:       %d\n", $jit['kind']);
echo sprintf("  Opt level:  %d\n", $jit['opt_level']);
echo sprintf("  Opt flags:  %d\n", $jit['opt_flags']);
echo sprintf("  Buffer:     %s (free: %s)\n", $formatBytes($jit['buffer_size']), $formatBytes($jit['buffer_free']));
