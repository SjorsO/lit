# Lit
Lit is a deployment tool for Laravel written in Bash.
It supports deploying from git repositories or pre-built bundles (tar archives).

Lit is always invoked via `lit.sh` (e.g., `bash lit.sh deploy`).
Users either run it manually on the server or trigger it remotely via an SSH session (e.g., `ssh server "cd /path/to/project && lit deploy"`).

## Running Tests
```bash
bash tests/run-tests.sh              # Run all tests sequentially
bash tests/run-tests.sh 103          # Run a specific test case
bash tests/run-tests-in-parallel.sh  # Run all tests in parallel
```

The sequential test runner automatically resets the `tests/worlds/world-NNN` directory before each test - no manual cleanup needed.
After you run a specific test, created files you can do assertions on are in `tests/worlds/world-NNN`.

The parallel test runner creates `tests/worlds/` with a separate world per test (`worlds/world-104/`, etc.).
After running, all log files are collected into `worlds/_lit-logs/` and `worlds/_lit-output-logs/` for inspection.

## Notes
- It's "zero downtime", not "zero-downtime"
- Always verify that path variables are where we expect them to be (by checking if files/directories that we expect there actually exist, for example). Never take the risk of doing something in the wrong directory.
- The lit repository (https://github.com/SjorsO/lit.git) has a branch called "another-branch" that can be used for testing `lit checkout`
- In usage strings: required args use `<arg>`, optional args use `[arg]`, optional flags use `[--flag]`
- Use `printf` instead of `echo` for output (more portable)
- Use single quotes for printf format strings: `printf 'Hello %s\n' "$name"`
- When printf format starts with a dash, use `--` to terminate options: `printf -- '- item\n'`
- Use the `is_macos` helper for platform-specific behavior
- Lit should work on macOS and on Linux (no Windows support)
- Prefer explicit variable names over positional parameters in complex scripts
- Log important events to `$project_base_path/logs/lit.log` with timestamp: `echo "[$(get_human_timestamp)] Message" >> "$project_base_path/logs/lit.log"`
- When writing new tests, don't run them automatically, I'll do that manually.
- Claude should never run "lit.sh deploy" or any other Lit command. Run a unit test instead and read the "lit-output.log".
- Print only the first 11 chars of git commits, but print all 40 chars of sha1 hashes
- All test cases need a unique 3 digit prefix
- In `deploy.sh`, hooks have to be called using `cat "$path_to_script" | bash -se -- "$var1" "$var2"`, this has been proven to work reliably. (and the -e ensures they fail properly)
- "scripts/init.sh" contains a hardcoded link to a heading in the readme. If we update the readme, you have to check that link.
- Use `shasum` instead of `sha1sum` for hashing - always use a sha1 hash.
