import os
import shutil
import subprocess
from pathlib import Path
from typing import Any, Sequence


def git_test_env() -> dict[str, str]:
    env = os.environ.copy()
    env.update(
        {
            "GIT_AUTHOR_NAME": "t",
            "GIT_AUTHOR_EMAIL": "t@t",
            "GIT_COMMITTER_NAME": "t",
            "GIT_COMMITTER_EMAIL": "t@t",
        }
    )

    return env


def seed_git_checkout(base: Path, env: dict[str, str] | None = None) -> tuple[Path, Path]:
    child_env = env or git_test_env()

    bare = base / "canonical.git"
    subprocess.run(["git", "init", "-q", "--bare", str(bare)], check=True)
    subprocess.run(
        ["git", "--git-dir", str(bare), "symbolic-ref", "HEAD", "refs/heads/main"],
        check=True,
        env=child_env,
    )

    seed = base / "seed"
    subprocess.run(["git", "init", "-q", "-b", "main", str(seed)], check=True, env=child_env)
    (seed / "README").write_text("base\n", encoding="utf-8")
    subprocess.run(["git", "add", "."], cwd=seed, check=True, env=child_env)
    subprocess.run(["git", "commit", "-q", "-m", "base"], cwd=seed, check=True, env=child_env)
    subprocess.run(["git", "remote", "add", "origin", str(bare)], cwd=seed, check=True, env=child_env)
    subprocess.run(["git", "push", "-q", "-u", "origin", "main"], cwd=seed, check=True, env=child_env)

    clone = base / "checkout"
    subprocess.run(["git", "clone", "-q", str(bare), str(clone)], check=True, env=child_env)

    return bare, clone


def make_executable(path: Path) -> None:
    path.chmod(path.stat().st_mode | 0o100)


def copy_executable_scripts(source_dir: Path, target_dir: Path, names: Sequence[str]) -> None:
    target_dir.mkdir()

    for name in names:
        source = source_dir / name
        destination = target_dir / name
        shutil.copy2(source, destination)
        make_executable(destination)


def _git_root() -> Path | None:
    """Return the installation root for the Git executable on PATH."""
    git = shutil.which("git")
    if git is None:
        return None

    return Path(git).resolve().parent.parent


def _git_tool_executable(tool: str) -> str | None:
    """Resolve a Git-for-Windows tool when it is not itself on PATH."""
    git_root = _git_root()
    if git_root is None:
        return None

    for directory in ("bin", "usr/bin"):
        for suffix in ("", ".exe"):
            candidate = git_root / directory / f"{tool}{suffix}"
            if candidate.is_file():
                return str(candidate)

    return None


def _bash_executable() -> str:
    """Resolve Bash for POSIX hosts and ordinary Git-for-Windows installs."""
    # On Windows, `bash` may resolve to the WSL launcher in System32 even
    # though Git-for-Windows is installed. Prefer the Git installation so the
    # shell and the companion coreutils come from the same environment.
    git_bash = _git_tool_executable("bash")
    if git_bash is not None:
        return git_bash

    bash = shutil.which("bash")
    if bash is not None:
        return bash

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
    if kwargs.get("text") or kwargs.get("universal_newlines"):
        kwargs.setdefault("encoding", "utf-8")

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
