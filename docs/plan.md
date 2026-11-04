Ready for review
Select text to add comments on the plan
Lone Oak billing engine: build plan
Context
Take-home: a small Laravel 13 billing engine built from a written spec. The repo holds a fresh Laravel app plus the docs. There are no domain migrations, models or tests yet. The goal is to reproduce the spec's Run 1 and Run 2 exactly, then hold up against a larger hidden data set and a live feature-adding session.

Project location: everything lives in ~/Herd/resolute-billing-engine. This session opened in ~/code/memberweb, an unrelated repo with no CLAUDE.md or docs/. Build from a session started in the billing project, so its CLAUDE.md, Boost MCP, skills and billing-reviewer agent load. None of those are available here.

Checked so far: all three docs, using a bcmath script that was not saved. All spec numbers reproduce (table in Step 3). No code has been written.

Flags
A. Decisions I need from you (each has a recommendation)
A1. Multi-year data: the rules contradict each other. This is the biggest gap. DECISIONS.md says season = the year the event was recorded. But the schema has no season, and CLAUDE.md says any newer final event on a billed property becomes an adjustment. So P1's 2027 outcome would "adjust" its 2026 bill instead of being a new bill.

Recommend: apply latest-event-wins, billed-or-not and the 25% tier per (property, year of recorded_at). This needs no schema change, because a line's season comes from its event.
Known cost: a 2026 case revised in January 2027 bills as a new 2027 fee instead of adjusting. Record that in DECISIONS.md and name the real fix: a season field from the case management system.
Alternative: declare the app single-season. That's simpler, but any second-year event produces a wrong adjustment.
A2. What "enrolled" means. The data has no enrollment record.

Recommend: a property counts if it has at least one event in that season, recorded on or before the as-of date. Replaying a past date then gives the same answer, and it matches your "rate set at billing time" decision.
Consequences to accept: a property in the file with no events doesn't count, and one client can have some lines at 30% and later ones at 25% in the same season.
A3. Which rate an adjustment uses. "The rate is decided at billing time" and "already-billed lines are not re-rated" conflict when a client crosses 3 properties between the original bill and a revision.

Example: savings of $1,000 billed at 30% = $300, later revised to $1,100. Keeping 30% gives $330 (+$30). Moving to 25% gives $275, a −$25 credit even though savings went up.
Recommend: the adjustment reuses the fee_rate of the line it corrects, so the difference reflects only the outcome change.
A4. A revision that doesn't change the fee. Examples: P3 stays capped at $2,500, or a waived fee stays waived. The spec says an adjustment is "a charge or a credit for the difference", and there's no difference here. But CLAUDE.md says $0 invoices are still issued.

Recommend: issue no adjustment. The $0 rule applies to regular invoices only.
Consequence: that event never lands on a line, so each run re-evaluates it and gets $0 again. That's harmless and stays idempotent, and a test will cover it.
A5. What adjusts_line_id points at after a second revision. The spec says "the original invoice", CLAUDE.md says "the line it corrects", and DECISIONS.md says "the original line it corrects".

