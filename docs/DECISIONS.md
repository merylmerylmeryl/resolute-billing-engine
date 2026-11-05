# DECISIONS

## How I approached it

- Studied the case study and made my own notes to make sure I understood it
- Ran through the JSON by hand and did the math to confirm the expected results
- Used Claude to clarify info, research property tax context, and challenge my ideas
- Estimated data size (~14M properties in Texas) to think about scale
- Designed the database myself first, then had it reviewed
- Wrote down test cases and possible features as I thought of them

## How I used AI

- CLAUDE.md with my rules (money as decimals, invoices never edited, etc.) so Claude Code followed them every session
- Opus for plan and money and billing logic, Sonnet for pages, form, README to save usage
- Plan Mode before coding, and approved each plan
- Tests written first from the spec's expected results; a hook runs them automatically after every code change
- A review subagent checks changes against my rules before each commit
- I reviewed every file and can explain all of it

## Money

- All money math uses exact decimals (bcmath), never floats, so rounding is consistent
- Order: homestead exemption -> tax per jurisdiction -> savings -> fee % -> round half up to the cent -> $10 waiver -> $2,500 cap
- Each invoice line stores a snapshot of how it was calculated, so every charge can be explained: join for identity (which property), snapshot for money (what was billed and why)
- bcmath truncates and defaults to 0 decimal places, so I always pass a scale and round half up by adding 0.005: 9.995 -> 10.00 (kept), 9.994999 -> 9.99 (waived). Negative savings subtract it instead; they only appear in the snapshot, since their fee is $0
- SQLite stores DECIMAL as REAL, so 0.1 + 0.2 in SQL gives 0.30000000000000004. Money uses `decimal:N` casts, totals use bcmath in PHP, never SQL `SUM`, and JSON values stay strings. Fees are never calculated from the rounded `savings` column; exact values stay in the calculator and the line's breakdown

## Data design

- Adjustments are invoices with type "adjustment". Each adjustment line references the original line it corrects, so a charge's full history is traceable and nothing is ever edited
- Each invoice line records the event it billed, so a run can tell whether a property's latest outcome is new, changed, or already billed. That's what makes billing safe to run twice
- Clients and properties keep their source IDs as external_id (unique), so re-imports update instead of duplicating, and pages show the IDs the business already uses
- Jurisdiction rates and exemptions are stored as data, not hardcoded, since they change yearly
- The database backs up the rules: an event can be on only one line (`event_id` unique), one invoice per client, type and run, one line per property per invoice, and foreign keys restrict deletes so no cascade can remove an issued line
- `exemption_amount` has 4 decimals so a 0.125 exemption isn't rounded to 0.13. The line's breakdown also keeps the exact savings, the fee before the waiver and cap, a note (waived, capped, no savings), and each jurisdiction's rate, exemption and taxable value, since rates may change later
- Dates are stored as `2026-07-01 00:00:00`, which SQLite says is not `<= '2026-07-01'`, so queries use `whereDate` to keep events recorded exactly on the billing date
- Re-import after a no-change revision can issue one adjustment; documented, not fixed.

## Where the spec was silent

- **Which event counts:** latest event per property and season, on or before the billing date
- **Same-date events:** the one recorded last wins. `recorded_at` is date-only, so that means highest id, and import keeps file order
- **Duplicate imports:** events are de-duplicated by source_id; form events have none. A repeated source_id with different values: the first one wins, with a warning
- **"Season":** the year the event was recorded. Latest event, billed-or-not and the 25% tier all apply per property and season, with no schema change, because a line's season comes from its event. Known cost: a 2026 case revised in January 2027 bills as a new 2027 fee instead of adjusting. The real fix is a season field from the case management system. I rejected treating the app as single-season: simpler, but any second-year event would produce a wrong adjustment. The $2,500 maximum also applies per season, since each year is a separate fee
- **"Enrolled":** a property with at least one event in that season, on or before the billing date, so replaying a past date gives the same answer. A property with no events doesn't count, and one client can have lines at 30% and later ones at 25% in the same season
- **25% tier:** counts all of a client's enrolled properties that season, including pending ones ("whatever their outcomes")
- **Client reaches 3 properties after earlier ones were billed:** the rate is set at billing time; already-billed lines aren't re-rated
- **Which rate an adjustment uses:** the rate of the line it corrects, so the difference reflects only the outcome change. Savings of $1,000 at 30% = $300, revised to $1,100: +$30, not a -$25 credit at 25%
- **Revision that doesn't change the fee:** no adjustment. The event never lands on a line, so each run re-evaluates it and finds no difference again, which is still idempotent
- **What an adjustment references:** always the original line. The difference is measured against the latest line's fee: $212.88 -> $266.10 (+$53.22) -> $240.00 is -$26.10, not +$27.12
- **Final value higher than noticed:** savings are negative, fee is $0
- **Final -> pending after billing:** keep what was billed; adjust once it's final again
- **Backdated event or older billing date:** never un-bills anything. If the eligible event isn't newer than the last billed one, skip it
- **$0 invoices:** a regular invoice that totals $0 is still issued, so there's a record. It needs at least one new line, so a client whose properties are all pending or already billed gets no invoice
- **A run with nothing to issue:** creates no run record, so it's truly "nothing new"
- **Several revisions for one client in one run:** one adjustment invoice, one line per property, each referencing the original line it corrects
- **New and revised properties for one client in one run:** one invoice and one adjustment. A mixed adjustment's total can be negative
- **Bad import data:** unreadable JSON or a missing top-level key aborts. A bad row (unknown property or jurisdiction, final with no value, pending with a value) is skipped and reported, and the command exits non-zero, so one bad row doesn't block the rest
- **Re-imported homestead or jurisdiction changes:** affect future calculations only, with no adjustment
- **Form:** recorded_at can't be in the future, money has at most 2 decimals, and final value may exceed noticed. If noticed values differ across events, the latest event's value is used
- **Authentication:** none, since the spec doesn't ask for it

## How I checked it works

- Unit tests for the fee calculator, including every expected number from the spec
- Feature tests that reproduce Run 1 and Run 2, plus running billing again producing nothing new
- Tests for: taxable value can't go below $0, rounding half up, the waiver, the cap, pending properties being skipped, re-importing the same file, and form validation (property must exist)

- The sample data can't prove some rules: P5 is waived at both 30% and 25%, and no sample client reaches 3 properties with one pending. "Pending counts" gets its own test, as does a revision that doesn't change the fee

## What I'd do next / questions I'd ask

- What causes revisions, and how often?
- How many kinds of homestead rules exist, and can new ones be added? If rules start stacking, I'd move to an ordered list of small rule objects
- Exemptions are one type plus amount per jurisdiction, which covers this spec. If rules start stacking (over-65, flat then percent, rules by year), I’d move them into a separate jurisdiction_rules table: an ordered list of rules per jurisdiction, applied in sequence
- Should a client who later reaches 3 properties get earlier fees re-rated?
- Rates and exemptions by year, so old invoices can always be reproduced
- A season field from the case management system, so a revision in January adjusts the right year
- Product questions for later: sending invoices, payments (Stripe?), notifications, logins, multiple users or owners per account