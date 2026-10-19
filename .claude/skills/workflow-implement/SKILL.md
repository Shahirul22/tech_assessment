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
7. Follow the commit policy in tasks.md Conventions. Commit only if the user asked or the policy says so.

## 3. Deviations
Reality sometimes disagrees with the docs:
- **Small, within the task's intent** (naming, helper extraction): proceed; mention it in the task note.
- **Changes a contract, schema, dependency, scope, or any doc content:** stop, explain, propose. After the user approves, update the document and add a dated row to the plan's **Change log**. Never edit PRD/plan silently, and never edit `requirement.md`.
- **Task is blocked** (missing decision, failing prerequisite): mark `[!]` with the reason and needed input, list it under "Blocked / deferred", and continue with independent tasks only.
- **Discovered work not in the task list:** do not do it. Report it so a task can be added.

## 4. Checkpoints and finishing
- At the end of a phase, run its checkpoint check and report what a human can now run and what they should see. **Stop and wait** for review before the next phase unless the user said to continue.
- When all tasks are `[x]` and the slice's AC pass, set tasks.md `Status: Done` only if the user agrees; point to any `Done when` items from `requirement.md` that were verified.
- Final report each run: tasks completed (IDs), verification output summary, deviations, blocked items, next task.

## Do not
- Work outside the selected scope or the files a task names (without asking).
- Bypass a rule (or boundary, if defined) because it is inconvenient; ask instead.
- Mark a task done on assumption.
