"""
docker_runner.py — Build and run student code inside a locked-down Docker container.

Security hardening applied to every container:
  --network=none            No internet access
  --memory / --memory-swap  RAM cap, no swap bypass
  --cpus                    CPU throttle
  --read-only               Immutable root filesystem
  --tmpfs /tmp              Writable scratch space, no exec bit
  --security-opt no-new-privileges
  --cap-drop ALL            Strip all Linux capabilities
  --pids-limit              Prevent fork bombs
  --user 1000:1000          Non-root execution
"""

import subprocess
import logging
import re
from dataclasses import dataclass, field
from pathlib import Path

logger = logging.getLogger("grader.docker_runner")


# ── Result dataclass ──────────────────────────────────────────────────────────
@dataclass
class DockerRunResult:
    exit_code:  int
    stdout:     str
    stderr:     str
    timed_out:  bool  = False
    image_tag:  str   = ""

    @property
    def success(self) -> bool:
        return self.exit_code == 0 and not self.timed_out

    @property
    def combined_output(self) -> str:
        parts = []
        if self.stdout.strip():
            parts.append(f"[stdout]\n{self.stdout.strip()}")
        if self.stderr.strip():
            parts.append(f"[stderr]\n{self.stderr.strip()}")
        if self.timed_out:
            parts.append("[error] Container execution timed out")
        return "\n".join(parts) or "(no output)"


class DockerRunError(Exception):
    """Raised on unrecoverable Docker errors (build failure, daemon unreachable, etc.)"""


# ── Dockerfile templates ──────────────────────────────────────────────────────
_DOCKERFILE_PYTHON = """\
FROM python:3.11-slim

# Create non-root user
RUN useradd -m -u 1000 student

WORKDIR /app
COPY . .

# Install dependencies if present
RUN if [ -f requirements.txt ]; then \
        pip install --no-cache-dir --timeout 60 -r requirements.txt; \
    fi

USER student
CMD ["python", "main.py"]
"""

_DOCKERFILE_NODE = """\
FROM node:18-alpine

RUN adduser -D -u 1000 student

WORKDIR /app
COPY . .

RUN if [ -f package.json ]; then npm ci --quiet 2>/dev/null || npm install --quiet; fi

USER student
CMD ["node", "index.js"]
"""

_DOCKERFILE_JAVA = """\
FROM eclipse-temurin:17-jdk-alpine

RUN adduser -D -u 1000 student

WORKDIR /app
COPY . .

RUN find . -name "*.java" | xargs javac 2>/dev/null || true

USER student
CMD ["java", "Main"]
"""

_LANGUAGE_DOCKERFILES: dict[str, str] = {
    "python": _DOCKERFILE_PYTHON,
    "node":   _DOCKERFILE_NODE,
    "java":   _DOCKERFILE_JAVA,
}


# ── Language detection ────────────────────────────────────────────────────────
def _detect_language(project_dir: str) -> str:
    p = Path(project_dir)
    if list(p.glob("*.py")) or (p / "requirements.txt").exists():
        return "python"
    if (p / "package.json").exists() or list(p.glob("*.js")):
        return "node"
    if list(p.glob("*.java")):
        return "java"
    return "python"   # safe default


# ── Docker runner ─────────────────────────────────────────────────────────────
class DockerRunner:
    def __init__(
        self,
        submission_id: str,
        project_dir:   str,
        timeout:       int   = 30,
        memory:        str   = "128m",
        cpu_quota:     float = 0.5,
        language:      str | None = None,
    ):
        self.submission_id = submission_id
        self.project_dir   = project_dir
        self.timeout       = min(timeout, 120)
        self.memory        = memory
        self.cpu_quota     = cpu_quota
        self.language      = language or _detect_language(project_dir)
        self.image_tag     = f"grader_{submission_id}"

    # ── Build ─────────────────────────────────────────────────────────────
    def _build(self) -> None:
        dockerfile_path = Path(self.project_dir) / "Dockerfile"

        # Auto-generate Dockerfile if student didn't provide one
        if not dockerfile_path.exists():
            template = _LANGUAGE_DOCKERFILES.get(self.language, _DOCKERFILE_PYTHON)
            dockerfile_path.write_text(template)
            logger.info(
                f"[{self.submission_id}] Auto-generated {self.language} Dockerfile"
            )

        logger.info(f"[{self.submission_id}] Building image {self.image_tag}")

        result = subprocess.run(
            ["docker", "build", "-t", self.image_tag, "--no-cache", "."],
            cwd=self.project_dir,
            capture_output=True,
            text=True,
            timeout=120,
        )

        if result.returncode != 0:
            raise DockerRunError(
                f"Docker build failed (exit {result.returncode}):\n"
                f"{result.stderr[:1000]}"
            )

        logger.info(f"[{self.submission_id}] Image built successfully")

    # ── Run ───────────────────────────────────────────────────────────────
    def _run(self) -> DockerRunResult:
        logger.info(
            f"[{self.submission_id}] Running container "
            f"(mem={self.memory}, cpu={self.cpu_quota}, timeout={self.timeout}s)"
        )

        cmd = [
            "docker", "run",
            "--rm",                                        # auto-remove after exit
            "--name",    self.image_tag,                   # named for force-kill
            f"--memory={self.memory}",                     # RAM cap
            "--memory-swap=0",                             # disable swap
            f"--cpus={self.cpu_quota}",                    # CPU cap
            "--network=none",                              # NO internet
            "--read-only",                                 # immutable rootfs
            "--tmpfs",   "/tmp:size=32m,noexec,nosuid",    # writable /tmp, no exec
            "--security-opt", "no-new-privileges",         # no privilege escalation
            "--cap-drop", "ALL",                           # drop all capabilities
            "--pids-limit", "64",                          # no fork bombs
            "--user",    "1000:1000",                      # non-root
            self.image_tag,
        ]

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=self.timeout,
            )
            return DockerRunResult(
                exit_code  = result.returncode,
                stdout     = result.stdout[:20_000],   # cap log size
                stderr     = result.stderr[:10_000],
                timed_out  = False,
                image_tag  = self.image_tag,
            )

        except subprocess.TimeoutExpired:
            logger.warning(f"[{self.submission_id}] Container timed out — force-killing")
            subprocess.run(
                ["docker", "kill", self.image_tag],
                capture_output=True,
                timeout=5,
            )
            return DockerRunResult(
                exit_code = -1,
                stdout    = "",
                stderr    = "",
                timed_out = True,
                image_tag = self.image_tag,
            )

    # ── Cleanup ───────────────────────────────────────────────────────────
    def _remove_image(self) -> None:
        try:
            subprocess.run(
                ["docker", "rmi", "-f", self.image_tag],
                capture_output=True,
                timeout=15,
            )
            logger.info(f"[{self.submission_id}] Image {self.image_tag} removed")
        except Exception as exc:
            logger.warning(f"[{self.submission_id}] Image removal warning: {exc}")

    # ── Public entry point ────────────────────────────────────────────────
    def run(self) -> DockerRunResult:
        """Build → run → cleanup. Always removes the image."""
        try:
            self._build()
            return self._run()
        except DockerRunError:
            raise
        except Exception as exc:
            raise DockerRunError(f"Unexpected runner error: {exc}") from exc
        finally:
            self._remove_image()
