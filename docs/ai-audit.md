# AI interaction audit

I used Claude Code (Sonnet 5.5) in the terminal and VS Code, with the Laravel Boost MCP server. This is a curated log, not a transcript. The full sessions are stored locally and I can show them on request.

## How I worked with the AI

Before writing any code I set up a small spec-driven process. Each step produces something I can check, so the AI's mistakes show up as failing tests or conflicting documents, not as surprises at the end.

```
requirement.md → /workflow-prd → prd.md → /workflow-plan → plan.md → /workflow-tasks → tasks.md → /workflow-implement → code
                                   gate 1                    gate 2                      gate 3             stop at each phase checkpoint
```

- Four skills live in `.claude/skills/workflow-*`, with templates in `workflow/templates/` and an index in [workflow/README.md](../workflow/README.md). I approve each document before the next stage runs.
- The implementer skill sets its own rules. A task counts as done only after its `Verify` command has run and passed. A test is never weakened, skipped or deleted to get green. A PRD or plan is never edited silently, and changes go in the plan's change log. Each task-list phase gets one commit.
- When documents disagree, the order is rules, then `requirement.md` §0, then PRD, then plan, then tasks. If that order does not settle it, the implementer stops and asks.
- I split the work into milestones M1 to M4. Each is a usable deliverable with its own PRD, plan and tasks in `workflow/slices/`, and M1 carried the shared foundation so later milestones did not rework it.
- Laravel Boost is a hard rule in `CLAUDE.md` and `workflow/rules/laravel-boost.md`. It gives the assistant the documentation for the installed versions (Laravel 13, Reverb, Pest 4), the real schema and the live app, so it does not answer from memory. Each task note names the Boost query it used.
- The skills turn ambiguity into questions for me. The PRD skill carries every open question forward, marks the blocking ones, and never answers one itself. The plan skill will not run while a blocking question is open, and unknowns are written as `[NEEDS CLARIFICATION]` instead of guessed. I also prompted for this directly ("identify ambiguities, missing business rules, and assumptions", later "present to me the open questions and the ambiguities").
- The decisions were mine. For each question the AI proposed options and I chose between them (see "Where I steered the AI").

### Limits of the process

The templates and skills come from public practice (GitHub Spec Kit, Kiro/EARS, spec-writing guidance) and are adapted to this repo, not written from scratch. I would normally spend more time defining them. I also removed the optional constitution and boundaries files to keep the setup small, so the limits on what the AI may do rest on the rules file and the skill alone.

### Sessions

| Stage | What happened |
|---|---|
| Requirement | Summary, functional and non-functional requirements, risks and 7 open questions, then my answers, recorded as D1 to D12 |
| Workflow | Built the workflow folder, templates and four skills |
| M1 | PRD, plan and tasks, then the shared pool, live updates and concurrency tests |
| M2 | PRD, plan and tasks, then expiry and status |
| M3 | PRD, plan and tasks, then restock, corrections and the audit view |
| M4 | PRD, plan and tasks, then the field-ready UI, hybrid retry and browser audits |

## Where the AI was naive or wrong, and how it was corrected

None of the corrections below came from me spotting a bug while reading code. They came from the process I set up, which is why I trust it more than reading the diff carefully.

### Case 1: the empty-ledger crash (M1, `PoolCalculator`)

To get the pool's current `version`, the AI took the highest `seq` across the ledger:

```php
return max(0, ...array_map(fn ($entry) => $entry->seq, [...$ledger->restocks, ...$ledger->allocations, ...$ledger->corrections]));
```

This is correct for every pool that has any history. With an empty ledger the spread produces nothing, so the call becomes `max(0)`. PHP rejects `max()` with a single non-array argument and throws a `TypeError`. A brand-new pool, the first thing anyone sees after a fresh migrate, would have failed on its first read.

The implementer wrote the calculator's tests before the calculator (the task note says "Test first"), and one of them covered the empty ledger. Once the calculator existed, the run came back `1 failed, 16 passed`. `it reads an empty ledger as nothing remaining at version 0` failed with the `TypeError`, and the AI reported it as a real bug.

