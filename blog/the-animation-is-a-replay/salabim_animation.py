"""Render a short, genuine salabim animation of the software factory.

The browser executes this file inside Pyodide. salabim advances the model and
Pillow records its animation frames into an animated WebP. JavaScript only
displays the returned artifact.
"""

from __future__ import annotations

import json
import random
from pathlib import Path

import salabim as sim


PARAMS = {
    "arrivalMean": 25,
    "codeMean": 45,
    "ciTime": 10,
    "failP": 0.25,
    "agents": 3,
    "reviewers": 1,
    "reviewMean": 30,
    "horizonMin": 360,
}


def render_salabim(seed: int, theme: str = "light") -> str:
    """Run six simulated hours and return metadata for the animated WebP."""

    rng = random.Random(seed)
    dark = theme == "dark"
    palette = {
        "background": "#0e1311" if dark else "#eef2ef",
        "surface": "#1b2520" if dark else "#fcfdfc",
        "ink": "#e8efec" if dark else "#111714",
        "muted": "#95a69e" if dark else "#4a5750",
        "rule": "#28362f" if dark else "#cfd8d3",
        "brand": "#7ec49b" if dark else "#215239",
        "coding": "#8fb9ff" if dark else "#315f9b",
        "ci": "#d5a0f0" if dark else "#7a3e9d",
        "review": "#72d1c2" if dark else "#0b6b61",
        "deployed": "#9fd7a5" if dark else "#1d6b3a",
        "token_text": "#0e1311" if dark else "#ffffff",
    }
    output = Path(f"/tmp/salabim-factory-{seed}-{theme}.webp")
    output.unlink(missing_ok=True)

    env = sim.Environment(
        random_seed=seed,
        blind_animation=True,
        yieldless=False,
        time_unit="minutes",
    )
    coding = sim.Resource("coding", capacity=PARAMS["agents"], env=env)
    reviewers = sim.Resource("review", capacity=PARAMS["reviewers"], env=env)
    ci_active = sim.Queue("ci active", env=env)
    deployed = sim.Queue("deployed", env=env)
    counts = {"arrivals": 0, "rework": 0, "deployed": 0}

    class WorkItem(sim.Component):
        def setup(self, item_id: int) -> None:
            self.item_id = item_id

        def animation_objects(self, id: str):
            token = sim.AnimateRectangle(
                spec=(-15, -9, 15, 9),
                fillcolor=id,
                linecolor=palette["ink"],
                linewidth=1,
                text=str(self.item_id),
                textcolor=palette["token_text"],
                fontsize=10,
                env=env,
            )
            return 36, 24, token

        def process(self):
            while True:
                yield self.request(coding)
                yield self.hold(rng.expovariate(1 / PARAMS["codeMean"]))
                self.release(coding)

                self.enter(ci_active)
                yield self.hold(PARAMS["ciTime"])
                self.leave(ci_active)

                if rng.random() < PARAMS["failP"]:
                    counts["rework"] += 1
                    continue
                break

            yield self.request(reviewers)
            yield self.hold(rng.expovariate(1 / PARAMS["reviewMean"]))
            self.release(reviewers)
            counts["deployed"] += 1
            self.enter(deployed)
            yield self.passivate()

    class Arrivals(sim.Component):
        def process(self):
            item_id = 0
            while True:
                yield self.hold(rng.expovariate(1 / PARAMS["arrivalMean"]))
                item_id += 1
                counts["arrivals"] += 1
                WorkItem(item_id=item_id, env=env)

    Arrivals(env=env)

    env.animation_parameters(
        width=640,
        height=360,
        fps=10,
        speed=60,
        x0=0,
        y0=0,
        x1=640,
        show_time=False,
        show_menu_buttons=False,
        background_color=palette["background"],
        foreground_color=palette["ink"],
        video=str(output),
        video_repeat=1,
    )

    sim.AnimateText(
        text="salabim 26.0.8 runs + draws",
        x=24,
        y=335,
        text_anchor="nw",
        fontsize=19,
        textcolor=palette["ink"],
        env=env,
    )
    sim.AnimateText(
        text=lambda: f"sim clock {env.now() / 60:0.1f} h",
        x=616,
        y=335,
        text_anchor="ne",
        fontsize=15,
        textcolor=palette["muted"],
        env=env,
    )

    stage_x = (76, 212, 338, 462, 578)
    stage_names = ("coding queue", "coding", "CI", "review queue", "deployed")
    for x, label in zip(stage_x, stage_names):
        sim.AnimateText(
            text=label,
            x=x,
            y=285,
            fontsize=13,
            textcolor=palette["muted"],
            env=env,
        )

    for x0, x1 in zip(stage_x, stage_x[1:]):
        sim.AnimateLine(
            spec=(x0 + 42, 226, x1 - 42, 226),
            linecolor=palette["brand"],
            linewidth=2,
            env=env,
        )

    sim.AnimateText(
        text="CI fail ↻ coding",
        x=338,
        y=180,
        fontsize=11,
        textcolor=palette["muted"],
        env=env,
    )
    sim.AnimateText(
        text="reviewing",
        x=498,
        y=170,
        fontsize=11,
        textcolor=palette["muted"],
        env=env,
    )

    sim.AnimateQueue(
        coding.requesters(),
        x=24,
        y=245,
        direction="e",
        max_length=3,
        id=palette["brand"],
        title="",
    )
    sim.AnimateQueue(
        coding.claimers(),
        x=164,
        y=245,
        direction="e",
        max_length=3,
        id=palette["coding"],
        title="",
    )
    sim.AnimateQueue(
        ci_active,
        x=315,
        y=245,
        direction="e",
        max_length=2,
        id=palette["ci"],
        title="",
    )
    sim.AnimateQueue(
        reviewers.requesters(),
        x=424,
        y=245,
        direction="e",
        max_length=3,
        id=palette["brand"],
        title="",
    )
    sim.AnimateQueue(
        reviewers.claimers(),
        x=480,
        y=190,
        direction="e",
        max_length=1,
        id=palette["review"],
        title="",
    )
    sim.AnimateQueue(
        deployed,
        x=548,
        y=245,
        direction="e",
        max_length=2,
        reverse=True,
        id=palette["deployed"],
        title="",
    )

    sim.AnimateText(
        text=lambda: (
            f"arrivals {counts['arrivals']}   rework {counts['rework']}   "
            f"deployed {counts['deployed']}   waiting for review {len(reviewers.requesters())}"
        ),
        x=24,
        y=54,
        text_anchor="sw",
        fontsize=15,
        textcolor=palette["ink"],
        env=env,
    )
    sim.AnimateText(
        text="3 coding agents   ·   CI 10 min, 25% fail   ·   1 reviewer",
        x=24,
        y=24,
        text_anchor="sw",
        fontsize=13,
        textcolor=palette["muted"],
        env=env,
    )

    env.animate(True)
    env.run(till=PARAMS["horizonMin"])
    env.video_close()

    return json.dumps(
        {
            "path": str(output),
            "bytes": output.stat().st_size,
            "engineVersion": sim.__version__,
            "seed": seed,
            "simMinutes": PARAMS["horizonMin"],
            "playSeconds": PARAMS["horizonMin"] / 60,
            "fps": 10,
            "frames": 61,
            "theme": theme,
            "arrivals": counts["arrivals"],
            "rework": counts["rework"],
            "deployed": counts["deployed"],
        }
    )
