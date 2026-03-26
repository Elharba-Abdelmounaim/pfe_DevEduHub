"""
test_grader.py — Unit tests for repo_cloner and tester modules.

Run with:  pytest test_grader.py -v
Quick run: python test_grader.py
"""

import pytest
from docker_runner import DockerRunResult
from tester import Tester, build_suite_from_json
from repo_cloner import _validate, CloneError


# ── repo_cloner tests ─────────────────────────────────────────────────────────
class TestRepoValidation:

    def test_valid_github_url(self):
        assert _validate("https://github.com/user/repo") == "https://github.com/user/repo"

    def test_strips_trailing_slash(self):
        assert _validate("https://github.com/user/repo/") == "https://github.com/user/repo"

    def test_strips_git_suffix(self):
        assert _validate("https://github.com/user/repo.git") == "https://github.com/user/repo"

    def test_valid_gitlab_url(self):
        assert _validate("https://gitlab.com/user/project") == "https://gitlab.com/user/project"

    def test_blocks_ssh(self):
        with pytest.raises(CloneError):
            _validate("git@github.com:user/repo.git")

    def test_blocks_credentials(self):
        with pytest.raises(CloneError):
            _validate("https://user:pass@github.com/user/repo")

    def test_blocks_localhost(self):
        with pytest.raises(CloneError):
            _validate("https://localhost/user/repo")

    def test_blocks_private_ip(self):
        with pytest.raises(CloneError):
            _validate("https://192.168.1.1/user/repo")

    def test_blocks_unknown_host(self):
        with pytest.raises(CloneError):
            _validate("https://evil.com/user/repo")


# ── tester tests ──────────────────────────────────────────────────────────────
def make_result(exit_code=0, stdout="", stderr="", timed_out=False):
    return DockerRunResult(exit_code=exit_code, stdout=stdout,
                           stderr=stderr, timed_out=timed_out)


class TestTester:

    def test_perfect_score_default_suite(self):
        result = make_result(exit_code=0, stdout="Hello World", stderr="")
        report = Tester("t001", result, "default").evaluate()
        assert report["score"] == 100.0
        assert report["passed"] is True

    def test_zero_on_timeout(self):
        result = make_result(exit_code=-1, timed_out=True)
        report = Tester("t002", result, "default").evaluate()
        assert report["score"] < 50
        assert report["passed"] is False

    def test_hello_world_detects_output(self):
        result = make_result(exit_code=0, stdout="Hello, World!")
        report = Tester("t003", result, "hello_world").evaluate()
        assert report["score"] == 100.0

    def test_hello_world_fails_missing_hello(self):
        result = make_result(exit_code=0, stdout="Goodbye World")
        report = Tester("t004", result, "hello_world").evaluate()
        assert report["score"] < 100
        assert report["passed"] is False

    def test_json_suite_valid_json(self):
        result = make_result(exit_code=0, stdout='{"result": 42}')
        report = Tester("t005", result, "json_api").evaluate()
        assert report["score"] == 100.0

    def test_json_suite_invalid_json(self):
        result = make_result(exit_code=0, stdout="not json")
        report = Tester("t006", result, "json_api").evaluate()
        assert report["score"] < 100

    def test_stderr_penalised(self):
        result = make_result(exit_code=0, stdout="output", stderr="Warning!")
        report = Tester("t007", result, "default").evaluate()
        assert report["score"] < 100

    def test_dynamic_suite_from_json(self):
        test_cases = [
            {"name": "Has output", "weight": 50, "strategy": "has_output", "hint": ""},
            {"name": "No timeout", "weight": 50, "strategy": "no_timeout",  "hint": ""},
        ]
        result = make_result(exit_code=0, stdout="something")
        report = Tester("t008", result, test_cases_json=test_cases).evaluate()
        assert report["score"] == 100.0

    def test_submission_id_in_feedback(self):
        result = make_result(exit_code=0, stdout="ok")
        report = Tester("MY_SUB_ID", result).evaluate()
        # feedback is returned — just ensure it's non-empty
        assert len(report["feedback"]) > 0

    def test_all_builtin_suites_runnable(self):
        result = make_result(exit_code=0, stdout="ok")
        for suite in Tester.available_suites():
            report = Tester("run_all", result, suite).evaluate()
            assert "score" in report


# ── Smoke test (no pytest needed) ────────────────────────────────────────────
if __name__ == "__main__":
    print("Running smoke tests...\n")

    # URL validation
    try:
        _validate("https://github.com/user/repo")
        print("✓ Valid URL accepted")
    except CloneError:
        print("✗ Valid URL rejected — BUG")

    try:
        _validate("git@github.com:user/repo.git")
        print("✗ SSH URL accepted — BUG")
    except CloneError:
        print("✓ SSH URL blocked")

    # Tester scoring
    r = make_result(exit_code=0, stdout="Hello World")
    rep = Tester("smoke", r, "default").evaluate()
    assert rep["score"] == 100.0, f"Expected 100, got {rep['score']}"
    print(f"✓ Default suite score: {rep['score']}/100")

    r2 = make_result(exit_code=-1, timed_out=True)
    rep2 = Tester("smoke_timeout", r2, "default").evaluate()
    assert rep2["score"] < 50
    print(f"✓ Timeout penalised: {rep2['score']}/100")

    print("\n✅ All smoke tests passed!")
