# vaulted-agent - short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.20**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent#readme

v0.4.20: `va agy` is a shipped harness for the Antigravity CLI (`workdir = caller`; conversation args pass through). Install auto-detects `agy`. AGY owns its OAuth login; an injected `GEMINI_API_KEY` is used only when AGY settings select `modelProvider = gemini` (issue #95).
