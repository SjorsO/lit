<?php

function opcache_get_status($include_scripts = true) {
    return [
        'opcache_enabled' => true,
        'cache_full' => false,
        'restart_pending' => false,
        'restart_in_progress' => false,
        'memory_usage' => [
            'used_memory' => 106993152,
            'free_memory' => 429877760,
            'wasted_memory' => 0,
            'current_wasted_percentage' => 0,
        ],
        'interned_strings_usage' => [
            'buffer_size' => 67108864,
            'used_memory' => 21033368,
            'free_memory' => 46075496,
            'number_of_strings' => 64091,
        ],
        'opcache_statistics' => [
            'num_cached_scripts' => 922,
            'num_cached_keys' => 1817,
            'max_cached_keys' => 262237,
            'hits' => 48343,
            'start_time' => 1769675952,
            'last_restart_time' => 0,
            'oom_restarts' => 0,
            'hash_restarts' => 0,
            'manual_restarts' => 0,
            'misses' => 922,
            'blacklist_misses' => 0,
            'blacklist_miss_ratio' => 0,
            'opcache_hit_rate' => 98.12848878514157,
        ],
        'jit' => [
            'enabled' => true,
            'on' => false,
            'kind' => 0,
            'opt_level' => 0,
            'opt_flags' => 0,
            'buffer_size' => 67108848,
            'buffer_free' => 67106363,
        ],
    ];
}