The fix passes an array with a floor of 0: `max([0, ...array_map(...)])` ([PoolCalculator.php:76](../app/Pool/Calculation/PoolCalculator.php#L76)). The test stayed unchanged and passed (17 tests, then the whole suite). The code was wrong only at the empty edge, and the test-first rule found it before any screen could show it.

### Case 2: the task list that contradicted the acceptance criteria (M3, `StatusHistory`)

The AI wrote the M3 task list, and the plan's test table, from the PRD. For the audit's "status before and after" it put a worked example in the task's `Verify` line that does not match the PRD. The thresholds are standard from 0, elevated from 10 and high from 30. Ana logs Consumed 28 at 09:00 and Consumed 5 at 10:30, so she holds 33 (high). Dana lowers the first entry from 28 to 20, so Ana holds 25 (elevated).

| | Task text said | PRD AC-US6.1 says (the arithmetic agrees) |
|---|---|---|
| At the correction moment | elevated → elevated | high → elevated |
| Now | elevated → elevated, not listed | high → elevated, listed |

The task text described the 09:00 row (28 to 20, elevated both times) as if it were the correction moment. Tests copied from it would have asserted a wrong status and passed against code that was wrong in the same way.

The implementer worked out the numbers before writing the test and found that the task text disagreed with the PRD. Under the precedence rule (PRD above tasks) it followed the PRD and wrote `StatusHistoryTest` against AC-US6.1. It logged the discrepancy in plan §12 and in the task note instead of editing the document silently, and it raised the issue with me with a worked example. I asked it to elaborate before deciding.

I had approved the task list, so the slip passed my gate. The rules the implementer follows stopped it: precedence between documents, and stopping to ask instead of choosing silently. Both the plan's test table and the task's `Verify` line still carry the wrong sentence, and the discrepancy is on record in the plan's change log. The error started in an AI-written spec, and it was found because two documents could be compared.

### Smaller catches

- Mixed clocks (M1). A screen that went stale could never recover, because the submit flow and the gap timer used different clocks. Unit tests passed and only the browser run exposed it. A regression test now fails on the old code.
- Missing bind mount (M1 plan). The first plan did not mount the repo into the app container, which would have broken `APP php artisan test`, host edits and the files Boost writes. The AI fixed the plan and logged the change as "Pending user review" instead of making it silently.
- A schema change made without asking (M1). The AI added an `amend` branch to a migration constraint. It flagged the change, proposed keeping it, and waited for my approval.

## Where I steered the AI

In these decisions my answer changed what the AI proposed.

| Decision | What I said | Result |
|---|---|---|
| Overdrawn requests | Enforce on both sides and never go negative: grant what is left, reject the rest. "Is it better to reject fast?" | The client caps the request at what was last seen, and the server grants `min(requested, remaining)` (D2) |
| Queueing | "The nature of the business doesn't seem practical to implement a queueing system." | No waitlist and no offline queue (D5) |
| Raising a correction | "Raise needs a bit of correction: do not allow negative, refuse the correction." | Raises are refused unless the free units cover them, and lowering frees units at once (D7) |
| A question the AI raised | On its status question I asked: "was this mentioned in the requirement? justify it." | It answered "partly": the brief mentions status three times but never defines it. It laid out the reading and I chose it (D10) |
| Delivery shape | "I am not looking for one-shot development… each milestone should be a deliverable, not simply a skeleton." | M1 to M4 slices, with M1 carrying the shared foundation |
| Cost of live updates | "Do confirm Reverb is free of cost." | Self-hosted Reverb in Docker Compose (D12) |
| Size of the process | I asked what a constitution and boundaries file would need, then removed both for now | A smaller setup, with rules and precedence carrying the load |
| Retry behaviour (M4) | "Make the retry a hybrid: resend by itself up to 3 times, and when all fail present the manual retry." Later: "Shorten the resend timeout to 3 s." | Hybrid retry with the same idempotency key. Retry appears after about 7 s when the network fails at once |
| Commit policy | "Should be per phase, not per task, phase from a task doc." | The skill commits once per phase, so the history reads as one commit per phase |
| Tooling fault | "Investigate the Laravel Boost failed connection." | The Docker stack had stopped, so `boost:mcp` (which runs inside the app container) could not connect. The AI restarted the stack and confirmed Boost worked before planning continued, as the project rule requires |

## Where to look in the repo

- `workflow/slices/*/plan.md`, in the change-log section: dated entries for every place the implementation departed from a document.
- `git log`: one commit per task-list phase, each made after the phase's checks passed.
- `scripts/verify.sh`: the full check in order. The final run had 405 PHP tests, 210 JS tests, the 50-parallel-request check (p95 0.709 s) and the live-update check.
