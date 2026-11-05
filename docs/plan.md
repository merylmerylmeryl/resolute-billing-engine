# Lone Oak billing engine: build plan

## Context

Take-home: a small Laravel 13 billing engine built from a written spec. The repo holds a fresh Laravel app plus the docs. There are no domain migrations, models or tests yet.

The goal is to reproduce the spec's Run 1 and Run 2 exactly, then hold up against a larger hidden data set and a live feature-adding session.

All spec numbers were checked by hand with bcmath before any code was written (see the table in Step 3).

---

## A. Decisions

| # | Question | Decision |
|---|---|---|
| A1 | Multi-year data | Latest-event-wins, billed-or-not, the 25% tier and the $2,500 cap all apply per (property, year of `recorded_at`). No schema change: a line's season comes from its event. |
| A2 | What "enrolled" means | A property counts if it has at least one event in that season, recorded on or before the as-of date. |
| A3 | Which rate an adjustment uses | The adjustment reuses the `fee_rate` of the line it corrects, so the difference reflects only the outcome change. |
| A4 | A revision that doesn't change the fee | No adjustment. The $0 rule applies to regular invoices only. |
| A5 | What `adjusts_line_id` points at | Always the original line. The difference is measured against the latest line's fee. |

### A1 details
- **Known cost:** a 2026 case revised in January 2027 bills as a new 2027 fee instead of adjusting.
- **Real fix:** a season field from the case management system.
- **Alternative considered:** single-season only. Simpler, but any second-year event would produce a wrong adjustment.

### A2 consequences
- A property in the file with no events doesn't count.
- One client can have some lines at 30% and later ones at 25% in the same season.

### A3 example
Savings of $1,000 billed at 30% = $300, later revised to $1,100:
- Keep 30%: $330, so **+$30**
- Move to 25%: $275, so **-$25** credit even though savings went up

### A4 consequence
That event never lands on a line, so each run re-evaluates it and gets $0 again, unless a re-import has changed the data (see the exception in E). Still idempotent when there's no new information. Covered by a test.

### A5 example
$212.88 -> $266.10 (+$53.22) -> $240.00 must give **-$26.10**, not +$27.12.

---

## B. Schema changes

| Change | Why |
|---|---|
| `invoice_lines.event_id` unique | Database-level backstop against double billing: an event can be billed at most once. |
| `invoices` unique (`run_id`, `client_id`, `type`) | Enforces "one per client per run". One line per property per season on each invoice is enforced in code and tested, because a property with unbilled events in two seasons needs two lines on one invoice. |
| `exemption_amount` as `decimal(12,4)` | `12,2` fits 0.20 but would silently round a 0.125 exemption to 0.13. |
| Add to `breakdown` JSON: exact savings, fee before waiver/cap, a note (waived / capped / no_savings), and rate, exemption and taxable values per jurisdiction | `savings (12,2)` is rounded, so it can't always reproduce the fee. There's nowhere else to record "waived", and rates may change later. No new columns needed. |
| Foreign keys use `restrictOnDelete` | No cascade may ever remove issued lines. |

---

## C. Doc fixes

- Rename `docs/SCHEMA.md` to `docs/schema.md` to match CLAUDE.md (works on macOS, breaks on Linux).
- Add a `.claude/settings.json` hook that runs tests after edits, so DECISIONS.md is accurate.
- Rewrite DECISIONS.md at the end to describe only what actually exists, under one page.
- Add `ext-bcmath` to `composer.json`.
- Rewrite README.md with setup instructions (still the Laravel default).

---

## D. Traps found by testing them

- **SQLite has no true DECIMAL.** `decimal(12,2)` stores a REAL: 69.38 comes back as a PHP double, and summing 0.1 + 0.2 gives 0.30000000000000004.
  - Use decimal:2 casts only on stored money columns (values, savings, fee, amount, total). Rates and exemption_amount use decimal:4. Exact unrounded savings and taxes stay in the calculator and in the breakdown JSON as strings. Never calculate a fee from the stored, rounded savings column.
  - Never use SQL `SUM` / `withSum` on money
  - Total with `bcadd` in PHP, and keep JSON values as strings
