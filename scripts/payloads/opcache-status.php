<?php

if (! function_exists('opcache_get_status')) {
    echo "OPcache is not available.\n";

    exit;
}

$status = opcache_get_status(false);

if ($status === false) {
    echo "OPcache is not enabled.\n";

    exit;
}

if (isset($_GET['json'])) {
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";

    exit;
}

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

$memory = $status['memory_usage'];
$stats = $status['opcache_statistics'];
$interned = $status['interned_strings_usage'];
$jit = $status['jit'];

echo sprintf(<<<STRING
OPcache:
  Cache full:              %s
  Restart pending:         %s
  Restart in progress:     %s
  Memory:                  %s (max: %s, free: %s)
  Wasted memory:           %s (%.1f%%)
  Interned strings:        %s
  Interned strings memory: %s (max: %s, free: %s)
  Cached scripts:          %s
  Cached keys:             %s (max: %s, free: %s)
  Hits:                    %s (misses: %s, hit rate: %.1f%%)
  Blacklist misses:        %s (ratio: %.1f%%)
  OOM restarts:            %s
  Hash restarts:           %s
  Manual restarts:         %s
  Started:                 %s
  Last restart:            %s

JIT:
  Status:     %s
  Kind:       %d
  Opt level:  %d
  Opt flags:  %d
  Buffer:     %s (free: %s)

STRING,
    $formatBool($status['cache_full']),
    $formatBool($status['restart_pending']),
    $formatBool($status['restart_in_progress']),
    $formatBytes($memory['used_memory']), $formatBytes($memory['used_memory'] + $memory['free_memory']), $formatBytes($memory['free_memory']),
    $formatBytes($memory['wasted_memory']), $memory['current_wasted_percentage'],
    number_format($interned['number_of_strings']),
    $formatBytes($interned['used_memory']), $formatBytes($interned['buffer_size']), $formatBytes($interned['free_memory']),
    number_format($stats['num_cached_scripts']),
    number_format($stats['num_cached_keys']), number_format($stats['max_cached_keys']), number_format($stats['max_cached_keys'] - $stats['num_cached_keys']),
    number_format($stats['hits']), number_format($stats['misses']), min($stats['opcache_hit_rate'], 99.9),
    number_format($stats['blacklist_misses']), $stats['blacklist_miss_ratio'],
    number_format($stats['oom_restarts']),
    number_format($stats['hash_restarts']),
    number_format($stats['manual_restarts']),
    $formatTimeAgo($stats['start_time']),
    $formatTimeAgo($stats['last_restart_time']),
    $jit['enabled'] ? ($jit['on'] ? 'Enabled (and turned on)' : 'Enabled (but not turned on)') : 'Disabled',
    $jit['kind'],
    $jit['opt_level'],
    $jit['opt_flags'],
    $formatBytes($jit['buffer_size']), $formatBytes($jit['buffer_free']),
);
