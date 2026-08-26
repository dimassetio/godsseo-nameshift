# Nameshift

Internal Laravel + Inertia application for synchronizing Namecheap and Name.com domains and changing authoritative nameservers in safe, auditable batches.

Nameserver changes have two entry flows:

- **Single domain:** edit nameserver 1 and 2 directly in the domain table, click Save, review the before/after dialog, then confirm.
- **Bulk update:** download the Excel template, enter up to 100 exact domain names with `nameserver1` and `nameserver2`, upload it, review the generated preview, then confirm with one button. Domains absent from the workbook are never included.

## Local setup

1. Copy `.env.example` to `.env`, configure MySQL/MariaDB, and set a unique `APP_KEY`.
2. Install dependencies with `composer install` and `npm install`.
3. Run `php artisan migrate`.
4. Create the initial administrator with `php artisan app:create-admin`.
5. Start the application with `composer run dev`.

Registrar accounts and encrypted credentials are configured in **Settings → Registrar accounts**. Use sandbox accounts first. Namecheap production access requires the server's public IPv4 address to be allowlisted.

## Production operations

- Serve the application only over HTTPS and set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.
- Keep `APP_KEY` stable and outside source control; changing it makes stored registrar credentials unreadable.
- Run database migrations before starting workers.
- Run queue workers under Supervisor/systemd, for example:
  `php artisan queue:work database --queue=registrar-mutations,registrar-sync,default --sleep=2 --tries=50 --timeout=60 --max-time=3600`.
- Run `php artisan schedule:run` every minute so queue batches and failed-job records are pruned.
- Back up the MySQL database and test restoration regularly; it contains inventory, rollback snapshots, and audit history.
- Treat a provider success as registrar acceptance, not proof of global DNS propagation.

## Verification

Run `php artisan test`, `npm run build`, `npm run format:check`, and `npx eslint .` before deployment. Start production verification with one non-critical domain from each registrar.
