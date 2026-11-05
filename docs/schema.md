# Database schema

All foreign keys use `restrictOnDelete`, so no cascade can ever remove an issued line.

SQLite stores DECIMAL as REAL. Money attributes use `decimal:N` casts (which return strings), and totals are added with bcmath in PHP, never SQL `SUM`.

## clients
- id (PK)
- external_id (unique) — e.g. "C1"

## properties
- id (PK)
- external_id (unique) — e.g. "P1"
- client_id (FK -> clients)
- homestead (bool)

## jurisdictions
- id (PK)
- code (unique) — "ISD", "CNTY", "CITY"
- rate (decimal 8,4) — per $100
- exemption_type (enum: none, flat, percent)
- exemption_amount (decimal 12,4, nullable) — 100000 for flat, 0.20 for percent; 4 places so 0.125 isn't rounded to 0.13

## property_jurisdictions
- id (PK)
- property_id (FK -> properties)
- jurisdiction_id (FK -> jurisdictions)
- unique (property_id, jurisdiction_id)

## events (append-only, never edited)
- id (PK)
- source_id (unique, nullable) — from import; null for form events
- property_id (FK -> properties)
- recorded_at (date)
- status (enum: pending, final)
- noticed_value (decimal 14,2)
- final_value (decimal 14,2, nullable) — null when pending
- index (property_id, recorded_at)

The season is the year of `recorded_at`. There is no season column. "Recorded last" means highest `id`, because `recorded_at` is date-only.

## runs
- id (PK)
- as_of (date)
- timestamps

A run that issues nothing creates no row here.

## invoices (never edited after issue)
- id (PK)
- run_id (FK -> runs)
- client_id (FK -> clients)
- type (enum: invoice, adjustment)
- total (decimal 12,2) — can be negative on an adjustment
- unique (run_id, client_id, type)

## invoice_lines (never edited after issue)
- id (PK)
- invoice_id (FK -> invoices)
- property_id (FK -> properties)
- event_id (FK -> events, unique) — the event this charge is based on; unique so an event can be billed at most once
- adjusts_line_id (FK -> invoice_lines, nullable) — adjustments only; always the property's original line for that season
- noticed_value (decimal 14,2)
- final_value (decimal 14,2)
- savings (decimal 12,2) — rounded for display; never calculate a fee from it
- fee_rate (decimal 5,4) — 0.30 or 0.25
- fee (decimal 12,2) — the property's full fee as of this line
- amount (decimal 12,2) — what this line charges: the fee, or the difference for adjustments
- breakdown (json, values as strings):
  - exact savings
  - calculated fee before the waiver and cap
  - note: waived, capped or no_savings
  - per jurisdiction: rate, exemption, and taxable value and tax at both noticed and final values

One line per property per season on each invoice is enforced in code and covered by a test, not by a constraint, because the season isn't stored. A property with unbilled events in two seasons gets two lines on one invoice.