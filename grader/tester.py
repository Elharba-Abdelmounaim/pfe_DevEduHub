"""
tester.py — Evaluate Docker run results against test suites.

Supports multiple evaluation strategies per test case:
  - exit_code:       process exited cleanly
  - no_timeout:      execution stayed within time limit
  - has_output:      program produced stdout
  - no_stderr:       no error output
  - output_contains: stdout includes expected substring
  - output_matches:  stdout matches a regex
  - json_field:      stdout is valid JSON containing a required key/value

Each test has a weight (points). Score = earned / total × 100.
"""

import re
import json
import logging
from dataclasses import dataclass, field
from typing import Callable

from docker_runner import DockerRunResult

logger = logging.getLogger("grader.tester")


# ── Individual test case ──────────────────────────────────────────────────────
@dataclass
class TestCase:
    name:   str
    weight: int                           # points this test contributes
    check:  Callable[[DockerRunResult], bool]
    hint:   str = ""                      # shown in feedback on failure

    def run(self, result: DockerRunResult) -> bool:
        try:
            return bool(self.check(result))
        except Exception as exc:
            logger.warning(f"Test '{self.name}' raised: {exc}")
            return False


# ── Built-in check functions ──────────────────────────────────────────────────
def _exit_zero(r: DockerRunResult)     -> bool: return r.exit_code == 0 and not r.timed_out
def _no_timeout(r: DockerRunResult)    -> bool: return not r.timed_out
def _has_output(r: DockerRunResult)    -> bool: return bool(r.stdout.strip())
def _no_stderr(r: DockerRunResult)     -> bool: return not r.stderr.strip()

def _contains(expected: str) -> Callable:
    return lambda r: expected.lower() in r.stdout.lower()

def _matches(pattern: str) -> Callable:
    return lambda r: bool(re.search(pattern, r.stdout, re.MULTILINE))

def _json_has(key: str, value=None) -> Callable:
    def _check(r: DockerRunResult) -> bool:
        try:
            data = json.loads(r.stdout.strip())
            if value is None:
                return key in data
            return data.get(key) == value
        except (json.JSONDecodeError, AttributeError):
            return False
    return _check


# ── Test suite registry ───────────────────────────────────────────────────────
# Production: load suites from assignments.test_cases jsonb (Phase 2).
# For now: built-in named suites used as defaults.

_SUITES: dict[str, list[TestCase]] = {

    "default": [
        TestCase("Program runs without crash",     30, _exit_zero,   "Ensure main.py / index.js exits with code 0"),
        TestCase("Execution within time limit",    30, _no_timeout,  "Reduce computation or add early termination"),
        TestCase("Program produces output",        20, _has_output,  "Print at least one line to stdout"),
        TestCase("No runtime errors in stderr",    20, _no_stderr,   "Fix exceptions or warnings printed to stderr"),
    ],

    "hello_world": [
        TestCase("Program runs without crash",     25, _exit_zero,    "Exit code must be 0"),
        TestCase("Output contains 'hello'",        50, _contains("hello"), "Print 'Hello, World!' or similar"),
        TestCase("Execution within time limit",    25, _no_timeout,   "Must finish within the time limit"),
    ],

    "json_api": [
        TestCase("Program runs without crash",     20, _exit_zero,    "Exit code must be 0"),
        TestCase("Output is valid JSON",           30, _json_has("result"), "Print JSON with a 'result' key"),
        TestCase("Output contains 'result' key",   30, _json_has("result"), "JSON must include 'result'"),
        TestCase("No timeout",                     20, _no_timeout,   "Must finish within the time limit"),
    ],

    "fibonacci": [
        TestCase("Program runs without crash",     20, _exit_zero,    "Check for syntax errors"),
        TestCase("No timeout",                     20, _no_timeout,   "Avoid recursion without memoisation"),
        TestCase("Output is a number",             30, _matches(r"^\d+\s*$"), "Print only the numeric result"),
        TestCase("Output contains 55",             30, _contains("55"),  "fib(10) should be 55"),
    ],

    "sort_algorithm": [
        TestCase("Program runs without crash",     20, _exit_zero,    "Exit code must be 0"),
        TestCase("No timeout",                     20, _no_timeout,   "O(n²) is fine for small inputs"),
        TestCase("Output matches sorted order",    60, _contains("[1, 2, 3, 4, 5]"), "Sort [5,3,1,4,2] correctly"),
    ],
}


