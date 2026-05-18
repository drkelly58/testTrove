<h1><img src="public/favicon.svg" alt="" height="36" /> TestTrove</h1>

TestTrove is a **test case management** application: organize projects, suites, sections, and cases with steps and variants; execute **test runs** with per-step results; and collaborate with **role-based access**. The backend is a **PHP JSON API** designed to run on typical **shared hosting** (Apache + `mod_rewrite`). The UI is a **Vue 3** single-page app served from `/app/` after a production build.

---

## Features

- **Workspace model**: Projects contain test suites; suites contain sections and test cases.
- **Rich test cases**: Title, preconditions, priority, workflow status (`draft` / `ready` / `deprecated`), ordered steps with optional **variants** (extra criteria labels).
- **Version history**: Case versions with restore.
- **Bulk operations**: e.g. bulk status updates; drag-and-drop reordering for steps (via UI).
- **Test runs**: Create runs for a suite or section; record results per case/step (including severity and screenshots where supported).
- **Import / export**: Workspace and per-suite exchange via **JSON** and **CSV** (`.xlsx` is reserved and returns HTTP 501 until implemented).
- **Authentication (optional)**:
  - **OAuth2**: Microsoft Entra ID (Azure AD), Google, GitHub, or a **generic OIDC** provider.
  - **Local email + password** (`AUTH_LOCAL_ENABLED`), with optional one-time bootstrap admin user.
