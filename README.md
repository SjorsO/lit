> [!WARNING]
> Lit is still in beta. Commands and features may change.

# Lit
Lit is a drop-in replacement for deploying Laravel projects with `git pull`.

Lit makes `git pull` better by adding:
- Automated deployments: deploy your whole project by just running `lit pull`
- Zero downtime: safely deploy and always keep your app online
- Detailed logs: know exactly which commit was deployed and when

## Install
Here's how to install Lit on your server:
```
# Download Lit
git clone git@github.com:SjorsO/lit.git

# Configure Lit
source lit/lit.sh
```

## Usage
- `lit clone <git repository url>` clones a git repository into a new Lit directory
- `lit migrate` migrates a project deployed with Git to Lit
- `lit pull` pulls the current branch and deploys it
- `lit checkout <branch>` switches and deploys another branch
- `lit log` runs `git log` for the current release

## Getting started
If your Laravel project is already deployed with `git pull`, then you can migrate to Lit by running `lit migrate`.
This command sets up Lit's directory structure and walks you through the next steps.

To deploy a Laravel project with Lit, run `lit clone <git repository url>`, and follow the on-screen instructions.

After setting up a Lit directory, you can customize the deployment by editing the `lit/hooks/before-release.sh` and `lit/hooks/after-release.sh` hooks.

When everything is configured, deploy the latest commit of your current branch by running `lit pull`.

## Zero downtime deployments
Lit deployments are zero downtime, just like deployments done by Laravel Forge, Laravel Envoyer, and Deployer.

In a nutshell, zero downtime deployments prepare each new release in a separate directory from the active release.
The new release is only activated once it has been fully built without errors.
If anything fails during deployment, the release is gracefully deleted without affecting the currently active release.

For example, if a typical deployment using `git pull` has errors during `composer install` or `npm run build`, then your application is in a broken state.
These commands failed in the directory that is currently deployed, so your users might run into errors.
With zero downtime deployments, those same commands run in a new directory, completely separate from your active release.
If they fail, the deployment is aborted and your active release is unaffected.

Lit uses the same directory structure as Laravel Forge and Envoyer, with the addition of a `lit` directory:
```
project
├── .env
├── current -> releases/2/
├── lit/
│   ├── hooks/
│   └── logs/
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

## Reusing releases
Lit has the option of caching and reusing releases.
This is significantly faster when you have to deploy the same commit multiple times.
Reusing releases is perfect for servers that host the same application multiple times for multiple tenants.

Reusing releases is disabled by default, because most servers host each application only once.
Caching the release adds a few seconds of overhead, so there's no reason to enable it if you don't need it.

To enable reusing releases for an application, run `lit enable-reusing`.
This will add a `before-storing-for-reuse.sh` hook to the project.
This hook should contain the steps that should be reused, typically these are `composer install`, `npm install` and `npm run build`.
After this hook is done running, Lit will cache the release so it can be reused the next time this commit is deployed.

A cached release is only reused if the `before-storing-for-reuse.sh` is identical to the hook that created the cache entry.
Because of this, it is recommended to use a symlink for your `before-storing-for-reuse.sh`.

## Downsides of deploying with Lit (or Git)
Using Lit (or Git) to deploy your Laravel applications is perfectly fine in almost all cases.
There are a few minor downsides you should be aware of:
- You don't run your tests before deploying (don't do this in prod, load + risk of dropping DB)
- The dependencies you're installing are not identical to the ones that passed your test suite
- Manual action required
- You need git, composer and NPM installed on your server

## License
Lit is open-sourced software licensed under the MIT license.
