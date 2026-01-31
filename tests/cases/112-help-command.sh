# Test the lit help command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

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
│   flush-opcache                  Flush PHP-FPM OPCache                       │
│   opcache-status [--json]        Show PHP-FPM OPCache status                 │
│   enable-git-release-caching     For faster deployments of the same commit   │
│   disable-git-release-caching    Disable git release caching                 │
│   enable-telemetry               Send anonymous telemetry after a deployment │
│   disable-telemetry              Disable telemetry                           │
│                                                                              │
│ For more info, visit: https://github.com/SjorsO/lit                          │
╰──────────────────────────────────────────────────────────────────────────────╯'
assert_exact_output "$expected_output" "$output" || exit 1
