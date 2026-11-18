---
title: "Maker-Checker TDD vs. a Single-Agent Ticket Run - agent-oriented version"
audience: AI agents and orchestrator designers evaluating multi-agent maker-checker TDD workflows against single-agent ticket pipelines
canonical_human_version: https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/
pdf_version: https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/maker-checker-tdd-vs-single-agent-ticket-run.pdf
markdown_version: https://stephens.page/blog/maker-checker-tdd-vs-single-agent-ticket-run/paper.md
prior_study: https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/
flagship_essay: https://stephens.page/blog/one-mind-to-build-one-blind-mind-to-break/
author: Jacob Stephens
date: 2026-11-17
license: MIT (per the site repository's LICENSE)
---

# Maker-Checker TDD vs. a Single-Agent Ticket Run (for agents)

This is the machine-oriented companion to the second preregistered, paired, blinded comparison run at Educational Travel Adventures (ETA) on Tourbot (production PHP 8.4 / MySQL 8.4 ERP), following the [10-issue adoption study](https://stephens.page/blog/maker-checker-tdd-ten-issue-adoption-study/) (`thirdshift 0.18.6`, Claude Code orchestrator). Here, both arms run under [thirdshift](https://thirdshift.app) (`0.19.0`) with **both** top-level orchestrators on Codex (`gpt-6.1-sol`, effort `high`), comparing `thirdshift`'s single-agent Built-in Ticket run (**Baseline**) against the updated multi-agent maker-checker test-driven workflow with multi-round cross-family review (**Candidate**).

Governing finding:

> **Do not replace a capable single-agent ticket workflow with multi-session maker-checker orchestration for small-to-medium issues.** Even after moving the Candidate's top-level orchestrator from Claude Code to Codex (`gpt-6.1-sol`, `high`) - which halved the Candidate-to-Baseline cost and time ratios from `10.4×` / `6.4×` (first study) to `4.62×` / `3.28×` - splitting a ~20-minute ticket across 11–64 fresh contexts spent **56.1%** of total Candidate cost in orchestration alone (`76.0%` outside green implementation), lowered unattended delivery from **17/17** to **11/17**, multiplied test lines per production line from **2.64×** to **10.10×**, and dropped blind maintainer `merge as is` readiness from **10/11** (`91%`) to **2/11** (`18%`). Adopt only bounded, single-pass checks inside the single-agent pipeline.

## TL;DR

- **Delivery (`H3`):** Baseline delivered `17/17` primary PRs (`16` green CI, `1` unrelated pre-existing portal test failure on `T12`). Candidate delivered `11/17` (`10` green CI, `1` same CI failure on `T12`), paused `4` times on unanswered questions (`stopped-to-ask`: `T1`, `T8`, `T13`, `T16`), timed out after `699.4` min / `64` sessions / `$64.22` in `15` meta-review loops on `T3`, and failed git push after editing `.github/workflows/` on `T9`.
- **Blind human preference (`H6`):** On the `11` delivered primary pairs (`13` graded pairs with repeats), the blind author rated Baseline `10 as_is / 1 small_fixes / 0 no` (`12/1/0` across `13`) vs. Candidate `2 as_is / 6 small_fixes / 3 no` (`2/7/4` across `13`), preferring Baseline `8–0–3` on delivered first pairs (`10–0–3` across `13`; `14–0–3` counting non-deliveries), choosing the smaller diff on `8/8` non-tied primary pairs.
- **Model panel & colleague sample:** `7-voter` panel (`6` non-arm-model LLMs + author) voted `10–1–0` Baseline on delivered first pairs (`16–1–0` across `17`). `6-LLM` panel voted `5–1–5` Baseline (`8–1–2` when excluding OpenAI's `gpt-6-astra`; `5–0–6` when excluding both Anthropic and OpenAI). Across `2` blind colleague sample reviews (`1–0–1` Baseline): on `Pair 10 / T10` (the lone LLM panel Candidate win), the colleague rated **both** arms `merge after small fixes` (`tie`), noting stronger input validation on Baseline and cleaner test-file separation on Candidate; on `Pair 12 / T12` (where the `6-LLM` panel tied), the colleague rated Baseline `merge as is` vs. Candidate `merge after small fixes` (**Baseline win**, matching the author's blind call).
- **Cost & work (`H2`, `H4`):** Primary-pair medians: Baseline `$1.27`, `18.9` min, `4.0` sessions, `163` test / `67` prod lines (`2.64×`); Candidate `$5.46`, `55.1` min, `11.0` sessions, `454` test / `56` prod lines (`10.10×`; paired median ratios `4.62×` cost, `3.28×` time, `2.32×` test lines). Across all `20` pairs, Candidate cost `$197.70` in tracked phases (`$199.13` total across commands; `290` sessions, `10,901` tool calls) vs. Baseline `$25.93` (`79` sessions, `2,891` tool calls).
- **Independent test fault detection (`H1`) & fail-safe (`H5`):** Candidate cross-tests found **0** confirmed defects in Baseline PRs (`independent_test_defects = 0`). Conversely, a Baseline unit test caught **1** regression in Candidate's `T17` PR (`FILTER_VALIDATE_INT` rejecting valid leading-zero numeric string IDs `'0010'`). Neither arm held a net fail-safe advantage on unexpected inputs (`H5`: `advantage_pairs = 0`).
- **Decisions:** Keep `thirdshift`'s single-agent Ticket run. Evaluate 3 bounded additions individually: (1) automated `tests_on_base` verification, (2) a single parallel non-OpenAI code review pass capped at 1 review-and-fix round, and (3) explicit test-proportionality and no-test-helper-spiral prompt rules.

## Terminology

- **Arm:** Complete workflow under test (`Baseline` = `thirdshift` Built-in Ticket run; `Candidate` = multi-agent maker-checker TDD + cross-family review) at pinned versions.
- **Task:** One closed Tourbot issue (`T1`–`T17`, plus shakedown `T0`) with explicit acceptance criteria, replayed from the exact `ready-for-agent` text at the parent commit before its real fix merged.
- **Trial:** One arm's unattended attempt at one task (`delivered`, `stopped-to-ask`, or `failed`).
- **Pair:** Both arms' trials on one task, prepared and graded together.
- **Transcript:** Complete parent + child sub-agent logs and tool calls for a trial.
- **Outcome:** The resulting PR and repository tree, or non-delivery status.
- **Grader:** A human or model evaluator scoring a pair on the structured five-criterion rubric.
- **Fail safe:** Stopping, refusing, reporting an error, or leaving data unchanged on an unexpected input rather than sending, saving, or showing corrupted output.

## Study design & apparatus

- **Task census (deterministic selection):** All closed Tourbot tasks (1 July – 3 November 2026) with a single merged `master` PR changing `2–8` files and `+13` to `+330` lines, no DB migrations or staging-only changes, not in Study 1, labelled `ready-for-agent`, and containing an explicit observable acceptance-criteria section. `20` met criteria 1–4; `3` failed criterion 5 (`1` became uncounted shakedown `T0`), leaving all `17` tasks (`T1`–`T17`) enrolled chronologically.
- **Repeatability sample:** `3` tasks (`T17`, `T8`, `T15`) sampled with `random.Random(20261103)` and scheduled as Pairs `18–20` (`T17-r2`, `T8-r2`, `T15-r2`). Kept out of the `17`-task primary totals to prevent double-counting.
- **Arms (`thirdshift 0.19.0`, frozen 2026-11-03):**
  - **Baseline (`ticket-run`):** `Codex CLI 0.166.0`, `gpt-6.1-sol` (`high`) single implementation session + parallel `spec_review` & `standards_review` (`gpt-6.1-sol`) + `security_review` (`gpt-6.1-sol`).
  - **Candidate:** `Codex CLI 0.166.0`, `gpt-6.1-sol` (`high`) orchestrator + fresh Codex `gpt-6.1-sol` (`high`) maker sub-agents (`test_planner`, `red_test_writer`, `green_implementer`, `refactorer`, `review_preparer`, `synthesizer`, `meta_reviewer`), fresh intermediate verifiers configured for `claude --print` (`claude-opus-5-5`, `medium`, `Claude Code 2.1.312`) or sub-agent contexts, and up to `5`+ rounds of parallel Claude (`claude-opus-5-5`, `medium`) + Codex (`gpt-6.1-sol`, `low`) review with meta-review routing, followed by `thirdshift` `delivery`.
- **Isolation & execution evidence:** Separate OS accounts, separate private replay repos, scoped tokens, two identical 8-vCPU / 16 GB Ubuntu 24.04 droplets; post-run transcript leak search (`clean: true` on all trials); `php-cs-fixer v3.95.27` (`@PSR12`), stripped Git/agent metadata, `random.SystemRandom()` `X`/`Y` labels. Automated evidence per delivered pair inside an unprivileged `bwrap` / grading sandbox: `cross_tests` (PHPUnit `10.5.63` + headless Chrome `156.0.8078.79`), `base_tests` (`X_tests_on_base`, `Y_tests_on_base`), and scoped `infection 0.35.8` (`PCOV 1.0.12`) on `src/**/*.php`.
- **Graders (dual order `["X", "Y"]` and `["Y", "X"]`, max effort):**
  - **6-LLM Panel:** `claude-sonnet-5-5` (`max`), `gpt-6-astra` (`ultra`), `gemini-4-argon-high` (`high`), `grok-4.7` (`xhigh`), `muse-wonder-full-1.0` (`xhigh`), `moonshotai/Kimi-K3.1-Pro[1m]` (`max`). None shares an exact model ID with either arm. Order flips (`X` vs `Y`) resolve to `tie` (`0`).
  - **2 Diagnostic graders (non-voting):** `claude-opus-5-5` (`max`) and `gpt-6.1-sol` (`ultra`).
  - **Human grading:** Author graded all `13` delivered pairs blind under a two-stage form lock (unaided merge calls sealed before accessing the `gemini-4-argon-high` blind packet assistant or structured form, and before opening any model verdicts). A colleague completed independent blind unaided reviews on `2` pairs: Pair 10 (`T10`) and Pair 12 (`T12`).

## Summary metrics (Table A)

| Measure | Baseline (`ticket-run`) | Candidate (Maker-Checker TDD) |
|---|---|---|
| PRs delivered (17 primary tasks) | `17 / 17` (`16` green CI, `1` red on `T12`) | `11 / 17` (`10` green CI, `1` red on `T12`) |
| Non-deliveries (`stopped-to-ask` / `failed`) | `0` / `0` | `4` (`T1`, `T8`, `T13`, `T16`) / `2` (`T3` timeout, `T9` workflow push) |
| Author merge calls (`as_is` / `small_fixes` / `no`) | `10 / 1 / 0` (`12 / 1 / 0` all `13`) | `2 / 6 / 3` (`2 / 7 / 4` all `13`) |
| Author pair wins (`B`–`C`–`tie`) | `8–0–3` (`11` deliv.) / `14–0–3` (`17` all) | `0` wins |
| Colleague blind sample (`2` pairs: `T10`, `T12`) | `1 as_is, 1 small_fixes` (`1–0–1` B) | `0 as_is, 2 small_fixes` (`0` wins) |
| `7-voter` panel (`6` LLMs + author) | `10–1–0` (`11` deliv.) / `16–1–0` (`17` all) | `1` win (`T10`) |
| `6-LLM` panel (`all 6`) | `5–1–5` (`11` deliv.) / `11–1–5` (`17` all) | `1` win (`T10`) |
| `5-LLM` (`-astra`, OpenAI excluded) | `8–1–2` (`11` deliv.) | `1` win (`T10`) |
| `4-LLM` (`-family`, neither Anthropic/OpenAI) | `5–0–6` (`11` deliv.) | `0` wins |
| Median cost (17 primary; paired ratio `4.62×`) | `$1.27` (`$22.50` / 17; `$25.93` / 20) | `$5.46` (`$177.07` / 17; `$199.13` / 20) |
| Median time (17 primary; paired ratio `3.28×`) | `18.9m` (`311.4m` / 17; about `357.5m` / 20 from rounded trial rows) | `55.1m` (`1,686.7m` / 17; about `1,872.9m` / 20 from rounded trial rows) |
| Median test / prod lines (11 delivered) | `163` / `67` (`2.64×`) | `454` / `56` (`10.10×`; paired test ratio `2.32×`) |
| Repeat self-consistency (`3` repeat tasks) | `2/3` delivery outcome; `2/2` human call | `3/3` delivery outcome; `1/2` 6-LLM panel |

## Pre-registered hypotheses (H1–H6)

1. **H1 (Independent test fault detection) - Not supported (`0` Baseline defects).** Candidate tests found `0` confirmed defects in Baseline PRs across `11` delivered primary pairs (`13` with repeats). Both Candidate cross-test failures on Baseline (`T5`, `T10`) asserted Candidate-private helper structure. Conversely, a Baseline unit test caught a real regression in Candidate `T17` (`FILTER_VALIDATE_INT` rejecting zero-padded numeric strings like `'0010'`).
2. **H2 (Verification rounds & test volume) - Supported.** Candidate ran a median `4.0` verification rounds (`0–21`, `91` rounds across `17` primary pairs, `112` across `20`) vs. `1.0` for Baseline, and wrote `4,953` vs. `1,832` total test lines across `11` primary pairs (`2.32×` paired median; `6,323` vs. `2,250` across `13`).
3. **H3 (Planning questions & unattended stops) - Supported.** Candidate failed to deliver on `6/17` (`35.3%`): `4` `stopped-to-ask` (`T1`, `T8`, `T13`, `T16`; `44.4m` total), `1` 12h timeout on `T3` (`699.4m`, `64` sessions, `$64.22`, stuck in `meta1`–`meta11` building a 32-row formal state-transition table `T01`–`T32` of a local Apache test-fixture `stop()` method after the actual task passed 543 HTTP tests at `1h44m`), and `1` `failed` push on `T9` after editing `.github/workflows/` without `workflow` PAT scope.
4. **H4 (Context re-reading & pre-production spend) - Supported.** Candidate made `2,934` file-read/search tool calls across `17` primary pairs (`3,355` across `20`) vs. `729` (`848` across `20`, `2.65×` paired median, `3.96×` total across 20 pairs), reached first PHP edit at median `11.4m` vs. `1.8m` (`6.11×`), and spent `74.5%` of primary-pair cost (`76.0%` across `20` pairs: `$150.32` of `$197.70`) before green implementation.
5. **H5 (Fail-safe behaviour) - No net advantage.** `advantage_pairs = 0`; human grader recorded `0` unexpected-case defects on either arm across `13` pairs.
6. **H6 (Diff size & preference divergence) - Supported.** Human reviewer preferred the smaller diff/test suite on `8/8` non-tied primary pairs (`10/10` with repeats), citing test bloat on `9/13` graded Candidate PRs. By contrast, `astra` and `grok` preferred the larger diff on `4/9` non-tied pairs, `kimi` on `3/8`, and `sol` on `3/9`.

## Grader panel, position bias & family bias

- **Dual-order neutralization worked:** First-position preference rates spanned `33.3%` (`sol`) to `54.5%` (`kimi`). Order flips (`muse` `5/11`, `kimi` `3/11`, `sonnet` `2/11`, `opus` `2/11`) resolved near-ties to `tie`.
- **OpenAI family bias:** OpenAI graders (`astra` `5–4–1`, `sol` `6–3–1`) gave Candidate `3–4` wins out of `10` complete pairs, whereas Anthropic (`sonnet` `8–1–2`, `opus` `7–0–4`) and Google (`gemini` `7–1–0`) favored Baseline. Removing `astra` lowers the strict-majority threshold from `4 of 6` to `3 of 5` voters. Three primary ties (`T5`, `T12`, `T17`) become **Baseline wins**, yielding `8 Baseline, 1 Candidate, 2 ties` on the 11 delivered first pairs; the repeat `T15-r2` also changes to Baseline. These were not evenly split votes: Table 6 records `3B/2C/1 tie` on `T5`, `3B/1C/1 tie/1 missing` on `T12`, and `3B/0C/1 tie/2 missing` on both `T17` and `T15-r2`.
- **Qualitative rubric profile (`145` completed 6-LLM panel evaluations across `13` pairs):**
  - Merge calls (`as_is` / `small_fixes` / `no`): Baseline `57 (39.3%) / 85 (58.6%) / 3 (2.1%)` vs. Candidate `41 (28.3%) / 91 (62.8%) / 13 (9.0%)`.
  - Codebase fit (`follows` / `minor_departures` / `reshapes`): Baseline `82 (56.6%) / 59 (40.7%) / 4 (2.8%)` vs. Candidate `43 (29.7%) / 94 (64.8%) / 8 (5.5%)`.
  - Unrequested `not_required` items with `review_or_maintenance_cost`: Baseline `115` (`0.79`/call) vs. Candidate `323` (`2.23`/call, `2.81×`).
  - Implementation-coupled tests (`tests_tied_to_implementation`): Baseline `240` (`1.66`/call) vs. Candidate `368` (`2.54`/call, `1.53×`).

## Where the time and cost went

20-pair phase breakdown (Candidate `$197.70` tracked across phases, `290` sessions, `2,958.7m`, `10,901` tool calls vs. Baseline `$25.93`, `79` sessions, `334.4m`, `2,891` tool calls):

| Phase | Candidate sessions (verif.) | Candidate min | Candidate cost (share) | Baseline sessions | Baseline min | Baseline cost (share) |
|---|---|---|---|---|---|---|
| Orchestrator | `23` (`0`) | `1,778.2` | `$110.87` (`56.1%`) | - | - | - |
| Plan + verify | `65` (`26`) | `402.6` | `$24.59` (`12.4%`) | - | - | - |
| RED + verify | `42` (`17`) | `208.7` | `$14.86` (`7.5%`) | - | - | - |
| GREEN / Implement | `32` (`10`) | `199.1` | `$13.97` (`7.1%`) | `21` | `193.5` | `$14.53` (`56.0%`) |
| Refactor | `5` (`0`) | `7.9` | `$1.02` (`0.5%`) | - | - | - |
| Review / meta-review | `84` (`63`) | `232.7` | `$23.12` (`11.7%`) | `38` (spec/std) + `20` (sec) | `62.2` + `78.7` | `$6.82` (`26.3%`) + `$4.59` (`17.7%`) |
| Other (setup, audits) | `39` (`7`) | `129.5` | `$9.28` (`4.7%`) | - | - | - |

Key takeaway: actual `make` + `check` tool-call medians differed only slightly (`94.0` make / `65.0` check for Candidate vs. `88.5` / `59.0` for Baseline, `1.24×` and `1.25×`). Almost the entire tool-call gap came from the Candidate's median `237.5` orchestration calls per run. On Pair 3 (`T3`), the dedicated test-fixture lifecycle test grew to `1,101` lines and `41` cases (`31` failing, `10` passing) against a `17`-line server config fix: the maker called `F2` non-blocking (`"no collision frequency or failed canonical run is established"`, MAST `FM-2.5`), and meta-reviews 12 and 14 called the loop `"expensive churn"` / `"unjustified"` while authorizing another step (`FM-2.6`).

## Five governing CS mechanisms

1. **Conceptual integrity & implicit decisions (`Brooks 1975`; `Yan / Cognition 2025`; `Kim et al. 2025`):** Fresh-context handoffs discard implicit exploration state (`H4`: `4.0×` reads here, `5.2×` in Study 1; `6.11×` / `21.4×` delay to first edit) and cause helper-name mismatches (`T5`, `T10`).
2. **Cleanroom separation vs. Goodhart's Law (`Mills et al. 1987`; `Goodhart 1975`; `Hora & Robbes 2026`; `Chen et al. 2026`):** Grading RED writers before GREEN exists on 10–30 line natural-language tickets turns verifier checklist survival into the target (`10.10:1` here, `14.7:1` in Study 1).
3. **Deterministic state machines vs. prose loops (`Schluntz & Zhang 2024`; `MAST / Cemri et al. 2025`):** `thirdshift`'s fixed Rust state machine delivered `17/17`; LLM-interpreted prose stop rules produced `FM-3.1` (`4` halts), `FM-2.5`, `FM-2.6`, and `FM-1.5` (`11.7h` loop on `T3`).
4. **Intrinsic self-correction blindness vs. diff-blind post-hoc oracles (`Huang et al. 2024`; `Kamoi et al. 2024`; `Knight & Leveson 1986`; `arXiv:2607.05139`):** Combined across both studies (`19` unique delivered pairs: `8 + 11`), Candidate cross-tests exposed Baseline bugs on `2 of 19` (`10.5%`: `I6`, `I7`; `0/11` in Study 2 while a Baseline test caught `1` Candidate regression on `T17`).
5. **Review attention economics & Shape Up (`Sadowski et al. 2018`; `Singer 2019`):** Blind human preference fell strictly to the smaller diff (`8/8` here, `6/6` in Study 1); Shape Up's baseline comparison, fixed appetite, scope hammering, and hard circuit breakers turn this into runner constraints.

## Modular decisions taken for thirdshift

- **Ship / run on every ticket (`~$0`, seconds) - Deterministic `tests_on_base` check & `assume_and_document`:** Verify newly added regression tests fail on `base/` via a deterministic command, and set `on_ambiguity = "assume_and_document"` to eliminate unattended `stopped-to-ask` halts without extra model turns.
- **Evaluate selectively - Bounded `+1` Blind Behavioral Breaker (`public_api_delta.txt`, `≤ 3 min`, `≤ 150` LOC, `max_rounds = 1`):** Given Study 2's `H1 = 0` (`2/19 = 10.5%` combined) and `T5`/`T10` helper-name false positives, extract only added/changed public class/method signatures (`public_api_delta.txt`, stripping method bodies and `tests/` diffs) and gate a single cross-family breaker pass onto stateful data/text-mutation tickets or the `Spec` feature boundary (`Fresh Eyes`), discarding passing tests that add no branch coverage and capping repair to `1` builder resume turn.
- **Evaluate - Single bounded cross-family code review pass (`max_rounds = 1`):** Add one non-OpenAI reviewer (`claude-sonnet-5-5` or `gemini-4-argon-high`) in parallel with `spec_review` and `standards_review`, capped at **1** review-and-fix pass.
- **Evaluate - Review-prompt rules for test proportionality & no helper spirals + Rust runner circuit breakers:** Flag bespoke test harnesses, source-string assertions, and tests of deleted files (`T6`), prohibit recursive formal modeling of test helpers (`T3`), and enforce loop/cost limits in Rust runner code.
- **Reject - Multi-session maker-checker orchestration & unbounded meta-review:** Do not split single-ticket implementation across fresh planner/RED/GREEN/verifier sessions or allow meta-review loops past a hard round cap without human interruption.

## Operational checklist for multi-agent workflow design

- [ ] Measure `orchestrator` spend and tool calls separately from `make` and `check` calls; cap or eliminate context-relay bookkeeping.
- [ ] Run `tests_on_base` deterministically so every regression test proves it fails without the production fix.
- [ ] Enforce hard circuit breakers (e.g., max 1–2 meta-reviews per stage) instead of continuing whenever "changed evidence" appears.
- [ ] Scope-hammer low-severity test-fixture findings to follow-up issues instead of locking a PR in verification loops.
- [ ] Instruct code reviewers to penalize test harnesses that mock/re-implement SQL or internal helpers rather than testing shipping code.
- [ ] Evaluate LLM judges in both presentation orders (`[X, Y]` and `[Y, X]`) and report results excluding judges from any model family used in the arms.
- [ ] Record human unaided merge readiness *before* consulting AI review assistants or model verdicts.

## Threats to validity & open uncertainties

- **Task scope:** All `17` tasks are small-to-medium changes (`2–8` files, `+13` to `+330` lines) starting from already-triaged `ready-for-agent` briefs in one legacy PHP codebase. Greenfield architecture and upstream spec shaping were not tested.
- **Role-recipe reconstruction:** Because the Candidate's Codex-to-Claude account-routing spec remained unimplemented at freeze time, the Codex orchestrator frequently ran sub-roles via native `spawn_agent` threads alongside `claude --print` calls.
- **Historical parent commits:** `4` of `17` tasks (`T1`, `T3`, `T4`, `T6`) predate the repository's unified worktree-setup script, triggering `stopped-to-ask` on `T1` under the Candidate's strict no-auto-setup rule.
- **Human sample size:** One human maintainer (the author, who also develops `thirdshift`) graded all `13` delivered pairs blind; one colleague independently reviewed `2` pairs blind (`T10` `tie`; `T12` Baseline win matching the author).
- **Mutation testing scope:** `infection` covers only `src/**/*.php` classes; tasks touching legacy procedural files, scripts, server config, or `phpunit.xml` produce `0` mutants and rely on `cross_tests`, `base_tests`, and inspection.

## Self-test (answers inline)

1. Did moving the Candidate's top-level orchestrator from Claude Code to Codex (`gpt-6.1-sol`) eliminate the cost/time gap? **No - it roughly halved the ratios (`10.4× → 4.62×` cost, `6.4× → 3.28×` time), but Candidate still spent `56.1%` of total cost in the orchestrator alone and delivered fewer PRs (`11/17` vs `17/17`).**
2. How many Baseline defects did Candidate cross-tests expose across the 11 delivered primary pairs? **Zero (`0`); meanwhile a Baseline cross-test caught 1 regression in Candidate `T17` (leading-zero numeric string IDs rejected by `FILTER_VALIDATE_INT`).**
3. What caused Pair 3 (`T3`) to burn 11.7 hours and `$64.22` without opening a PR? **After passing 543 HTTP tests in `1h44m`, a meta-review ban on deferring low-severity test-fixture findings combined with unbounded "changed evidence" loops and a model-checking rule forced 15 meta-review cycles debating a 32-row state table of the test helper's `stop()` method until the 12-hour timeout hit.**
4. Which arm did OpenAI graders (`astra`, `sol`) vs. non-OpenAI panel graders favor? **OpenAI graders gave Candidate `3–4` wins out of `10` pairs; removing `astra` turned `4` ties into Baseline wins (`8–1–2`), and the 4-family panel excluding both OpenAI and Anthropic voted `5–0–6` Baseline.**
5. What did the blind colleague sample reviews find on Pair 10 (`T10`) and Pair 12 (`T12`)? **`1` Baseline win (`T12`), `0` Candidate wins, `1` tie (`T10`): on `T10` (where the 6-LLM panel picked Candidate), both PRs were rated `merge after small fixes` (`tie`: Baseline had stronger input validation in a crowded test file, while Candidate split tests cleanly into a dedicated file for diagnosis); on `T12` (where the 6-LLM panel tied), the colleague rated Baseline `merge as is` and Candidate `merge after small fixes` (matching the author's Baseline win).**