- **Date boundary bug.** Laravel stores dates as `2026-07-01 00:00:00`, and SQLite says that is not `<= '2026-07-01'`. A plain `where` would drop events recorded exactly on the as-of date. Use `whereDate` and test the boundary.
- **bcmath truncates and defaults to 0 decimal places.** Always pass a scale. Round with `bcadd($x, '0.005', 2)`, or `bcsub` for negative values. Fees are never negative, but the `savings` snapshot can be, for example -1.843155 -> -1.84.
  - 9.995 -> 10.00 (not waived)
  - 9.994999 -> 9.99 (waived)
- **The sample data can't prove some rules.** P5 is waived at both 30% ($7.52) and 25% ($6.27), and no sample client reaches 3 properties with one pending. "Pending counts" needs its own test.
- **"Recorded last" means highest id**, because `recorded_at` is date-only. Import must keep file order and must not sort.

---

## E. Defaults

- A run that issues nothing creates no `runs` row, so it's truly "nothing new".
- A client with only pending properties gets no invoice. A $0 invoice needs at least one line.
- A client with both new and revised properties in one run gets one invoice and one adjustment. A mixed adjustment's total can be negative.
- A backdated event, or a run with an older as-of date, never un-bills anything. If the eligible event isn't newer than the last billed one by (`recorded_at`, `id`), skip it.
- **Import:** unreadable JSON or a missing top-level key aborts. A bad row (unknown property or jurisdiction, final with no value, pending with a value, invalid status or date, non-numeric value, missing field, or a property row that moves the property to a different client) is skipped and reported, and the command exits non-zero, so one bad row doesn't block the rest.
- A repeated `source_id` with different values: the first one wins, with a warning.
- Re-importing changed homestead or jurisdiction data affects future calculations only and triggers no adjustment, with one exception: a revision that didn't change the fee never landed on a line (A4), so the next run recalculates it with the new data and issues one adjustment if the fee now differs.
- **Form:** `recorded_at` can't be in the future, money has at most 2 decimals, and final value may exceed noticed (savings go negative, fee is $0). If noticed values differ across events, the latest event's value is used.
- No authentication, since the spec doesn't ask for it. Listed under "what I'd do next".

---

## Build steps

Each step:
1. Write failing Pest tests
2. Build
3. Run `php artisan test`
4. Run `vendor/bin/pint --dirty --format agent`
5. Run the `billing-reviewer` agent
6. Stop for review (commit only when approved)

If time runs short (the spec says stop at two hours), Steps 2-5 are the core. Keep Step 6 plain, and use Step 8 to note anything unfinished.

### Step 1: Fix the docs and settle decisions

Apply the decisions above to CLAUDE.md, `docs/schema.md` and `docs/DECISIONS.md`, so the rules are correct before any code depends on them.

### Step 2: Migrations, enums, models, factories, seeder

- **Migrations** (`make:model -mf`), in foreign key order: clients, properties, jurisdictions, property_jurisdictions, events, runs, invoices, invoice_lines (with a self-referencing `adjusts_line_id`).
- **Enums** in `app/Enums`: `ExemptionType`, `EventStatus`, `InvoiceType`.
- **Models:** relationships, `decimal:N` casts on all money, date casts, enum casts, `breakdown` as array, `$timestamps = false` except on `Run`.
  - Note: `App\Models\Event` shares its name with the `Event` facade, so watch the imports.
- **Immutability:** `Event`, `Invoice` and `InvoiceLine` throw on updating and deleting. To avoid ever updating an invoice, compute its lines and total in memory first, then create it with its total already set.
- **JurisdictionSeeder:** idempotent `updateOrCreate`, called from `DatabaseSeeder` (replaces the default user seeding).
- **Test setup:** enable `RefreshDatabase` for Feature tests in `tests/Pest.php`.

