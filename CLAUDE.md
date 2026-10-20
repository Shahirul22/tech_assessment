# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Project rules

These rules are written by hand, sit outside Boost's managed block below, and survive `boost:update`.

## Laravel Boost is mandatory

1. You MUST, before writing or changing any Laravel code (migrations, models, factories, controllers, middleware, config, routes, Blade, Pest tests, events/broadcasting, Reverb/Echo setup), call Boost's `search-docs` for the installed package versions and follow Boost's guidelines. Do not rely on memory.
2. You MUST use Boost's `database-schema` and `database-query` tools to inspect the real schema instead of guessing columns or constraints, its `tinker` capability to check behaviour, and its `last-error` and `browser-logs` tools when debugging.
3. You MUST call Boost's `application-info` tool at the start of each session.
4. If the Boost MCP server is unreachable, you MUST start the stack (`docker compose up -d --wait`) and retry. You MUST NOT continue without it, and MUST NOT silently fall back to memory.
5. Every task completion note MUST name the Boost documentation query that was used.
6. Boost guidance MUST NOT override `requirement.md` §0, the approved PRD or the approved plan. If they conflict, stop and ask.

## Project basics

- Everything runs in Docker; do not assume PHP, Composer or Node on the host. Shorthand: `APP` = `docker compose run --rm app`, `NODE` = `docker compose --profile tools run --rm node` (add `--user "$(id -u):$(id -g)"` when a command writes files). Tests: `APP php artisan test` (unit + feature, excludes `concurrency`), `APP php artisan test --group=concurrency`, `NODE npm test`.
- `requirement.md` is never edited.
- The workflow documents (PRD, plan, tasks, rules) live in `workflow/`; start with `workflow/README.md`. Precedence when they disagree: rules > `requirement.md` §0 > PRD > plan > tasks. Each milestone (M1–M4, all implemented) has its PRD, plan and tasks in `workflow/slices/`; code comments cite their IDs (FR, DD, PQ, AC). The HTTP contract is `docs/api-contract.md`.

## Commands

- Start the stack: `docker compose up -d --build --wait` (app at http://localhost:8080, Reverb websocket on 8081). `.env.docker` is gitignored and Compose needs it; the README has a snippet that generates one.
- Everything in CI-style order: `bash scripts/verify.sh` (smoke, Pest, concurrency, JS, live latency; stops at first failure). It **erases demo data** and reseeds at the end.
- One PHP test: `APP php artisan test --compact --filter=testName` or `APP php artisan test tests/Feature/IdempotencyTest.php`. Run `vendor/bin/pint --dirty --format agent` (inside `APP`) after editing PHP.
- One JS test: `NODE node --test resources/js/__tests__/<file>.test.js`. `NODE npm ci` once on a fresh clone.
- Concurrency tests (`tests/Concurrency`, group `concurrency`) hit the **running stack over real HTTP** with committed data (no `RefreshDatabase`), so start the stack first. They and `tests/Live/live-latency.mjs` consume units; reset with `APP php artisan migrate:fresh --seed`.
- Browser audits `node tests/Live/ui-audit.mjs` and `offline-flow.mjs` run on the **host** (need a local Chromium, `CHROMIUM_PATH`), not in Docker, and are outside `verify.sh`.
- Short expiry windows for demos: `bash scripts/demo-expiry.sh` (sets `POOL_WINDOW_SCALE`; no code changes); `docker compose up -d --wait` returns to 24/48/72 h.
- Feature tests use PostgreSQL (the lock and `timestamptz(6)` behaviour matter), so tests need the compose `postgres` service up.

## Architecture

A Laravel 13 JSON API plus an Alpine.js single page (`resources/views/dashboard.blade.php`, `resources/js/`), PostgreSQL, and Reverb websockets. There is no auth: `demo.user` middleware trusts an `X-Demo-User` header and `role:operator|supervisor` middleware gates routes (`routes/api.php`). That is a demo role switch, not security.

**One write path, one lock.** Operator requests, supervisor restocks and corrections all go through `App\Pool\PoolWriteGate::locked()`: a transaction at READ COMMITTED that takes `SELECT … FOR UPDATE` on the pool row with a 5 s `lock_timeout` (surfaced as `PoolBusy` → 503). Under the lock the service reloads the ledger, computes, and stores one entry numbered from `LedgerSequence` (the pool's `version`). Commit order is therefore service order. `AllocationService`, `RestockService` and `CorrectionService` each follow this shape; do not add a write that bypasses the gate.

**Ledger-derived state, nothing stored.** `remaining`, expiries and operator status are never stored. `LedgerRepository` loads restocks, allocations and corrections as plain rows into an immutable `Calculation\Ledger`; `PoolCalculator::calculateAt($ledger, $instant)` (pure, built from `Calculation\Steps`) derives the state at an instant. Consequences:
- Expiry writes and broadcasts nothing; every read recomputes it. The client schedules its own refresh at `next_expiry_at`.
- Corrections are extra ledger entries, never edits. Original rows stay readable.
- A write is evaluated at `Ledger::evaluationInstant()`: its server receipt time, but never earlier than the newest entry already stored, so replies stay consistent with the ledger and replays are exact. Client time fields are ignored.
- Audit (`app/Pool/Audit`) replays history with `Ledger::asOf($seq)` to compute before/after status and "overdrawn in hindsight".
- Tier windows and status thresholds come from `config/pool_rules.php`, validated at boot into `RulesConfig` (`PoolServiceProvider`); a bad config stops startup. Time comes from the `Clock` interface (`FrozenClock` in tests via `freezeClock()`).

**Idempotency.** Writes require an `Idempotency-Key` header. The same key with the same payload replays the stored result (200 + `Idempotent-Replayed`, no new entry, no broadcast); a different payload is 409. Errors are rendered by `Http\ApiException` / `ApiErrorRenderer`.

**Live updates.** After commit, `PoolWriteGate::announce()` defers a `PoolUpdated` broadcast (public channel `pool.{id}`, event `pool.updated`, carrying `version`, `remaining` and the rendered log entry). A broadcast failure is reported and swallowed. The client (`resources/js/pool-state.js`, pure functions) applies updates in `version` order, holds a gap for 500 ms, then fetches a snapshot (`SnapshotService`, `GET /api/pools/{pool}`); it also polls every 10 s, so Reverb being down degrades but does not break the screen. `dashboard.js` is the Alpine glue; the other `resources/js/*.js` modules are DOM-free logic with tests in `resources/js/__tests__/`.

**Tests.** `tests/Pest.php` holds the shared world builders (`workedPool()`, `expiryWorld()`, `submitAllocation()`, `postRestock()`, `correct()` …). Unit tests cover the pure calculation, Feature tests run services and HTTP against Postgres with `RefreshDatabase`, and `tests/Live/*.mjs` are Node scripts against the running stack.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

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
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
