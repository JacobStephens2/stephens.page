#!/usr/bin/env python3
"""Live-try hit admission.

The behavior lives in PHP (exclusive lock, re-read, cap). This wrapper
runs that file so the Python suite fails when the admission tests fail.
"""

import socket
import subprocess
import time
import unittest
import urllib.error
import urllib.request
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

    def test_web_request_does_not_run_the_admission_harness(self):
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            deadline = time.time() + 5
            while time.time() < deadline:
                try:
                    with socket.create_connection(("127.0.0.1", port), 0.2):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                self.fail("local php server did not accept a connection")
            url = f"http://127.0.0.1:{port}/tests/test_live_try_rate_admit.php"
            try:
                with urllib.request.urlopen(url, timeout=30) as response:
                    status = response.status
                    content_type = response.headers.get("Content-Type", "")
                    body = response.read(4000)
            except urllib.error.HTTPError as error:
                status = error.code
                content_type = error.headers.get("Content-Type", "")
                body = error.read(4000)
        finally:
            server.kill()
            server.wait(timeout=5)
        self.assertEqual(
            status,
            404,
            f"a web request ran the harness (status {status}, {content_type})",
        )
        self.assertNotIn("application/json", content_type)
        self.assertNotIn(b"PASS ", body)
        self.assertNotIn(b"FAIL ", body)

if __name__ == "__main__":
    unittest.main()