**Tests:** seeded rates and exemptions, relationships, unique constraints reject duplicates, and updating or deleting an issued line, invoice or event throws.

### Step 3: Fee calculator

`app/Billing/FeeCalculator.php` plus a readonly `FeeResult`. Pure: it takes values and jurisdiction data, with no queries, no floats and no `round()`.

- **Inputs:** noticed, final, homestead, jurisdictions, fee rate. Choosing the rate stays out of this class; it only exposes `rateFor(int $enrolledCount)`.
- **Order:** homestead exemption -> tax per jurisdiction -> sum -> savings -> % -> round half up -> $10 waiver -> $2,500 cap.
- **Precision:** one scale constant of 20. The longest result needs about 14 decimals, and dividing by 100 always terminates, so nothing is lost.

**Tests** in `tests/Unit/FeeCalculatorTest.php`, every spec number:

| Case | Rate | ISD | CNTY | CITY | Savings | Rounded fee | Final fee |
|---|---|---|---|---|---|---|---|
| P1 (Run 1) | 30% | 380.00 | 121.60 | 208.00 | 709.60 | 212.88 | 212.88 |
| P2 | 25% | 142.50 | 57.00 | 78.00 | 277.50 | 69.38 (from 69.375) | 69.38 |
| P3 | 25% | 7,600.00 | 3,040.00 | n/a | 10,640.00 | 2,660.00 | 2,500.00 (capped) |
| P4 | 25% | 0 | 0 | 0 | 0.00 | 0 | 0.00 |
| P5 | 30% | 19.00 | 6.08 | n/a | 25.08 | 7.52 | 0.00 (waived) |
| P1 (Run 2) | 30% | 475.00 | 152.00 | 260.00 | 887.00 | 266.10 | 266.10 |

*(ISD, CNTY and CITY columns show the savings per jurisdiction.)*

**Edge tests:**
- ISD floors at $0 (noticed and final both under $100,000)
- Negative savings -> $0
- Exactly $10.00 is kept; $9.99 is waived
- $9.995 rounds to $10.00 and is kept
- Exactly $2,500 and $2,500.01 -> $2,500
- Non-homestead ignores exemptions
- Savings with more than 2 decimals aren't rounded before the %
- `rateFor`: 2 -> 0.30, 3 -> 0.25

### Step 4: Import command

`billing:import {path}`, with logic in `app/Billing/DataImporter.php`. Sample data lives at `database/data/sample.json`, and the tests use the same file.

- Clients and properties: `updateOrCreate` on `external_id`. Check the client first: a property row that names a different client is skipped whole
- Jurisdictions: sync
- Events: create only if `source_id` is new
- Convert JSON numbers to strings here, the one place floats can enter
- Prints a summary: created, updated, duplicates skipped, rows rejected

**Tests:**
- Sample loads: 3 clients, 6 properties, 7 events
- Re-import creates nothing
- Changed property data updates
- A row that moves a property to a different client is reported as a bad row, and the property keeps its original client
- Same-date events keep file order
- Missing or malformed file fails cleanly
- Each bad-row type is reported and valid rows still load
- A different file path works

### Step 5: Billing command

`billing:run {as_of}` (strict `Y-m-d`, otherwise a clean error), with logic in `app/Billing/BillingRunner.php`, inside one DB transaction. Loads clients with `lazyById` and eager loading, to avoid N+1 on the larger data set.

```text
for each client, property and season (A1):
    latest = newest event <= as_of, by (recorded_at, id)
    pending or none                          -> skip
    previous = newest line for this property and season
    no previous                              -> regular line at the tier rate (amount = fee)
    latest not newer than previous's event   -> skip (idempotency, backdating)
    fee at previous.fee_rate (A3); diff = fee - previous.fee
    diff = 0                                 -> skip (A4)
    otherwise                                -> adjustment line: fee = new total, amount = diff,
                                                adjusts_line_id = original line (A5)

if anything was built: create the Run, then invoices with totals, then lines
```

