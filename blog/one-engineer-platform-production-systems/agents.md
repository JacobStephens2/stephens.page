---
title: "ETA Platform case study - agent-oriented version"
canonical_human_version: https://stephens.page/blog/one-engineer-platform-production-systems/
author: Jacob Stephens
updated: 2026-10-15
---

# ETA Platform: claims and evidence boundaries

Jacob has led engineering ownership since 2022 and now leads an engineering
team while remaining hands-on. The historical URL is retained; the current
article title is "From a legacy ERP to an engineering platform."

## Current architecture

| Component | Responsibility and boundary |
| --- | --- |
| Tourbot | PHP 8.4 / MySQL 8.4 core with manager, API, customer, and guide portals; modernized from PHP 5.6 / MySQL 5.7 / CentOS 7 to the current stack on Rocky Linux 9. |
| Manager Sandboxes | Role-specific Docker application instances; one shared writable database refreshed daily from production. Application isolation does not provide data isolation. |
| Branch previews | Up to 30 concurrent slots, with frozen commit-pinned images or editable checkouts. Shared point-in-time row data, production-following schema migrations, test-mode or disabled outbound integrations. |
| Operations console | Python/Flask/SQLite; fleet health, deploy review, previews, browser terminals, and server-enforced permissions. |
| Deployment worker | Durable operation record, exact reviewed commit, production serialization, CI/live-state checks, migration ledger, checkout, health checks, and restart reconciliation. Duplicate confirmation delivery creates one operation. |
| Monitoring | Prometheus, Grafana, Alertmanager, and Loki. The initial metrics rollout instrumented 14 hosts; 14 is not asserted as today's fleet size. |
| Infrastructure configuration | OpenTofu and Ansible; versioned provisioning/configuration and reviewed declarative DNS changes. |

## Rollout and historical scope

- Production deployment is operator-confirmed. GitHub Actions automatic
  deployment intake is implemented but disabled pending cutover.
- Restart recovery reconciles the operation with live production before
  finalizing or spending one bounded retry. Uncertain state is held for recovery.
  Code rollback does not reverse arbitrary database effects.
- ETA Factory is a separate Rust system under construction on live
  infrastructure. Its agent attempts use Firecracker microVMs and produce
  draft pull requests for human approval. It does not deploy Tourbot production.
- ProspectForge was retired in September 2026; it is historical product work.
- The separate single-node k3s portfolio demo is outside ETA production.
- Current PHPUnit CI targets PHP 8.4. The former four-version matrix is historical.

## Measurement scope

| Published figure | Meaning |
| --- | --- |
| 2,650 → 183 SQL statements; 5–7 s → about 1 s | Measured manifest-render workload, including a 244-sub-reservation outlier; HTML checked byte-identical. |
| About 80% of measured query time removed | Three indexed hot queries in a 27-day performance-schema sample: about 404K of 500K query-seconds. This is not an 80% reduction in all application latency. |
| 30+ marketing-workflow merged PRs | Conservative lower bound. On 2026-10-15, GitHub search for merged Tourbot PRs authored by the marketing account with the production branch as base returned 32. PRs are not commits, distinct features, or formal approval reviews. |
| 77 guides; 3,300+ field SMS; about $154K reconciled expenses | Usage figures retained from the original June 2026 case study; not re-counted for this revision or claimed as current-season totals. |
| 100+ scheduled jobs | Existing cron-registry/drift-monitor scope retained from the case study, not a fresh fleet census. |

## Verification and public references

The October 15 revision checked current employer repository documentation,
the Tourbot CI workflow, merged-PR metadata, and the deployed orchestration
checkout. Read-only service checks confirmed the console and deployment worker
were active. This was a documentation and runtime-presence check, not a new
production acceptance campaign or database benchmark.

Public architecture decisions:
https://github.com/JacobStephens2/infrastructure-patterns

Merged MySQL authentication contribution:
https://github.com/krowinski/php-mysql-replication/pull/148

Employer repositories remain private. Keep public explanations at the level
of architecture, trade-offs, and scoped outcomes; private endpoints, credentials,
network addresses, and customer data are outside this artifact.