# ── Dynamic suite builder from assignment.test_cases jsonb ───────────────────
def build_suite_from_json(test_cases: list[dict]) -> list[TestCase]:
    """
    Build a test suite from the assignments.test_cases JSONB field (Phase 2).

    Expected structure per case:
      {
        "id":              "tc1",
        "name":            "Empty list returns []",
        "weight":          25,
        "strategy":        "output_contains",
        "expected":        "[]",         # used by output_contains / output_matches
        "hint":            "..."
      }
    """
    suite = []
    strategy_map: dict[str, Callable] = {
        "exit_zero":       lambda _: _exit_zero,
        "no_timeout":      lambda _: _no_timeout,
        "has_output":      lambda _: _has_output,
        "no_stderr":       lambda _: _no_stderr,
        "output_contains": lambda tc: _contains(tc.get("expected", "")),
        "output_matches":  lambda tc: _matches(tc.get("expected", "")),
        "json_field":      lambda tc: _json_has(tc.get("key", ""), tc.get("value")),
    }

    for tc in test_cases:
        strategy = tc.get("strategy", "exit_zero")
        builder  = strategy_map.get(strategy, lambda _: _exit_zero)
        suite.append(TestCase(
            name   = tc.get("name", strategy),
            weight = int(tc.get("weight", 25)),
            check  = builder(tc),
            hint   = tc.get("hint", ""),
        ))

    return suite or _SUITES["default"]


# ── Tester ────────────────────────────────────────────────────────────────────
class Tester:
    def __init__(
        self,
        submission_id: str,
        run_result:    DockerRunResult,
        suite_name:    str             = "default",
        test_cases_json: list[dict] | None = None,
    ):
        self.submission_id = submission_id
        self.run_result    = run_result

        # Phase 2: use dynamic suite from assignment.test_cases jsonb if provided
        if test_cases_json:
            self.suite = build_suite_from_json(test_cases_json)
            self.suite_name = "dynamic"
        else:
            self.suite      = _SUITES.get(suite_name, _SUITES["default"])
            self.suite_name = suite_name

    def evaluate(self) -> dict:
        """
        Run all test cases and compute weighted score.

        Returns:
            {
                "passed":       bool,
                "score":        float (0–100),
                "passed_tests": int,
                "total_tests":  int,
                "feedback":     str,
            }
        """
        total_weight  = sum(t.weight for t in self.suite)
        earned_weight = 0
        passed_count  = 0
        lines         = [
            f"Test suite : {self.suite_name} ({len(self.suite)} tests)",
            "─" * 48,
        ]

        for test in self.suite:
            passed = test.run(self.run_result)
            icon   = "✓" if passed else "✗"
            lines.append(f"  {icon}  {test.name:<40} ({test.weight}pts)")

            if not passed and test.hint:
                lines.append(f"     Hint: {test.hint}")

            if passed:
                earned_weight += test.weight
                passed_count  += 1

        score         = round((earned_weight / total_weight) * 100, 2) if total_weight else 0
        overall       = passed_count == len(self.suite)

        lines += [
            "─" * 48,
            f"Result  : {'PASSED ✓' if overall else 'FAILED ✗'}",
            f"Score   : {score}/100",
            f"Tests   : {passed_count}/{len(self.suite)} passed",
        ]

        feedback = "\n".join(lines)
        logger.info(
            f"[{self.submission_id}] score={score} "
            f"({passed_count}/{len(self.suite)} tests passed)"
        )

        return {
            "passed":       overall,
            "score":        score,
            "passed_tests": passed_count,
            "total_tests":  len(self.suite),
            "feedback":     feedback,
        }

    @staticmethod
    def available_suites() -> list[str]:
        return list(_SUITES.keys())
