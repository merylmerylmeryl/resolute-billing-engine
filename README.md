# Lone Oak Billing Engine

A small Laravel app that bills property tax protest clients. Each fee is a share of the tax savings on a property, and billing can be run again safely at any time.

Requires PHP 8.5 with the `bcmath` extension, and Composer. The database is SQLite.

Design choices are in [docs/DECISIONS.md](docs/DECISIONS.md). The spec is [docs/case-study.md](docs/case-study.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
```

`migrate:fresh --seed` must come before the first import. The seeder loads the jurisdiction rates and homestead exemptions that the import and billing need.

Then serve the app with Herd, or `php artisan serve`.

## Commands

```bash
php artisan billing:import database/data/sample.json   # load clients, properties, events
php artisan billing:run 2026-07-01                     # bill as of a date (Y-m-d)
```

- `billing:run` bills every property whose latest outcome is final and not yet billed. Run it again with a later date and it issues adjustments for revised outcomes. Run it again with nothing new and it creates nothing.
- `billing:import` can be repeated. It updates existing records and skips events it has already loaded. Bad rows are skipped and reported, valid rows still load, and the command exits non-zero.

The sample data should give: Run 1 (`2026-07-01`) three invoices (212.88, 2,569.38, 0.00), then Run 2 (`2026-09-01`) one adjustment for C1 (+53.22).

## Loading a different file

Pass any path to the import command:

```bash
php artisan billing:import /path/to/other-data.json
```

The file needs the same top-level keys and shape as `database/data/sample.json`. An unreadable file or a missing top-level key aborts the import.

## Pages

- `/clients`: client list
- `/clients/C1`: invoices, adjustments and how each line was calculated
- `/events/create`: record a new outcome event, then run billing

## Tests

```bash
php artisan test
```

Tests use an in-memory SQLite database, so they never touch `database/database.sqlite`.
