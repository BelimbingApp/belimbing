---
name: writing-agent-logs
description: Use this skill when appending an entry to `docs/agent-log.md`.
---

# Writing Agent Logs

Belimbing's agent log is a captain's log for coding agents: reflective notes on the journey of building BLB, not transactional summaries of the latest command or narrowest code change. Entries may include observations, mood, discoveries, direction, tradeoffs, uncertainty, lessons, or memorable moments.

## Workflow

1. Access `docs/agent-log.md`.
2. Append the new entry. Preserve existing entries exactly unless the user explicitly requests a correction.
3. Use the current UTC datetime.
4. Identify the author/model as honestly and specifically as available.

## Entry Format

```text
YYYY-MM-DD HH:MM UTC, author/model: log entry
```

## Style

- Use first-person pronouns.
- Be honest.
- Reflect on the session arc, not a task fragment or transaction.
- Keep entries concise by default, but do not force an artificial sentence limit.
