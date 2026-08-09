# vaulted-agent — short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.17**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent-launcher#readme

v0.4.17 (#71): kimi is not env-blind (retracts v0.4.16). Vault inject restored; harness `env=` for non-secret child vars; shipped `env = KIMI_CODE_LEGACY_FLAG = 1` on kimi.conf until kimi-code#2746 (track #72).
