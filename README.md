<p align="center">
    <img src="https://sjorso.com/images/logos/lit-logo.png" width="48" height="48">
</p>

# Lit
Lit is a CLI for deploying Laravel.

Key features:
- Fully automated zero-downtime deployments by running `lit deploy`
- Configurable hooks for custom deployment logic
- Detailed logs with exact deployment history

## Install
Install lit with git:
```
git clone --quiet https://github.com/SjorsO/lit.git && source lit/lit.sh
```
Or, install the latest release:
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
- `lit deploy` deploys the current branch
- `lit checkout <branch>` switches to a different branch and deploys it
- `lit log` forwards to `git log` for the current release

For bundle deployments:
- `lit init <bundle download url> [name]` initializes a new Lit directory
- `lit deploy` downloads the bundle from the URL and deploys it

Other commands:
- `lit flush-opcache` flush PHP-FPM OPCache by calling `opcache_reset()` via an HTTP request
- `lit enable-caching` enables git release caching
- `lit disable-caching` disables git release caching
- `lit enable-telemetry` enables anonymous telemetry
- `lit disable-telemetry` disables telemetry

## Getting started
To deploy a Laravel project with Lit, run `lit init <url>`, and follow the on-screen instructions.

After setting up a Lit directory, you can customize the deployment by editing the `hooks/before-release.sh` and `hooks/after-release.sh` hooks.

When everything is configured, deploy the latest commit of your current branch by running `lit deploy`.

## Migrating an existing project
TODO:

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
├── lit/
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

## Release caching
Lit can cache releases and reuse them for future deployments.
Using a cached release is significantly faster when deploying the same commit multiple times.
Release caching is perfect for servers that host the same application multiple times for multiple tenants.

Release caching is disabled by default because caching adds a few seconds of overhead.

To enable release caching for an application, run `lit enable-caching`.
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
