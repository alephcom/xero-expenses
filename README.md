# Expense Review for Xero

A [Laravel](https://laravel.com/) app that connects to [Xero](https://www.xero.com/) so your team can link organisations (tenants), sync bank spend and accounts-payable lines, and review expenses in the browser—with **Sign in with Xero** and organisation-scoped roles.

**Stack:** Laravel 13, PHP 8.3, [Laravel Breeze](https://laravel.com/docs/starter-kits#laravel-breeze) (Livewire + Volt), Tailwind CSS, Vite.

## Features

- **Sign in with Xero** (OpenID Connect)—no local passwords for normal users.
- **Connect Xero** (OAuth 2) to store API tokens per organisation; tokens are encrypted at rest.
- **Organisations & roles:** admins connect tenants and invite reviewers by email; members only see orgs they belong to.
- **Expense review UI:** filter, sort, and update line-level review state synced from Xero.
- **Background sync** via the database queue (`SyncXeroOrganisationJob`), with authorization re-checked when the job runs.

## Requirements

- PHP 8.3+
- [Composer](https://getcomposer.org/)
- Node.js and npm (for frontend assets)
- A [Xero developer app](https://developer.xero.com/) with **Sign in with Xero** enabled and a registered redirect URI

## Quick start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# Create database/database.sqlite if using SQLite, then:
php artisan migrate
npm run build
```

For a scripted first-time setup (deps, `.env`, key, migrate, build): `composer run setup`.

## Configuration

### Database

Set `DB_*` in `.env`. SQLite is the default; create `database/database.sqlite` if it does not exist.

### Xero

In the Xero developer portal, register **one** redirect URL used for both OpenID sign-in and accounting OAuth. It must match `.env` exactly (scheme, host, path):

- **`${APP_URL}/xero/callback`** — same value as `XERO_REDIRECT_URI` (see `.env.example`).

| Variable | Purpose |
|----------|---------|
| `XERO_CLIENT_ID` / `XERO_CLIENT_SECRET` | Shared by SSO and Connect Xero |
| `XERO_REDIRECT_URI` | Callback for both flows |
| `XERO_ALLOW_OPEN_SIGNUP` | Default `false`: blocks self-serve sign-up unless the DB has no users yet or the user has a valid invitation. Set `true` only if you want a public, self-serve app. |
| `XERO_SSO_SCOPES` | OpenID (default `openid profile email`) |
| `XERO_SCOPES` | Accounting API scopes for sync (`offline_access`, transactions, contacts, settings, etc.) |

### Mail

Invitations are sent from the dashboard. Configure `MAIL_*` in `.env`. For local dev, `MAIL_MAILER=log` writes messages to the log.

## Running locally

| Command | Use |
|---------|-----|
| `composer run dev` | HTTP server, queue worker, log tail, and Vite (recommended) |
| `php artisan serve` | App only |
| `npm run dev` | Vite watch mode |

Use `QUEUE_CONNECTION=database` (default in `.env.example`) so sync jobs can run; `composer run dev` includes `queue:listen`.

## Using the app

1. Open `/` or go to **`/login`** and **Sign in with Xero**.
2. On the **Dashboard**, organisation **admins** use **Connect Xero** to authorize tenants. **Reviewers** see orgs they belong to; the Connect button appears when they admin at least one org or have no orgs yet.
3. **Admins** invite others by email with role **Reviewer** or **Admin**. Invitees use the link and sign in with Xero using the **invited email**.
4. Open **Review expenses** for an organisation (`/orgs/{public_id}/expenses`). Route keys use an opaque **`public_id`** (ULID), not the internal numeric id.

## Syncing data

- Sync is triggered from the app (e.g. after connection / review flows) and runs via queued jobs where configured.
- **`SyncXeroOrganisationJob`** always includes an **initiating user id**; `handle()` verifies that user is still a **member** of the organisation before calling Xero.
- **CLI:** `php artisan xero:sync {public_id-or-slug}` — pass **`--acting-user={id}`** with a user who belongs to that org. Optional: `--from=YYYY-MM-DD`, `--to=YYYY-MM-DD`, `--queue` to dispatch instead of running inline.

## Production notes

- Set **`APP_DEBUG=false`**, **`APP_URL`** to your real HTTPS origin, and **`SESSION_SECURE_COOKIE=true`** behind TLS.
- If the app sits behind a reverse proxy or load balancer, configure **trusted proxies** so Laravel generates correct HTTPS URLs and secure cookies.
- Keep **`XERO_ALLOW_OPEN_SIGNUP=false`** unless you intentionally allow anyone to create an account via Xero.

## Optional: seeded test user

Set `SEED_TEST_USER=true` in `.env`, then `php artisan db:seed`. This creates `test@example.com` (password `password`) for **local/testing** only. Normal browser sign-in still uses Xero unless you authenticate in tests with `actingAs`.

## Tests

```bash
composer run test
```

## Health check

Laravel exposes **`GET /up`** for load balancers and uptime monitors.

## License

This project is open source under the [MIT license](LICENSE). [Laravel](https://laravel.com/) is [MIT licensed](https://opensource.org/licenses/MIT) as well.
