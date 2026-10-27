#!/usr/bin/env python3
"""The site environment file is outside the document root.

Seam: the path configured on contact-submit.php, inkvoke/try-submit.php,
and hello-ladder.service. Those three entry points are the public contract.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT_ROOT = "/var/www/stephens.page"
SITE_ENV = "/etc/stephens.page/site.env"


def _outside_document_root(path: str) -> bool:
    """True when path is absolute and not inside the checkout or document root.

    The document root and the checkout are the oracle. The expected path is
    not derived from the code under test.
    """
    if not path.startswith("/"):
        return False
    if path == DOCUMENT_ROOT or path.startswith(DOCUMENT_ROOT + "/"):
        return False
    root = str(ROOT)
    if path == root or path.startswith(root + "/"):
        return False
    return True


class SiteEnvironmentFileTests(unittest.TestCase):
    def test_named_entry_points_share_a_path_outside_the_document_root(self):
        contact = (ROOT / "contact-submit.php").read_text(encoding="utf-8")
        try_submit = (ROOT / "inkvoke" / "try-submit.php").read_text(encoding="utf-8")
        service = (
            ROOT
            / "blog/from-machine-code-to-ai-the-same-hello-world/server/deploy/hello-ladder.service"
        ).read_text(encoding="utf-8")

        contact_path = re.search(r"\$envFile\s*=\s*'([^']+)'\s*;", contact)
        try_path = re.search(r"load_env\(\s*'([^']+)'\s*\)", try_submit)
        service_path = re.search(r"^EnvironmentFile=-(.+)$", service, re.M)
        read_only = re.search(r"^ReadOnlyPaths=(-?.+)$", service, re.M)

        self.assertIsNotNone(
            contact_path,
            "contact-submit.php must set $envFile to a single-quoted path",
        )
        self.assertIsNotNone(
            try_path,
            "try-submit.php must pass a single-quoted path to load_env",
        )
        self.assertIsNotNone(service_path, "hello-ladder.service must set EnvironmentFile")
        self.assertIsNotNone(read_only, "hello-ladder.service must set ReadOnlyPaths")

        contact_env = contact_path.group(1)
        try_env = try_path.group(1)
        service_env = service_path.group(1).strip()
        read_only_path = read_only.group(1).strip()
        if read_only_path.startswith("-"):
            read_only_path = read_only_path[1:]

        self.assertEqual(contact_env, try_env)
        self.assertEqual(try_env, service_env)
        self.assertEqual(service_env, SITE_ENV)
        self.assertEqual(read_only_path, str(Path(SITE_ENV).parent))
        self.assertTrue(_outside_document_root(contact_env))
        self.assertTrue(_outside_document_root(try_env))
        self.assertTrue(_outside_document_root(service_env))
        self.assertNotIn("private/.env", contact)
        self.assertNotIn("private/.env", try_submit)
        self.assertNotIn("/var/www/stephens.page/private", service)

    def test_operator_hints_do_not_name_the_document_root_copy(self):
        hint = (
            ROOT
            / "blog/from-machine-code-to-ai-the-same-hello-world/server/llm_client.py"
        ).read_text(encoding="utf-8")
        page = (ROOT / "inkvoke" / "index.html").read_text(encoding="utf-8")
        self.assertIn(SITE_ENV, hint)
        self.assertNotIn("private/.env", hint)
        self.assertNotIn("/var/www/stephens.page/private", hint)
        self.assertNotIn("private/.env", page)


if __name__ == "__main__":
    unittest.main()
