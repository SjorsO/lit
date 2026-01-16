<p align="center">
    <img src="https://sjorso.com/images/logos/lit-logo.png" width="48" height="48">
</p>

# Lit
Lit is a CLI for deploying Laravel.

Key features:
- Fully automated zero-downtime deployments by running `lit deploy`
- Deploy by pulling a git repository or by downloading a prepared bundle
- Hooks for custom deployment logic
- Logs with detailed deployment history

## Install
Install Lit with git:
```
git clone --quiet https://github.com/SjorsO/lit.git && source lit/lit.sh
```
Or, install from a bundle:
```
mkdir lit \
  && curl --silent --output lit/lit.tar --location https://github.com/SjorsO/lit/releases/download/latest/lit.tar.gz \
  && (cd lit && tar --extract --file lit.tar) \
  && rm lit/lit.tar \
  && source lit/lit.sh
```

## Usage
For git deployments:
- `lit init <git repository url> [name]` initializes a new Lit directory
- `lit deploy` runs `git clone` and deploys the current branch
- `lit checkout <branch>` switches to a different branch and deploys it

For bundle deployments:
- `lit init <bundle download url> [name]` initializes a new Lit directory
- `lit deploy` downloads the bundle and deploys it

To switch between git and bundle deployments, run `lit init <url>` in your existing Lit project.

Other commands:
- `lit flush-opcache` flush PHP-FPM OPCache
- `lit enable-git-release-caching` for faster deployments of the same commit
- `lit disable-git-release-caching` disables git release caching
- `lit enable-telemetry` enables sending anonymous telemetry after a deployment (disabled by default)
- `lit disable-telemetry` disables telemetry

## Getting started
To deploy a Laravel project with Lit, run `lit init <url>`.
You'll be asked to fill in your `.env` file, and to review the `hooks/before-release.sh` and `hooks/after-release.sh` hooks.
When you're done, run `lit deploy` to deploy the project.

## Migrating an existing project
If your application is already deployed, you can migrate to Lit using `lit init`.
Lit never moves or modifies your existing files.

### Migrating from Deployer, Laravel Envoyer, or Laravel Forge
- Run `lit init <url>` inside your existing project
- Review the generated hook files
- Run `lit deploy`

After migrating, you can continue using Deployer, Envoyer, or Forge alongside Lit.
Lit uses the same directory structure and doesn't move or modify your existing files.

### Migrating from `git pull` or FTP
- Run `lit init <url>` inside your existing project
- Review the generated hook files
- Run `lit deploy`
- Update the cron and queue workers to use `/current/artisan` instead of `/artisan`
- Update nginx to use `/current/public/index.php` instead of `/public/index.php`

### Migrating from any setup to a fresh directory
- Run `php artisan down` to put your existing project in maintenance mode
- Run `lit init <url> [name]` to create a new Lit project
- Copy your existing `.env` file and `storage` directory to the Lit project
- Run `lit deploy`
- Update the cron and queue workers to point at `{lit_directory}/current/artisan`
- Update nginx to point at `{lit_directory}/current/public/index.php`
- Run `php artisan up` to take your Lit project out of maintenance mode

## Deploying a bundle
Lit can deploy pre-built bundles.
Bundles can include your Composer dependencies and front-end assets, avoiding any installing or building on your server.

```
lit init <bundle download url>
```

You can also upload a `.hash` file at `{bundle download url}.hash` containing the SHA1 hash of the bundle.
Lit checks this hash first to prevent downloading the same bundle twice.

The script below is the recommended way to create a bundle and hash file:
```bash
project="$(basename "$(pwd)")"

cd ..

sed 's/\/$//' > "exclude-from-tar" <<EOF
$project/.git/
$project/bootstrap/cache/
$project/node_modules/
$project/public/storage
$project/storage/
$project/tests/
.env
EOF

tar --create --use-compress-program "zstd -T0 -3" \
    --exclude-from="exclude-from-tar" \
    --file "/tmp/artifacts.tar" "$project"

sha1sum "/tmp/artifacts.tar" | awk '{print $1}' > "/tmp/artifacts.tar.hash"

echo "Bundle contents:"
tar --list --file /tmp/artifacts.tar | awk -v p="$project" '$0 ~ "^" p "/vendor/" {c++; next} {print} END{if(c) print p "/vendor/{" c " entries}"}'
```

## Git release caching
Lit can cache git releases and reuse them for future deployments.
Using a cached release is significantly faster when deploying the same commit multiple times.
Release caching is perfect for servers that host the same application multiple times for multiple tenants.

Release caching is disabled by default because caching adds a few seconds of overhead.

To enable release caching for an application, run `lit enable-git-release-caching`.
This will add a `before-caching.sh` hook to the project.
This hook should contain the steps that can be cached and reused between projects, typically these are `composer install`, `npm install` and `npm run build`.
After this hook is done running, Lit will cache the release so it can be reused the next time this commit is deployed.

A cached release is only reused if the `before-caching.sh` hook is identical to the hook that created the cache entry.
To keep the hook identical, consider using a symlink for your `before-caching.sh` to share it across projects.

## Directory structure
Lit uses the same zero downtime approach as Laravel Envoyer, Laravel Forge, and Deployer.
Below is the directory structure of a project deployed with Lit:
```
project
├── .env                       # A symlink shares the ".env" file between each release
├── current -> releases/2/     # The "current" directory is a symlink to the currently active release
├── hooks/
│   ├── before-release.sh      # Contains commands like "composer install" and "php artisan config:cache"
│   └── after-release.sh       # Contains commands like "php artisan queue:restart" and "lit flush-opcache"
├── logs/
│   ├── lit.log                # Single line log entries for each Lit deployment
│   └── lit-output.log         # Full output of each Lit deployment
├── releases/
│   ├── 1/                     # The previous release, will get deleted when release #3 is deployed
│   └── 2/                     # The currently active release, symlinked to the "current" directory
└── storage/                   # A symlink shares the "storage" directory between each release
```

## License
Lit is open-sourced software licensed under the MIT license.
