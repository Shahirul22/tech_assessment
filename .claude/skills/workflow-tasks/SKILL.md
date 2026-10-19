---
name: workflow-tasks
description: Create the task list for one slice from its approved Plan/Design (and PRD) using workflow/templates/tasks.md — atomic, ordered, verifiable tasks with dependencies and coverage. Use when the user asks to "create the tasks", "break down the plan", or "task breakdown for M1".
---

# workflow-tasks — Plan → Tasks

Turns the plan's task outline into executable task cards.

## Steps
1. **Identify the slice**; folder is `workflow/slices/<id>-<name>/`.
2. **Gate check.** `plan.md` must exist with `Status: Approved` (and the PRD approved). Otherwise stop and say what is missing; continue only if the user explicitly says so.
3. **Read inputs:** `plan.md` and `prd.md` fully; `workflow/templates/tasks.md` (follow structure and comment rules); `workflow/rules/*.md`, plus `workflow/constitution.md` / `workflow/boundaries.md` if they exist (all optional; tasks must not require breaking them, and anything needing an "ask first" approval is flagged in the task); existing code via CodeGraph when it exists, so file paths and patterns named in tasks are real.
4. **Expand** each outline line (plan section 10) into a task card in `<slice-folder>/tasks.md`:
   - Sequential IDs (T001...), imperative title, `Refs` (PRD/plan IDs), `Depends on`, exact `Files` (create/edit), `Do` (what, key signatures, the pattern to follow), `Verify` (a runnable command or a precise observable check with expected result), `Done when` (tied to an AC id), `Out of scope` where scope creep is likely.
   - Size: one verifiable outcome, about 1–3 files. Split larger ones. Merge trivial ones.
   - Tests precede or accompany the code they prove. Each hard-rule test from plan section 9 is its own task.
   - Mark `[P]` only when files and dependencies are truly independent. Keep dependencies acyclic.
   - Keep the plan's phases and checkpoints. Foundation tasks that block everything come before story phases.
   - Each task must be understandable alone; do not write "as above".
5. **Coverage table:** every PRD FR/NFR/AC maps to at least one task, every task maps to at least one requirement or plan decision. Fix gaps by adding tasks, or by reporting a plan/PRD defect. Do not hide gaps.
6. **Self-check:** no dependency cycles; every Verify is checkable; no task silently adds scope beyond the plan; file paths match the plan's project structure.
7. Set `Status: Draft`. **Report:** path, task count per phase, checkpoints, any gaps or plan defects found, next step ("approve, then ask me to implement").

## Do not
- Change the PRD or plan to make tasks easier; report instead.
- Mark the task list Approved.
- Implement anything.
