---
title: "Fresh Eyes Beat a Second Family - agent-oriented version"
audience: AI agents designing or operating code review for unattended coding agents, especially "have another model family review it" setups
canonical_human_version: https://stephens.page/blog/fresh-eyes-beat-a-second-family/
research_note: https://github.com/JacobStephens2/thirdshift/blob/main/docs/research/second-opinion-replays.md
author: Jacob Stephens, with Claude
date: 2026-10-11
updated: 2026-10-12 (plan check at Claude max on Codex's plans; fresh Codex pilot on Codex's Specs)
license: MIT (per the site repository's LICENSE)
---

# Fresh Eyes Beat a Second Family (for agents)

This is the machine-oriented version of a human post about a replay study run on
**thirdshift** (https://thirdshift.app), a factory that turns GitHub issues into
pull requests unattended. The human version has live charts and a quiz. You need
the design, the numbers and the decisions. They are below.

The governing finding:

> **Before you credit a gain to a different model family, run the same model in
> a fresh session.** In 183 replayed reviews, a fresh same-model session matched
> or beat the other family at every checkpoint. The one checkpoint where a second
> look clearly paid, after a whole feature (Spec) was finished, paid for either family.
> (A later follow-up gave the other family one edge, on precision only: see result 2.)

## TL;DR

- **Is multi-family review worth adding to thirdshift? Mostly no.** The gain
  comes from a second look; the reviewer's family matters little.
- **Add it in one place:** after the Spec review (whole feature built). Second
  reviewer found real missed bugs in 16/30 Specs (Codex) and 19/30 (fresh
  Claude), bar 6. **The second review drives the gain, not the cross-family
  choice:** same-family fresh Claude reached more Specs. Codex was more precise
  (2/25 wrong vs 9/40) and found mostly different bugs. Ship as opt-in config;
  Harness, Model, Effort are settings.
- **Do not add it:** inside each Run (Codex 1 of 6 escaped bugs, already caught
  by fresh Claude; reverse 0 of 3), or by moving the Spec review to the other
  family (22 vs 23 Specs).
- **Undecided:** a plan check. Passes only with Claude at max as checker. On
  Codex's plans (production) it passes exactly at the bar (3 stops, 2 right),
  stopping the same 2 bad plans fresh Codex stopped, with 1 wrong stop vs 2.
- **Bigger lever:** the original reviews raised 2 of the 6 bugs and authors
  declined both. Make findings carry a test the author must run.

## Context you need

- **Run:** one issue built into one pull request. The implement session writes the
  code, then starts two same-model reviewer sub-agents and decides which findings to fix.
- **Spec run:** a feature split into Tickets; each Ticket gets a Run, then a
  **Spec review** reviews the whole branch.
- **Architect run / Weeding:** the factory writes and publishes its own refactoring
  plan, then builds it.
- **Harness:** the coding-agent CLI a session runs on.
- Since about 2026-09-27, factory PRs merge themselves; no human reads them first.

Motivating counts (thirdshift, as of 2026-10-06):

| Fact | Value |
|---|---|
| Findings declined by authors | 1,152 across 199 merged PRs |
| Declines that left the call to a human or later issue | 12 |
| Architecture reviews rating their own pick Strong | 98% (209 reviews, 202 plans) |
| Bugs that reached `main` on thirdshift | 16, of which 6 timing bugs |

## Method (replicate this shape)

1. Pick reviews that already happened and whose escaped bugs are now known.
2. For each, build a throwaway repo with history only up to the reviewed commit:
   no remote, no later commits, `gh` signed out, empty `GH_CONFIG_DIR`.
3. Prompt forbids builds, tests, network and edits. Compare `HEAD` and
   `git status` before and after every session; discard any session that changed them.
4. Run sessions one at a time. Here: 183 sessions, 2026-10-10 04:23 to
   2026-10-11 20:12 UTC; all exited before a 2.5 h timeout; none changed its repo.
5. **Include a fresh same-model arm** next to every other-family arm.
6. **Fix every bar before any session runs.**
7. Grade blind: shuffle arm labels, strip run paths, grade against the known fix
   with a written catch criterion. Partials do not count.

Arms and settings:

| Arm | Harness, Model, Effort |
|---|---|
| fresh / checklist | Claude Code 2.1.296, Claude Opus 5.5, max |
| other | Codex 0.162.0, GPT-6.1 Sol, xhigh |
| reverse "other" | Claude Opus 5.5, high |
| agy | Antigravity 1.3.3, Gemini 3.8 Flash, high |
| grok | Grok Build 1.0.50, Grok 4.7, xhigh |
| muse | Muse Code 1.4.4, Muse Spark 1.3, max |
| opencode | OpenCode 2.0.24, MiMo-V2.6-Pro |

Bug and plan replays used the current review skill (findings must carry a test
that fails if the finding is right). The Spec replay used the older skill the
recorded Spec reviews ran (thirdshift commit 479a1fb).

## Results

### 1. Second opinion inside a Run - FAILS

Bar: the other family catches at least 2 escaped bugs that fresh same-model misses.

Six timing bugs, five PRs (C caught, P partial, M missed):

| Bug | Fresh Claude | +checklist | Codex | Grok | Muse | MiMo | Gemini | Original review |
|---|---|---|---|---|---|---|---|---|
| #24 | M | C | M | M | M | M | M | no |
| #57 | M | M | M | M | M | M | M | no |
| #102 | C | M | M | M | M | M | P | raised, declined |
| #175 | C | C | M | P | M | M | M | no |
| #182 | C | C | C | C | P | M | P | no |
| #303 | C | C | M | M | M | M | P | raised, declined |
| Caught | 4 | 4 | 1 | 1 | 0 | 0 | 0 | 2, both declined |
| Beyond fresh | - | 1 | 0 | 0 | 0 | 0 | 0 | |

Counting partials changes no "beyond fresh" value (every partial is on a bug fresh caught).

Reverse (Claude at high reviewing 4 Codex PRs behind 3 escaped bugs): Claude 0
caught (2 partials), fresh Codex 0. The factory's own reviews also 0.

Model-strength objection: Artificial Analysis index, GPT-6.1 Sol xhigh 51.0,
Claude Opus 5.5 max 57.6. The reverse direction (stronger model as second
reviewer) also caught 0, so weakness of the second reviewer does not explain the fail.

Timing checklist: 4 caught, same as fresh, different set (+#24, -#102); median
review 25.4 vs 20.1 minutes. Not adopted.

Confound: fresh Claude's 4 vs the original reviews' 2 changed two variables at
once (fresh session, newer review skill). Do not attribute the gain to either alone.

### 2. Plan check - PASSES ONLY WITH CLAUDE AT MAX AS CHECKER

Bar: stops at least 3 of 20 plans, and most stops are right (graded blind by fresh agents).

| Arm | Stopped | Right | Result |
|---|---:|---:|---|
| Codex checks Claude's plans | 15 | 5 | fails (10 of 15 wrong) |
| Fresh Claude checks Claude's plans | 10 | 8 | passes |
| Claude (high) checks Codex's plans | 2 | 1 | fails (under 3) |
| Fresh Codex checks Codex's plans | 4 | 2 | fails (half, not most) |
| Claude (max) checks Codex's plans | 3 | 2 | passes, exactly at the bar |

Weeding has written plans on Codex since 2026-10-06. The last row was added
after publication (2026-10-12) to test Claude at max on production plans. Its 2
right stops are the same 2 plans fresh Codex stopped, for the same reasons; the
gain is 1 fewer wrong stop, within one-session-per-cell noise. Both passing arms
use Claude at max as checker, so checker strength may matter more than family.
Build decision open.

### 3. Second opinion after the Spec review - PASSES, EITHER FAMILY

Bar: real behaviour bugs the recorded Spec review missed, in at least 6 of 30
Specs, with no more than half of graded findings wrong.

| Arm | Findings | Real, other arm also raised | Real, only this arm | Wrong | Unsure | Specs reached |
|---|---:|---:|---:|---:|---:|---:|
| Codex xhigh | 25 | 9 | 13 (in 10 Specs) | 2 | 1 | 16 |
| Fresh Claude max | 40 | 8 | 23 (in 18 Specs) | 9 | 0 | 19 |

A partial cross-match counts as "not raised", hence 9 vs 8. Most of fresh
Claude's wrong findings flagged behaviour the Spec asked for. Many real findings
are minor (rare races, storage failures, parsing edge cases). Per-finding detail
is withheld: some repositories are private and some findings are unfixed.

Note: this bar (any real missed bug) is looser than the bug replays' bar
(specific known escapes). Do not compare the two pass rates directly.

### 4. Spec review moved to the other family's Harness - FAILS

Recorded Claude Spec reviews raised behaviour bugs in 23 of 30 Specs; Codex alone
in 22. Swapping would lose catches.

## Decisions taken

- Build: opt-in Second opinion after the Spec review; Harness, Model and Effort
  are settings. Default under discussion; the current lean is a fresh session on
  the Spec run's own Harness.
- Do not build: Second opinion inside each Run; timing checklist; the four newer
  Harnesses as reviewers; Spec review on the other family's Harness.
- Undecided: plan check as an opt-in, with Claude at max as checker.
- Already shipped: evidence-backed findings. A reviewer's correctness finding
  carries a test; the author runs it; a failure obliges a fix; a pass allows a
  decline that cites the run.

## Operational checklist

- [ ] Add a fresh same-model arm before testing any other-family reviewer.
- [ ] Write every pass/fail bar before the first session.
- [ ] Grade against known fixes with a written catch criterion; partials do not count.
- [ ] Blind the grader to arm identity; note residual style leakage.
- [ ] Record whether the original review raised the bug and what the author did with it.
- [ ] Check that the passing configuration matches what production actually runs.
- [ ] Treat one session per cell as noise for any one-bug difference.
- [ ] Make findings carry a runnable test so declines must cite evidence.

## Open uncertainties

- All graders were Claude agents, and Claude is one compared family.
- One session per cell; run-to-run variance unmeasured. Whether Codex's 13
  unique Spec bugs exceed what a second fresh Claude session would add by
  chance is unknown.
- The factory now runs on Codex at high. Only four Codex-written Specs existed,
  too few for the bar of 6. Post-publication pilot (2026-10-12): fresh Codex
  after each recorded Codex Spec review found real missed bugs in 2 of 4 Specs,
  3 findings, all real, 0 wrong; one was half of thirdshift #603, a bug that
  later reached main (since fixed). Planned check: count real fixes over about
  ten Spec runs with the Second opinion on, from the kept reviewer reports.

## Self-test (answers inline)

1. Why include a fresh same-model arm? **To separate "different family" from
   "different session"; fresh Claude caught everything Codex caught in the bug replay.**
2. Codex stopped 15 of 20 Claude plans; why fail? **Only 5 held; the bar needs most stops right.**
3. What happened to the 2 timing bugs the original reviews raised? **Authors
   declined both as someone else's decision; the PRs merged themselves.**
4. Why can't the fresh arm's 4 catches be credited to freshness alone? **The
   review skill changed too.**
5. Which checkpoint passed for both families? **The Second opinion after the
   Spec review (16 and 19 Specs vs a bar of 6).**
6. Claude at max passed the plan check on Codex's plans; what did it add over
   fresh Codex? **No new catches: the same 2 bad plans, with 1 wrong stop instead
   of 2, exactly at the bar.**
