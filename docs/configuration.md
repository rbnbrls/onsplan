# Configuration

onsplan is configured entirely through environment variables in `.env`. Start from `.env.example` (`cp .env.example .env`) and fill in what you need. This page documents the variables that matter for running the app; the defaults in `.env.example` are enough to boot a local instance on SQLite.

> **Secrets never live in the repo.** `.env` and all credential files are git-ignored. In production, set these as your platform's secret env vars (for example, encrypted App Platform variables or Docker secrets), not in a committed file.

## Application

| Variable | Default | Notes |
|---|---|---|
| `APP_NAME` | `onsplan` | Shown in the UI and emails. |
| `APP_ENV` | `local` | `production` in production. |
| `APP_KEY` | none | **Required.** Generate with `php artisan key:generate`. |
| `APP_DEBUG` | `true` | Set to `false` in production. |
| `APP_URL` | `http://localhost` | The public URL the app is served from. |

## Database

Local development defaults to SQLite (no server needed). Production uses PostgreSQL.

| Variable | Notes |
|---|---|
| `DB_CONNECTION` | `sqlite` locally, `pgsql` in production. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL connection (ignored for SQLite). |
| `DB_SSLMODE` | e.g. `require` for managed Postgres. |

For SQLite, create the file once: `touch database/database.sqlite`.

## Sessions, cache, and queue

| Variable | Default | Notes |
|---|---|---|
| `SESSION_DRIVER` | `database` | |
| `CACHE_STORE` | `database` | Use `redis` in production for speed. |
| `QUEUE_CONNECTION` | `database` | Background jobs; `redis` recommended in production. |

The `composer dev` script runs a queue worker for you locally. In production, run `php artisan queue:work` (or the queue container in `docker-compose.yml`).

## Realtime (Laravel Reverb)

Live updates between family members use WebSockets via Laravel Reverb.

| Variable | Notes |
|---|---|
| `BROADCAST_CONNECTION` | `log` disables realtime (fine for local); `reverb` enables it. |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Reverb credentials. `APP_KEY` is a public client identifier; the secret is not. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Where the Reverb server is reachable. |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | The `VITE_` copies are bundled into the client, so they must be the **public** values only. |

Realtime is optional. With `BROADCAST_CONNECTION=log` the app works fully; members just refresh to see each other's changes.

## File storage (receipts and uploads)

Receipt photos and other uploads go to an S3-compatible bucket.

| Variable | Notes |
|---|---|
| `FILESYSTEM_DISK` | `local` for on-disk storage; `s3` for a bucket. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Bucket credentials (secret). |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Bucket region and name. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` for some providers. |

Any S3-compatible provider works (AWS S3, Cloudflare R2, MinIO, etc.). For local development, `FILESYSTEM_DISK=local` needs no credentials.

## Mail

Standard Laravel mail settings (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`). Locally, `MAIL_MAILER=log` writes emails to the log instead of sending them.

## AI receipt scanning (optional)

| Variable | Notes |
|---|---|
| `GEMINI_API_KEY` | A Google Gemini API key. When set, the **Scan receipt** action appears on spends and reads a photo into pre-filled fields (amount, currency, date, category, shop, note). Leave blank to disable the feature entirely (no button, endpoint 404s). |
| `GEMINI_MODEL` | Optional override. Defaults to `gemini-3-flash-preview`, which reads messy, rotated, multilingual receipts reliably at roughly $0.001 per scan. |

Get a free key at [aistudio.google.com/apikey](https://aistudio.google.com/apikey). Receipts are personal financial data, so use a paid-tier key in production (Google's free tier may use submitted data for training).

## AI categorisation (optional)

| Variable | Notes |
|---|---|
| `TYPESAFE_API_KEY` | A [TypeSafe](https://typesafe.ai) API key (Jev). When set: any shopping item saved into the catch-all **Other** aisle is sent (name, quantity, note) right after the response and moved to the best-fitting aisle of that shop; every expense saved into the catch-all **Other** category gets the same treatment (note, amount, list); and Spending settings gains a **Sort "Other" with AI** tool that walks every expense still in "Other" with a progress bar for the backlog. Leave blank to disable both. |
| `TYPESAFE_MODEL` | Optional override. Defaults to `jev-latest`. |

Only records that sit in "Other" are ever sent or changed, so a manual choice always wins and the spending tool is safe to re-run.

## After changing configuration

In production, clear the cached config so changes take effect:

```bash
php artisan config:clear   # or: php artisan config:cache
```
