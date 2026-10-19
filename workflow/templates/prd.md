<!--
PRD TEMPLATE — the WHAT and WHY for one slice. No tech choices (stack, schema, libraries): those belong in plan.md.
Rules for whoever fills this in (human or agent):
- Derive everything from requirement.md. Copy IDs (FR1, NFR2, D1, OQ1, A3 ...) so every line traces back. Never invent a requirement; if you must infer, mark it [I].
- Anything unknown or contested → [NEEDS CLARIFICATION: question]. Do not guess.
- Every requirement must be testable. Acceptance criteria are observable pass/fail statements, ideally Given/When/Then, with concrete numbers.
- Delete these comments and any section that truly does not apply (write "N/A — reason" rather than deleting silently).
-->

# PRD: <slice id> — <slice name>

| Field | Value |
|---|---|
| Slice | <e.g. M1> |
| Status | Draft / Approved (approval = human gate before plan.md is written) |
| Source | requirement.md §<sections> |
| Date | <YYYY-MM-DD> |
| Plan | <link once created> |

## 1. Summary
<2–4 sentences: who gets what, and why it matters. State the usable deliverable of this slice in one sentence.>

## 2. Goals and non-goals
**Goals**
- <outcome the user can observe>

**Non-goals (explicitly NOT in this slice)**
- <thing a reader might assume is included, and where it lands instead, e.g. "Expiry behaviour → M2">

## 3. Users and scenarios
| ID | Persona | Scenario (one sentence) | Priority (P1/P2/P3) |
|---|---|---|---|
| US1 | <Operator on a phone> | <what they do and the outcome they get> | P1 |

<!-- Each story must be independently demonstrable. P1 alone should already be a viable slice. -->

## 4. Functional requirements
| ID | Requirement (MUST / SHOULD, one testable behaviour each) | Source | Story |
|---|---|---|---|
| FR1 | The system MUST ... | requirement.md FR1 [S] | US1 |

## 5. Non-functional requirements
| ID | Requirement with measurable threshold | Source |
|---|---|---|
| NFR1 | <e.g. 50 parallel requests never drive the pool below 0> | requirement.md NFR1 |

## 6. Business rules and decisions that apply
<List the decisions (D#), rules and assumptions (A#) from requirement.md that constrain this slice, one line each, with the ID. Do not restate unrelated ones.>

## 7. Acceptance criteria
<!-- One block per story. Concrete data, observable result. The "Done when" list in requirement.md must appear here verbatim or sharpened. -->
**AC-US1.1** Given <state>, when <action>, then <observable result>.
**AC-US1.2** ...

**Edge cases that must be covered**
- <boundary / concurrency / failure / empty state, each with its expected result>

## 8. Open questions and assumptions
| ID | Question | Blocking? | Proposed answer / working assumption | Status |
|---|---|---|---|---|
| OQ1 | <copied from requirement.md> | Yes/No | <proposal> | Open / Resolved by <who, date> |

<!-- A blocking question must be Resolved before the PRD is Approved. A non-blocking one is recorded as an assumption and carried into the plan. -->

## 9. Dependencies and hand-offs
- Needs from other slices: <...>
- Provides to other slices: <contracts / foundations other slices rely on>

## 10. Traceability
| Requirement ID | Covered by acceptance criteria |
|---|---|
| FR1 | AC-US1.1 |

<!-- Every FR/NFR in sections 4–5 must appear here with at least one AC. A gap is a defect. -->
