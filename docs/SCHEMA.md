# Database schema

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
- exemption_amount (decimal 12,2, nullable) — 100000 for flat, 0.20 for percent

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

## runs
- id (PK)
- as_of (date)
- timestamps

## invoices (never edited after issue)
- id (PK)
- run_id (FK -> runs)
- client_id (FK -> clients)
- type (enum: invoice, adjustment)
- total (decimal 12,2)

## invoice_lines (never edited after issue)
- id (PK)
- invoice_id (FK -> invoices)
- property_id (FK -> properties)
- event_id (FK -> events) — the event this charge is based on
- adjusts_line_id (FK -> invoice_lines, nullable) — adjustments only
- noticed_value (decimal 14,2)
- final_value (decimal 14,2)
- savings (decimal 12,2)
- fee_rate (decimal 5,4) — 0.30 or 0.25
- fee (decimal 12,2) — the property's full fee as of this line
- amount (decimal 12,2) — what this line charges: the fee, or the difference for adjustments
- breakdown (json) — tax per jurisdiction, at noticed and final values