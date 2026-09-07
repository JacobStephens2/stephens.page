# vaulted-agent - short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.22**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent#readme

v0.4.22: Bitwarden launch lookup optimization reuses vault listing within each manifest resolution (fixes #100). Fail-closed handling for glued 0.3.0 bash refresh lines (#99). Includes `va update` from v0.4.21 and `va agy` from v0.4.20.
