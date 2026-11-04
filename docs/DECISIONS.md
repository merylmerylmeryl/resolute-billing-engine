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
- Had it plan before coding, and approved each plan
- Tests written first from the spec's expected results; a hook runs them automatically after every change
- A review subagent checks changes against my rules before each commit
- I reviewed every file and can explain all of it

## Money

- All money math uses exact decimals (bcmath), never floats, so rounding is consistent
- Order: homestead exemption -> tax per jurisdiction -> savings -> fee % -> round half up to the cent -> $10 waiver -> $2,500 cap
- Each invoice line stores a snapshot of how it was calculated, so every charge can be explained: join for identity (which property), snapshot for money (what was billed and why)

## Data design

- Adjustments are invoices with type "adjustment". Each adjustment line references the original line it corrects, so a charge's full history is traceable and nothing is ever edited
- Each invoice line records the event it billed, so a run can tell whether a property's latest outcome is new, changed, or already billed. That's what makes billing safe to run twice
- Clients and properties keep their source IDs as external_id (unique), so re-imports update instead of duplicating, and pages show the IDs the business already uses
- Jurisdiction rates and exemptions are stored as data, not hardcoded, since they change yearly

## Where the spec was silent

- **Which event counts:** latest event per property, on or before the billing date
- **Same-date events:** the one recorded last wins
- **Duplicate imports:** events are de-duplicated by source_id; form events have none
- **"Season":** the year the event was recorded
- **25% tier:** counts all of a client's enrolled properties that season, including pending ones ("whatever their outcomes")
- **Client reaches 3 properties after earlier ones were billed:** the rate is set at billing time; already-billed lines aren't re-rated
- **Final value higher than noticed:** savings are negative, fee is $0
- **Final -> pending after billing:** keep what was billed; adjust once it's final again
- **$0 invoices:** still issued, so there's a record
- **Several revisions for one client in one run:** one adjustment invoice, one line per property, each referencing the line it corrects

## How I checked it works

- Unit tests for the fee calculator, including every expected number from the spec
- Feature tests that reproduce Run 1 and Run 2, plus running billing again producing nothing new
- Tests for: taxable value can't go below $0, rounding half up, the waiver, the cap, pending properties being skipped, re-importing the same file, and form validation (property must exist)

## What I'd do next / questions I'd ask

- What causes revisions, and how often?
- How many kinds of homestead rules exist, and can new ones be added? If rules start stacking, I'd move to an ordered list of small rule objects
- Exemptions are one type plus amount per jurisdiction, which covers this spec. If rules start stacking (over-65, flat then percent, rules by year), I’d move them into a separate jurisdiction_rules table: an ordered list of rules per jurisdiction, applied in sequence
- Should a client who later reaches 3 properties get earlier fees re-rated?
- Rates and exemptions by year, so old invoices can always be reproduced
- Product questions for later: sending invoices, payments (Stripe?), notifications, logins, multiple users or owners per account