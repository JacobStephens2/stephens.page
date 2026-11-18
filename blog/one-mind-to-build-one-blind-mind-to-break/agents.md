---
title: "One Mind to Build, One Blind Mind to Break - agent-oriented version"
audience: AI agents and orchestrator designers evaluating single-agent workflows vs. multi-agent maker-checker TDD pipelines
canonical_human_version: https://stephens.page/blog/one-mind-to-build-one-blind-mind-to-break/
study_1: https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/
study_2: https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/
related_study: https://stephens.page/blog/fresh-eyes-beat-a-second-family/
author: Jacob Stephens
date: 2026-11-17
license: MIT (per the site repository's LICENSE)
---

# One Mind to Build, One Blind Mind to Break (for agents)

This is the machine-oriented synthesis of two preregistered, paired, blinded replay experiments (**30 pairs in all**) comparing [thirdshift](https://thirdshift.app)'s single-context Built-in Ticket run (**Baseline**) against an isolated multi-agent maker-checker test-driven workflow with cross-family review (**Candidate**) on Educational Travel Adventures' production PHP 8 / MySQL 8 platform (Tourbot):

- **Study 1 (`10` pairs, `I1`–`I10`, 30 Oct – 1 Nov 2026):** Claude Code (`claude-opus-5-5`) Candidate orchestrator vs. Codex (`gpt-6.1-sol`) Baseline. [Full paper](https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/)
- **Study 2 (`20` pairs: `17` unique tasks `T1`–`T17` + `3` repeats, 3–5 Nov 2026):** **Both** orchestrators equalized on Codex `gpt-6.1-sol` (`high`), after 64 commits to the Candidate rules, scored on a 5-criterion rubric by a blind maintainer, a colleague sample (`2` pairs), and an 8-model panel (`6` voting families + `2` diagnostic). [Full paper](https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/)

Governing finding:

> **Keep construction inside one continuous context (`One Mind to Build`), enforce deterministic loop and budget ceilings in runner code rather than agent prompts, and reserve cross-family isolation strictly for post-hoc, diff-blind verification (`One Blind Mind to Break`).** Across 30 paired runs, splitting planning, RED tests, GREEN code, and refactoring across fresh contexts spent `$422.84` (`$223.71` + `$199.13`) and `67.6` active hours vs. Baseline's `$39.32` and `7.7` hours, lowered primary PR delivery (`19/27` vs. `27/27`), inflated test volume to `14.7:1` / `10.10:1` LOC, dropped blind `merge as is` readiness from `89%` (`17/19`) to `16%` (`3/19`), and lost `14–0–5` on blind maintainer preference across delivered pairs. Equalizing the orchestrator on Codex halved the cost and time ratios (`10.4× → 4.62×` cost; `6.4× → 3.28×` time) without fixing delivery, test bloat, or human preference. Candidate cross-tests exposed Baseline bugs on `2 of 19` (`10.5%`) unique delivered pairs (`I6`, `I7` in Study 1; `0 of 11` in Study 2, while a Baseline test caught `1` Candidate regression in `T17`).

## 30-pair empirical summary

| Metric | Study 1 (10 pairs, Claude Candidate orch.) | Study 2 (17 primary tasks + 3 repeats, Codex Candidate orch.) |
|---|---|---|
| Primary PRs delivered (`B` vs. `C`) | `10 / 10` vs. `8 / 10` (`2` `stopped-to-ask`) | `17 / 17` vs. `11 / 17` (`4` `stopped-to-ask`, `2` `failed`) |
| Paired median cost ratio (`C ÷ B`) | **`10.4×`** (`$14.06` vs `$1.35`; totals `$223.71` vs `$13.39`) | **`4.62×`** (`$5.46` vs `$1.27`; totals `$177.07` vs `$22.50` across 17; `$199.13` vs `$25.93` across 20) |
| Paired median time ratio (`C ÷ B`) | **`6.4×`** (`62.0m` vs `10.8m`; totals `1,095m` vs `125m`) | **`3.28×`** (`55.1m` vs `18.9m`; totals `1,686.7m` vs `311.4m` across 17) |
| Median minutes to first PHP edit (`B` vs `C`) | `1.3m` vs `24.4m` (`21.4×`) | `1.8m` vs `11.4m` (`6.11×`) |
| Test lines per production line (`B` vs `C`) | `3.7` vs `14.7` (`1,178 / 319` vs `7,123 / 484`) | `2.64` vs `10.10` (`1,832` vs `4,953` test LOC across 11 pairs) |
| Actual implementation active time (`B` vs `C`) | `89m` vs `85m` (virtually identical) | `193.5m` vs `199.1m` across 20 pairs (`$14.53` vs `$13.97`) |
| Pre-GREEN Candidate spend share | `74%` (`$163.95` of `$222.67`: orchestrator + plan + RED) | `76.0%` (`$150.32` of `$197.70` across 20 pairs; `56.1%` orchestrator alone) |
| Blind author `merge as is` (delivered pairs) | **`7 / 8` (`88%`)** vs **`1 / 8` (`13%`)** (`4` `no`) | **`10 / 11` (`91%`)** vs **`2 / 11` (`18%`)** (`3` `no`; `12/13` vs `2/13` all) |
| Blind author pair wins (`B`–`C`–`tie`) | **`6–0–2`** (graded) / `7–0–1` category comparison | **`8–0–3`** (delivered) / **`14–0–3`** (all 17 first pairs) |
| Blind colleague sample (`2` pairs: `T10`, `T12`) | — | **`1–0–1` Baseline** (`T10` `small_fixes`/`small_fixes` = `tie`; `T12` `as_is`/`small_fixes` = **B**, matching author) |
| Candidate cross-tests catching Baseline defects | **`2 / 8`** (`I6` coordinator-text regex deletion; `I7` DST bug) | **`0 / 11`** (`1` Baseline test caught Candidate `T17` `'0010'` leading-zero bug) |
| `7-voter` / `6-LLM` / `5-LLM (-astra)` panel (Study 2) | Dual-model panel: `2–3–5` (Candidate rejected on `< 8/10`) | `10–1–0` (`16–1–0` all `17`) / `5–1–5` / `8–1–2` (`4-LLM -family`: `5–0–6`) |

## Five governing CS laws explaining the data

1. **Context fragmentation vs. conceptual integrity (`Brooks 1975`; `Yan / Cognition 2025`; `Kim et al. 2025`):**
   Splitting sequentially coupled changes across fresh contexts discards implicit codebase exploration priors (`2,505` vs `481` reads in Study 1; `2,934` vs `729` in Study 2) and creates clashing implicit decisions (`T5` and `T10` Candidate cross-test failures came from asserting Candidate-invented private helpers). Matches SWE-bench Verified evidence across 260 configurations (`-2.1%` to `-14.9%`).
2. **Cleanroom test separation (`Mills et al. 1987`) vs. Goodhart's Law (`1975`) (`Hora & Robbes 2026`; `Chen et al. 2026`):**
   DO-178C / Cleanroom separation requires a complete upfront formal specification. On 10–30 line natural-language tickets where `GREEN` does not exist yet, grading `RED` writers against adversarial verifiers turns checklist survival into the target (`14.7:1` and `10.10:1` test bloat).
3. **Deterministic code workflows vs. prose-interpreted loops (`Schluntz & Zhang 2024`; `Cemri et al. 2025 [MAST]`):**
   In Study 2 `T3` (a `17`-line server access-control rule where `543` HTTP tests passed at `1h 44m` / `$8.10`), a low-severity `F2` finding on the local test fixture triggered `15` meta-reviews across `699.4m` (`64` sessions, `$64.22`, `0` PR), growing a dedicated fixture-lifecycle test to `1,101` lines and `41` cases (`31` failing, `10` passing) with a `32`-row state table:
   - `FM-2.5` (*Ignored Other Agent's Input*): maker noted `"no collision frequency or failed canonical run is established"`, yet recovery continued.
   - `FM-2.6` (*Reasoning–Action Mismatch*): meta-reviews 12 and 14 wrote `"Twelve meta invocations and earlier capture/assignment failures are expensive churn"` and `"Thirteen earlier meta calls make another unchanged opinion or model loop unjustified"` while still authorizing another loop.
   - `FM-1.5` (*Unaware of Termination Conditions*): rules forbade follow-up deferral and kept looping whenever `"changed evidence"` existed.
   - `FM-3.1` (*Premature Termination*): `6` unattended halts across the two studies (`I2`, `I10`, `T1`, `T8`, `T13`, `T16`) when minor ambiguities hit a human escalation rule.
4. **Intrinsic self-correction blindness vs. diff-blind post-hoc oracles (`Huang et al. 2024`; `Kamoi et al. 2024`; `Knight & Leveson 1986`; `arXiv:2607.05139`):**
   Diff-conditioned reviewers anchored on the builder's regex in Study 1 `I6` (`25%` vs `14%` detection for independent tests). Transplanting an implementation-blind test exposed `I6` and `I7` without requiring pre-implementation role separation. Reconciled across both studies: `2/19` (`10.5%`) yield argues for **selective** gating rather than running an extra session on every deletion/config ticket (`T6`, `T12`).
5. **Code-review attention economics & *Shape Up* (`Sadowski et al. 2018`; `Singer 2019`):**
   Human defect detection and mergeability collapse on large diffs (`14/14` non-tied primary pairs won by smaller diff; `0` for larger). Runner enforcement must encode fixed appetite, baseline comparison, scope hammering, and hard circuit breakers in code.

## Reconciled architecture: Selective `4+1` Hybrid (`thirdshift`)

What runs on **every** ticket (`~$0` incremental model cost):
- Single continuous-context Builder (`Explore → write TDD test → Implement → pass CI`).
- `on_ambiguity = "assume_and_document"` in runner prompts (`0` unattended halts).
- Deterministic `tests_on_base` check verifying new regression tests fail on `base/`.
- Hard numeric limits in Rust runner code (`max_total_sessions`, `max_wall_clock_minutes`, `max_api_cost_usd`, `max_repair_rounds = 1`).
- Explicit review-prompt rules penalizing source-string assertions, mocked internals, tests of deleted files, and test-helper TDD spirals.

What is evaluated **selectively** (e.g., on stateful data/text-mutation tickets or at the feature `Spec` boundary matching [Fresh Eyes](https://stephens.page/blog/fresh-eyes-beat-a-second-family/)):
- Deterministic signature extraction: `thirdshift extract-signatures --base HEAD~1 --head HEAD --out .thirdshift/public_api_delta.txt` (exposes *only* added/changed public class and function signatures; strips all method bodies and `tests/` diffs — eliminating `T5`/`T10` private-helper false positives while preserving diff blindness).
- Single bounded cross-family **Blind Behavioral Breaker** (`≤ 180s`, `≤ 25` tool calls, `≤ $1.50`, `≤ 5` test methods / `≤ 150` LOC) run in parallel with `spec_review`, `standards_review`, and `security_review`.
- Execute breaker test against Builder's branch via PHPUnit/test runner: discard if passing without adding branch coverage delta; otherwise allow **at most `1` warm-context Builder resume turn**.

## Operational checklist

- [ ] Keep exploration, TDD test authoring, implementation, and refactoring inside one continuous-context Builder session.
- [ ] Run `tests_on_base` deterministically on every regression fix so new tests prove they fail without the patch.
- [ ] Put loop ceilings, timeouts, and cost caps in runner code—never leave stopping criteria in LLM-interpreted prose.
- [ ] Set `on_ambiguity = "assume_and_document"` for unattended ticket runs; reserve questions for interactive sessions.
- [ ] Feed any independent behavioral test generator *only* `public_api_delta.txt` (signatures without bodies or `tests/` diffs).
- [ ] Gate extra cross-family test/review passes selectively on stateful mutations or feature (`Spec`) boundaries rather than trivial deletions or config rules.
- [ ] Enforce `max_rounds = 1` on post-review repair and defer low-severity test-helper polish to follow-up issues.

## Self-test (answers inline)

1. Why did Candidate spend `74%–76%` of its budget outside green implementation while taking only ~`8.5–10` min per task on `GREEN` coding? **Orchestration (`56.1%` in Study 2), test planning, and pre-implementation RED cycles rebuilt context across `11–72` sessions (`4.0×–5.2×` file reads) before production edits began.**
2. How does equalizing the Candidate orchestrator from Claude Code (`opus-5-5`) to Codex (`gpt-6.1-sol`) affect the Study 1 findings? **It roughly halved cost and time ratios (`10.4× → 4.62×` cost; `6.4× → 3.28×` time) because of lower cached-input rates, yet delivery dropped (`11/17` vs `17/17`), test bloat stayed `10.10:1` vs `2.64:1`, and blind human preference remained `8–0–3` Baseline (`10/11` vs `2/11` `merge as is`).**
3. What MAST failure mode did Pair 3 (`T3`) meta-reviews 12 and 14 exhibit verbatim? **`FM-2.6` (*Reasoning–Action Mismatch*): calling prior rounds *"expensive churn"* and another loop *"unjustified"* in their reasoning while still authorizing the next round because `"changed evidence"` existed and follow-up deferral was forbidden.**
4. Why does `public_api_delta.txt` solve the `T5`/`T10` false-positive cross-test problem without breaking blindness? **It shares only added/changed public class and function signatures with the independent test generator while stripping all implementation bodies and `tests/` diffs.**
5. How did Study 2's `H1 = 0` (`2/19 = 10.5%` combined) refine the `4+1` Blind Breaker design? **Instead of paying for an independent breaker session on every simple deletion or config ticket (`T6`, `T12`), run deterministic `tests_on_base` (`$0`) on every ticket and gate the bounded (`≤ 3m`, `≤ 150` LOC) Blind Breaker onto stateful mutations or whole-feature (`Spec`) boundaries.**
6. On the `2` pairs independently graded blind by a colleague (`T10` and `T12`), what was the verdict? **`1–0–1` Baseline: `tie` (`small_fixes`/`small_fixes`) on `T10` where Baseline had stronger input validation in one file and Candidate split test files cleanly, and Baseline preferred (`as_is`/`small_fixes`) on `T12`, matching the author.**
