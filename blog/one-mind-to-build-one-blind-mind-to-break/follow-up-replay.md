# Follow-up verification record: Issue #69 reconciliation, primary citations, and 4-pair Blind Breaker replay

**Date:** November 18, 2026
**Scope:** Primary-record audit behind [the November 18 updates](/blog/one-mind-to-build-one-blind-mind-to-break/#breaker-replay) to *One Mind to Build, One Blind Mind to Break*, [Study 1](/blog/maker-checker-tdd-ten-issue-adoption-study/), and [Study 2](/blog/maker-checker-tdd-vs-single-agent-ticket-run/). Every internal identifier, repository path, symbol name, and fixture name is normalized to the public study terminology.

---

## 1. Issue #69 primary-record reconciliation (Study 2)

Re-auditing Study 2 against the retained tally output (`tally-2/report.json`, 20 pairs total: 17 primary + 3 repeats; 11 delivered primary pairs and 13 delivered pairs including repeats) resolved both internal consistency checks raised in GitHub Issue `#69`:

1. **H2 cumulative test-line totals (`4,953` / `6,323` updated to `5,063` / `6,433`):**
   - Summing Study 2 Table 5 across the **11 delivered primary pairs** gives **1,832** Baseline test lines (`192 + 229 + 158 + 24 + 320 + 163 + 95 + 133 + 76 + 239 + 203`) and **5,063** Candidate test lines (`454 + 542 + 1,073 + 23 + 485 + 354 + 155 + 309 + 164 + 896 + 608`).
   - Adding the **2 delivered repeat pairs** (`T17-r2`: `109` Baseline / `365` Candidate; `T15-r2`: `309` Baseline / `1,005` Candidate) across all **13 delivered pairs** gives **2,250** Baseline test lines and **6,433** Candidate test lines.
   - The earlier `4,953` and `6,323` totals were a 110-line manual addition discrepancy relative to Table 5; all medians, ratios (`10.10` vs `2.64` test-to-production lines; `2.32x` paired test-line ratio), and Baseline sums (`1,832` and `2,250`) were unchanged.

2. **H6 blind human bloat citations (`9 of 13` updated to `10 of 13`):**
   - Auditing blind human merge calls alongside written review notes across all 13 delivered pairs confirms **10 of 13** pairs where the author flagged excessive, verbose, or over-engineered tests while rating the Candidate below `merge as is`:
     - Primary (`8`): `T2`, `T4`, `T5`, `T7`, `T10`, `T12`, `T15`, `T17`.
     - Repeats (`2`): `T17-r2` (`merge after small fixes`, excessive tests) and `T15-r2` (`don't merge`, test-section scroll length).
   - The remaining **3 of 13** delivered pairs were excluded because they tied on merge readiness without a Candidate test-volume penalty: `T11` (`merge as is` / `merge as is`, fixture naming only), `T6` (`merge after small fixes` / `merge after small fixes`, unnecessary `23`-`24` line deleted-file test on both arms equally), and `T14` (`merge as is` / `merge as is`, noting *"perhaps more test than needed, but acceptable"*).

---

## 2. Primary bibliographic verification

Previously bare preprint identifiers across [Study 1](/blog/maker-checker-tdd-ten-issue-adoption-study/), [Study 2](/blog/maker-checker-tdd-vs-single-agent-ticket-run/), and [the synthesis essay](/blog/one-mind-to-build-one-blind-mind-to-break/) were resolved against primary bibliographic records:

- **`arXiv:2607.05139` (`[1]`):** Konstantinou, M., Tambon, F., and Papadakis, M. *On the risk of coding before testing: An empirical study on LLM-based test generation workflow.* arXiv:2607.05139, July 2026 (`25%` fault detection for independent tests vs `14%` after viewing faulty code).
- **`arXiv:2512.08296` (`[2]`):** Kim, Y., Gu, K., Park, C., et al. *Towards a Science of Scaling Agent Systems.* arXiv:2512.08296, December 2025.
- **`arXiv:2607.21656` (`[3]`):** Xiang, Z., Zhang, Y., Zhang, Y., and Xu, H. *Cross-Model LLM Code Review: Should you use Claude to review Codex or vice versa?* *Agentic SE @ KDD '26*, arXiv:2607.21656, July 2026 (`91.4%` vs `82.8%` on Claude drafts; `71.6%` vs `89.7%` on Codex drafts).
- **`arXiv:2604.06996` (`[8]`):** Pombal, J., Rei, R., and Martins, A. F. T. *Self-Preference Bias in Rubric-Based Evaluation of Large Language Models.* arXiv:2604.06996, April 2026.
- **`arXiv:2602.07150` (`[9]`):** Bjarnason, B. H., Silva, A., and Monperrus, M. *On Randomness in Agentic Evals.* arXiv:2602.07150, February 2026 (`2.2` to `6.0` percentage-point pass@1 variance).

---

## 3. Empirical 4-pair follow-up replay of the bounded +1 Blind Behavioral Breaker

### Setup and blindness contract

To test Section 6's proposed selective `4+1` hybrid runner directly instead of leaving Open Question #1 untested, we replayed four saved tasks from Studies 1 and 2 on November 18, 2026 using the exact proposed Blind Behavioral Breaker bounds:

- **Model and reasoning effort:** `claude-opus-5-5` at `medium` effort (`max_duration_seconds = 180`, `max_tool_calls = 25`, `max_test_lines = 150`, `max_test_methods = 5`, `max_budget_usd = 1.50`).
- **Blind interface header (`public_api_delta.txt`):** A deterministic script compared the base commit against the builder pull request and exposed only changed filenames and public/protected function and method signatures. All function bodies, top-level procedural code, non-PHP file diffs, and `tests/` changes were stripped; non-PHP edits appeared only as hidden filenames.
- **Four target pairs:**
  1. **Study 1 `I6`** (saved email signature replacement without touching coordinator notes): the signature catch of Study 1, where Candidate spent `72` sessions, `14` plan rounds, `1,736` test lines, and `$74.14` to expose a Baseline text-corruption bug.
  2. **Study 1 `I7`** (zero conference-call date phantom change and NULL storage): the second Study 1 bug catch.
  3. **Study 2 `T5`** (Docker Apache virtual-host webroot update): a non-PHP configuration task used to verify both selective gating (`0` PHP signatures extracted -> skip in production) and false-positive elimination after Study 2's cross-test false positive.
  4. **Study 2 `T10`** (proposal deck override helper extraction): the second Study 2 pair that had triggered a helper-name false positive in cross-testing.

*(Pair `T5` was also forced through the breaker despite `selective_gate_would_run = false` to measure whether `public_api_delta.txt` eliminates false positives even on config-only diffs.)*

### Outcomes

| Task | Public API signatures | Selective gate | Size & time | API cost | Base check | Baseline branch | Candidate branch |
|---|---|---|---|---|---|---|---|
| **Study 1 `I6`** (email signature repointing) | `5` signatures across `3` PHP files | **Run** | `139` LOC, `5` tests, `61.7s` | `$0.35` (`$0.46` CLI) | `exit 1` (`5/5` fail on missing handler) | **`exit 0` (`5/5` pass, `29` assertions)** | `exit 1` (`5/5` fail; Baseline-derived helper name absent) |
| **Study 1 `I7`** (zero call-date normalization) | `2` functions in `1` new helper + `5` modified files | **Run** | `106` LOC, `5` tests, `54.1s` | `$0.27` (`$0.35` CLI) | `exit 1` (`5/5` fail on missing helpers) | **`exit 2` (`1` Error, `4/5` pass, `40` assertions)** | `exit 1` (`5/5` fail; Baseline-derived helper file absent) |
| **Study 2 `T5`** (Docker Apache vhost config) | `0` signatures (`3` hidden non-PHP files) | **Skip** *(forced here)* | `123` LOC, `5` tests, `56.3s` | `$0.32` (`$0.42` CLI; `$0.00` gated) | `exit 1` (`2/5` fail on old docroot) | **`exit 0` (`5/5` pass, `39` assertions)** | **`exit 0` (`5/5` pass, `41` assertions)** |
| **Study 2 `T10`** (proposal deck override apply) | `1` top-level apply signature | **Run** | `128` LOC, `5` tests, `65.5s` | `$0.34` (`$0.44` CLI) | `exit 1` (`5/5` fail on missing function) | **`exit 0` (`5/5` pass, `46` assertions)** | **`exit 0` (`5/5` pass, `46` assertions)** |

*(Cross-arm failures on Candidate in `I6` and `I7` reflect testing a Baseline-generated `public_api_delta.txt` against a Candidate branch that named its internal helper files/functions differently; in production, a `+1` breaker inspects the builder branch's own extracted signatures.)*

### What the 4-pair replay established

1. **`public_api_delta.txt` eliminated the Study 2 false positives at ~`$0.32` API per gated ticket:** Across all three gated runs (`I6`, `I7`, `T10`), total spend was `$0.95` API (`$1.26` CLI reported; mean `$0.32` API) in `54s`-`66s` using `5`-`6` tool calls and `106`-`139` test lines. Including forced `T5`, **0 of 4** runs produced spurious implementation-coupled test failures on target branches sharing the extracted interface (`T5` and `T10` both passed `5/5` cleanly on both arms).
2. **The `150`-line / `3`-minute budget caught a missing zero-date sentinel in `I7`, without replicating `I6`'s `1,736`-line adversarial depth:**
   - On **`I7`**, `testUnsetSentinelsNormaliseToNullAndRealDatesSurvive` passed `40` assertions across four behavior checks and threw `InvalidArgumentException` specifically on `'0000-00-00 00:00'` (a zero-datetime string omitting seconds, matching short form inputs, after accepting `'0000-00-00 00:00:00'` and `'0000-00-00'`) - either an unhandled sentinel edge case or Baseline's deliberate strict validation noted in Study 1 Section 4.4.
   - On **`I6`**, the bounded breaker generated clean tests covering HTML whitespace reflow, handoffs across roles, and a separate coordinator notes paragraph ahead of the signature, all of which Baseline handled cleanly (`29/29` assertions passed). It did **not** invent Study 1's subtler adversarial fixture (coordinator text typed inline beside the trailing signature block or a second signature pasted inside notes), which had emerged in Study 1 only after 14 plan rounds, 72 sessions, `$74.14`, and 1,736 lines of email fixtures.

That trade-off narrows Open Question #1 cleanly: bounded (`<= 150` LOC, `<= 3` min) `public_api_delta.txt` probes buy cheap, zero-false-positive boundary checking (~`$0.32` when gated on PHP interface deltas), while deeper adversarial fixture exploration belongs selectively on risky data/text mutations or whole-feature (`Spec`) verification rather than every routine ticket.
