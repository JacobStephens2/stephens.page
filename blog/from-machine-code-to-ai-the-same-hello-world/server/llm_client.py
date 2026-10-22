"""OpenAI GPT-5.6 Luna client for AI engineering + vibe coding rungs."""

from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.request
from typing import Any

from llm_spend import estimate_cost_usd, record_spend

MODEL = os.environ.get("HELLO_LADDER_LLM_MODEL", "gpt-5.6-luna")
REASONING_EFFORT = os.environ.get("HELLO_LADDER_LLM_REASONING", "low")
OPENAI_API_KEY = os.environ.get("OPENAI_API_KEY", "")
OPENAI_BASE = os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1").rstrip("/")
# Room for a short program + language + reason (tight contract is multi-part).
MAX_OUTPUT_TOKENS = int(os.environ.get("HELLO_LADDER_LLM_MAX_OUTPUT", "768"))
TIMEOUT_S = float(os.environ.get("HELLO_LADDER_LLM_TIMEOUT", "45"))


class LLMConfigError(RuntimeError):
    pass


class LLMRequestError(RuntimeError):
    pass


def _usage_tokens(usage: dict | None) -> tuple[int, int, int]:
    if not isinstance(usage, dict):
        return 0, 0, 0
    inp = int(usage.get("input_tokens") or usage.get("prompt_tokens") or 0)
    out = int(usage.get("output_tokens") or usage.get("completion_tokens") or 0)
    cached = 0
    details = usage.get("input_tokens_details") or usage.get("prompt_tokens_details") or {}
    if isinstance(details, dict):
        cached = int(details.get("cached_tokens") or 0)
    return inp, out, cached


def _extract_text(payload: dict[str, Any]) -> str:
    text = payload.get("output_text")
    if isinstance(text, str) and text.strip():
        return text.strip()
    chunks: list[str] = []
    for item in payload.get("output") or []:
        if not isinstance(item, dict):
            continue
        if item.get("type") == "message":
            for part in item.get("content") or []:
                if isinstance(part, dict) and part.get("type") in ("output_text", "text"):
                    t = part.get("text")
                    if isinstance(t, str):
                        chunks.append(t)
    return "\n".join(chunks).strip()


def complete(
    *,
    mode: str,
    system: str,
    user: str,
) -> dict[str, Any]:
    """Call GPT-5.6 Luna (reasoning effort low). Returns text + spend fields."""
    if not OPENAI_API_KEY:
        raise LLMConfigError(
            "OPENAI_API_KEY is not set on the hello-ladder service. "
            "Load /etc/stephens.page/site.env via EnvironmentFile."
        )

    body = {
        "model": MODEL,
        "reasoning": {"effort": REASONING_EFFORT},
        "max_output_tokens": MAX_OUTPUT_TOKENS,
        "input": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
    }
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(
        f"{OPENAI_BASE}/responses",
        data=data,
        headers={
            "Authorization": f"Bearer {OPENAI_API_KEY}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT_S) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        err_body = exc.read().decode("utf-8", errors="replace")[:500]
        raise LLMRequestError(f"OpenAI HTTP {exc.code}: {err_body}") from exc
    except urllib.error.URLError as exc:
        raise LLMRequestError(f"OpenAI network error: {exc}") from exc

    wall_ms = (time.perf_counter() - started) * 1000
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise LLMRequestError("OpenAI returned non-JSON") from exc

    text = _extract_text(payload)
    if not text:
        raise LLMRequestError("OpenAI returned empty output_text")

    # Full model reply (tight contract asks for program + language + reason).
    display = text.strip()
    inp, out, cached = _usage_tokens(payload.get("usage") if isinstance(payload.get("usage"), dict) else {})
    cost = estimate_cost_usd(inp, out, cached)
    spend = record_spend(
        mode=mode,
        model=MODEL,
        input_tokens=inp,
        output_tokens=out,
        cached_input_tokens=cached,
        cost_usd=cost,
        meta={"reasoning_effort": REASONING_EFFORT},
    )

    return {
        "text": display,
        "displayStdout": display,
        "stdout": display + ("" if display.endswith("\n") else "\n"),
        "model": MODEL,
        "reasoningEffort": REASONING_EFFORT,
        "inputTokens": inp,
        "outputTokens": out,
        "cachedInputTokens": cached,
        "costUsd": cost,
        "spendTotalUsd": float(spend.get("total_usd") or 0),
        "spendRequestCount": int(spend.get("request_count") or 0),
        "llmWallMs": round(wall_ms, 3),
        "usage": payload.get("usage"),
    }
