import os
import shutil
import subprocess
from pathlib import Path
from typing import Any, Sequence


def _bash_executable() -> str:
    """Resolve Bash for POSIX hosts and ordinary Git-for-Windows installs."""
    bash = shutil.which("bash")
    if bash is not None:
        return bash

    git = shutil.which("git")
    if git is not None:
        git_root = Path(git).resolve().parent.parent
        for candidate in (git_root / "bin" / "bash.exe", git_root / "usr" / "bin" / "bash.exe"):
            if candidate.is_file():
                return str(candidate)

    raise FileNotFoundError("Bash is required to exercise the AI-team shell mechanisms")


def bash_path(path: Path) -> str:
    """Return a PATH entry that Bash can consume on POSIX and Windows."""
    resolved = str(path.resolve())
    drive, tail = os.path.splitdrive(resolved)

    if not drive:
        return Path(resolved).as_posix()

    return f"/{drive.rstrip(':').lower()}{tail.replace(os.sep, '/')}"


def run_with_bash_path(
    command: Sequence[str],
    *,
    stub_directory: Path,
    env: dict[str, str],
    **kwargs: Any,
) -> subprocess.CompletedProcess[str]:
    """Run a command under Bash with extensionless test shims first on PATH."""
    child_env = env.copy()
    child_env["AI_TEAM_TEST_STUB_PATH"] = bash_path(stub_directory)

    return subprocess.run(
        [
            _bash_executable(),
            "-c",
            'PATH="$AI_TEAM_TEST_STUB_PATH:$PATH"; export PATH; exec "$@"',
            "ai-team-test",
            *command,
        ],
        env=child_env,
        **kwargs,
    )
