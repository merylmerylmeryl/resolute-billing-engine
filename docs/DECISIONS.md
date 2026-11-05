# DECISIONS

## How I approached it

- Studied the case study, made my own notes, and did the math on the sample JSON by hand to confirm the expected results
- Used Claude to clarify info, research property tax context, and challenge my ideas
- Designed the database myself first, then had it reviewed
- Estimated data size (~14M properties in Texas) to think about scale

## How I used AI

- CLAUDE.md with my rules (money as decimals, invoices never edited, etc.) so Claude Code followed them every session
- Plan Mode before coding; I approved the plan, then built one step at a time, reviewing and committing after each
- Opus for planning and money and billing logic, Sonnet for pages, form and README to save usage
- Tests written first from the spec's expected results; a hook runs tests after every file Claude edits
- A review subagent checks changes against my rules before each commit. It caught real bugs, like a "safe default" that billed $114.00 instead of $57.00
- I reviewed every file and can explain all of it

## Money

- All money math uses exact decimals (bcmath), never floats
- Order: homestead exemption -> tax per jurisdiction -> savings -> fee % -> round half up to the cent -> $10 waiver -> $2,500 cap
- bcmath truncates, so I always pass a scale and round half up by adding 0.005: 9.995 -> 10.00 (kept), 9.994999 -> 9.99 (waived)
- Fees are calculated from exact savings, never the rounded `savings` column. SQLite stores DECIMAL as REAL, so totals use bcmath in PHP, never SQL `SUM`
- Each invoice line stores a snapshot of how it was calculated: join for identity (which property), snapshot for money (what was billed and why)

## Data design

- Events are append-only. Each invoice line records the event it billed, which is what makes billing safe to run twice
- Adjustments are invoices with type "adjustment". Each adjustment line references the original line it corrects, so nothing is ever edited
- Clients and properties keep their source IDs as external_id (unique), so re-imports update instead of duplicating
- Jurisdiction rates and exemptions are data, not hardcoded, since they change yearly
- The database backs up the rules: `event_id` unique, one invoice per client, type and run, and restricted deletes

## Where the spec was silent

- **Which event counts:** latest event per property and season, on or before the billing date. Same date: highest id (import keeps file order)
- **"Season":** the year the event was recorded. Known cost: a 2026 case revised in January 2027 bills as a new fee; the real fix is a season field from case management. The $2,500 cap applies per property per season
- **25% tier:** counts a client's properties with an event that season on or before the billing date, including pending ones ("whatever their outcomes")
- **Rates:** set at billing time; already-billed lines aren't re-rated. An adjustment uses the rate of the line it corrects, so the difference reflects only the outcome change
- **Adjustments:** always reference the original line, with the difference measured against the latest fee ($212.88 -> $266.10 = +$53.22). A revision that doesn't change the fee creates no adjustment
- **Final value higher than noticed:** fee is $0. **Final -> pending after billing:** keep the bill. Backdated events never un-bill anything
- **$0 invoices:** still issued if the client has at least one new line. A run with nothing to issue creates nothing
- **Re-imported homestead or jurisdiction changes:** affect future bills only. Exception: a property whose last revision didn't change the fee gets recalculated with the new data and can get one adjustment; documented, not fixed
- **Bad import rows** (unknown property or jurisdiction, empty jurisdictions, negative or non-numeric values, invalid status or date, final with no value, a property moving to another client): skipped and reported, so one bad row doesn't block the rest
- **Authentication:** none, since the spec doesn't ask for it
- **Who the pages are for** Lone Oak staff (all clients, no login), with client-friendly labels so the page can be shared with a client

## How I checked it works

- Tests for every expected number in the spec, Run 1, Run 2, and running billing again producing nothing new
- Tests for: taxable value can't go below $0, rounding half up, the waiver, the cap, pending properties being skipped, re-importing the same file, and form validation
- Broke the calculator on purpose 10 ways to confirm the tests catch each break, and compared 40,000 random cases against a separate exact calculation
- The sample data can't prove some rules (no client reaches 3 properties with one pending), so those have their own tests

## What I'd do next / questions I'd ask

- What causes revisions, and how often?
- How many kinds of homestead rules exist? If rules start stacking (over-65, flat then percent), I'd move them into a jurisdiction_rules table: an ordered list of rules per jurisdiction, applied in sequence
- Should a client who later reaches 3 properties get earlier fees re-rated?
- Rates and exemptions by year, so old invoices can always be reproduced, plus range checks if rates become editable
- A season field from the case management system
- Product questions for later: sending invoices, payments (Stripe?), notifications, logins, multiple users or owners per account
- For a client-facing version I’d add names and addresses, hide event IDs, explain adjustments in a sentence, and format dates. The data model already supports it; it’s view work.
- Searchable select for properties on Record Event page
- Show recorded events on the client page, so a new event is visible before billing runs