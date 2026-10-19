---
name: workflow-prd
description: Create the PRD for one slice (e.g. a milestone like M1) from requirement.md using workflow/templates/prd.md. Use when the user asks to "create a PRD", "write the PRD for M2", or starts a new slice. Output goes to workflow/slices/<id>-<name>/prd.md.
---

# workflow-prd — requirement → PRD

Produces the **what and why** for one slice. No tech choices; those belong in the plan.

## Steps
1. **Identify the slice.** Take it from the user's request (e.g. "M1"). If ambiguous, ask which. Folder: `workflow/slices/<id>-<kebab-name>/` (e.g. `m1-shared-pool`). Create it if missing. If `prd.md` already exists, read it and update rather than overwrite; tell the user you are revising.
2. **Read inputs:**
   - `requirement.md` fully — §0 (verbatim brief) is the source of truth; also the slice's entry in §8, plus the FR/NFR, decisions (D), rules (§4), open questions (OQ) and assumptions (A) that touch it.
   - `workflow/templates/prd.md` — follow its structure and its HTML-comment rules exactly.
   - `workflow/rules/*.md`, and `workflow/constitution.md` / `workflow/boundaries.md` if they exist (skim; the PRD must not contradict them). None of these are required; if absent, continue.
   - PRDs of other slices, if any, for hand-offs.
3. **Draft the PRD** into `<slice-folder>/prd.md`:
   - Copy IDs from `requirement.md` so each line traces back. Mark anything you infer `[I]`.
   - Keep it to this slice: put everything else in Non-goals with the slice where it lands.
   - Turn the slice's "Done when" list into concrete Given/When/Then acceptance criteria with numbers. Add edge cases (concurrency, boundaries, failure, empty states).
   - Carry in every open question the slice depends on (e.g. M2 needs OQ3/OQ4, M3 needs OQ1). Mark each Blocking or not and give the proposal from `requirement.md`. Never resolve an OQ yourself; "Proposed" is not "Resolved".
   - Fill the traceability table. Every FR/NFR needs at least one AC.
   - Set `Status: Draft`.
4. **Self-check** before reporting: no tech/stack decisions; no requirement without an AC; no AC without a requirement; every unknown is a marked question; nothing from outside the slice sneaked in; no edits made to `requirement.md`.
5. **Report** briefly: file path, the blocking open questions the user must decide (use the heading / context / example / bolded proposal format from the user's global CLAUDE.md), and the next step ("approve, then ask me for the plan").

## Do not
- Edit `requirement.md`.
- Invent requirements or silently pick answers to open questions.
- Mark the PRD Approved.
