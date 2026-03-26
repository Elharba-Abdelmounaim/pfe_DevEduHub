"""
main.py — DevEduHub Auto-Grading Service
FastAPI app that receives a GitHub repo from Laravel,
orchestrates cloning → Docker execution → test evaluation,
and returns structured grading results.
"""

import uuid
import shutil
import logging
import asyncio
from pathlib import Path
from datetime import datetime
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, HttpUrl, field_validator

from docker_runner import DockerRunner, DockerRunError
from tester import Tester
from repo_cloner import clone_repo, checkout_commit, CloneError

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s — %(message)s",
)
logger = logging.getLogger("grader.main")

# ── Shared temp directory ─────────────────────────────────────────────────────
WORKDIR = Path("/tmp/grader")
WORKDIR.mkdir(parents=True, exist_ok=True)


# ── Lifespan: clean up on shutdown ───────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    yield
    shutil.rmtree(WORKDIR, ignore_errors=True)
    logger.info("Cleaned up workdir on shutdown.")


# ── App ───────────────────────────────────────────────────────────────────────
app = FastAPI(
    title="DevEduHub Grader",
    description="Secure auto-grading microservice for DevEduHub",
    version="1.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],   # tighten to Laravel origin in production
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)


# ── Schemas ───────────────────────────────────────────────────────────────────
class GradeRequest(BaseModel):
    repo:          HttpUrl
    commit_sha:    str | None = None
    submission_id: str | None = None   # Laravel submission UUID — echoed back
    timeout:       int        = 30     # seconds, hard-capped at 120
    memory:        str        = "128m"

    @field_validator("commit_sha")
    @classmethod
    def validate_sha(cls, v):
        if v and (len(v) not in (7, 40) or not all(c in "0123456789abcdefABCDEF" for c in v)):
            raise ValueError("commit_sha must be a 7- or 40-char hex string")
        return v

    @field_validator("timeout")
    @classmethod
    def cap_timeout(cls, v):
        return min(v, 120)


class GradeResponse(BaseModel):
    submission_id:  str
    status:         str        # "success" | "failed" | "error"
    score:          float      # 0.0 – 100.0
    passed_tests:   int
    total_tests:    int
    logs:           str
    feedback:       str
    execution_time: float      # seconds
    graded_at:      str        # ISO-8601


# ── Health ────────────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {"status": "ok", "service": "DevEduHub Grader", "version": "1.0.0"}


# ── Grade endpoint ────────────────────────────────────────────────────────────
@app.post("/grade", response_model=GradeResponse)
async def grade(request: GradeRequest, background_tasks: BackgroundTasks):
    """
    Main grading pipeline:
      1. Clone the student's GitHub repo
      2. Checkout specific commit if provided
      3. Build + run a Docker sandbox
      4. Evaluate test results
      5. Return structured JSON to Laravel
    """
    submission_id = request.submission_id or f"sub_{uuid.uuid4().hex[:12]}"
    repo_url      = str(request.repo)
    work_path     = WORKDIR / submission_id

    logger.info(f"[{submission_id}] Grading started — {repo_url}")
    start = datetime.utcnow()

    try:
        # ── 1. Clone ─────────────────────────────────────────────────────
        logger.info(f"[{submission_id}] Cloning {repo_url}")
        await asyncio.to_thread(clone_repo, repo_url, str(work_path))

        if request.commit_sha:
            logger.info(f"[{submission_id}] Checking out {request.commit_sha}")
            await asyncio.to_thread(checkout_commit, str(work_path), request.commit_sha)

        # ── 2. Run in Docker sandbox ──────────────────────────────────────
        logger.info(f"[{submission_id}] Launching Docker sandbox")
        runner = DockerRunner(
            submission_id=submission_id,
            project_dir=str(work_path),
            timeout=request.timeout,
            memory=request.memory,
        )
        run_result = await asyncio.to_thread(runner.run)

        # ── 3. Evaluate tests ─────────────────────────────────────────────
        logger.info(f"[{submission_id}] Evaluating tests")
        tester  = Tester(submission_id=submission_id, run_result=run_result)
        results = tester.evaluate()

        elapsed = (datetime.utcnow() - start).total_seconds()

        response = GradeResponse(
            submission_id  = submission_id,
            status         = "success" if results["passed"] else "failed",
            score          = results["score"],
            passed_tests   = results["passed_tests"],
            total_tests    = results["total_tests"],
            logs           = run_result.combined_output,
            feedback       = results["feedback"],
            execution_time = round(elapsed, 3),
            graded_at      = datetime.utcnow().isoformat() + "Z",
        )

        logger.info(
            f"[{submission_id}] Done — score={results['score']}/100 "
            f"in {elapsed:.2f}s"
        )
        return response

    except CloneError as exc:
        logger.error(f"[{submission_id}] Clone failed: {exc}")
        raise HTTPException(status_code=422, detail=f"Repository clone failed: {exc}")

    except DockerRunError as exc:
        logger.error(f"[{submission_id}] Docker error: {exc}")
        raise HTTPException(status_code=500, detail=f"Execution error: {exc}")

    except asyncio.TimeoutError:
        logger.warning(f"[{submission_id}] Pipeline timed out")
        raise HTTPException(status_code=408, detail="Grading pipeline timed out")

    except Exception as exc:
        logger.exception(f"[{submission_id}] Unexpected error: {exc}")
        raise HTTPException(status_code=500, detail=f"Internal grader error: {exc}")

    finally:
        # Always remove temp files regardless of outcome
        background_tasks.add_task(_cleanup, str(work_path), submission_id)


def _cleanup(path: str, submission_id: str) -> None:
    try:
        shutil.rmtree(path, ignore_errors=True)
        logger.info(f"[{submission_id}] Temp files removed: {path}")
    except Exception as exc:
        logger.warning(f"[{submission_id}] Cleanup warning: {exc}")


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)