**Tests** in `tests/Feature/BillingRunTest.php`:

- **Run 1 as of 2026-07-01:** exactly 3 invoices (C1 212.88, C2 2,569.38, C3 0.00), the 5 line values from the table, 25% for C2, 30% for C1 and C3, P6 not billed, E7 ignored.
- **Run 2 as of 2026-09-01:** exactly one new invoice, C1 type adjustment, amount +53.22, line fee 266.10, pointing at the original line. The original line and invoice are unchanged afterwards.
- **Re-run:** counts of runs, invoices and lines unchanged, including a re-run with an older as-of date.
- **Events:**
  - An event recorded exactly on the as-of date counts; the next day doesn't
  - Same-date events: highest id wins
  - Two unbilled final events in one season -> one line, for the latest
  - Pending -> final gets billed later
  - Final -> pending after billing keeps the bill, then adjusts once final again
  - A backdated event changes nothing
- **Tier:** 2 final + 1 pending -> 25%, plus a client reaching 3 later (A2, A3).
- **Adjustments:**
  - Credit (negative)
  - Second revision measured against the latest fee
  - $0 -> charge (waived, then above $10)
  - Unchanged fee -> nothing (A4); after a re-import that changes the fee -> one adjustment, then nothing
  - Several revisions -> one adjustment, one line each
  - Invoice and adjustment in the same run
- **Multi-year (A1):** a second-year final event is a new bill with its own tier count. A property with unbilled events in two seasons gets two lines on one invoice. The $2,500 cap applies in each season.

### Step 6: Blade pages

`ClientController` (index, show) with routes `clients.index` and `clients.show`, plus `/` redirecting to the list. Routes bind on `external_id` (`/clients/C1`). The layout uses a small inline `<style>` and no `@vite`, so reviewers don't need `npm run build`.

- **Client list:** ID, property count, invoice count, paginated. Totals are not summed in SQL.
- **Client page:** each invoice or adjustment with its as-of date and total. Each line shows property, source event, noticed and final values, savings, rate, calculated fee, final fee with its note, and amount. A per-jurisdiction table explains the tax. Adjustments link to the original invoice and show previous fee -> new fee. Properties not yet billed are listed too.

**Tests:** the pages render C1's 212.88 and +53.22, "waived" for P5, capped for P3, the link to the original invoice, and a 404 for an unknown client. Update `tests/Feature/ExampleTest.php` to expect the 302 redirect from `/` instead of a 200.

### Step 7: Event form

`EventController` (create, store) and `StoreEventRequest`. The form uses a property dropdown and always stores a null `source_id`.

**Validation rules:**
- Property exists
- Valid date, not in the future
- Status is an enum value
- Noticed value: required, non-negative, at most 2 decimals
- Final value: `required_if:status,final` and `prohibited_if:status,pending`, same numeric rules

**Tests:**
- Each rule rejects bad input and keeps the old values
- Valid pending and final events save
- Two form events can both have a null `source_id`
- End to end: submit a revision, run billing, get an adjustment

### Step 8: README and DECISIONS

Rewrite README.md with setup, the commands, and how to load a different file. Update DECISIONS.md with the choices above and the real test list, under one page.

---

## Verification

`php artisan test` passes in full. Run the narrowest set while working.

From a clean database, against the real SQLite file rather than the in-memory test DB:

```bash
php artisan migrate:fresh --seed
php artisan billing:import database/data/sample.json
php artisan billing:run 2026-07-01    # 3 invoices: 212.88 / 2,569.38 / 0.00
php artisan billing:run 2026-09-01    # 1 adjustment: C1 +53.22
php artisan billing:run 2026-09-01    # nothing new
```

Then:
- Browse the client list and `/clients/C1`, `C2` and `C3` via Herd.
- Use the form to submit an event, re-run billing, and confirm the charge can be explained from the page alone.
- Run a final `billing-reviewer` pass over the whole repo.
