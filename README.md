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
- `lit deploy` runs `git pull` and deploys the current branch
- `lit checkout <branch>` switches to a different branch and deploys it

For bundle deployments:
- `lit init <bundle download url> [name]` initializes a new Lit directory
- `lit deploy` downloads the bundle and deploys it

To switch between git and bundle deployments, run `lit init <url> .` in your existing Lit project.

Other commands:
- `lit flush-opcache` flush PHP-FPM OPCache by calling `opcache_reset()` via an HTTP request
- `lit enable-git-release-caching` for faster deployments of the same commit
- `lit disable-git-release-caching` disables git release caching
- `lit enable-telemetry` enables sending anonymous telemetry after a deployment (disabled by default)
- `lit disable-telemetry` disables telemetry

## Getting started
To deploy a Laravel project with Lit, run `lit init <url>`, and follow the on-screen instructions.
You'll be asked to fill in your `.env` file, and to review and update the `hooks/before-release.sh` and `hooks/after-release.sh` hooks.
When you're done, run `lit deploy` to deploy the project.

## Migrating an existing project
Migrate from Deployer, Laravel Envoyer, or Laravel Forge to Lit:
- Run `lit init <url> .` inside your existing project
- Review the hook files (as mentioned in the on-screen instructions)
- Run `lit deploy` to deploy

After migrating, you can continue using Deployer, Envoyer, or Forge alongside Lit.
Lit uses the same directory structure and doesn't move or modify your existing files.

Migrate from `git pull` or FTP deployments to Lit:
- Run `lit init <url> .` inside your existing project
- Review the hook files (as mentioned in the on-screen instructions)
- Run `lit deploy` to deploy
- Update your cron and queue workers to point at `/current/artisan` instead of `/artisan`
- Update your nginx to point at `/current/public/index.php` instead of `/public/index.php`

Migrate an existing project to a new directory:
- Run `php artisan down` to put your existing project in maintenance mode
- Create a new Lit project with `lit init <url> [name]`
- Copy your existing `.env` file and `storage` directory to the Lit directory
- Run `lit deploy` to deploy
- Update your cron and queue workers to point at `{lit_directory}/current/artisan`
- Update your nginx to point at `{lit_directory}/current/public/index.php`
- Run `php artisan up` to take you Lit project out of maintenance mode 

## Deploying a bundle
Lit can download and deploy pre-built bundles.
The bundle can include your composer dependencies and javascript bundle so you don't have to install or build anything on the server.

To deploy from a bundle, first create a bundle and make it available to download, then run:
```
lit init <bundle download url>
```

You can upload a `.hash` file alongside your bundle that contains the SHA1 hash of your bundle.
This allows Lit to check the version of the bundle without having to download the full bundle. 
If the bundle hash is the same as the currently deployed bundle, the deployment is sk
If your bundle is available at: `https://example.com/bundle.tar`, then your hash file should be at `https://example.com/bundle.tar.hash`.

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

## Zero downtime deployments
Lit deployments are zero downtime, just like deployments done by Laravel Forge, Laravel Envoyer, and Deployer.

In a nutshell, zero downtime deployments prepare each new release in a separate directory from the active release.
The new release is only released once it has been fully built without errors.
If anything fails during deployment, the release is gracefully deleted without affecting the currently active release.

For example, if a typical deployment using git has errors during `composer install` or `npm run build`, then your application is in a broken state.
These commands failed in the directory that is currently deployed, so your users might run into errors.
With zero downtime deployments, those same commands run in a new directory, completely separate from your active release.
If they fail, the deployment is aborted and your active release is unaffected.

Lit uses the same directory structure as Laravel Forge and Envoyer, with the addition of `lit`, `logs`, and `hooks` directories:
```
project
├── .env
├── current -> releases/2/
├── hooks/
├── logs/
├── releases/
│   ├── 1/
│   └── 2/
└── storage/
```

The "current" directory contains the currently active release.
This directory is a symlink to the latest release in the "releases" directory.
Your Nginx webroot, cronjob, and supervisor queue workers should point to the "current" directory.

## Zero downtime deployment pitfalls
TODO:
storage dir
sqlite database
database migrations can fail
(all the other stuff from my deploy guide)

## Git release caching
Lit can cache releases and reuse them for future deployments.
Using a cached release is significantly faster when deploying the same commit multiple times.
Release caching is perfect for servers that host the same application multiple times for multiple tenants.

Release caching is disabled by default because caching adds a few seconds of overhead.

To enable release caching for an application, run `lit enable-git-release-caching`.
This will add a `before-caching.sh` hook to the project.
This hook should contain the steps that can be cached and reused between projects, typically these are `composer install`, `npm install` and `npm run build`.
After this hook is done running, Lit will cache the release so it can be reused the next time this commit is deployed.

A cached release is only reused if the `before-caching.sh` hook is identical to the hook that created the cache entry.
To keep the hook identical, consider using a symlink for your `before-caching.sh` to share it across projects.

## Downsides of deploying with Lit (or Git)
Using Lit (or Git) to deploy your Laravel applications is perfectly fine in almost all cases.
There are a few minor downsides you should be aware of:
- You don't run your tests before deploying (don't do this in prod, load + risk of dropping DB)
- The dependencies you're installing are not identical to the ones that passed your test suite
- Manual action required
- You need git, composer and NPM installed on your server

## Log rotation
Lit creates a `logs` directory for each project.
This directory contains Lit's own log files, but it's also a good place to store your application's cron and queue worker output.

You can use logrotate to keep log file size manageable.
First, create a directory for rotated logs:
```
mkdir /home/{user}/www/{project}/logs/old
```

Then add a logrotate configuration file at `/etc/logrotate.d/lit-log-rotation`:
```
/home/{user}/www/{project}/logs/*.log {
    size 10M
    rotate 4
    compress
    delaycompress
    missingok
    notifempty
    copytruncate

    su {user} {group}

    olddir /home/{user}/www/{project}/logs/old
}
```

Test your configuration with a dry-run:
```
sudo logrotate -d -v /etc/logrotate.d/lit-log-rotation
```

## License
Lit is open-sourced software licensed under the MIT license.
