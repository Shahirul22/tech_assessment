# Rules

Optional, topic-specific rules the implementer must follow (e.g. `coding-style.md`, `testing.md`, `api-conventions.md`, `ui-accessibility.md`). One markdown file per topic. If this folder has no other files, there are no rules yet and the skills carry on without them.

Each file: start with a one-line scope ("Applies to: ..."), then short, checkable rules. Prefer one real code snippet over paragraphs of description. Skills read every `*.md` file in this folder (except this README).

Precedence when sources conflict: **rules > requirement.md §0 > PRD > plan > tasks.** If two conflict at the same level, stop and ask.

A constitution (principles) and boundaries (always / ask first / never) can be added later as `workflow/constitution.md` and `workflow/boundaries.md`. Skills use them automatically if present, and they outrank rules.
