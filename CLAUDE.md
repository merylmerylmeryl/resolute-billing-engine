# Lone Oak Billing Engine: project rules

- Spec: `docs/case-study.md`
- Schema: `docs/schema.md`
- Decisions: `docs/DECISIONS.md`

## How we work

- Plan before writing code. Show me the plan and wait for my approval.
- Keep changes small. One step at a time.
- Write Pest tests first from the spec's expected results, then build until they pass.
- Run `php artisan test` after every change. Never say something works without running the tests.
- Before each commit, run the `billing-reviewer` agent and fix what it finds.
- Explain anything non-obvious so I can explain it in the live session.
- Use Laravel the normal way (Eloquent, migrations, Artisan commands, form requests, Blade). No extra packages unless I approve.

## Money

- Never use floats for money. Use exact decimal math (bcmath) everywhere.
- Store money as DECIMAL columns.
- Do all math without rounding, then round each property's fee to the cent, rounding halves up ($69.375 → $69.38).
- Fee order: savings → fee % → round → $10 waiver → $2,500 cap.
- bcmath truncates and defaults to 0 decimal places, so always pass a scale. Because it truncates toward zero, round with `bcadd($x, '0.005', 2)` for positive values and `bcsub($x, '0.005', 2)` for negative ones. Fees are never negative, but the `savings` snapshot can be.
- SQLite stores DECIMAL as REAL. Use `decimal:2` casts on stored money and `decimal:4` on rates and `exemption_amount`. Never use SQL `SUM` or `withSum` on money; total with `bcadd` in PHP. Keep JSON values as strings.
- Never calculate a fee from the stored, rounded `savings` column.

## Tax

- Order: homestead exemption → tax per jurisdiction (taxable value / 100 × rate) → sum → savings.
- Savings = tax at noticed value − tax at final value.
- Taxable value can't go below $0.
- Jurisdiction rates and exemptions are data (table + seeder), not hardcoded.
- Exemption types: none, flat (subtract an amount), percent (subtract a percentage).

## Fee rules

- A season is the year of an event's `recorded_at`.
- 30% of savings, or 25% if the client has 3+ properties enrolled that season, whatever their outcomes (pending counts).
- A property is enrolled in a season if it has at least one event in that season, recorded on or before the billing date.
- The rate is decided at billing time; already-billed lines are not re-rated.
- An adjustment reuses the `fee_rate` of the line it corrects, so the difference reflects only the outcome change.
- Savings zero or negative → $0.
- Fee under $10 after rounding → waived, $0.
- Max $2,500 per property per season.

## Events and billing

- Events are the only way outcomes reach billing. They are append-only, never edited.
- Latest event, billed-or-not and the fee tier all apply per property and season.
- Latest event per property and season, recorded on or before the billing date, wins. Same date: the one recorded last (highest `id`) wins. Filter dates with `whereDate`, because a plain `where` drops events recorded exactly on the billing date.
- Imported events are de-duplicated by `source_id`. Form events have a null `source_id`.
- Clients and properties keep their source IDs as `external_id` (unique). Re-imports update, never duplicate.
- A billing run bills every property whose latest outcome is final and not yet billed. Pending properties are skipped.
- Each invoice line stores the `event_id` it billed. If a property's latest final event is already on a line, skip it.
- If a property was billed for an older event in the same season and now has a newer final event, issue an adjustment for the fee difference. If the fee is unchanged, issue nothing.
- A backdated event or an older billing date never un-bills anything. If the eligible event isn't newer than the last billed one by (`recorded_at`, `id`), skip it.
- Each client with new regular lines gets one regular invoice per run, one line per property per season. An invoice that totals $0 is still issued. A client with no new regular lines, such as one whose properties are all pending or already billed, gets no regular invoice.
- Adjustments are invoices with `type = adjustment`. Each adjustment line sets `adjusts_line_id` to the property's original line for that season.
- On adjustment lines, `fee` is the property's new total fee and `amount` is the difference from the latest line's fee. A client with new and revised properties in one run gets one invoice and one adjustment. An adjustment's total can be negative.
- Issued invoices and lines are never updated or deleted.
- Running billing twice with no new information creates nothing new. A run that issues nothing creates no `runs` row.
- Each invoice line stores a snapshot (noticed value, final value, savings, fee rate, fee, per-jurisdiction breakdown) so every charge can be explained.

## Import and form

- Import keeps file order and never sorts, because "recorded last" depends on `id`.
- Unreadable JSON or a missing top-level key aborts the import.
- A bad row (unknown property or jurisdiction, final with no value, pending with a value, invalid status or date, non-numeric value, missing field) is skipped and reported, valid rows still load, and the command exits non-zero.
- A repeated `source_id` with different values: the first one wins, with a warning.
- Re-importing changed homestead or jurisdiction data affects future calculations only and triggers no adjustment, with one exception: a revision that didn't change the fee never landed on a line, so the next run recalculates it with the new data. If the fee now differs, that run issues one adjustment.
- Form: `recorded_at` can't be in the future, money has at most 2 decimals, and final value may exceed noticed (savings go negative, fee is $0). If noticed values differ across events, the latest event's value is used.
- No authentication, since the spec doesn't ask for it.

## Code structure

- Keep the fee/tax calculator in its own class, separate from commands and controllers.
- Import command takes a file path argument, so any data file can be loaded.
- Billing command takes an "as of" date argument.
- Keep Blade pages plain: a client list, and a client page with invoices, adjustments and line breakdowns.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>