Recommend: always point at the original line. It's one hop to the original invoice, and the previous fee can be derived as fee − amount.
Either way, the difference is measured against the latest line's fee: $212.88 → $266.10 (+$53.22) → $240.00 must give −$26.10, not +$27.12.
B. Schema changes to approve
Change	Why
invoice_lines.event_id unique	Database-level backstop against double billing: an event can be billed at most once.
invoices unique (run_id, client_id, type); invoice_lines unique (invoice_id, property_id)	Enforces "one per client per run" and "one line per property".
exemption_amount decimal(12,4)	12,2 fits 0.20 but would silently round a 0.125 exemption to 0.13.
Add to breakdown JSON: exact savings, calculated fee before waiver/cap, a note (waived/capped/no_savings), and rate, exemption and taxable values per jurisdiction	savings (12,2) is rounded, so it can't always reproduce the fee. The schema also has nowhere to record "waived", which the spec's table shows, and rates may change later. No new columns needed.
Foreign keys use restrictOnDelete	No cascade may ever remove issued lines.
C. Doc inconsistencies to fix
Filename case: CLAUDE.md links docs/schema.md, but the file is docs/SCHEMA.md. That works on macOS and breaks on Linux. Rename the file to match.
DECISIONS.md claims a hook runs tests after every change: the project has no .claude/settings*.json, so there's no project-level hook. I didn't check your user-level settings. Either add one or remove the claim, since you may be asked about it live.
DECISIONS.md is written in the past tense about tests that don't exist yet. Reconcile it in Step 8 and keep it to the one-page limit.
PHP version: composer.json allows ^8.3, but bcround() needs 8.4 and the project runs 8.5. Recommend the method in Step 3, which works on all of them. ext-bcmath also isn't declared; may I add it to composer.json?
README.md is still the Laravel default, and the spec requires setup instructions.
AGENTS.md still says to install Boost, which is already installed. No action needed, just don't follow it.
D. Traps confirmed by running them
SQLite has no true DECIMAL. decimal(12,2) stores a REAL: 69.38 comes back as a PHP double, and SUM of 0.1 + 0.2 gives 0.30000000000000004. So: use decimal:2 casts on every money attribute (these return strings), never use SQL SUM/withSum on money, and total with bcadd in PHP. Keep JSON values as strings.
Date boundary bug. Laravel stores date casts as 2026-07-01 00:00:00, and SQLite says that is not <= '2026-07-01'. A plain where would drop events recorded exactly on the as-of date. Use whereDate and test the boundary.
bcmath truncates and has a default scale of 0. Always pass a scale. Round with bcadd($x, '0.005', 2), which is correct because rounding only happens on positive values. Verified: 9.995 → 10.00 (not waived), 9.994999 → 9.99 (waived).
The sample data can't prove some rules. P5 is waived at both 30% ($7.52) and 25% ($6.27), and no sample client reaches 3 properties with a pending one. So "pending counts" needs its own test.
"Recorded last" means highest id, because recorded_at is date-only. Import must keep file order and must not sort.
E. Defaults I'll apply and record in DECISIONS.md unless you object
A run that issues nothing creates no runs row, so it's truly "nothing new".
A client with only pending properties gets no invoice. $0 invoices need at least one line.
A client with both new and revised properties in one run gets one invoice and one adjustment. A mixed adjustment's total can be negative.
A backdated event, or a run with an older as-of date, never un-bills anything. If the eligible event isn't newer than the last billed one by (recorded_at, id), skip it.
Import: unreadable JSON or a missing top-level key aborts. A bad row (unknown property or jurisdiction, final with no value, pending with a value) is skipped and reported, and the command exits non-zero. So one bad row in the hidden data doesn't block the rest.
A repeated source_id with different values: the first one wins, with a warning.
Re-importing changed homestead or jurisdiction data affects future calculations only and triggers no adjustment.
Form: recorded_at can't be in the future, money has at most 2 decimals, and final value may exceed noticed (savings go negative, fee is $0). If noticed values differ across events, the latest event's value is used.
No authentication, since the spec doesn't ask for it. Listed under "what I'd do next".
Build steps
Each step: write failing Pest tests → build → php artisan test → vendor/bin/pint --dirty --format agent → billing-reviewer → stop for your review. I commit only when you say so. The repo's history is on main, so I'd continue there unless you want a branch.

If time runs short (the spec says stop at two hours), Steps 2–5 are the core. Keep Step 6 plain, and use Step 8 to note anything unfinished.

Step 1: Fix the docs and settle flags
Apply your answers to A–C in CLAUDE.md, docs/SCHEMA.md and docs/DECISIONS.md, so the rules are correct before any code depends on them.

Step 2: Migrations, enums, models, factories, seeder
Migrations (make:model -mf), in FK order: clients, properties, jurisdictions, property_jurisdictions, events, runs, invoices, invoice_lines (including a self-referencing adjusts_line_id).
Enums in app/Enums: ExemptionType, EventStatus, InvoiceType.
Models: relationships, decimal:N casts on all money, date casts, enum casts, breakdown as array, $timestamps = false except on Run. Note that App\Models\Event shares its name with the Event facade, so watch the imports.
Immutability: Event, Invoice and InvoiceLine throw on updating and deleting. To avoid ever updating an invoice, compute its lines and total in memory first, then create the invoice with its total already set.
JurisdictionSeeder: idempotent updateOrCreate, called from DatabaseSeeder. Replaces the default user seeding.
Tests setup: enable RefreshDatabase for Feature in tests/Pest.php.
Tests: seeded rates and exemptions, relationships, unique constraints reject duplicates, updating or deleting an issued line, invoice or event throws.
Step 3: Fee calculator
app/Billing/FeeCalculator.php plus a readonly FeeResult. Pure: it takes values and jurisdiction data, with no queries, no float and no round().

Inputs: noticed, final, homestead, jurisdictions, fee rate. Choosing the rate stays out of this class. It only exposes rateFor(int $enrolledCount).
Order: homestead exemption → tax per jurisdiction → sum → savings → % → round half up → $10 waiver → $2,500 cap.
Precision: one scale constant of 20. The longest result needs about 14 decimals, and dividing by 100 always terminates, so nothing is lost.
Tests in tests/Unit/FeeCalculatorTest.php, every spec number (all reproduced with bcmath):
Case	Rate	ISD	CNTY	CITY	Savings	Rounded fee	Final fee
P1 (Run 1)	30%	380.00	121.60	208.00	709.60	212.88	212.88
P2	25%	142.50	57.00	78.00	277.50	69.38 (from 69.375)	69.38
P3	25%	7,600.00	3,040.00	n/a	10,640.00	2,660.00	2,500.00 (capped)
P4	25%	0	0	0	0.00	0	0.00
P5	30%	19.00	6.08	n/a	25.08	7.52	0.00 (waived)
P1 (Run 2)	30%	475.00	152.00	260.00	887.00	266.10	266.10
Edge tests:
ISD floors at $0 (noticed and final both under $100,000)
negative savings → $0
exactly $10.00 is kept; $9.99 is waived
$9.995 rounds to $10.00 and is kept
exactly $2,500 and $2,500.01 → $2,500
non-homestead ignores exemptions
savings with more than 2 decimals aren't rounded before the %
rateFor: 2 → 0.30, 3 → 0.25
Step 4: Import command
billing:import {path}, with logic in app/Billing/DataImporter.php. Sample data lives at database/data/sample.json, and the tests use the same file.

