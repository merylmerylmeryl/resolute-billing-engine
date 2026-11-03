# Resolute Case Study: Billing Engine

## Overview

This is a take-home exercise followed by a live session, where you’ll walk us through what you built and then add a feature to it with us. It should take about an hour; but please stop at two hours. If you run out of time, a smaller piece that works, plus a few notes on what you’d do next, is better than everything half-finished.

We’ll look at your code, but its main purpose is to give you a codebase you're familiar with, so the live session can focus on building something together. There are no tricks or gotchas, and it isn’t meant to be stressful. We’re more interested in how you approach the work than in a perfect answer.

You'll build a small billing engine from a written spec. We're looking at how you model data, handle money and state that changes over time, structure and test your code, and make decisions when the spec is silent.

**Use AI coding tools.** We expect you to use them, the same way you would on the job. What we're evaluating is your judgment: how you steer the tools, what you check, and whether you understand what you hand in. Please build it in a recent version of Laravel, and use the framework the way you would on a real project. 

**About this scenario:** Lone Oak Property Tax is a fictional company, and all data is invented. We won't use your work, and it remains yours.

## The scenario

Lone Oak Property Tax protests property values on behalf of Texas homeowners and investors. Each year the county sets a "noticed" value for each property; Lone Oak argues for a lower one. Clients pay nothing up front. Instead, Lone Oak charges a percentage of the tax money it saved them.

Today billing is done in a spreadsheet, and it goes wrong in predictable ways: rounding disputes, discounts applied inconsistently, and invoices quietly edited after a case's outcome changes, which leaves no record of what the client was originally charged. Lone Oak wants a billing engine that gets this right and can be trusted by the finance team.

You don't need any property tax background. Everything you need is in the rules below.

## Billing rules

### 1. Tax savings for a property

A property is taxed by several jurisdictions. For each one, tax = taxable value ÷ 100 × rate. A property's tax is the sum across its jurisdictions, and its **savings** are the tax at the noticed value minus the tax at the final value.

Some jurisdictions give homestead (owner-occupied) properties an exemption that lowers the taxable value:

| Jurisdiction | Rate per $100 | Homestead exemption |
| --- | --- | --- |
| ISD (school district) | 0.95 | First \$100,000 of value; taxable value can't go below \$0 |
| CNTY (county) | 0.38 | 20% of value |
| CITY (city) | 0.52 | None |

### 2. The fee for a property

- The fee is 30% of savings, or 25% if the client has 3 or more properties enrolled that season, whatever their outcomes.
- If savings are zero or negative, the fee is \$0.
- If a property's fee comes to less than \$10, it's waived and the fee is $0.
- The maximum fee is \$2,500 per property.
- Do all math without rounding, then round each property's fee to the cent, rounding halves up (\$69.375 becomes \$69.38). Apply the $10 threshold and the maximum after rounding.

### 3. Invoices

- A **billing run** bills every property whose outcome is final and hasn't been billed yet. Each client gets one invoice per run, with one line per property.
- Properties whose outcome is still pending are skipped and billed in a later run once final.
- **Issued invoices never change.** If a property's final value is revised after it was billed, the next run issues an **adjustment** for that client: a charge or a credit for the difference, referencing the original invoice.
- Running billing twice with no new information must produce nothing new.

When the spec doesn't cover a situation, make a reasonable decision and write it down.

## Sample data

Properties, and the outcome events that arrive over the season. Events are the only way outcomes reach the billing engine. Imported events come from Lone Oak's case management system, and `source_id` is that system's identifier for each event. Events entered through your form won't have one.

```json
{
  "properties": [
    {"id": "P1", "client_id": "C1", "homestead": true,  "jurisdictions": ["ISD", "CNTY", "CITY"]},
    {"id": "P2", "client_id": "C2", "homestead": false, "jurisdictions": ["ISD", "CNTY", "CITY"]},
    {"id": "P3", "client_id": "C2", "homestead": false, "jurisdictions": ["ISD", "CNTY"]},
    {"id": "P4", "client_id": "C2", "homestead": false, "jurisdictions": ["ISD", "CNTY", "CITY"]},
    {"id": "P5", "client_id": "C3", "homestead": true,  "jurisdictions": ["ISD", "CNTY"]},
    {"id": "P6", "client_id": "C3", "homestead": true,  "jurisdictions": ["ISD", "CNTY", "CITY"]}
  ],
  "events": [
    {"source_id": "E1", "property_id": "P1", "recorded_at": "2026-06-01", "status": "final",   "noticed_value": 400000,  "final_value": 360000},
    {"source_id": "E2", "property_id": "P2", "recorded_at": "2026-06-03", "status": "final",   "noticed_value": 300000,  "final_value": 285000},
    {"source_id": "E3", "property_id": "P3", "recorded_at": "2026-06-05", "status": "final",   "noticed_value": 4000000, "final_value": 3200000},
    {"source_id": "E4", "property_id": "P4", "recorded_at": "2026-06-05", "status": "final",   "noticed_value": 250000,  "final_value": 250000},
    {"source_id": "E5", "property_id": "P5", "recorded_at": "2026-06-10", "status": "final",   "noticed_value": 120000,  "final_value": 118000},
    {"source_id": "E6", "property_id": "P6", "recorded_at": "2026-06-12", "status": "pending", "noticed_value": 510000,  "final_value": null},
    {"source_id": "E7", "property_id": "P1", "recorded_at": "2026-08-15", "status": "final",   "noticed_value": 400000,  "final_value": 350000}
  ]
}
```

### Expected results

**Run 1, as of 2026-07-01** (events E1–E6):

| Client | Property | Savings | Fee |
| --- | --- | --- | --- |
| C1 | P1 | \$709.60 | \$212.88 |
| C2 | P2 | \$277.50 | \$69.38 |
| C2 | P3 | \$10,640.00 | \$2,500.00 |
| C2 | P4 | \$0.00 | \$0.00 |
| C3 | P5 | \$25.08 | \$0.00 (waived) |

Three invoices: C1 \$212.88, C2 \$2,569.38, C3 \$0.00. P6 is pending and not billed.

**Run 2, as of 2026-09-01** (adds E7, a revised outcome for P1): one adjustment for C1 of **+$53.22**, since P1's fee is now $266.10. Running again immediately produces nothing new.

## What to build

A small Laravel application that:

- Imports a JSON file of properties and events with an Artisan command
- Runs billing as of a given date with an Artisan command, storing the invoices and adjustments it issues so state persists between runs (SQLite is fine)
- Has a few simple blade pages: a list of clients, and a page per client showing their invoices and adjustments with enough detail to explain each charge
- Has a form for recording a new outcome event, with validation
- Reproduces the expected results above

Plain blade pages are fine; no javascript framework needed. Use whichever parts of Laravel you'd normally reach for. There's no extra credit for using more of the framework; we care whether you use it well.

We'll also run it against a larger set of cases we haven't shared, so make loading a different data file easy.

### What to send us

- A zip or repo link, with a README explaining how to set it up and run it
- **DECISIONS.md, one page at most:** anything you feel you need to explain; anything the spec didn't cover and what you decided; how you checked that it works; what you'd do next
