# vaulted-agent — short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.16**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent-launcher#readme

v0.4.16 (#69): kimi is env-blind for custom OpenAI-compatible providers — keys in `~/.kimi-code/config.toml`, harness stays on empty.env, doctor warns if a vault manifest is attached. Shared `etc/env-blind-agents` for install + doctor.