Clients and properties: updateOrCreate on external_id. Jurisdictions: sync. Events: create only if source_id is new. Convert JSON numbers to strings here, the one place floats can enter.
Prints a summary: created, updated, duplicates skipped, rows rejected.
Tests:
sample loads: 3 clients, 6 properties, 7 events
re-import creates nothing
changed property data updates
same-date events keep file order
missing or malformed file fails cleanly
each bad-row type is reported and valid rows still load
a different file path works
Step 5: Billing command
billing:run {as_of} (strict Y-m-d, otherwise a clean error), with logic in app/Billing/BillingRunner.php, inside one DB transaction. Loads clients with lazyById and eager loading, to avoid N+1 on the larger data set.

for each client, property and season (A1):
    latest = newest event ≤ as_of, by (recorded_at, id)
    pending or none               → skip
    previous = newest line for this property and season
    no previous                   → regular line at the tier rate (amount = fee)
    latest not newer than previous's event → skip   (idempotency, backdating)
    fee at previous.fee_rate (A3); diff = fee − previous.fee
    diff = 0                      → skip (A4)
    otherwise                     → adjustment line: fee = new total, amount = diff,
                                    adjusts_line_id = original line (A5)
if anything was built: create the Run, then invoices with totals, then lines
Tests in tests/Feature/BillingRunTest.php:

Run 1 as of 2026-07-01: exactly 3 invoices (C1 212.88, C2 2,569.38, C3 0.00), the 5 line values from the table, 25% for C2, 30% for C1 and C3, P6 not billed, E7 ignored.
Run 2 as of 2026-09-01: exactly one new invoice, C1 type adjustment, amount +53.22, line fee 266.10, pointing at the original line. The original line and invoice are byte-identical afterwards.
Re-run: counts of runs, invoices and lines unchanged, including a re-run with an older as-of date.
Events:
an event recorded exactly on the as-of date counts; the next day doesn't
same-date events: highest id wins
pending → final gets billed later
final → pending after billing keeps the bill, then adjusts once final again
a backdated event changes nothing
Tier: 2 final + 1 pending → 25%, plus a client reaching 3 later (A2, A3).
Adjustments:
credit (negative)
second revision measured against the latest fee
$0 → charge (waived, then above $10)
unchanged fee → nothing (A4)
several revisions → one adjustment, one line each
invoice and adjustment in the same run
Multi-year (A1): a second-year final event is a new bill with its own tier count.
Step 6: Blade pages
ClientController (index, show) with routes clients.index and clients.show, plus / redirecting to the list. Routes bind on external_id (/clients/C1). The layout uses a small inline <style> and no @vite, so reviewers don't need npm run build.

Client list: ID, property count, invoice count, paginated. Totals are not summed in SQL.
Client page: each invoice or adjustment with its as-of date and total. Each line shows property, source event, noticed and final values, savings, rate, calculated fee, final fee with its note, and amount. A per-jurisdiction table explains the tax. Adjustments link to the original invoice and show previous fee → new fee. Properties not yet billed are listed too.
Tests: the pages render C1's 212.88 and +53.22, "waived" for P5, capped for P3, the link to the original invoice; 404 for an unknown client.
Step 7: Event form
EventController (create, store) and StoreEventRequest. The form uses a property dropdown and always stores a null source_id.

Rules: property exists; valid date, not in the future; status is an enum value; noticed required, non-negative, at most 2 decimals; final required_if:status,final and prohibited_if:status,pending, same numeric rules.
Tests:
each rule rejects bad input and keeps the old values
valid pending and final events save
two form events can both have a null source_id
end to end: submit a revision, run billing, get an adjustment
Step 8: README and DECISIONS
Rewrite README.md with setup, the commands and how to load a different file. Update DECISIONS.md with the choices above and the real test list, and keep it to one page.

Verification
php artisan test passes in full. Run the narrowest set while working.
From a clean database, against the real SQLite file rather than the in-memory test DB:
php artisan migrate:fresh --seed
php artisan billing:import database/data/sample.json
php artisan billing:run 2026-07-01    # 3 invoices: 212.88 / 2,569.38 / 0.00
php artisan billing:run 2026-09-01    # 1 adjustment: C1 +53.22
php artisan billing:run 2026-09-01    # nothing new
Browse the client list and /clients/C1, C2 and C3 via Herd (URL from Boost's get-absolute-url). Use the form to submit an event, re-run billing, and confirm the charge can be explained from the page alone.
Final billing-reviewer pass over the whole repo.