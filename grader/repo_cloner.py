"""
repo_cloner.py — Secure GitHub/GitLab repo cloning.

Security:
  - Only HTTPS github.com / gitlab.com URLs allowed
  - No embedded credentials
  - No git@ / SSH
  - No localhost / private IPs
  - GIT_TERMINAL_PROMPT=0  → fails immediately if auth required
  - Shallow clone (--depth 1) for speed
"""

import re
import shutil
import subprocess
import logging
from pathlib import Path

logger = logging.getLogger("grader.repo_cloner")

# ── URL allow-list ─────────────────────────────────────────────────────────
_ALLOWED = re.compile(
    r"^https://(github\.com|gitlab\.com)/[\w\-\.]+/[\w\-\.]+/?$"
)

# ── Block patterns: credentials, SSH, local ───────────────────────────────
_BLOCKED = [
    re.compile(r"git@"),
    re.compile(r"://[^/]*:[^/]*@"),   # user:pass@host
    re.compile(r"localhost"),
    re.compile(r"127\.0\.0\.1"),
    re.compile(r"0\.0\.0\.0"),
    re.compile(r"192\.168\."),
    re.compile(r"10\.0\."),
]

# Minimal env for git — no SSH keys, no credential helpers, no prompts
_GIT_ENV = {
    "HOME":                 "/tmp",
    "PATH":                 "/usr/bin:/bin",
    "GIT_TERMINAL_PROMPT":  "0",
    "GIT_ASKPASS":          "echo",
}


class CloneError(Exception):
    """Raised when repo validation or cloning fails."""


def _validate(url: str) -> str:
    """Sanitise and validate the repo URL. Returns clean URL or raises CloneError."""
    url = url.strip().rstrip("/")

    for pattern in _BLOCKED:
        if pattern.search(url):
            raise CloneError(f"Blocked URL pattern in: {url}")

    if not _ALLOWED.match(url):
        raise CloneError(
            "Only public HTTPS GitHub / GitLab URLs are allowed. "
            f"Got: {url}"
        )

    # Normalise: strip .git suffix so we always work with the base URL
    return url.removesuffix(".git")


def clone_repo(repo_url: str, target_dir: str, timeout: int = 30) -> str:
    """
    Shallow-clone a public GitHub/GitLab repo into target_dir.

    Args:
        repo_url:   Public HTTPS URL (validated internally).
        target_dir: Local destination path (must not exist).
        timeout:    Max seconds for git clone.

    Returns:
        target_dir on success.

    Raises:
        CloneError on any failure.
    """
    clean_url = _validate(repo_url)
    dest      = Path(target_dir)

    if dest.exists():
        shutil.rmtree(dest)
    dest.mkdir(parents=True, exist_ok=True)

    clone_url = f"{clean_url}.git"
    logger.info(f"Cloning {clone_url} → {target_dir}")

    try:
        result = subprocess.run(
            [
                "git", "clone",
                "--depth", "1",         # shallow — faster, less disk
                "--single-branch",
                "--no-tags",
                "-q",
                clone_url,
                str(dest),
            ],
            capture_output=True,
            text=True,
            timeout=timeout,
            env=_GIT_ENV,
        )
    except subprocess.TimeoutExpired:
        shutil.rmtree(dest, ignore_errors=True)
        raise CloneError(f"git clone timed out after {timeout}s — repo too large?")

    if result.returncode != 0:
        err = result.stderr.strip()
        shutil.rmtree(dest, ignore_errors=True)

        if "not found" in err.lower() or "Repository not found" in err:
            raise CloneError("Repository not found — is it public?")
        if "Authentication failed" in err:
            raise CloneError("Private repository — only public repos are supported.")

        raise CloneError(f"git clone failed (exit {result.returncode}): {err[:400]}")

    files = list(dest.iterdir())
    if not files:
        raise CloneError("Clone succeeded but the directory is empty.")

    logger.info(f"Clone OK — {len(files)} root entries")
    return str(dest)


def checkout_commit(repo_dir: str, commit_sha: str, timeout: int = 15) -> None:
    """
    Checkout a specific commit inside an already-cloned repo.
    Requires fetching the full history first (unshallow).

    Args:
        repo_dir:   Path to the cloned repository.
        commit_sha: 7- or 40-char commit hash.
        timeout:    Max seconds per git command.

    Raises:
        CloneError if fetch or checkout fails.
    """
    dest = Path(repo_dir)
    if not dest.exists():
        raise CloneError(f"Repo directory does not exist: {repo_dir}")

    # Unshallow so the specific commit is reachable
    fetch = subprocess.run(
        ["git", "fetch", "--unshallow", "-q"],
        cwd=str(dest),
        capture_output=True,
        text=True,
        timeout=timeout,
        env=_GIT_ENV,
    )
    # fetch --unshallow fails if already full — that's fine
    if fetch.returncode != 0 and "already a complete repository" not in fetch.stderr:
        logger.warning(f"Unshallow warning: {fetch.stderr.strip()[:200]}")

    checkout = subprocess.run(
        ["git", "checkout", "-q", commit_sha],
        cwd=str(dest),
        capture_output=True,
        text=True,
        timeout=timeout,
        env=_GIT_ENV,
    )

    if checkout.returncode != 0:
        raise CloneError(
            f"git checkout {commit_sha} failed: {checkout.stderr.strip()[:300]}"
        )

    logger.info(f"Checked out commit {commit_sha}")
