# Development Workflow

A staged, spec-driven process: **requirement → PRD → Plan/Design → Tasks → Implementation.** Each stage is a markdown document derived from the one before it, reviewed by a human before the next begins. Agents do the drafting and the building; humans hold the gates.

```
requirement.md ──/workflow-prd──> prd.md ──/workflow-plan──> plan.md ──/workflow-tasks──> tasks.md ──/workflow-implement──> code
                                   ▲ gate 1                   ▲ gate 2                    ▲ gate 3
                                   └──────────── rules/* (if any) apply to every stage ────────────┘
```

## Folder map
| Path | What it is |
|---|---|
| `requirement.md` (repo root) | Source of truth. §0 is the verbatim client brief. Never edited by the workflow. |
| `workflow/rules/` | Optional topic rules (style, testing, API...). |
| *(not yet created)* `workflow/constitution.md`, `workflow/boundaries.md` | Optional later additions: principles, and always / ask-first / never limits. Skills pick them up automatically if they exist. |
| `workflow/templates/` | `prd.md`, `plan.md`, `tasks.md` templates. Skills fill these in. |
| `workflow/slices/<id>-<name>/` | One folder per slice (e.g. `m1-shared-pool/`) holding that slice's `prd.md`, `plan.md`, `tasks.md`. |
| `.claude/skills/workflow-*/` | The four skills that drive the process. |

A **slice** is a vertical piece of delivery that ends in something usable. For this project they are the milestones M1–M4 in `requirement.md` §8.

## How to use it

### Optional setup
Add files under `workflow/rules/` if you want standing rules. Nothing else is needed to start.

### Per slice
| Step | You say | Skill | Output | Your gate |
|---|---|---|---|---|
| 1 | "Create a PRD for M1" | `/workflow-prd` | `slices/m1-.../prd.md` | Resolve blocking open questions, set Status → Approved |
| 2 | "Create the plan for M1" | `/workflow-plan` | `plan.md` | Check design, rules check, task outline; Status → Approved |
| 3 | "Create the tasks for M1" | `/workflow-tasks` | `tasks.md` | Check coverage table and task sizing; Status → Approved |
| 4 | "Implement M1" / "Implement T003" / "Implement phase 2" | `/workflow-implement` | Code + ticked tasks | Review at each checkpoint |

Each skill refuses to run if the previous document is missing or not Approved (it will tell you and offer to continue anyway only if you say so).

### Status convention
- Documents: `Draft` → `Approved`. Only a human flips this (or tells the agent to).
- Tasks: `[ ]` todo · `[~]` in progress · `[x]` done · `[!]` blocked.

## Principles behind the templates
Drawn from GitHub Spec Kit, Kiro/EARS, Addy Osmani's spec guidance and similar agent-oriented practice:
- **What vs how are separate.** PRD has no tech choices; the plan has no new requirements.
- **Stable IDs everywhere** (FR1, NFR2, AC-US1.1, DD1, T003) so every line traces back to a requirement and no requirement goes uncovered.
- **No guessing.** Gaps become `[NEEDS CLARIFICATION]` / open questions, not assumptions buried in prose.
- **Checkable acceptance.** Every requirement ends in a pass/fail statement or a command.
- **Explicit non-goals** keep the agent inside the slice.
- **Small atomic tasks** with dependencies, file paths and a Verify line.
- **Docs follow reality.** If implementation forces a change, it is logged in the plan's change log, not silently drifted.

## Precedence when documents disagree
`rules > requirement.md §0 > PRD > plan > tasks.` (If a constitution/boundaries file is added later, it ranks above rules.) Stop and ask rather than choosing silently.

## Changing the workflow itself
Edit the templates in `workflow/templates/`; skills read them at run time, so there is nothing to regenerate.
