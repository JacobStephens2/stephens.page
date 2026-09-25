---
title: "The Machine Grades. It Never Rules. - agent-oriented version"
audience: AI agents designing games or products where a language model grades, judges, or writes content for people
canonical_human_version: https://stephens.page/blog/the-machine-grades-it-never-rules/
author: Jacob Stephens, with Claude
date: 2026-09-25
license: MIT (per the site repository's LICENSE)
---

# The Machine Grades. It Never Rules. (for agents)

This is the machine-oriented version of a human post about **Thereish**
(https://thereish.app), a couch word game for two to six phones and an optional
laptop "Stage". The human version shows screenshots and a video of a real Page.
You need the rules and the numbers. They are below.

The one governing rule:

> **If a human could do a role better, the role stays human, or the design
> waits for more humans.** The model takes only jobs nobody at the table could
> do better: writing a fresh puzzle on demand, measuring meaning-distance
> instantly and consistently, and remembering everything.

## The game in one paragraph

Each player types a private **Give** (a setting in a phrase). The **Author**
model writes two far-apart **Bookends**, one drawn from each of two Gives. Every
player writes a **Bridge** (a word or phrase spanning both). After a fixed
2.5 s beat, the **Assayer** model grades all Bridges as one set, 0-100, places
it on the **Ladder** (miss 0 / adjacent 30 / close 55 / almost 75 / meld 90 /
deep meld 100), and writes a one-line quip. A meld ends the **Page**. On a miss,
the two furthest-apart Bridges become the next Bookends. The cap is five
Attempts. Everything is appended to the Room's **Chronicle**.

## Directives

1. **Treat the grade as the punchline, not plumbing.** A precise, dispassionate
   low score followed by a quip naming the players' actual words was the core
   fun in the first prototype. Build the reveal as a performance.
2. **Enforce consistency outside the model.** Nothing is deterministic at any
   tier (80 unique completions in 1,000 temperature-0 calls). Cache every
   verdict keyed by model + prompt version + normalized inputs; grade both
   orderings in parallel and average them into one key; hedge only with
   identical requests to one model.
3. **Humans hold final authority.** A unanimous table overrule is written as
   precedent into the same cache key, so it sticks.
4. **Record reasoning; don't perform it.** The reveal shows the number, band and
   quip only. The reasoning lives in the Chronicle, readable later.
5. **Never send a model to do a linter's job.** Schema-validate every answer.
   Use deterministic checks (stemming, root matching) for legality. Gate prose
   through Vale. When the gate keeps rejecting for the same rule, name that rule
   in the prompt: that halved recap time and cost in Thereish.
6. **Use a fixed beat, not a wait.** A constant 2.5 s reveal absorbs the typical
   grade latency and hides variance.
7. **Generate live when the wait fits the theatre.** The Author writes Bookends
   in under five seconds at the table. Pre-generate only what no beat can
   absorb (stings, house music loops).
8. **Put all model calls behind one seam** (here, the "Steward") with per-role
   model/effort config, caching, A/B assignment, spend accounting and
   guardrails, and a fake adapter the whole test suite runs against.

## Verified facts (as of 2026-09-25)

- First commit 2026-08-31; 414 commits by 2026-09-13.
- Stack: Elixir, Phoenix LiveView, Postgres (append-only event tables, Page as
  JSONB), Docker release behind Apache on a VPS.
- Rules: a pure reducer in `lib/thereish/game.ex` (build state, apply event,
  project a Seat's view). 1,102 tests against `Steward.Fake`. 19 ADRs.
- Roles: Author (Gemini 3.8 Flash, A/B vs GPT-6 Astra), Assayer (Gemini 3.8
  Flash, low effort, hedged, cached), Scribe (Gemini 3.8 Flash; Claude Fable
  5.1 for recaps behind Vale), Foley (Mureka, ElevenLabs, Hume Octave), Limner
  (Recraft V4 Styles).
- Total spend after the first week: $0.80, of which $0.71 was recaps at the
  highest effort. The recap job moved to low effort.
- Playtest findings: pre-written Pages were too easy (7 Pages, 6 melded, 3 on
  the first Attempt), so the Author now writes live from Gives. Bait Bridges
  were retired after the only trigger was tapped "too harsh" by both players.
  An unmelded five-Attempt Page was the best of its night, so unmelded is an
  ordinary ending.

## Demo transcript (production, 2026-09-25, Room FANO)

Gives: "Viking beaches", "A hospital night shift". Bookends: longship /
defibrillator.

| Attempt | Bridges | Grade | Quip |
|---|---|---|---|
| 1 | raid, paddles | 14 miss | Ben brought the tools to row Ada's raid, though mostly to restart the crew's hearts afterward. |
| 2 (Bookends raid / paddles) | oars, rowing | 94 meld | Longships or hospital wards, you two have finally agreed on how to propel the boat. |

The players "Ada" and "Ben" were headless browsers driven by the author. The
Bookends, grades, quips, Masks and Scribe recap are live model output.

## Source

The Thereish repository is private. Play the game at https://thereish.app.