- **Authorization**: Global roles (`admin` / `user`) and per-project roles (`member`, `tester`, `viewer`). Admins get full access; other users are scoped via `project_members`.
- **User administration**: Global admins can manage users (`/admin/users` in the SPA).
- **Email notifications (optional)**: When outbound mail is configured on the server, signed-in users can opt in via **Preferences** for assignment and run-completion emails (see [Email notifications](#email-notifications)).
- **Developer convenience**: When auth is disabled, the SPA can simulate RBAC using URL query parameters (see [Developer notes](#developer-notes)).

---

## Stack

| Layer | Technology |
|--------|------------|
| API | PHP **8.1+**, [Slim 4](https://www.slimframework.com/), PSR-7 |
| Auth | PHP sessions + [League OAuth2 Client](https://oauth2-client.thephpleague.com/) (Azure, Google, GitHub, generic) |
| Database | **SQLite** (default), **MySQL/MariaDB**, or **PostgreSQL** via PDO |
| Frontend | **Vue 3**, **Vue Router**, **Vite 6**, **TypeScript**, **vuedraggable** |

Composer package: `testtrove/app`. Frontend package name: `testtrove-frontend`.

---

## Requirements

### Runtime

- **PHP** `^8.1` with extensions:
  - Required for HTTP stack: typical CLI/web SAPI setup (see Composer `require`).
  - Database: one of `pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql` matching `DB_DRIVER`.
- **Composer** (PHP dependencies).
- **Node.js** + **npm** (frontend dev server and production build).

### Web server (production)

- **Apache** with `mod_rewrite` (see `public/.htaccess`) is the primary deployment target.
- PHP must be able to write:
  - SQLite file directory (if using SQLite), including WAL sidecar files.
  - `storage/sessions/` for PHP session files when authentication is enabled.

### Reverse proxies

- Set **`APP_BASE_URL`** to your public URL (scheme + host, no trailing slash) so OAuth `redirect_uri` values are correct.
- For HTTPS termination at a proxy, ensure `X-Forwarded-Proto: https` is passed through so session cookies get the `Secure` flag when appropriate (`SessionMiddleware`).

---

## Repository layout

```
├── composer.json          # PHP dependencies & autoload (App\ → src/)
├── database/              # schema.sqlite.sql, schema.mysql.sql, schema.pgsql.sql
├── frontend/              # Vue + Vite app (base path /app/)
├── public/                # Web root: index.php, .htaccess, built SPA → public/app/
├── scripts/               # e.g. smoke_patch_entities.php
├── src/                   # PHP: controllers, middleware, services
└── storage/               # SQLite DB (optional path), session files — must be writable
```

---

## Quick start (local)

### 1. Backend

```bash
cd /path/to/TestTrove
composer install
cp .env.example .env
```

Edit `.env`:

- Set **`DB_PATH`** to an absolute path for SQLite (recommended), or rely on default `storage/app.sqlite` under the project root.
- Set **`CORS_ORIGIN`** to your SPA origin (e.g. `http://localhost:5173` for Vite dev).

Ensure the SQLite directory is writable by the user running PHP.

On first request (or when `index.php` runs), the app runs **schema application / additive migrations** automatically via `Database::migrate()` — there is no separate migration CLI.

Optional: set **`APP_SEED=1`** once to insert a small demo project when `projects` is empty.

### 2. Frontend (development)

```bash
cd frontend
npm install
npm run dev
```

Vite proxies **`/api/*`** to **`http://127.0.0.1`** by default (**port 80** — usual Apache / local stack). Use **`php -S`** instead? Point the proxy there:

```bash
VITE_API_PROXY_TARGET=http://127.0.0.1:8080 npm run dev
```

You can also set **`frontend/.env.development`** (`VITE_API_PROXY_TARGET`); copy **`frontend/.env.development.example`** as a starter.

Open **`http://localhost:5173/app/`** (same path prefix as production; plain **`http://localhost:5173/`** is not guaranteed to resolve the SPA with this `base`).

If you see **`/app/api-unavailable`**, **`/api/auth/session`** isn’t usable JSON—the proxy target may only be serving SPA HTML (**wrong port / wrong vhost**), or SQLite bootstrap failed (**`storage/`** not writable when running **`php -S`**). See **`php -S`** above and **`VITE_API_PROXY_TARGET`**.

### 3. PHP built-in server (optional)

For a quick API + static check without Apache:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

Then point **`VITE_API_PROXY_TARGET`** at that origin.

### 4. Production frontend build

```bash
cd frontend
npm ci   # or npm install
npm run build
```

This emits assets to **`public/app/`** (`emptyOutDir: true`). See [Deployment](#deployment).

---

## Configuration highlights

| Variable | Purpose |
|----------|---------|
| `APP_ENV` | Conventional environment label |
| `APP_DEBUG` | When truthy, Slim may expose more error detail (JSON renderer for API errors) |
| `CORS_ORIGIN` | Allowed browser origin for credentialed API calls |
| `DB_DRIVER` | `sqlite`, `mysql`, or `pgsql` (aliases supported — see `.env.example`) |
| `DB_PATH` | SQLite file path (absolute or relative to project root) |
| `APP_BASE_URL` | Public URL for OAuth redirects |
| `AUTH_*` / `OAUTH_*` | Local login and OAuth client credentials — see `.env.example` |
| `MAIL_*` | Optional outbound mail for run notification emails — see [Email notifications](#email-notifications) and `.env.example` |

When **any** OAuth client id or **`AUTH_LOCAL_ENABLED`** is set, **`RequireAuthMiddleware`** treats the API as authenticated: `/api/auth/*` and `/api/health` stay public; other `/api/*` routes expect a valid session.

### Email notifications

Run notification email is **off by default**. The server must have outbound mail configured; users then opt in per account in the SPA (**Preferences** → **Email notifications**). The section appears only when you are signed in and the instance reports mail as available (`email_notifications_available` on `/api/auth/session`).

**Enable on the server** (in `.env` on each environment — not in `scripts/deploy.env`):

1. Set **`MAIL_ENABLED=1`** (or `true` / `yes`).
2. Set a valid **`MAIL_FROM`** (e.g. `TestTrove <noreply@example.com>`) or **`MAIL_FROM_ADDRESS`** + **`MAIL_FROM_NAME`**.
3. Choose transport:
   - **`MAIL_TRANSPORT=php`** — PHP `mail()` (host must allow outbound mail from PHP).
   - **`MAIL_TRANSPORT=smtp`** — set **`MAIL_SMTP_HOST`**, **`MAIL_SMTP_PORT`**, and optionally **`MAIL_SMTP_USER`**, **`MAIL_SMTP_PASSWORD`**, **`MAIL_SMTP_ENCRYPTION`** (`tls` or `ssl`).

Set **`APP_BASE_URL`** to your public URL (scheme + host, no trailing slash) so links in emails match your deployment (same requirement as OAuth). In production this should always be set; when it is missing, invite emails sent from the admin UI can still include a sign-in link derived from the browser request.

**New-user invite emails** (when mail is enabled): global admins can create users with **Send invite email**; the dialog includes an editable intro paragraph (credentials and sign-in link are appended automatically). Override the instance defaults with optional **`MAIL_INVITE_SUBJECT`** and **`MAIL_INVITE_INTRO`** in `.env` (both support `{display_name}`).

**What users can opt into** (stored in `users.preferences`):

| Preference | When mail is sent |
|------------|-------------------|
| When I'm assigned a test run | Someone assigns you a run (not self-assignment). |
| When a run I created … is completed | A run you created, assigned to someone else, reaches auto-complete. |

Copy the commented block from **`.env.example`** (`# --- Email notifications ---`) as a starting point. For staging/production, configure mail in each server’s **`.env`** manually (deploy scripts do not upload or overwrite `.env`).

---

## Deployment

Deploy **`public/`** as the web-exposed directory (never the repository root). Run **`npm run build`** in `frontend/` before release so `public/app/` exists. After deploy, confirm the release via **Preferences** (footer): git tag on `HEAD`, or short SHA and build time.

### Self Hosted

1. **Document root**: Point the vhost at **`public/`**.
2. **Apache**: Keep **`public/.htaccess`** enabled (`AllowOverride` / `mod_rewrite`). It routes **`/api/*`** to **`index.php`**, redirects **`/`** → **`/app/`**, and SPA fallback for **`/app/*`**.
3. **Build the SPA** before going live (`npm run build` from `frontend/`).
4. **Permissions**: **`storage/`** (and the SQLite path if it lives outside **`storage/`**) must be writable; **`storage/sessions/`** when users sign in.
5. **HTTPS**: Use TLS in production so session cookies behave correctly behind proxies (see `SessionMiddleware`).
6. **Caching**: **`.htaccess`** sets **`Cache-Control`** for API and SPA shell to reduce stale JSON/HTML; hashed assets under **`/app/assets/`** are cached long-term.

**Self-hosted nginx / PHP-FPM**: The steps above assume Apache + **`.htaccess`**. On nginx, reproduce the same routing: send **`/api/*`** to **`public/index.php`**, serve static files under **`public/app/`**, and use **`public/app/index.html`** as the SPA fallback for **`/app/*`**. Terminate TLS at the proxy and forward **`X-Forwarded-Proto: https`** when appropriate so session cookies get **`Secure`**.

### Shared Hosting

Typical **cPanel / Plesk / budget Apache** plans—often no root shell and no Node on the server.

1. **`public_html` web root**: Many hosts only serve from **`public_html`** (sometimes under **`domains/<your-domain>/`**). Treat that folder like this repo’s **`public/`**: upload **`index.php`**, **`.htaccess`**, and the built **`app/`** tree into **`public_html`** (same layout as **`public/`** after **`npm run build`**). **`index.php`** loads code via **`dirname(__DIR__)`**, so the **application root is the parent of the web directory**: keep **`vendor/`**, **`src/`**, **`database/`**, **`.env`**, **`storage/`**, and **`composer.json`** **beside** **`public_html`** (not inside it)—for example **`~/vendor`**, **`~/storage`**, **`~/public_html/index.php`** on a typical primary-domain layout. If that pollutes your home directory, prefer an **addon domain** or **custom document root** pointing at **`…/your-project/public`** inside a single project folder instead.
2. **Composer & build**: Run **`npm run build`** so **`app/`** exists under **`public_html`**. Install PHP deps with **`composer install --no-dev`** (locally or via SSH) and upload **`vendor/`** next to **`public_html`** as in step 1—along with **`src/`**, **`database/`**, **`.env`**, **`storage/`**, etc.
3. **PHP in the panel**: Pick **PHP 8.1+** and enable the PDO extension for **`DB_DRIVER`** (`pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`).
4. **Database**: Prefer the provider’s **MySQL** when **`storage/`** is not reliably writable for SQLite; set **`DB_DRIVER=mysql`** and the credentials from the host.
5. **OAuth / URLs**: Set **`APP_BASE_URL`** to your live **`https://…`** URL so redirect URIs match what you registered at Microsoft/Google/GitHub/your IdP.
6. **Email notifications (optional)**: On the server **`.env`**, set **`MAIL_*`** as in [Email notifications](#email-notifications). Use your host’s SMTP relay or PHP `mail()` if allowed. Users opt in from **Preferences** after signing in.
7. **Provider quirks**: Some hosts disable **`AllowOverride`** or strip **`Cache-Control`**—if APIs look cached or **`/api`** 404s, open a ticket or adjust “Apache handlers” / nginx cache settings per their docs.
8. **Subdirectory installs**: If the site is not at the domain root (e.g. **`https://example.com/testtrove/`**), you may need **`RewriteBase`** in **`public/.htaccess`** (see [Troubleshooting](#troubleshooting)).

---

## API overview

- **Health**: `GET /api/health` → `{ "ok": true }`
- **Auth**: session, login/logout, OAuth callbacks under `/api/auth/...`
- **CRUD**: projects, suites, sections, cases, steps, runs, users, project members
- **Exchange**: workspace export/import, CSV preview, suite case export/import

For the full route map, see **`public/index.php`** (Slim route definitions).

---

## Developer notes

### Auto-migrations

Schema is applied from `database/schema.<driver>.sql` plus PHP-driven additive migrations inside `Database::migrate()`. Editing production data safely still requires backups and operational care; the app does not ship a separate migration runner.

### SPA base path

The frontend assumes deployment under **`/app/`**. Changing that requires updating Vite `base`, router history base, and Apache rewrite rules consistently.

### Auth disabled: dev RBAC simulation

When no OAuth/local auth is configured, you can append query parameters so the SPA sends dev permission hints on every `/api/*` request, for example:

- `http://localhost:5173/app/?role=tester&projects=1,2,3`
- `http://localhost:5173/app/?role=admin`

Clear with `?role=off` or the in-app banner control (see `DevPermissionMiddleware` and frontend `devPermissions`).

### Smoke script

`scripts/smoke_patch_entities.php` exercises PATCH flows against a SQLite DB when `DB_PATH` is set — useful for quick API regression checks.

### XLSX

Import/export with `format=xlsx` is intentionally **not implemented**; the API returns **501** with a message pointing to CSV/JSON (`App\IO\XlsxImportExportStub`).

---

## Troubleshooting

| Issue | Things to check |
|-------|------------------|
| SQLite “not writable” | Directory ownership/permissions for `DB_PATH`; web server user must create/update `.sqlite` and WAL files |
| OAuth redirect mismatch | `APP_BASE_URL` matches registered redirect URIs (`{APP_BASE_URL}/api/auth/callback/{provider}`) |
| CORS / cookie login | `CORS_ORIGIN` matches the SPA origin exactly; requests use credentials (`fetch` with `credentials: 'include'` in `frontend/src/api.ts`) |
| SPA blank/black in **`npm run dev`** | Proxy default **`http://127.0.0.1`** (Apache :80): bad target if `/api` returns HTML—set **`VITE_API_PROXY_TARGET`**. **`php -S`** on **8080** needs **`http://127.0.0.1:8080`**. **`php -S`** as your user + **`storage/`** owned by **`www-data`** → SQLite errors—**`chown`/`chmod`** **`storage/`**. Visit **`http://localhost:5173/app/`**. |
| Blank **`/app/`** after deploy | Run **`npm run build`**; confirm **`public/app/index.html`** exists; Apache/nginx must route **`/api/*`** to **`index.php`**, not the SPA fallback |
| API 404 on Apache | `mod_rewrite`, `AllowOverride`, and `RewriteBase` if the app lives in a subdirectory |
| No **Email notifications** in Preferences | Sign in; set **`MAIL_ENABLED=1`**, valid **`MAIL_FROM_*`**, and **`MAIL_TRANSPORT`** + SMTP host or PHP mail on the server **`.env`**; restart PHP / reload config; confirm `/api/auth/session` includes `"email_notifications_available": true` |

---

## Contributing

There is no bundled automated test suite in the repository root; use the smoke script and manual QA through the SPA for changes. Keep `.env` out of version control (see `.gitignore`).
