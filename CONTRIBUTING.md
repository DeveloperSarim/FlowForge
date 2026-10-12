# Contributing to FlowForge

Thanks for helping make FlowForge better. Bug reports, new app integrations, docs fixes and features are all welcome.

## Reporting a bug

[Open an issue](https://github.com/DeveloperSarim/FlowForge/issues/new/choose) with:
- your FlowForge version (Settings → Updates) and PHP version,
- your hosting (Hostinger, cPanel, VPS…),
- what you did, what you expected and what happened instead (the last lines of `storage/logs/laravel.log` help).

Security problems: please don't open a public issue; see [SECURITY.md](SECURITY.md).

## Adding or fixing an app

Integrations are JSON/YAML manifests in `resources/integrations/`, not PHP classes. The manifest format, the audit command and the logo builder are described in [DEVELOPMENT.md](DEVELOPMENT.md#integrations-manifests-not-classes). Please run `php artisan flowforge:audit <app>` before opening the pull request; it must report 0 errors.

## Working on the code

1. Fork the repo and create a branch from `main`.
2. Set it up locally (see [DEVELOPMENT.md](DEVELOPMENT.md#development)):
   ```bash
   composer install && npm install && npm run build
   cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
   ```
3. Make your change. Keep it working on plain shared hosting: PHP 8.2+, MySQL and one cron job, no Node, Redis or daemons on the server.
4. Run the checks:
   ```bash
   php artisan test
   vendor/bin/pint
   npm run build
   ```
5. Open a pull request that explains what changed and why, with a screenshot for UI changes.

By contributing, you agree that your contribution is licensed under the project's [AGPL-3.0 license](LICENSE), and you follow our [Code of Conduct](CODE_OF_CONDUCT.md).
