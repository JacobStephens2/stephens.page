---
title: "Ten Replayed Issues: Maker-Checker TDD vs. a Single-Agent Ticket Run - agent-oriented version"
audience: AI agents and orchestrator designers evaluating multi-agent maker-checker TDD workflows against single-agent ticket pipelines
canonical_human_version: https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/
pdf_version: https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/maker-checker-tdd-ten-issue-adoption-study.pdf
markdown_version: https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/paper.md
follow_up_study: https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/
flagship_essay: https://stephens.page/blog/one-mind-to-build-one-blind-mind-to-break/
author: Jacob Stephens
date: 2026-11-03
public_edition: 2026-11-17
license: MIT (per the site repository's LICENSE)
---

# Ten Replayed Issues: Maker-Checker TDD vs. a Single-Agent Ticket Run (for agents)

This is the machine-oriented companion to the first pre-registered, paired, blinded adoption comparison at Educational Travel Adventures (ETA) on Tourbot (production PHP / MySQL ERP), using [thirdshift](https://thirdshift.app) (`0.18.6`). It compares `thirdshift`'s single-agent Built-in Ticket run (**Baseline**) against an independently proposed multi-agent maker-checker test-driven workflow (**Candidate**, testing the Cleanroom software-engineering hypothesis of Mills et al., 1987) orchestrated by Claude Code (`claude-opus-5-5`, effort `medium`). For what changed when the Candidate was updated and moved to a Codex `gpt-6.1-sol` orchestrator across 17 tasks and 3 repeats, see the [follow-up second study](https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/), and for the 30-pair synthesis and resulting hybrid architecture, see [One Mind to Build, One Blind Mind to Break](https://stephens.page/blog/one-mind-to-build-one-blind-mind-to-break/).

Governing finding:

> **The pre-registered decision rule rejects adopting the multi-agent maker-checker workflow on every criterion (`RQ1`, `RQ2`, `RQ3`).** Across ten replayed Tourbot issues (`I1`–`I10`), the Candidate won **2 of 10** pairs (3 losses including 2 unattended planning stops with no PR, 5 ties; win rate `20%`, 95% CI `2.5–56%`), cost a median **10.4×** more at API rates (`$14.06` vs `$1.35`; totals `$223.71` vs `$13.39`), took a median **6.4×** longer (`62.0` vs `10.8` min; totals `1,095` vs `125` min), and had **3** correctness or non-delivery failures to the Baseline's **2**. Judging blind, the human maintainer rated **7 of 8** graded Baseline pull requests `merge as is` against **1 of 8** for the Candidate (`0` `don't merge` vs `4` `don't merge`), noting test bloat in `7/8` Candidate PRs (`7,123` vs `1,178` test lines, `14.7` vs `3.7` test lines per code line). Keep the single-agent Ticket run and evaluate bounded single-pass additions separately.

## TL;DR

- **Pre-registered decision (`RQ1–RQ3`):** **Reject** on every criterion: Candidate `2` wins (`I3`, `I6`), `3` losses (`I1` graded; `I2`, `I10` no PR), `5` ties (`I4`, `I5`, `I7`, `I8`, `I9`) against threshold `≥ 8/10`; median cost ratio **10.4×** (exact 97.9% order-statistic CI `6.9–23.5×`) and median time ratio **6.4×** (`3.8–12.7×`) against cap `≤ 2.0×`; correctness failures **3 vs. 2** (`I2`, `I10`, `I8` vs. `I6`, `I7`).
- **Blind human merge-readiness (`RQ4`):** Baseline **7 `as is` / 1 `small fixes` / 0 `don't merge`** across 8 graded pairs (`10/10` PRs delivered); Candidate **1 `as is` / 3 `small fixes` / 4 `don't merge`** (`8/10` PRs delivered; `I2`, `I10` no PR). Author preferred Baseline on **7 of 8** graded pairs and tied **1 of 8** (`I4`), Candidate higher in `0`.
- **Where work and cost went (`EQ1`):** Candidate started `4–72` sessions per run (median `19.5` vs `4.0`, `4.9×`), made `5,189` tool calls (`412` median vs `144`, `2.8×`; `2,505` vs `481` reads), reached first PHP edit at median `24.4` min (`1.3` min Baseline, `21.4×`), and spent **50%** (`$110.50` of `$222.67`) on test planning & RED tests, **24%** (`$53.45`, `712` active min) in the orchestrator, **13%** (`$27.93`) in review, and only **8%** (`$17.57`, `85` min) implementing GREEN code (roughly equal to Baseline's `89` min / `$7.18` implementation session).
- **Test bloat vs. independent fault detection (`EQ2`, `EQ3`):** Both arms wrote tests first (`7/10` Baseline first edits were tests). Candidate wrote **6.0×** more test code (`7,123` vs `1,178` lines; `14.7` vs `3.7` test/code ratio), often parsing/evaluating PHP source strings. Independently written tests caught **2** real defects in Baseline (`I6` broad signature regex deleting coordinator text / stale bus email contacts; `I7` spring-forward DST exception), while Baseline caught a **`latin1` vs UTF-8** encoding mismatch on `I8` (`Montréal`) that Candidate's `729`-combination test grid missed.
- **Model vs. human divergence (`EQ4`):** Grader pair result matched author's ranking only once (`I1`). In `I6` (`$74.14`, `295.9m`, `72` sessions, `1,736` test lines), both model graders chose Candidate while the blind author marked Baseline `merge after small fixes` and Candidate `don't merge` — yet Candidate cross-tests showed Baseline's PR had a subtle defect. Neither model grading alone nor unaided diff reading sufficed alone.

## Terminology

- **Arm / Process:** Complete workflow at pinned frozen versions (`Baseline` = `thirdshift` Built-in Ticket run; `Candidate` = multi-agent maker-checker TDD + cross-family review workflow).
- **Pair:** One closed Tourbot issue (`I1`–`I10`, plus shakedown `I0`) replayed from its parent commit and attempted independently in parallel by both arms.
- **Cross-test:** One arm's added/changed PHPUnit tests transplanted onto the other arm's code and run (failures investigated, not auto-counted as defects).
- **Win / Loss / Tie (`RQ1`):** Pair goes to an arm only if both blinded model graders prefer it net across `[X, Y]` and `[Y, X]` presentation orders; an unattended process failure with no PR counts as a loss; split/tied/order-flipped judgements are ties.

## Apparatus & design

- **Issue selection:** Of `156` Tourbot issues closed since 1 July 2026 by 1 merged `master` PR, `24` were shortlisted (`2–8` files, no migrations/staging/marketing; exact intermediate shortlist not preserved — re-running criteria on 3 Nov yields `77`), and all `10` with explicit acceptance criteria (`I1`–`I10`) were enrolled in chronological `ready-for-agent` order. (`I0` served as uncounted shakedown.)
- **Arms (`thirdshift 0.18.6`, frozen 29 October 2026):**
  - **Baseline (`ticket-run`):** Codex `gpt-6.1-sol` (`high`) single session + parallel spec/standards reviewers + security review (`4` sessions/run).
  - **Candidate:** Claude Code (`claude-opus-5-5`, `medium`) orchestrator + fresh Claude Agent-tool / `codex exec` makers and cross-provider verifiers + up to `5` rounds of parallel Claude + Codex review + `thirdshift` `delivery`. (`44` installed files checksum-verified before every pair; no security review; Codex reviewers ran at `high` rather than documented `low`.)
- **Isolation & blinding:** Separate OS accounts, separate replay repos, cloned 8-vCPU / 16 GB Ubuntu 24.04 cloud droplets; zero transcript leaks; `php-cs-fixer` (`3.95.27`, `@PSR12`) normalization; `random.SystemRandom().shuffle` `X`/`Y` labels; read-only `bwrap` grading sandbox with `PHPUnit`, `PCOV 1.0.12`, `Infection` (no mutations hit in 7/10 pairs due to legacy top-level PHP scripts outside `src/`).
- **Blind grading:** Two graders — Claude Code (`claude-opus-5-5`, read-only repo access) and Codex (`gpt-6.1-sol`, read-only command execution) — each graded `[X, Y]` and `[Y, X]` (32 verdicts across 8 graded pairs); author judged all 8 pairs blind in a write-once form before unsealing verdicts or mappings.

## Pre-registered decision table (RQ1–RQ4)

| Criterion | Result | Threshold | Outcome |
|---|---|---|---|
| Candidate wins (`RQ1`) | **2 of 10** (`3` losses, `5` ties; 95% CI `2.5–56%`) | `≥ 8` adopts; `≤ 5` rejects | **Reject** |
| Median cost ratio (`RQ2`) | **10.4×** (`$14.06` vs `$1.35`; 97.9% CI `6.9–23.5×`) | `≤ 2.0×` | **Reject** |
| Median time ratio (`RQ2`) | **6.4×** (`62.0` vs `10.8` min; 97.9% CI `3.8–12.7×`) | `≤ 2.0×` | **Reject** |
| Correctness failures (`RQ3`) | Candidate **3** (`I2`, `I10` no PR; `I8`), Baseline **2** (`I6`, `I7`) | Candidate `≤` Baseline | **Reject** |
| Blind human `merge as is` (`RQ4`, reported) | Baseline **7/8** (`1` small fixes), Candidate **1/8** (`3` small fixes, `4` no) | Baseline preferred `7–0–1` | Consistent (**Baseline**) |

## Per-pair outcomes, ratios & sizes (`I1`–`I10`)

| Pair | Issue | Summary | Result (Claude / Codex) | Cost B / C ($) | Time B / C (m) | Cost× / Time× | Author B / C | Code B / C | Test B / C |
|---|---|---|---|---:|---:|---:|---|---|---|
| 1 | `I1` | Affidavit expense review gate | **Loss** (`B` / `B`) | `1.16 / 22.57` | `12.2 / 124.3` | `19.4 / 10.2` | `as_is` / `no` | `24 / 32` | `156 / 1,013` |
| 2 | `I2` | Timeclock auto-clockout alert | **Loss** (no PR) | `1.12 / 7.76` | `8.6 / 33.1` | `6.9 / 3.8` | `–` / `no PR` | `–` | `–` |
| 3 | `I3` | Mailbox embedded image attachments | **Win** (`C` / `C`) | `0.97 / 7.16` | `7.0 / 40.6` | `7.4 / 5.8` | `as_is` / `small_fixes` | `11 / 22` | `130 / 412` |
| 4 | `I4` | Flaky wheel-scroll browser test | Tie (`C` / `none`) | `1.66 / 30.82` | `29.7 / 219.4` | `18.6 / 7.4` | `as_is` / `as_is` | `0 / 0` | `45 / 56` |
| 5 | `I5` | Payment reminder override-date check | Tie (`C` / `B`) | `1.28 / 12.58` | `9.4 / 62.7` | `9.8 / 6.7` | `as_is` / `small_fixes` | `21 / 13` | `243 / 687` |
| 6 | `I6` | Bid email send-time signature | **Win** (`C` / `C`) | `1.58 / 74.14` | `12.5 / 295.9` | `46.9 / 23.6` | `small_fixes` / `no` | `103 / 207` | `153 / 1,736` |
| 7 | `I7` | Zero conference call date phantom log | Tie (`B` / `C`) | `1.63 / 38.34` | `14.7 / 185.8` | `23.5 / 12.7` | `as_is` / `no` | `75 / 114` | `221 / 1,808` |
| 8 | `I8` | Tour manager address-change email | Tie (`C` / `B`) | `1.47 / 11.62` | `11.7 / 52.8` | `7.9 / 4.5` | `as_is` / `no` | `69 / 80` | `185 / 963` |
| 9 | `I9` | Summary dashboard session lock | Tie (`C` / `B`) | `1.41 / 15.54` | `9.9 / 61.3` | `11.0 / 6.2` | `as_is` / `small_fixes` | `16 / 16` | `45 / 448` |
| 10 | `I10` | Foreign profile state defaults `AL` | **Loss** (no PR) | `1.11 / 3.18` | `9.4 / 18.6` | `2.9 / 2.0` (`1.98`) | `–` / `no PR` | `–` | `–` |
| **Total / Med.** | `10` | `2 wins · 3 losses · 5 ties` | — | **`13.39 / 223.71`** | **`125 / 1,095`** | **`10.4× / 6.4×`** | **`7/8` vs `1/8` `as_is`** | **`319 / 484`** | **`1,178 / 7,123`** |

## Operational lessons & follow-up changes

1. **Test separation alone is not free:** Separating test planning, RED writing, GREEN coding, refactoring, and verification across fresh contexts destroyed shared context and consumed 50% of spend before production edits started (`10.4×` median cost), even while catching real defects on `I6` and `I7`.
2. **Verification without caps produces test bloat:** Up to `14` test-plan verifications (`I6`) inflated tests to `8.4–52.8` lines per production line (including source-token `eval` harnesses on `I5`, `I7`, `I9`).
3. **Human blocking questions fail unattended runs:** `stopped-to-ask` on `I2` and `I10` delivered no PR where the Baseline documented a sensible assumption and shipped; unattended ticket pipelines need explicit stated-assumption fallbacks rather than blocking halts.
4. **Methodological upgrades carried into Study 2:** Make blind human merge-readiness primary alongside an expanded multi-family grader panel, replace same-model panel voters with non-arm model graders plus structured five-criterion rubrics, automate deterministic task selection with `tests_on_base`, and test the Codex-orchestrated update directly — reported in [Maker-Checker TDD vs. a Single-Agent Ticket Run (Study 2)](https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/).

## Self-test for agents reading this file

Verify you can answer without opening the human paper:

1. *Why did the Candidate lose Pairs 2 (`I2`) and 10 (`I10`) despite no defect being found in its code?* Because its planning/verification stage paused on an unanswered product question and exited without opening a pull request, counting as process failures under `RQ1`/`RQ3`.
2. *In Pair 6 (`I6`), why did both model graders prefer the Candidate while the blind human author rated the Baseline higher?* Model graders had the cross-test output showing the Baseline deleted coordinator notes beside signatures, whereas the blind author inspected formatted diffs alone (`small_fixes` vs Candidate's `don't merge` due to 1,736 lines of brittle/email-pinned tests).
3. *Why was a second comparison still needed after clear rejection (`2/10`, `10.4×` cost)?* Because Study 1 tested the Candidate under a Claude Code orchestrator on 29 October rules with an unrecorded shortlist filter and same-model graders; Study 2 equalized both orchestrators on Codex `gpt-6.1-sol`, tested 64 subsequent Candidate commits across 17 deterministically selected tasks (+ 3 repeats), and used a 6-family non-arm grader panel.
