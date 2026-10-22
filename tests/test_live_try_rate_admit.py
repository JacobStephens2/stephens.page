#!/usr/bin/env python3
"""Live-try hit admission.

The behavior lives in PHP (exclusive lock, re-read, cap). This wrapper
runs that file so the Python suite fails when the admission tests fail.
"""

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests" / "test_live_try_rate_admit.php"


class LiveTryRateAdmitTests(unittest.TestCase):
    def test_php_admission_suite(self):
        completed = subprocess.run(
            ["php", str(PHP_TEST)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(
            completed.returncode,
            0,
            completed.stdout + completed.stderr,
        )
        self.assertIn("PASS test_admission_rereads_the_connection_file_under_the_lock", completed.stdout)
        self.assertIn("PASS test_admission_rereads_the_email_file_under_the_lock", completed.stdout)
        self.assertIn("PASS test_try_is_counted_only_after_turnstile_and_before_generation", completed.stdout)


if __name__ == "__main__":
    unittest.main()
