"""Reader-facing publication contracts, checked without browser dependencies."""

from html import unescape
from html.parser import HTMLParser
from pathlib import Path
import re
import statistics
import unittest


BLOG = Path(__file__).resolve().parents[1] / "blog"
STUDY_1 = "maker-checker-tdd-ten-issue-adoption-study"
STUDY_2 = "maker-checker-tdd-vs-single-agent-ticket-run"
ESSAY = "one-mind-to-build-one-blind-mind-to-break"


def read(slug, name="index.html"):
    return (BLOG / slug / name).read_text()


def text_content(html):
    class Text(HTMLParser):
        def __init__(self):
            super().__init__()
            self.parts = []

        def handle_data(self, data):
            self.parts.append(data)

    parser = Text()
    parser.feed(html)
    return re.sub(r"\s+", " ", " ".join(parser.parts)).strip()


def trial_rows(slug, table, end):
    section = read(slug, "paper.md").split(table, 1)[1].split(end, 1)[0]
    return [line.split("|")[1:-1] for line in section.splitlines()
            if re.match(r"^\| \d+ \|", line)]


class MakerCheckerPublications(unittest.TestCase):
    def test_static_figure_fallbacks(self):
        html = read(ESSAY)
        for host in ("archHost", "spendHost", "loopCard"):
            with self.subTest(host=host):
                content = re.search(r'<div[^>]*\bid="' + host + r'"[^>]*>(.*?)</div>', html, re.S)[1]
                self.assertTrue(text_content(content).strip(), host + " needs a static fallback")

    def test_agent_runner_contract(self):
        agent = read(ESSAY, "agents.md")
        for guard in ("max_total_sessions = 6", "max_wall_clock_minutes = 30", "max_api_cost_usd = 4.00"):
            with self.subTest(guard=guard):
                self.assertTrue(guard in agent, guard)
        human_contract = re.search(r'<pre[^>]*aria-labelledby="toml-cap"[^>]*><code>(.*?)</code></pre>', read(ESSAY), re.S)[1]
        self.assertTrue(unescape(human_contract) in agent, "Agent and human runner contracts differ")

    def test_source_and_agent_discovery(self):
        for slug in (STUDY_1, STUDY_2, ESSAY):
            with self.subTest(slug=slug):
                html = read(slug)
                self.assertIn("https://github.com/JacobStephens2/stephens.page/tree/main/blog/" + slug + "/", html)
                footer = re.search(r"<p[^>]*>\s*<strong>For AI agents:</strong>(.*?)</p>", html, re.S)
                self.assertIsNotNone(footer)
                self.assertIn("agents.md", footer[1])

    def test_agent_operational_checklists(self):
        for slug in (STUDY_1, STUDY_2, ESSAY):
            with self.subTest(slug=slug):
                agent = read(slug, "agents.md")
                self.assertRegex(agent, r"(?i)## Operational checklist")
                self.assertGreaterEqual(len(re.findall(r"^- \[ \] ", agent, re.M)), 3)

    def test_figure_rendering_disclosure(self):
        html = read(ESSAY)
        captions = re.findall(r'<div class="fig-caption">(.*?)</div>', html, re.S)
        self.assertEqual(len(captions), 4)
        for caption in captions:
            self.assertRegex(caption, r"\b(SVG|DOM|JavaScript)\b")
        provenance = re.search(r'<div class="ai-note">(.*?)</div>', html, re.S)[1]
        self.assertIn("SVG", provenance)
        self.assertIn("DOM", provenance)

    def test_house_punctuation(self):
        for slug in (STUDY_1, STUDY_2, ESSAY):
            names = ("index.html", "agents.md") + (("follow-up-replay.md",) if slug == ESSAY else ("paper.md",))
            for name in names:
                with self.subTest(slug=slug, name=name):
                    self.assertFalse(chr(8212) in unescape(read(slug, name)), "Rendered em dash")

    def test_primary_non_delivery_count(self):
        html = read(ESSAY)
        primary_rows = re.search(r'Primary PRs delivered.*?</tr>', html, re.S)[0]
        delivered = re.findall(r'(\d+)/(\d+)', text_content(primary_rows))
        self.assertEqual(len(delivered), 4)
        missing = sum(int(total) - int(count) for count, total in delivered[1::2])
        total = sum(int(total) for _, total in delivered[1::2])
        self.assertIn(f"{missing}/{total} non-delivered", html)

    def test_combined_time_uses_trial_minutes(self):
        first = trial_rows(STUDY_1, "**Table 12.**", "| **Total**")
        second = trial_rows(STUDY_2, "**Table 5.**", "## 4 Results")
        self.assertEqual((len(first), len(second)), (10, 20))
        baseline = sum(float(r[1]) for r in first) + sum(float(re.search(r'`([\d.]+)m`', r[2])[1]) for r in second)
        candidate = sum(float(r[2]) for r in first) + sum(float(re.search(r'`([\d.]+)m`', r[3])[1]) for r in second)
        lead = text_content(re.search(r'<section class="tldr".*?<li>(.*?)</li>', read(ESSAY), re.S)[1])
        claimed_candidate = float(re.search(r'([\d.]+) active hours', lead)[1])
        claimed_baseline = float(re.search(r'and ([\d.]+) hours', lead)[1])
        self.assertAlmostEqual(claimed_candidate, candidate / 60, delta=0.1)
        self.assertAlmostEqual(claimed_baseline, baseline / 60, delta=0.1)
        outline = text_content(re.search(r'<h2 id="sec-h-3">(.*?)</h2>', read(ESSAY), re.S)[1])
        self.assertIn(f"about {candidate / 60:.1f} hours", outline)

    def test_headline_test_volume_uses_comparable_units(self):
        first = read(STUDY_1, "paper.md").split("**Table 8.**", 1)[1]
        total = next(line for line in first.splitlines() if line.startswith("| **Total**")).split("|")[1:-1]
        counts = [int(re.sub(r"\D", "", total[i])) for i in (5, 6)]
        first_ratio = counts[1] / counts[0]
        ratios = []
        for row in trial_rows(STUDY_2, "**Table 5.**", "## 4 Results")[:17]:
            sizes = re.findall(r'`([\d,]+)/([\d,]+)`', row[5])
            if len(sizes) == 2:
                baseline, candidate = (int(t.replace(",", "")) for _, t in sizes)
                ratios.append(candidate / baseline)
        second_ratio = statistics.median(ratios)
        meta = unescape(re.search(r'<meta name="description" content="([^"]+)"', read(ESSAY))[1])
        self.assertIn(f"{first_ratio:.2f}x aggregate", meta)
        self.assertIn(f"{second_ratio:.2f}x paired median", meta)
        summary = text_content(re.search(r'href="/blog/' + ESSAY + r'/".*?<p class="note-summary">(.*?)</p>', (BLOG / "index.html").read_text(), re.S)[1])
        self.assertIn(f"{first_ratio:.2f}× aggregate", summary)
        self.assertIn(f"{second_ratio:.2f}× paired median", summary)

    def test_synthesis_does_not_claim_only_one_variable_changed(self):
        lead = text_content(re.search(r'<section class="tldr".*?</section>', read(ESSAY), re.S)[0])
        self.assertNotIn("only role isolation differed", lead)
        self.assertIn("Study 1 also differed in orchestrator and review configuration", lead)

    def test_panel_narrative_matches_votes_and_primary_denominator(self):
        paper = read(STUDY_2, "paper.md")
        section = paper.split("**Table 6.**", 1)[1].split("**Human merge readiness", 1)[0]
        rows = {r.split("|")[2].strip(" `"): r.split("|")[9:15]
                for r in section.splitlines() if re.match(r"^\| \d+ \|", r)}
        for task in ("T5", "T12", "T17", "T15-r2"):
            votes = [v.strip(" *`") for v in rows[task]]
            self.assertEqual(votes.count("B"), 3)
            self.assertLess(votes.count("C"), 3)
            self.assertNotEqual(votes[1], "B")  # astra's removal preserves three Baseline votes.
            self.assertGreater(3, (len(votes) - 1) / 2)
        for document in (paper, text_content(read(STUDY_2)), read(STUDY_2, "agents.md")):
            normalized = document.replace("`", "").replace("**", "")
            self.assertIn("strict-majority threshold from 4 of 6 to 3 of 5", normalized)
            self.assertIn("Three primary ties", normalized)
            self.assertNotIn("four 3–3 ties", normalized)

    def test_combined_phase_spend_matches_study_tables(self):
        groups = {
            "Orchestrator": ("Orchestrator",),
            "Plan + RED + verify": ("Test plan", "RED:"),
            "Review / Other": ("Code review", "Pull request body", "Other"),
            "GREEN + Refactor": ("GREEN:", "Refactor"),
        }
        expected = dict.fromkeys(groups, 0.0)
        phase_count = 0
        for slug, table in ((STUDY_1, "**Table 7.**"), (STUDY_2, "**Table 11.**")):
            candidate = read(slug, "paper.md").split(table, 1)[1].split("| **Baseline", 1)[0]
            for line in candidate.splitlines():
                if not line.startswith("| "):
                    continue
                cells = line.split("|")[1:-1]
                label = cells[0].strip(" -*`")
                for group, prefixes in groups.items():
                    if label.startswith(prefixes):
                        expected[group] += float(re.search(r'(\d+(?:\.\d+)?)', cells[-1])[1])
                        phase_count += 1
        self.assertEqual(phase_count, 14)
        combined = read(ESSAY).split("all: {", 1)[1].split("max:", 1)[0]
        amounts = re.findall(r"\['Candidate: ([^']+)', ([\d.]+), '([\d.]+)%'", combined)
        self.assertEqual(len(amounts), 4)
        total = sum(float(amount) for _, amount, _ in amounts)
        self.assertAlmostEqual(total, sum(expected.values()), places=2)
        for group, amount, percentage in amounts:
            self.assertAlmostEqual(float(amount), expected[group], places=2)
            self.assertAlmostEqual(float(percentage), float(amount) / total * 100, delta=0.05)
        self.assertIn(f"${total:.2f}", combined)
        caption = text_content(re.findall(r'<div class="fig-caption">(.*?)</div>', read(ESSAY), re.S)[1])
        self.assertIn("$0.02 rounding difference", caption)

    def test_study2_test_line_totals_match_table_5(self):
        rows = trial_rows(STUDY_2, "**Table 5.**", "## 4 Results")
        self.assertEqual(len(rows), 20)
        p_base = p_cand = a_base = a_cand = 0
        for row in rows:
            sizes = re.findall(r"`([\d,]+)/([\d,]+)`", row[5])
            if len(sizes) == 2:
                b_tl = int(sizes[0][1].replace(",", ""))
                c_tl = int(sizes[1][1].replace(",", ""))
                a_base += b_tl
                a_cand += c_tl
                if int(row[0]) <= 17:
                    p_base += b_tl
                    p_cand += c_tl
        self.assertEqual((p_base, p_cand), (1832, 5063))
        self.assertEqual((a_base, a_cand), (2250, 6433))
        for doc in (read(STUDY_2, "paper.md"), text_content(read(STUDY_2)), read(STUDY_2, "agents.md")):
            norm = doc.replace("`", "").replace("**", "")
            self.assertRegex(norm, r"5,063 vs\.? 1,832 total test lines")
            self.assertRegex(norm, r"6,433 vs\.? 2,250 across")
        self.assertRegex(read(ESSAY, "agents.md").replace("`", ""), r"1,832 vs\.? 5,063 test LOC across 11 pairs")

    def test_study2_h6_bloat_count_matches_enumerated_pairs(self):
        paper = read(STUDY_2, "paper.md")
        m_md = re.search(r"Candidate pull request on \*\*(\d+) of the 13\*\* graded pairs \((.*?)\)\.", paper)
        self.assertIsNotNone(m_md)
        ids_md = re.findall(r"`(T\d+(?:-r2)?)`", m_md[2])
        self.assertEqual(int(m_md[1]), len(set(ids_md)))
        self.assertEqual(int(m_md[1]), 10)
        html_txt = text_content(read(STUDY_2))
        self.assertIn("10 of the 13 graded pairs", html_txt)
        self.assertIn("10/13 graded Candidate PRs", read(STUDY_2, "agents.md").replace("`", ""))

    def test_primary_citations_named(self):
        for slug in (STUDY_1, STUDY_2, ESSAY):
            self.assertIn("Konstantinou", read(slug))
            if slug != ESSAY:
                self.assertIn("Konstantinou", read(slug, "paper.md"))
        self.assertIn("Konstantinou", read(STUDY_2, "agents.md"))
        self.assertIn("Konstantinou", read(ESSAY, "agents.md"))

    def test_service_worker_and_cache_headers_prevent_stale_theme(self):
        root = BLOG.parent
        sw = (root / "sw.js").read_text()
        self.assertNotIn("VERSION = 'v1'", sw)
        self.assertNotIn("caches.match(", sw)
        self.assertIn("PRECACHE_ASSETS", sw)
        precache_pages = re.search(r"PRECACHE_PAGES\s*=\s*\[(.*?)\]", sw, re.S)[1]
        self.assertNotIn(".css", precache_pages)
        self.assertNotIn(".js", precache_pages)
        htaccess = (root / ".htaccess").read_text()
        self.assertIn('<FilesMatch "\\.(html|css|js|md)$">', htaccess)
        self.assertIn('Header set Cache-Control "no-cache"', htaccess)


if __name__ == "__main__":
    unittest.main()
