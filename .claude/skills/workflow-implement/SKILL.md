---
name: workflow-implement
description: Implement tasks from a slice's approved tasks.md, following workflow/rules (and workflow/constitution.md and workflow/boundaries.md if they exist) throughout. Use when the user says "implement M1", "implement T003", "do phase 2", "continue implementation", or "build the next task".
---

# workflow-implement — Tasks → Code

Executes tasks one at a time, inside any rules (and constitution/boundaries, if they exist).

## 1. Preflight (every invocation, before touching code)
1. **Select scope** from the request: a specific task (`T003`), a phase, or "next". Default: the next unblocked `[ ]` task in order, then stop at the end of its phase's checkpoint. Slice folder: `workflow/slices/<id>-<name>/`.
2. **Gate check.** `tasks.md`, `plan.md`, `prd.md` must exist and be `Approved`. If not, stop and report.
3. **Read governing documents, every time** (they may have changed):
   - every `workflow/rules/*.md` (except README), if any
   - `workflow/boundaries.md` and `workflow/constitution.md`, **only if they exist**. They are optional and currently not defined; their absence is not a blocker.
   - the slice's `prd.md`, `plan.md`, `tasks.md`
4. **State the active constraints** in two or three lines to the user: which rules (and boundaries/principles, if defined) matter for this task. If none are defined, say "no rules defined" and rely on the plan and tasks.
5. **Explore existing code** with CodeGraph before editing (`codegraph_explore`; if `.codegraph/` is missing, run `codegraph init` in the project root first). Follow existing patterns.

Precedence if sources conflict: (boundaries > constitution, if defined) > rules > requirement.md §0 > PRD > plan > tasks. If equal-level sources conflict, or a task conflicts with a higher one, **stop and ask**; never pick silently.

## 2. Per-task loop
For each selected task, in dependency order (check `Depends on` are `[x]`; if not, do those first or stop):
1. Mark it `[~]` in tasks.md.
2. **Re-check the task against any rules (and boundaries/constitution, if defined)** before writing code. Does it need something in "Ask first"? Stop and ask. Anything in "Never"? Refuse and report.
3. **Implement only what the task says**, in the files it names. Write the proving test first or alongside. Respect `Out of scope`. Keep to the plan's contracts and structure exactly.
4. **Verify:** run the task's `Verify` command(s) and any relevant existing tests. A task is done only when they pass and the `Done when` condition is observably true. Report real output; if something fails, fix the cause. Never weaken, skip or delete a test to get green; never claim done without having run the check.
5. **Compliance pass:** re-read the diff against the rules (and principles, if defined) that applied; fix violations now.
6. Mark `[x]`. Append a one-line note under the task if something non-obvious happened (command used, small decision). Do not touch other tasks' status.
7. Do not commit per task. Commits happen at phase completion (section 4), unless tasks.md Conventions or the user say otherwise.

## 3. Deviations
Reality sometimes disagrees with the docs:
- **Small, within the task's intent** (naming, helper extraction): proceed; mention it in the task note.
- **Changes a contract, schema, dependency, scope, or any doc content:** stop, explain, propose. After the user approves, update the document and add a dated row to the plan's **Change log**. Never edit PRD/plan silently, and never edit `requirement.md`.
- **Task is blocked** (missing decision, failing prerequisite): mark `[!]` with the reason and needed input, list it under "Blocked / deferred", and continue with independent tasks only.
- **Discovered work not in the task list:** do not do it. Report it so a task can be added.

## 4. Checkpoints and finishing
- At the end of a phase, run its checkpoint check and report what a human can now run and what they should see. **Commit the phase** (see "Phase commits" below), then **stop and wait** for review before the next phase unless the user said to continue.
- When all tasks are `[x]` and the slice's AC pass, set tasks.md `Status: Done` only if the user agrees; point to any `Done when` items from `requirement.md` that were verified.
- Final report each run: tasks completed (IDs), verification output summary, deviations, blocked items, next task.

## 5. Phase commits
A phase is a `## Phase N — <name>` section of the slice's tasks.md, ending in its `**Checkpoint N:**` line. Make one commit per phase, when it is complete: every task under that heading is `[x]` (or `[!]` with the reason noted) and its checkpoint check has passed. The commit covers the whole phase (all its tasks together), never a single task. Never commit a phase whose checkpoint or Verify commands are failing. A partially done phase (stopped early, or the user asked for a single task) is not committed unless the user asks.

1. **Stage deliberately.** Review `git status` and `git diff`; stage by explicit path for the files this phase touched (code, tests, migrations, `tasks.md` status updates, and any plan Change log rows). Never `git add -A` blindly. Never stage `.env*` (except `.env.example`), secrets, `vendor/`, `node_modules/`, `.codegraph/` or build output. If unrelated changes are present, leave them out and mention them.
2. **Format first.** If PHP files changed, `vendor/bin/pint --dirty --format agent` must have been run, so the commit is already clean.
3. **Message: [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/).**

   ```
   <type>(<scope>): <description>

   <body>

   <footer>
   ```
   - **type**: `feat` (new behaviour), `fix` (bug fix), `refactor`, `test`, `docs`, `build` (Docker, dependencies), `ci`, `perf`, `style`, `chore`. Pick the one that matches the phase's main effect; a phase mixing a feature with its tests is `feat`. Use `!` after the type/scope and a `BREAKING CHANGE:` footer if an API contract changed incompatibly.
   - **scope**: the slice id, lowercase, e.g. `m3`; a narrower area may follow, e.g. `m3/audit`.
   - **description**: imperative mood ("add", not "added"), lowercase start, no trailing period, 72 characters or fewer for the whole first line. Say what the phase delivers, taken from the phase's heading and goal in tasks.md (e.g. "Phase 2 — Writers" → `add restock and correction writers`), not just its number.
   - **body** (blank line before it, wrapped at about 72): what changed and why, the phase (`Phase 2 — Writers`) and its task IDs (e.g. `Tasks: T010–T015`). Add the checkpoint result in one line (command and outcome). Mention deviations or blocked tasks if any. Explain, don't list every file.
   - **footer**: `BREAKING CHANGE: ...` when applicable, then the attribution trailer the session prescribes (e.g. `Co-Authored-By: ...`). Never invent trailers or references.

   Example:
   ```
   feat(m3): add restock and correction writers

   Phase 2 — Writers. RestockService and CorrectionService append to the
   shared ledger under the pool lock and announce after commit; replays
   are idempotent and corrections around expiry are covered.

   Tasks: T010-T015
   Checkpoint 2: php artisan test passes (212 tests).
   ```
4. **Commit only; do not push** unless the user asks. Do not amend earlier commits, skip hooks (`--no-verify`) or rewrite history. If a hook fails, fix the cause and make a new commit.
5. Report the commit hash and subject line in the phase report.

## Do not
- Work outside the selected scope or the files a task names (without asking).
- Bypass a rule (or boundary, if defined) because it is inconvenient; ask instead.
- Mark a task done on assumption.
