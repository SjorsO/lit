# Lit
Lit is a deployment tool for Laravel written in Bash.
It supports deploying from git repositories or pre-built bundles (tar archives).

Lit is always invoked via `lit.sh` (e.g., `bash lit.sh deploy`).
Users either run it manually on the server or trigger it remotely via an SSH session (e.g., `ssh server "cd /path/to/project && lit deploy"`).

## Running Tests
```bash
bash tests/run-tests.sh        # Run all tests
bash tests/run-tests.sh 103    # Run a specific test case
```

The test runner automatically resets the `tests/world` directory before each test - no manual cleanup needed.
After you run a specific test, created files you can do assertions on are in `tests/world`.

## Notes
- Always verify that path variables are where we expect them to be (by checking if files/directories that we expect there actually exist, for example). Never take the risk of doing something in the wrong directory.
- Use `printf` instead of `echo` for output (more portable)
- Use single quotes for printf format strings: `printf 'Hello %s\n' "$name"`
- When printf format starts with a dash, use `--` to terminate options: `printf -- '- item\n'`
- Use the `is_macos` helper for platform-specific behavior
- Lit should work on macOS and on Linux (no Windows support)
- Prefer explicit variable names over positional parameters in complex scripts
- Log important events to `$project_base_path/logs/lit.log` with timestamp: `echo "[$(get_human_timestamp)] Message" >> "$project_base_path/logs/lit.log"`
