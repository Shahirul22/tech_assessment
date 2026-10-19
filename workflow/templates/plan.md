<!--
PLAN / DESIGN TEMPLATE — the HOW for one slice, derived from its approved PRD. Includes the task outline; the full task list lives in tasks.md.
Rules for whoever fills this in:
- Read prd.md and workflow/rules/* (if any) first. The plan may not contradict them.
- Every design element must trace to a PRD ID (FR/NFR/AC). A design with no requirement behind it is scope creep — remove it.
- Record each real choice with the alternatives rejected and why. "We use X" with no reasoning is not a decision.
- Be specific: real file paths, real signatures, real schemas. Include small code/SQL/JSON snippets for contracts. Show, don't describe.
- Unknowns → [NEEDS CLARIFICATION: ...] or a time-boxed research item. Do not guess.
- Delete these comments when filling in.
-->

# Plan: <slice id> — <slice name>

| Field | Value |
|---|---|
| Slice | <e.g. M1> |
| PRD | <link to prd.md> |
| Status | Draft / Approved (human gate before tasks.md is written) |
| Date | <YYYY-MM-DD> |

## 1. Approach summary
<One paragraph: the shape of the solution and the single most important design idea.>

## 2. Rules check
<!-- Gate. Run before design (section 3+) and re-run at the end against workflow/rules/*. A violation must be fixed or explicitly justified in section 11 with human sign-off. If there are no rules yet, write "No rules defined" and skip the table. -->
| Rule (cite file / ID) | Complies? | How |
|---|---|---|
| <C-1 ...> | Yes / No / N/A | <one line> |

## 3. Technical context
| Item | Choice |
|---|---|
| Language / runtime | |
| Framework(s) | |
| Storage | |
| Testing | |
| Target platforms | |
| Constraints (perf, size, offline, etc.) | |

## 4. Architecture
<Components and their responsibilities, how data flows, where the hard rules are enforced. A small diagram (mermaid or ASCII) is welcome.>

### Project structure (files this slice creates or changes)
```
<path/>
├── <file>   # purpose
```

## 5. Data model
<Entities, fields, types, constraints, indexes. Note which fields later slices depend on. SQL/schema snippet preferred.>

## 6. Interface contracts
<!-- APIs, events, function signatures that other code or slices depend on. One entry each: request, response, error cases, idempotency/auth behaviour. -->
### <METHOD /path or functionName(...)>
- Purpose / PRD refs:
- Request:
- Response:
- Errors:

## 7. Key design decisions
| ID | Decision | Alternatives considered | Why this one | PRD refs |
|---|---|---|---|---|
| DD1 | | | | |

## 8. Requirement-to-design traceability
| PRD ID | Where it is handled (component / contract / decision) |
|---|---|
| FR1 | |
| NFR1 | |

## 9. Test strategy
| Level | What it proves | Maps to AC | Notes (clock injection, fixtures, concurrency harness...) |
|---|---|---|---|
| Unit | | | |
| Integration / API | | | |
| End-to-end / manual | | | |

<!-- The hard rules from the PRD (concurrency, boundaries, money-like invariants) need their own explicit tests named here. -->

## 10. Task outline
<!-- Phases in dependency order. 3–7 phases. Each line = one future task in tasks.md, small enough for one agent session (roughly ≤ 1–3 files, one verifiable outcome). Mark [P] if independent of its siblings. tasks.md expands these into full task cards. -->
- **Phase 0 — Setup:** <T-lines>
- **Phase 1 — Foundation (blocks everything after):** <...>
- **Phase 2 — <Story US1> (P1):** <...>
- **Phase N — Hardening and docs:** <...>

**Checkpoints:** <after which phase can a human run/demo something, and what they should see>

## 11. Risks, deviations and open items
| Item | Type (risk / deviation / open) | Mitigation or justification | Owner / status |
|---|---|---|---|

## 12. Change log
<!-- Appended by the implementer when reality diverges from this plan. Never rewrite history above; add a dated line. -->
| Date | Change | Reason | Approved by |
|---|---|---|---|
