<!--
TASKS TEMPLATE — the executable checklist for one slice, derived from plan.md section 10 (+ the PRD acceptance criteria).
Rules for whoever fills this in:
- One task = one verifiable outcome, small enough for a single agent session (about 1–3 files, ideally under ~1 hour of work). Split anything bigger.
- A task must be understandable on its own: an implementer reads the task, the files it names, and any rules — and can start. Do not rely on "see above".
- Every task cites the PRD/plan IDs it satisfies, lists real file paths, states dependencies, and has a Verify line that is a command or an observable check with a pass/fail result.
- Tests come before (or with) the code they prove. Hard-rule tests (concurrency, boundaries) are their own tasks.
- Every FR/NFR/AC in the PRD must be covered by at least one task (see coverage table). Every task must trace to at least one requirement or plan decision.
- Order: Setup → Foundation → one phase per user story (priority order) → Hardening. A phase ends in a checkpoint a human can run.
- [P] = can run in parallel with other [P] tasks in the same phase (different files, no dependency).
- Status markers: [ ] todo · [~] in progress · [x] done · [!] blocked (write the reason under the task).
- Delete these comments when filling in.
-->

# Tasks: <slice id> — <slice name>

| Field | Value |
|---|---|
| Slice | <e.g. M1> |
| PRD | <link> |
| Plan | <link> |
| Status | Draft / Approved / In progress / Done |
| Date | <YYYY-MM-DD> |

## Conventions
- Read before every task: `workflow/rules/*` (if any).
- Commit/stop policy: <e.g. one commit per phase, after its checkpoint passes; stop at each checkpoint for human review>.

## Phase 0 — Setup
### [ ] T001 <imperative title>
- **Refs:** <PRD/plan IDs, e.g. DD1, NFR3>
- **Depends on:** none
- **Files:** `<path>` (create), `<path>` (edit)
- **Do:** <2–5 lines: what to build, key signatures/behaviour, anything non-obvious. Name the pattern to follow in the existing code.>
- **Verify:** `<command>` → <expected result>
- **Done when:** <observable outcome, tied to an AC id if one exists>
- **Out of scope:** <what the implementer must not touch or add here>

## Phase 1 — Foundation
### [ ] T002 [P] <title>
- **Refs:**
- **Depends on:** T001
- **Files:**
- **Do:**
- **Verify:**
- **Done when:**

**Checkpoint 1:** <what a human can now run and the expected result>

## Phase 2 — <US1 name> (P1)
### [ ] T00X [US1] <title>
- **Refs:**
- **Depends on:**
- **Files:**
- **Do:**
- **Verify:**
- **Done when:**

**Checkpoint 2:** <demo + expected result>

## Phase N — Hardening and docs
### [ ] T0NN <title>
- ...

## Coverage
| PRD ID | Task(s) |
|---|---|
| FR1 | T00X |
| AC-US1.1 | T00X, T00Y |

<!-- Any PRD ID with an empty Task(s) cell is a gap: add a task or document why not. -->

## Blocked / deferred
| Task | Reason | Needs |
|---|---|---|
