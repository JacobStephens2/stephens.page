# vaulted-agent - short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.21**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent#readme

v0.4.21: `va update` replaces the installed launcher binary from a GitHub release asset (same stems as install-remote.sh). `--check` and `--dry-run` write nothing. Does not re-run install.sh. `va agy` remains the Antigravity harness from v0.4.20.
