---
description: Capture the most valuable reusable lesson from this session and persist it durably (memory or a skill).
argument-hint: "(optional) a hint at what to capture"
---

Review THIS session and extract the **single most valuable, reusable insight** worth
keeping. $ARGUMENTS is an optional hint about what to capture.

## Filter first (most sessions produce nothing worth saving)

Keep it only if a future session would genuinely benefit and it is **not already**
recorded in the code, git history, the ADRs, `docs/`, or `CLAUDE.md`. Skip trivial
one-off fixes, anything obvious from the repo, and anything that only mattered to this
conversation. If nothing clears the bar, say so and stop — do not manufacture a lesson.

## Then choose where it lives

- **A durable fact** — about the user, a piece of guidance/feedback they gave, ongoing
  project state, or an external resource → write it to the **memory system** at
  `C:\Users\Osman\.claude\projects\C--Users-Osman-Desktop-claude\memory\` following the
  memory format (frontmatter `name` / `description` / `metadata.type` of
  `user|feedback|project|reference`; for feedback/project add **Why:** and **How to
  apply:**), and add a one-line pointer to `MEMORY.md`. Link related memories with
  `[[name]]`. Prefer updating an existing memory over creating a near-duplicate.
- **A reusable multi-step workflow or house style** (something you'd want to *do the same
  way* next time, not just *know*) → propose a project **skill** under `.claude/skills/`.

## Confirm before writing

Show the user the distilled lesson and where you'll put it (memory vs skill, and the
type), and get a yes before saving. One good, findable entry beats three vague ones.
