# Test that lit help works outside of a lit directory

# We're in $world_path/case which is not a lit directory

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

# Help should work even outside a lit directory
assert_same 0 "$status_code" || exit 1

expected_output='╭──────────────────────────────────────────────────────────────────────────────╮
│ usage: lit <command>                                                         │
│                                                                              │
│ Common Lit commands:                                                         │
│                                                                              │
│   init <url> [name]    Initialize a new Lit directory from git or a bundle   │
│   deploy               Run a new deployment                                  │
│   checkout <branch>    Git checkout the given branch and deploy it           │
│                                                                              │
│ Other commands:                                                              │
│                                                                              │
│   flush-opcache                  Flush PHP-FPM OPcache                       │
│   opcache-status [--json]        Show PHP-FPM OPcache status                 │
│   enable-git-release-caching     For faster deployments of the same commit   │
│   disable-git-release-caching    Disable git release caching                 │
│   enable-telemetry               Send anonymous telemetry after a deployment │
│   disable-telemetry              Disable telemetry                           │
│                                                                              │
│ For more info, visit: https://github.com/SjorsO/lit                          │
╰──────────────────────────────────────────────────────────────────────────────╯'
assert_exact_output "$expected_output" "$output" || exit 1

# Also test that init works outside lit directory (it should)
set +e
output=$(lit init 2>&1)
status_code=$?
set -e

# Should show usage (exit 1) but not "This is not a Lit directory"
assert_same 1 "$status_code" || exit 1
expected_init_output='usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz'
assert_exact_output "$expected_init_output" "$output" || exit 1

# Test that lit help inside a lit directory doesn't log
lit init "https://github.com/SjorsO/lit.git" > /dev/null

cd "$world_path/case/lit"

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_exact_output "$expected_output" "$output" || exit 1

# Help should not be logged
log_content=$(cat "$world_path/case/lit/logs/lit-output.log" 2>/dev/null || echo "")
assert_string_not_contains "$log_content" "lit help" || exit 1

# Test that lit deploy fails inside the lit installation directory
cd "$world_path/lit"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'This is not a Lit directory' "$output" || exit 1

cd "$world_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'This is not a Lit directory' "$output" || exit 1
