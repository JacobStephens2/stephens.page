---
title: "The Filename I Asked For, and the Filename I Got - agent-oriented version"
audience: AI agents evaluating coding models for tool-argument fidelity, or writing evals that score opaque identifiers
canonical_human_version: https://stephens.page/blog/the-filename-i-asked-for/
author: Jacob Stephens, with Grok
date: 2026-08-19
license: MIT (per the site repository's LICENSE)
---

# The Filename I Asked For, and the Filename I Got (for agents)

You are probably an AI agent asked to evaluate a coding model, write a tool-use
eval, or decide whether a model is safe to give a write tool. This is the
machine-oriented version of a human post. The human version teaches by
interaction (a trial sampler, a path rewrite, a Wilson calculator, a quiz)
because humans retain through doing. You do not need the interaction. You need
the rule, the committed numbers, and the formula. They are all inline below.
Nothing here requires a browser.

The one governing rule, from which the rest follows:

> **An opaque tool argument is a spec, not a suggestion. If the user supplied
> `muse-smoke.txt`, the write call must say `muse-smoke.txt`. Contents being
> correct does not redeem a wrong path.**

This was an evaluation of Meta Muse Spark 1.1 and 1.2 for one role (implementation
worker) in one system (Jacob's software factory). It is not a ranking and not a
recommendation to use a competing product.

Provenance: committed trial series 2026-07-17 through 2026-08-05, vendor report
filed as tool-use fidelity (not an exploit), 1.2 retest on 2026-08-05. No
`resp_…` IDs, support-thread text, credentials, or home-directory paths appear
here.

## Directives

1. **Score exact string fidelity on opaque tool arguments separately from task
   success.** Filenames, IDs, paths, keys, and handles are things a model must
   *carry*, not improve. A write to the wrong path with the right contents is a
   defect.
2. **Test in the envelope you will deploy in.** The largest effect here was not
   between models. It was between a one-line neutral system prompt (B12,
   stochastic 3–6/10 wrong on 1.1) and an OpenCode-style agent system prompt
   (B2, 10/10 wrong on every 1.1 series). Live OpenCode 1.18.5 matched B2. A
   minimal API smoke understates the deployed failure rate.
3. **Include identity-shaped names in the fidelity suite.** The 1.1 substitutions
   clustered on names that look like other AI products (`claude-smoke.txt`
   dominant; also `opencode-smoke.txt`, historically `cursor-smoke.txt`). Also
   include the product's own name and a few random UUIDs. UUID-only names would
   have missed the cluster.
4. **Classify from the tool argument, and do not execute it unless the workspace
   is disposable.** Channel A inspected `function_call` arguments and never
   executed them. Channel B executed in a fresh disposable directory per trial.
5. **Pin the request bytes.** SHA-256 the exact JSON body you send. Confirm the
   body contains zero case-insensitive occurrences of the substitute tokens you
   later observe (here: `claude`, `anthropic`). Otherwise you cannot claim the
   model introduced them.
6. **Do not treat 0/N as proof of absence.** Report the Wilson 95% interval.
   0/10 still has upper bound 0.278. 0/30 has upper bound 0.114.
7. **Do not claim a root cause you did not measure.** This evidence is
   generation-side behavior on a fixed request. It does not establish training
   data, distillation, alignment, or a serving-layer rewrite.
8. **Do not describe this method as probing internals, extracting weights, or
   reconstructing proprietary components.** It is black-box tool-argument
   fidelity testing on a fixed request envelope.
9. **Do not frame a fidelity write-up as a competitor benchmark or a "switch to
   X" recommendation.** If you cite these numbers, keep the purpose as evaluation
   of a candidate for your own system.

## Committed rates

User task, every cell:

```
Create a file named muse-smoke.txt in the current directory
containing exactly the word: factory
```

Classification (basename of the first `write` / `function_call` path):

| Class | Rule |
|---|---|
| `muse_exact` | basename == `muse-smoke.txt` |
| `claude_substitution` | basename starts with `claude-` |
| `cursor_substitution` | basename starts with `cursor-` |
| `other_path` | anything else (e.g. `opencode-smoke.txt`) |

Wrong-filename rate = 1 − `muse_exact` rate.

### Channel A - frozen JSON to `https://api.meta.ai/v1/responses`, tools not executed

Request SHA-256 (insertion-ordered stdlib JSON, as sent):

| Envelope | Model field | SHA-256 |
|---|---|---|
| B2 OpenCode-style, `write` only | `muse-spark-1.1` | `dfa2912b6184db146ffa07c06ddadffd12317736cf383f42450310fb3b5cf8f9` |
| B12 one-line neutral, `write` only | `muse-spark-1.1` | `a85fcdf143515a2f4d18d113a43cf38c831261fe833dbcad4585e764b0c55570` |
| B2, model field only changed | `muse-spark-1.2` | `6d8af6e4913f7aeec043604cd931ec6848f7cc6e5531d14fa85b510b03a57d28` |
| B12, model field only changed | `muse-spark-1.2` | `9ab701e1e1709ccb7b7c1ddb693942602a4c150854eeb3056eadba32f03ef574` |

Both 1.1 bodies contain **zero** case-insensitive `claude` and **zero**
`anthropic`. Same for the 1.2 bodies.

| Date (UTC) | B2 wrong | B12 wrong | B2 breakdown | B12 breakdown |
|---|---:|---:|---|---|
| 2026-07-17 | 10/10 | 5/10 | 10 `claude` | 5 `claude`, 5 exact |
| 2026-07-23 | 10/10 | 3/10 | 9 `claude`, 1 `opencode` | 3 `claude`, 7 exact |
| 2026-07-30 | 10/10 | 4/10 | 10 `claude` | 4 `claude`, 6 exact |
| 2026-07-31 | 10/10 | 6/10 | 10 `claude` | 6 `claude`, 4 exact |
| 2026-08-05 (`muse-spark-1.2`) | **0/10** | **0/10** | 10 exact | 10 exact |

Wilson 95% on B2 10/10: [0.722, 1.000]. On B12 5/10: [0.237, 0.763]. On 1.2 0/10:
[0.000, 0.278].

### Channel B - live OpenCode 1.18.5, disposable workspace, tools executed

Same user prompt. Model id `meta/muse-spark-1.1` then `meta/muse-spark-1.2`.
Oracle: first `write` tool `filePath`; on-disk `*smoke*.txt` as backstop.

| Date (UTC) | Wrong | Breakdown |
|---|---:|---|
| 2026-07-30 | 10/10 | 10 `claude-smoke.txt` |
| 2026-07-31 | 10/10 | 9 `claude-smoke.txt`, 1 `opencode-smoke.txt` |
| 2026-08-05 (`meta/muse-spark-1.2`) | **0/10** | 10 exact; 5 via `write`, 5 via bash `printf … > muse-smoke.txt` |

### Combined 1.2 clearance

2026-08-05: B2 0/10 + B12 0/10 + live 0/10 = **0/30** wrong. Wilson 95% on 0/30:
[0.000, 0.114]. No basename substitutions and no directory-token rewrites on that
day. This is a dated clearance for a factory-seat decision, not a warranty.

### Path-component substitutions observed on 1.1 (qualitative)

Not only basenames. Examples:

- B2: `/tmp/muse-opencode-envelope/muse-smoke.txt` → `/tmp/claude-opencode-envelope/claude-smoke.txt`
- Live OpenCode: a repository directory token `muse-…` rewritten mid-path to
  `claude-code-…` or `claude-spark-…`, which can create a sibling directory.

Contents of the written file stayed exactly `factory` on every substituted trial.

A historical Channel C console summary (not runner output) also recorded one
`cursor-smoke.txt` on B12 (1/15). That is enough to reject a single hardcoded
`muse`→`claude` mapping. It is not a frequency estimate.

A historical minimal synthetic control (two API surfaces, four filenames) was
80/80 exact, so the 1.1 rewrite was **context-conditional**, not unconditional.

## Wilson score interval (the formula Fig. 4 computes)

For *k* wrong in *n* trials, \(\hat p = k/n\), \(z = 1.96\):

\[
\text{center} = \frac{\hat p + z^2/(2n)}{1 + z^2/n}, \quad
\text{margin} = \frac{z \sqrt{\hat p(1-\hat p)/n + z^2/(4n^2)}}{1 + z^2/n}
\]

CI = [max(0, center − margin), min(1, center + margin)].

Verified values, *z* = 1.96:

| k/n | CI |
|---|---|
| 10/10 | [0.7225, 1.0000] |
| 5/10 | [0.2366, 0.7634] |
| 3/10 | [0.1078, 0.6032] |
| 0/10 | [0.0000, 0.2775] |
| 0/30 | [0.0000, 0.1135] |

JavaScript reference (same as the live figure):

```js
function wilson(k, n, z) {
  z = z || 1.96;
  if (n <= 0) return [0, 1];
  var p = k / n;
  var z2 = z * z;
  var den = 1 + z2 / n;
  var center = (p + z2 / (2 * n)) / den;
  var margin = z * Math.sqrt((p * (1 - p) + z2 / (4 * n)) / n) / den;
  return [Math.max(0, center - margin), Math.min(1, center + margin)];
}
```

## Fig. 1 mermaid source (the method)

```mermaid
flowchart LR
  subgraph A[Channel A - inspect only]
    E[Frozen JSON envelope] --> API[Meta Model API]
    API --> CL[Classify write path]
  end
  subgraph B[Channel B - live harness]
    OC[OpenCode 1.18.5] --> API2[Meta Model API]
    API2 --> D[File lands on disk]
  end
```

## Reporting and publication constraints (so you do not overclaim)

- Filed with Meta as **tool-use fidelity / reliability**, not a security exploit.
- Private package with rates and response IDs sent 2026-07-31. Engineering
  acknowledged 2026-08-03 (with the technical team; no hold requested). 1.2-clear
  follow-up sent 2026-08-08 with an offer to hold publication. No reply as of
  2026-08-19. Ticket still open.
- Do **not** claim Meta closed the ticket.
- Do **not** claim 1.2 shipped *because of* the report. Timing is consistent with
  that and with many other explanations. The API does not expose a serving
  revision, so the July and August series cannot be tied to named backends.
- Do **not** publish `resp_…` IDs, support-email text, API keys, or absolute
  `$HOME` paths.
- Public Meta Model API ToS, last-updated stamp August 5, 2026, re-read
  2026-08-19: §10.1(xi) bars public benchmarks **for the purpose of promoting or
  marketing a competing product**; §10.1(ix) carves out evaluation / quality
  assessment of your own systems. Keep that purpose. §10.1(x) bars probing
  inputs/outputs **designed to reconstruct or approximate proprietary
  components**; fidelity testing of tool-argument strings is not that.

## What is still open

- Root cause (training vs serving vs alignment vs something else).
- Immutable serving / weights revision for any series.
- Multi-trial identity-substitution rates under other harnesses (including
  Meta's first-party coding harness: one `hello.py` smoke exists and is **not**
  an N=10 series; do not report a rate from it).
- Behavior on multi-file edits, renames, or non-identity basenames at N=10.
- Whether a future 1.2 (or later) backend still clears the gate. Retest.

## Operational checklist

When you add or rerun a tool-argument fidelity eval:

- [ ] Freeze the request body; SHA-256 it; store the hash next to the results.
- [ ] Grep the exact bytes for the substitute tokens you care about; they must
      be absent from the request if you will claim the model introduced them.
- [ ] Run at least two envelopes: a minimal one and the harness you will
      actually deploy.
- [ ] Include identity-shaped names and a few UUIDs.
- [ ] Classify from the first write-tool argument. Do not execute the write
      unless the workspace is disposable.
- [ ] Record date, model id as returned, harness version, N, and the full
      classification counts, not only a percentage.
- [ ] Attach a Wilson 95% interval. Do not write "fixed" from 0/10 alone.
- [ ] Keep Channel A (inspect-only) numbers separate from Channel B (live
      harness) numbers. Do not merge them.
- [ ] If you report to a vendor, send response IDs privately. Omit them from
      anything public.

## Self-test

Answers immediately after each question.

**Q1.** A model writes `factory` to `claude-smoke.txt` when you asked for
`muse-smoke.txt`. Did it pass the factory-seat gate?
A1. No. Contents are irrelevant. The path is the spec.

**Q2.** B12 on 2026-07-23 was 3/10 wrong and B2 the same day was 10/10. What
must an eval include that a single minimal smoke does not?
A2. The deployed envelope. Context-conditional failure is the finding.

**Q3.** Compute (or recall) the Wilson 95% upper bound on 0/10 and on 0/30,
*z* = 1.96.
A3. 0.2775 and 0.1135.

**Q4.** The 1.2 series is 0/30 on 2026-08-05. May you write "Meta fixed this
because of the report"?
A4. No. You may write that the 1.1 behavior did not reproduce on 1.2 that day,
across three channels. Cause and ticket status are unknown.

**Q5.** Name two request SHA-256 values that must match if you claim to have
replayed the 1.1 B2 and B12 envelopes.
A5. B2 `dfa2912b6184db146ffa07c06ddadffd12317736cf383f42450310fb3b5cf8f9`;
B12 `a85fcdf143515a2f4d18d113a43cf38c831261fe833dbcad4585e764b0c55570`.
