#!/usr/bin/env python3
"""Agent docs route issues to GitHub Issues, never to `.scratch/` files.

Seam: the issue-tracker instructions agents consume in `AGENTS.md`, optional
`CLAUDE.md` and `docs/agents/`, including the shell blocks agents run one at a
time in fresh shells.
"""

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ISSUE_TRACKER = ROOT / "docs/agents/issue-tracker.md"


class IssueTrackerDocumentationTests(unittest.TestCase):
    def test_agent_docs_do_not_route_issues_to_scratch_files(self):
        paths = [ROOT / "AGENTS.md", *sorted((ROOT / "docs/agents").glob("*.md"))]
        claude = ROOT / "CLAUDE.md"
        if claude.exists() or claude.is_symlink():
            paths.append(claude)

        for path in paths:
            with self.subTest(path=path.relative_to(ROOT)):
                self.assertNotIn(
                    ".scratch/",
                    path.read_text(encoding="utf-8"),
                    f"{path.relative_to(ROOT)} still routes issues to .scratch/ files",
                )

    def test_each_shell_block_sets_every_variable_it_uses(self):
        blocks = re.findall(
            r"```bash\n(.*?)```", ISSUE_TRACKER.read_text(encoding="utf-8"), re.S
        )
        self.assertTrue(blocks)

        for index, block in enumerate(blocks):
            with self.subTest(block=index):
                # Stub gh so the block runs offline; set -u fails on unset variables.
                result = subprocess.run(
                    ["bash", "-c", "gh() { :; }\nset -u\n" + block],
                    capture_output=True,
                    text=True,
                )
                self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
