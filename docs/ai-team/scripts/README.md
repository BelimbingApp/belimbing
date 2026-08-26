# AI-team mechanisms

These scripts enforce the reusable operating guide. Copy this directory with
`../README.md` when adopting the model in another GitHub repository.

Most scripts are repository-independent and resolve the current GitHub
repository through `gh`. `project-orient.sh` is the deliberate exception: it is
the local hook for source pins, assembly checks, and project commands. Replace
or remove it when copying the package.

The scheduled blocked-task workflow remains under `.github/workflows/` because
GitHub owns its trigger and permissions; its implementation and tests live here
with the board contract they enforce.

`blocked_by_sweep.py` is a Python entry point, not a shell command. Run it as
`python3 docs/ai-team/scripts/blocked_by_sweep.py` (the workflow supplies the
required `GITHUB_REPOSITORY` and `GITHUB_TOKEN` environment variables).

## Running the mechanism tests

```bash
# Linux / macOS
python3 -m unittest discover -s docs/ai-team/scripts -p 'test_*.py'

# Windows (PowerShell or Git Bash — the harness resolves Git for Windows' Bash;
# use `python` because `python3` may be the Store alias that exits immediately)
python -m unittest discover -s docs/ai-team/scripts -p 'test_*.py'
```

They are hermetic — stubbed `gh`, a `git` shim for the origin-identity answer,
and local bare repositories instead of the network — and run in CI as part of
the `quality` job, so a gate or sweep regression fails a required check.
