Shell: see `runtime_context.shell.backend` — `powershell` (Windows) or `bash` (Linux/macOS).

For file reads use `read_file`; for code search use `search_files` (mode: content or path). These are encoding-safe and need no shell.

In bash, prefer `rg` over grep/Select-String — cross-platform, gitignore-aware, handles encoding. `jq` is available for JSON work.
