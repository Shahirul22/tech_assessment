# Laravel Boost

Applies to: all Laravel/PHP/Blade/test work in this repo.

Checkable rules (the same six as the "Project rules" section of `CLAUDE.md`):

1. Before writing or changing any Laravel code (migrations, models, factories, controllers, middleware, config, routes, Blade, Pest tests, events/broadcasting, Reverb/Echo setup), Boost's `search-docs` was called for the installed package versions, and Boost's guidelines were followed.
2. The real schema was inspected with Boost's `database-schema` / `database-query` instead of guessing; behaviour was checked with Boost's tinker; `last-error` and `browser-logs` were used when debugging.
3. Boost's `application-info` was called at the start of the session.
4. If the Boost MCP server was unreachable, the stack was started (`docker compose up -d --wait`) and the call retried. Work did not continue without it and did not silently fall back to memory.
5. Every task completion note in `tasks.md` names the Boost documentation query that was used.
6. Boost guidance never overrides `requirement.md` §0, the approved PRD or the approved plan. On a conflict, stop and ask.
