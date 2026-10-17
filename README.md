# onsplan

**The family organizer for the people you live with.** Shared shopping lists, to-dos, a family calendar, spending, projects, recipes, the car and more, in one installable app. A small, stable core with self-contained **modules** you switch on per family, so each household only sees what it uses.

Self-host it for your own family, or use the hosted version at **[onsplan.nl](https://onsplan.nl)** (there's a one-click live demo).

[![License: PolyForm Noncommercial 1.0.0](https://img.shields.io/badge/license-PolyForm%20Noncommercial-blue.svg)](LICENSE)

<p align="center">
  <img src="docs/screenshots/phone-dashboard.png" width="200" alt="Home screen: this month's spending, what's happening now and next, birthdays, and today's to-dos">
  <img src="docs/screenshots/phone-shopping.png" width="200" alt="A shared grocery list grouped by aisle">
  <img src="docs/screenshots/phone-project.png" width="200" alt="A family project with progress and tasks">
  <img src="docs/screenshots/phone-recipe.png" width="200" alt="A recipe with a servings calculator">
</p>

<p align="center">
  <img src="docs/screenshots/phone-spending.png" width="200" alt="Spending: this month's total and the latest expenses">
  <img src="docs/screenshots/phone-vehicle.png" width="200" alt="A family car with maintenance due, mileage, fuel, tyres and documents">
</p>

<p align="center">
  <img src="docs/screenshots/desktop-calendar-month.png" width="820" alt="Family calendar, month view, with a trip, recurring swim practice and to-do due dates">
</p>

> **Source-available, not open source.** onsplan is free for **personal, noncommercial use**: run it for your own family, change it, learn from it. Commercial use and reselling are not allowed. See [License](#license).

---

## What you get

- **Families, not just accounts.** Invite the people you live with; everyone shares the same data, with roles (admin / member).
- **Modules you switch on per family** (Settings → Modules). Bundled:
  - **[Shopping](modules/shopping)**: several shared lists, grouped by aisle per shop, a "shopping mode", voice and paste input.
  - **[Tasks](modules/tasks)**: shared to-do lists with sub-tasks, due dates and assignees, plus [important](modules/important_todos) and [recurring](modules/recurring_todos) to-dos.
  - **[Calendar](modules/calendar)**: month and timeline views, repeating events, people and to-dos on events. Task due dates, birthdays, car due dates and project starts show up on their own. Includes a private subscribe link for Google or Apple Calendar.
  - **[Spending](modules/spending)**: quick-add expenses, categories, shared trackers with automatic "who owes whom", multi-currency, receipt photos and optional AI receipt scanning.
  - **[Projects](modules/projects)**: the bigger things a family works on, with tasks, progress and linked shopping lists.
  - **[Recipes](modules/recipes)**: family recipes with a calculator (scale by servings, total amount or what you have) and add-to-shopping.
  - **[Notes](modules/notes)**, **[Contacts](modules/contacts)**, **[Birthdays](modules/birthdays)**, **[Inventory](modules/inventory)** (what's in the house and what's running low) and **[Vehicles](modules/vehicles)** (mileage, maintenance, documents).
- **A home screen that brings it together**: this month's spending, what's on now and next, birthdays, and what's due today and this week.
- **Granular sharing**: anything shareable can be *family-wide*, *invited-only* or *private*, with per-person roles and read-only public links.
- **Works with AI assistants**: a built-in [MCP server](docs/mcp.md) lets Claude, ChatGPT and other MCP clients read and update your family's lists, calendar and more, with per-module permissions you pick when you connect. Interactive widgets render right in the chat.
- **Realtime and installable**: changes appear live for everyone (Laravel Reverb), and it installs to your home screen as a PWA.
- **Two languages so far**: English and Romanian, per person.

<p align="center">
  <img src="docs/screenshots/desktop-calendar-timeline.png" width="820" alt="Family calendar, timeline view">
</p>

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.2+, Laravel 12, Laravel Octane (FrankenPHP) |
| Frontend | Inertia.js 2, React 19, TypeScript, Tailwind CSS v4 |
| Realtime | Laravel Reverb (WebSockets) |
| Database | PostgreSQL (production) · SQLite (local, zero-config) |
| Storage | Any S3-compatible bucket (Cloudflare R2 in the cloud build) |
| Build | Vite |

One Docker image runs the same way self-hosted and in the cloud.

---

## Quick start (local development)

**Prerequisites:** PHP 8.2+, [Composer](https://getcomposer.org), Node 22+, and npm. No database server needed, local defaults to SQLite.

```bash
git clone https://github.com/bvdr/onsplan.git
cd onsplan

composer install
npm install

cp .env.example .env
php artisan key:generate
touch database/database.sqlite

php artisan migrate          # core tables
php artisan modules:migrate  # module tables

composer dev                 # serves the app + queue + logs + Vite together
```

Open http://localhost:8000, register an account (the first user creates a family), then enable a module from **Settings → Modules**.

`composer dev` runs the PHP server, the queue worker, the log tailer, and the Vite dev server concurrently. Run `npm run build` for a production asset build.

---

## Self-hosting

The whole app is a single image with a `docker compose` stack (app + Reverb + Postgres + Redis):

```bash
cp .env.example .env
php artisan key:generate            # or generate an APP_KEY and paste it into .env
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan modules:migrate
```

A prebuilt image is published at `ghcr.io/bvdr/onsplan`. See **[docs/self-hosting.md](docs/self-hosting.md)** for the full guide, including storage and Reverb configuration.

### Public demo account (optional)

A "Try the live demo" button appears on the login page once a demo family exists. Visitors are signed
in as its owner with one click (`/demo`), can change anything, and the family is wiped and re-seeded
at the top of every hour by the scheduler (`demo:reset`).

```bash
php artisan db:seed --class=DemoSeeder --force   # once: creates the demo family (flagged is_demo)
php artisan demo:reset                           # any time: wipe + re-seed it (what the scheduler runs)
```

The reset locates the family **only** by the `families.is_demo` flag and refuses to run unless exactly
one family carries it, so it can never touch a real family.

---

## Configuration

Everything is configured through environment variables in `.env`. See **[docs/configuration.md](docs/configuration.md)** for the complete reference. Highlights:

### AI receipt scanning (optional)

When a Google Gemini API key is present, spends gain a **Scan receipt** action: snap a photo and the amount, currency, date, category, shop, and a note are read from it and pre-filled for you to review and save. It costs roughly **$0.001 per scan** and reads rotated, low-quality, multilingual receipts.

The feature is **entirely gated on the key**, without it there is no button and the endpoint returns 404. To enable it, get a free key at [aistudio.google.com/apikey](https://aistudio.google.com/apikey) and set:

```dotenv
GEMINI_API_KEY=your-key-here
```

### AI categorisation (optional)

With a [TypeSafe](https://typesafe.ai) key, shopping items added into the catch-all **Other** aisle are sorted into the shop's best-fit aisle (Dairy, Produce, Plumbing, ...) right after they're saved, expenses saved into "Other" get a category the same way, and Spending settings has a one-tap **Sort "Other" with AI** for the backlog, with a progress bar:

```dotenv
TYPESAFE_API_KEY=your-key-here
```

No secrets are ever committed; `.env` and every credential file are git-ignored.

---

## Modules

onsplan's features live in `modules/`. Each module is a self-contained folder with its own routes, controllers, models, migrations, and (optionally) a frontend bundle, registered through a service provider and a `module.json` manifest. Every feature listed above is a module; `modules/important_todos` is a small example to start from.

To learn how the module system works and how to build your own, see **[docs/modules.md](docs/modules.md)**.

## Project structure

```
app/            Core Laravel application (families, auth, sharing, modules)
modules/        Self-contained feature modules (shopping, tasks, calendar, spending, ...)
resources/js/   React + Inertia frontend (pages, components, layouts)
routes/         Core web routes
docs/           Documentation and design notes
.do/            DigitalOcean App Platform deploy config (non-secret)
```

## Contributing

Contributions are welcome: bug fixes, new modules, translations, docs. Start with **[CONTRIBUTING.md](CONTRIBUTING.md)** for the workflow, conventions, and the terms your contribution is accepted under.

## License

onsplan is licensed under the **[PolyForm Noncommercial License 1.0.0](LICENSE)**. It's source-available, not open source:

- **Allowed:** personal and family use, self-hosting for your own household, studying, modifying and sharing the code for noncommercial purposes. Noncommercial organisations (a charity, a school, a public research group) may use it too, as the license defines.
- **Not allowed:** any commercial use, including selling or reselling onsplan or a modified version, offering it as a paid or hosted service, or using it inside a business.

Any copy or modified version must keep the license and its `Required Notice` line. For commercial licensing, contact the maintainer through [onsplan.nl](https://onsplan.nl).
