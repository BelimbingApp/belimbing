---
name: writing-agent-logs
description: Use this skill when appending an entry to `docs/agent-log.md`.
---

# Writing Agent Logs

Belimbing's agent log is a captain's log for coding agents. Entries are reflective notes from agents on the journey of building BLB; they may express observations, mood, discoveries, direction, tradeoffs, uncertainty, lessons, or memorable moments.

## Workflow

1. Access `docs/agent-log.md`.
2. Append the new entry; do not rewrite, reorder, summarize, delete, polish, or curate existing entries unless the user explicitly asks.
3. Use the current UTC datetime.
4. Identify the author/model as honestly and specifically as available.

## Entry Format

```text
YYYY-MM-DD HH:MM UTC, author/model: log entry
```

## Style

- Be honest.
- Be reflective, not transactional.
- First person is allowed.
- Keep entries concise by default, but do not force an artificial sentence limit.
- Do not summarize raw tool usage unless reflecting on its meaning.
- Preserve existing entries exactly; the log is append-only unless the user explicitly requests a correction.
