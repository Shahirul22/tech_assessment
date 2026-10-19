---
name: workflow-plan
description: Create the Plan/Design for one slice from its approved PRD using workflow/templates/plan.md — architecture, data model, contracts, decisions, test strategy and a task outline. Use when the user asks to "create the plan", "design M1", or "plan/design for the slice".
---

# workflow-plan — PRD → Plan/Design

Produces the **how**, including the task outline that `workflow-tasks` expands.

## Steps
1. **Identify the slice** from the request; folder is `workflow/slices/<id>-<name>/`.
2. **Gate check.** `prd.md` must exist and have `Status: Approved`, and no Blocking open question may remain unresolved. If not, stop and tell the user what is missing. Continue only if the user explicitly says to proceed anyway, and then record that in plan section 11.
3. **Read inputs:**
   - the slice's `prd.md` (fully)
   - the relevant parts of `requirement.md` (§0, §4 rules, §5 risks and direction, §8 foundation notes)
   - `workflow/templates/plan.md` — follow structure and comment rules
   - `workflow/rules/*.md`, plus `workflow/constitution.md` / `workflow/boundaries.md` if they exist. All optional; if none exist, section 2 says "No rules defined".
   - other slices' plans, and the existing code in the repo if any (use CodeGraph per the user's global instructions; if no `.codegraph/` exists, run `codegraph init` first). Reuse existing patterns and respect existing contracts.
4. **Rules check (before design).** Fill plan section 2 against every applicable rule (and constitution/boundaries if present). Fix or flag violations.
5. **Design** into `<slice-folder>/plan.md`:
   - Pick the stack/structure only as far as this slice needs; honour constraints in `requirement.md` (e.g. OQ5 requires transactional DB support). Record each real choice in the decisions table with rejected alternatives.
   - Use concrete file paths, schemas, signatures, and short snippets for contracts. Where later slices depend on this one (see `requirement.md` §8 "Foundation"), define those contracts now.
   - Map every PRD FR/NFR/AC to a design element (section 8) and a test (section 9). The PRD's hard rules get explicit, named tests.
   - Write the **task outline** (section 10): phases in dependency order, each line one task-sized unit, `[P]` for parallel-safe, checkpoints a human can demo.
   - Unknowns → `[NEEDS CLARIFICATION]` or a research item; never guess.
6. **Re-run the rules check** at the end; update section 2.
7. **Self-check:** no design element without a PRD ID; no PRD ID unmapped; no new requirements added (if the design reveals a missing requirement, report it for a PRD change rather than adding it here); outline covers every contract and test named.
8. Set `Status: Draft`. **Report:** path, key decisions and any real trade-offs the user should weigh (heading / context / example / bolded proposal format), rules-check result, next step.

## Do not
- Change the PRD or `requirement.md`; raise issues instead.
- Mark the plan Approved.
- Write implementation code.
