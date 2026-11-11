#!/usr/bin/env python3
"""Regression coverage for the obsolete local issue-tracker instructions."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class IssueTrackerDocumentationTests(unittest.TestCase):
    def test_agent_docs_do_not_route_issues_to_scratch_files(self):
        paths = [ROOT / "AGENTS.md", *sorted((ROOT / "docs/agents").glob("*.md"))]
        claude = ROOT / "CLAUDE.md"
        if claude.exists() or claude.is_symlink():
            paths.append(claude)

        for path in paths:
            with self.subTest(path=path.relative_to(ROOT)):
                self.assertFalse(
                    ".scratch/" in path.read_text(encoding="utf-8"),
                    f"{path.relative_to(ROOT)} still routes issues to .scratch/ files",
                )


if __name__ == "__main__":
    unittest.main()
