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

## Running the mechanism tests

```bash
python3 -m unittest discover -s docs/ai-team/scripts -p 'test_*.py'
```

They are hermetic — stubbed `gh`, local bare repositories instead of the
network — and run in CI as part of the `quality` job, so a gate or sweep
regression fails a required check. On Windows, run them from Git Bash (or any
shell where `bash` is on PATH); the harness invokes the scripts through `bash`.
