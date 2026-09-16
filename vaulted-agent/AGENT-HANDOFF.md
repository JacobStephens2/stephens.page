# vaulted-agent - short handoff

**Full agent contract:** [AGENTS.md](./AGENTS.md) (also https://vaultedagent.com/AGENTS.md)

Current pin: **v0.4.24**

```bash
curl -fsSL https://vaultedagent.com/install.sh | bash
vaulted-agent version
va doctor
va secrets validate
```

Product: https://vaultedagent.com/ · Repo README: https://github.com/JacobStephens2/vaulted-agent#readme

v0.4.23 adds Muse Code as a standard Harness: va muse launches in the caller’s directory with native permissions; va muse --yolo forwards the flag unchanged. Installer discovery and Doctor support include Muse. Start with a configured Backend and Manifest to inject secrets. Native resume: va muse resume --last.

v0.4.24 makes va update add missing detected Harnesses, preserving existing profiles. After upgrading from v0.4.23 or earlier, run va update --sync-harnesses once. Subsequent updates perform both steps automatically. New Harnesses inherit a Backend/Manifest pair only when existing profiles agree; otherwise configure the empty starter to enable injection.
