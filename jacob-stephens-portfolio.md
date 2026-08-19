Portfolio

# Real systems, real results.

Platform and infrastructure engineer specializing in safe AI automation for revenue-critical legacy systems. I lead a two-engineer team owning a multi-million-dollar specialty-travel stack - the reservations platform, payment and ACH rails, Linux fleet, manager agent sandboxes, and human-gated software factory it runs on - and the architecture decisions behind them.

* **Professional**
  Production systems built over years at Educational Travel Adventures.
* **Client**
  Focused builds for payments, publishing, and business visibility.
* **Personal**
  Side projects that reflect how I think about product and infrastructure.

## Professional Work

Production systems with operational and revenue impact.

A connected case study tying these systems together - architecture, the legacy→modern arc, and engineering highlights - plus the sanitized architecture decisions behind them.

[Read the ETA Platform case study →](/eta-platform.html)
[Architecture decisions (ADRs) →](https://github.com/JacobStephens2/infrastructure-patterns)

**The seven-boundary agent-safety checklist**
How an AI agent is allowed to touch a revenue-critical legacy system - and where a human stays in command.

1. **Isolation** - each agent runs in its own per-role container; no shared filesystem or database.
2. **Data** - a snapshot-refreshed replica with least-privilege, read-only DB users; production write paths are off-limits.
3. **Command** - default-deny execution; only an explicit allowlist of commands runs.
4. **Code promotion** - nothing reaches production without a human merge and review gate.
5. **Audit** - every action is logged and attributable; the trail reads "the system did X," and is reversible.
6. **Rollback** - every change ships with a kill switch or manual fallback.
7. **Upstream & downstream** - known model provenance, a labor-impact note, and an explicit not-delegated list: no agent approves, merges, deploys, pays, or alters revenue-critical data without a named human.

[The full write-up →](https://stephens.page/blog/human-in-the-loop-ai-agents/)

### Tourbot

The core operations platform at Educational Travel Adventures - a full-lifecycle group travel ERP handling sales pipeline and lead qualification, itinerary building, reservations, vendor management, passenger logistics, payment processing, communications, and 70+ reports across finance, sales, marketing, and operations.

**Problem**
A 40-person travel company needed a single system to run the entire business - from lead inquiry through post-trip reporting - covering sales, finance, operations, vendor coordination, and customer-facing portals without things falling through the cracks.

**What I Built**
A sales pipeline with lead qualification and agency management. An itinerary and quote builder with vendor pricing. Full reservation lifecycle management with passenger manifests, rooming lists, and multi-stage payment schedules. A vendor portal for self-service. A communications center with email, SMS, and document generation. Credit card processing, accounts receivable/payable, and QuickBooks integration. Customer and group leader portals. Employee tools including timeclock and expense management. Insurance and waiver workflows. 70+ reports and integrations with Google Workspace, Slack, and Twilio.

**Result**
An operational backbone that runs every part of the business daily - sales, booking, finance, logistics, and communications - still in production after years of continuous development.

**Webhook Authentication**
Webhook authentication spans senders and receivers: the payment processor's settlement posts are signature-verified with a timing-safe comparison, Tourbot signs its outbound service-to-service webhooks, and the parent-engagement portal verifies those plus Mandrill's email-event signatures. The verifiers are extracted as [webhook-verify](https://github.com/JacobStephens2/webhook-verify), with the write-up in [notes](/notes/hmac-signed-webhooks/).

PHP
MySQL
JavaScript
jQuery
Twilio
QuickBooks
Operations

[Organization](https://github.com/Educational-Travel-Adventures)

### Tourbot Chat

An AI-powered chat system built with OpenClaw and Claude, giving managers at Educational Travel Adventures natural-language access to business intelligence and the ability to safely prototype platform changes themselves - each in an isolated, sandboxed copy of Tourbot.

**Problem**
Managers needed to answer complex business questions - revenue breakdowns, booking trends, vendor performance - without waiting on developer time. They also wanted to experiment with reports and workflows on their own terms, without risking the production system.

**What I Built**
A multi-tenant infrastructure where each manager gets an isolated Tourbot instance - its own Docker container fronted by Traefik - with a dedicated Claude-powered OpenClaw agent. Agents query a replica of the production database refreshed once daily, run analysis, generate reports, and even modify PHP code, all sandboxed per-manager: allowlist-controlled command execution, dedicated least-privilege database users, and a git-based review gate so nothing reaches production unreviewed. Each container is provisioned from a shared compose definition, so standing up a new manager sandbox is a one-command operation.

**Result**
Non-technical managers independently explore business data, generate custom reports, and prototype workflow changes through plain conversation - 14 manager-prototyped features have shipped to production through the human merge gate, across all four roles (sales, marketing, GM, IT), with zero agent-caused incidents - while the system enforces per-manager isolation, read-only data access, and code-review boundaries that keep the production platform safe from accidental or unreviewed change.

OpenClaw
Claude
Docker
PHP
MySQL
Traefik
AI Agents

[Pattern write-up →](https://stephens.page/notes/human-in-the-loop-ai-agents/)
[Organization](https://github.com/Educational-Travel-Adventures)

### Tourbot Terminal: Coding Agents as a Platform Service

Persistent Claude Code and Codex CLI sessions running inside each manager's own sandbox - hosted by the platform, reachable two ways: a browser terminal built into the ERP itself, and Claude's remote-control UI for driving the same sessions conversationally. Where Tourbot Chat gives managers an assistant, this hands them the same coding agents an engineer uses - with the platform supplying the guardrails.

**Problem**
The strongest building tools are terminal agents - Claude Code, Codex CLI - but they assume a shell, SSH, and tmux, none of which a business manager has. The chat assistant covers questions and prototypes, but its API-metered usage costs more per unit of work than subscription-based agent CLIs, and the CLIs are simply better at sustained building. The gap was access, not capability.

**What I Built**
An xterm.js terminal embedded in Tourbot behind a dedicated permission, connected over a token-authenticated WebSocket bridge (Node, node-pty) into the manager's sandbox container - plus a route onto Claude Code remote, so the same server-side sessions can be driven from Claude's remote-control UI instead of the raw CLI. Every session is a persistent tmux session - close the laptop, the agent keeps working; pick it back up from any device, including a phone. A session manager spans both agent families (Claude Code and Codex sessions side by side), with file upload into the agent's workspace, full-scrollback copy, lightweight pane polling for idle detection, and one-click session reset. Under the hood, privilege separation: Claude Code runs under a dedicated non-root account via a sudo wrapper - it refuses to run as root - while other session types stay isolated on a separate tmux server.

**Result**
The general manager and marketing managers run real coding agents against their own sandboxed Tourbot - doing their own building, at subscription cost instead of API metering. In practice most drive Claude through the remote-control UI, with the embedded terminal as the direct view and the Codex path - and every Tourbot Chat boundary still holds either way: container isolation, a replica database, and the human merge gate before anything reaches production. The platform hosts the sessions; the UI is whichever door fits the user. Platform engineering in the literal sense - taking a capability that required an engineer's toolchain and shipping it as a permissioned, self-service feature of the business system.

xterm.js
WebSockets
node-pty
tmux
Claude Code
Codex CLI
AI Agents

[The boundary pattern it runs inside →](https://stephens.page/notes/human-in-the-loop-ai-agents/)

### ETA Software Factory

An agentic engineering platform for Educational Travel Adventures: small, self-describing staff requests in; evidenced draft pull requests out. One human gate - the PR - held by engineering and the company owner. The production deploy path is never touched.

**Problem**
Useful Tourbot changes die in inboxes because every request needs engineer time end to end. Managers already prototype inside sandboxed agents, but the company needed a purpose-built pipeline that turns a staff request into a reviewable PR - with isolation strong enough that untrusted screenshots and agent-written code never sit next to crown-jewel credentials.

**What I Built**
A Rust control plane (Axum supervisor, dashboard, `factoryctl`) on a dedicated host with socket-only PostgreSQL, an append-only ledger, and a draft-only publisher under a GitHub App machine identity. A separate secret-free worker droplet - OpenTofu + Ansible desired state, clean-room replaceable - runs implement and verify work inside Firecracker microVMs with no vault, fleet SSH, or Tourbot database access and only spend-capped model egress. Evidence capture, adversarial cross-family review, and a constitution (`AGENTS.md`) SHA-pinned in CI keep every run attributable. Phase 1 sessions covering isolation, verifier, publisher, dashboard, and the evidence lifecycle are merged on live infrastructure at `factory.etadventures.com`.

**Result**
Phase 1 runs on live hosts: control plane, secret-free Firecracker worker, draft-only publisher, and evidence path are real infrastructure - not a design deck - with the constitution and merge gate enforced in code. The intended loop is staff request → evidenced draft PR; review is the only human cost, and nothing auto-merges or deploys. End-to-end staff adoption is still the build's target, not a claimed steady-state metric. The trust model is written up interactively in public posts on the factory as a canonical model and as multi-engine discrete-event animation.

Rust
Axum
Firecracker
PostgreSQL
OpenTofu
Ansible
AI Agents
Human-in-the-loop

[Seven-boundary agent-safety pattern →](/blog/human-in-the-loop-ai-agents-for-legacy-business-systems/)
[The diagram is not the model →](/blog/the-diagram-is-not-the-model/)
[The animation is a replay →](/blog/the-animation-is-a-replay/)
[Organization](https://github.com/Educational-Travel-Adventures)

### ETA Guides Portal

A mobile-first PWA for tour guide contractors at Educational Travel Adventures - itinerary and medical info access, document workflows, daily and summary reporting with live auto-save, expense tracking with receipt uploads, SMS messaging to travelers, calendar scheduling, and manager-side administration.

**Problem**
Independent contractor tour guides - with no access to the main system - needed a reliable mobile interface to view trip details, access passenger medical and dietary information, file reports and expenses, and communicate with travelers while in the field.

**What I Built**
A full PWA with offline support and service worker caching. Tentative and final itinerary views, passenger manifests, medical conditions, allergies, and dietary reports. Daily reports and post-tour summary questionnaires with live auto-save. An expense system with categorization, receipt attachments, and driver gratuity forms. Twilio-powered SMS messaging to travelers and billing contacts. A calendar with unavailability scheduling. Tour confirmation and ride-along workflows. Manager admin tools for document management and report reassignment.

**Result**
Guides in the field can view trip details, check passenger medical needs, text travelers, file reports as they go, and submit expenses with receipts - all from their phone, online or offline. Managers can administer documents and review reports without developer involvement.

PHP
MySQL
Bootstrap
Twilio
Service Workers
PWA

[Visit Site](https://guides.etadventures.com)
[Organization](https://github.com/Educational-Travel-Adventures)

### ETA Orchestration & Status

A Python and Flask service that probes Educational Travel Adventures' fleet of web, database, and cron servers and renders a single-page health view - paired with a hardened Linux host that lets a Claude Code agent operate safely across that infrastructure.

**Problem**
A dozen production and supporting servers - MySQL 8 and 5.7, Apache, ACH cron processing, Docker-hosted manager sandboxes, an asset host - with no single place to see what was reachable, green, or degrading, and no clean way to give an AI agent multi-server access without leaking credentials or muddying audit logs.

**What I Built**
A Flask app behind Caddy and gunicorn that sweeps TCP, HTTP, and MySQL probes every 60 seconds across the fleet, with deploy controls, fleet-wide tmux management, and a web terminal - all gated by single-use magic-link tokens. The same VM runs a hardened service-account pipeline: secrets injected at launch from a managed vault, database grants scoped to least privilege, and a wrapper that lets engineers trigger the agent without ever handling its credentials. Auditing reads as "the orchestrator did X," not "Engineer Y did X via it."

**Result**
One URL to check fleet health, refreshed in the background so page loads never block on a probe - and a controlled launchpad from which an AI agent can coordinate work between web and database servers with credentials it never sees on disk.

Python
Flask
SQLite
gunicorn
Caddy
systemd
Linux
1Password
Claude Code

### ETA Customer Portal

The customer-facing side of Educational Travel Adventures - group leaders, parents, and travelers self-serve account creation, trip registration, payment plans, and communication. Inherited from a previous IT team and substantially rebuilt by me and the team I lead.

**Problem**
The original portal - built years earlier - had drifted out of step with the rest of the platform: payment integrations were aging, the UI looked dated next to the modern Tourbot interface, and security and PCI posture needed real work as ETA shifted between merchant processors. It needed to keep running every day for paying customers while we modernized it underneath.

**What I Built**
Led the rebuild while keeping the existing portal in production. Migrated the credit-card flow across multiple processors with bank-account encryption and PCI-conscious handling. Re-engineered registration, payment-plan, and refund flows. Modernized the front-end with a Tailwind-based component layer alongside legacy markup. Added in-portal chat, conference-call hooks, and customer-account export/import for cross-database migration. Tightened the API surface and aligned auth with the broader Tourbot platform.

**Result**
A portal that customers actually trust to take their money - clearer flows, modernized payments, current PCI posture - with continuity for the thousands of registrations and payments already in flight when the rebuild started.

PHP
MySQL
JavaScript
Tailwind
PCI

[Visit Site](https://tourbot.etadventures.com/customerweb/)
[Organization](https://github.com/Educational-Travel-Adventures)

### ACH & NACHA Bank-File Automation

The cron-driven pipeline that turns scheduled customer payments into NACHA-formatted batch files and submits them to the bank for ACH processing - the system that actually moves the money. Inherited the original implementation; took ownership, modernized the runtime, and kept it running through platform migrations.

**Problem**
The ACH side of the business depends on producing correctly-formatted NACHA files, uploading them to the bank on a schedule, and reconciling return files when entries fail. The original cron host was stuck on CentOS 7 and PHP 5.6, the code had drifted from the rest of the platform, and the bank doesn't tolerate malformed batches - a bad file is a failed payment day.

**What I Built**
Took over the bank-file generator and the surrounding cron fleet (`nacha\_files\_to\_upload`, `nacha\_returnfiles`, vendor ACH batches, and recurring-payment crons). Modernized the code paths against current PHP, migrated the MySQL backend across versions, added monitoring around the daily cron schedule, and integrated a card-payment processor as a routing target for recurring payments alongside the ACH flow. Kept the bank-facing format and submission cadence rock-steady the entire time.

**Result**
The money kept moving and no one had to notice: customer ACH payments and refunds clear the bank on schedule, return files are processed back into the system, and the pipeline is now legible and modifiable instead of a black box only the original author understood.

PHP
MySQL
NACHA
ACH
Cron
Linux

[Organization](https://github.com/Educational-Travel-Adventures)

### Tourbot Manifest Performance Tuning

A six-round, profile-driven performance pass on Tourbot's group manifest page - the document staff print before every trip - taking page loads from 5-7 seconds to about 1 second by eliminating N+1 queries, batching in-request prefetches, and adding a targeted history-table index.

**Problem**
The manifest page renders everything operations needs about a group reservation in one shot - passengers, payments, waivers, schedules, cancellation history. Each load fired ~2,650 SQL statements and opened 118 fresh MySQL connections, because the `Booking`, `Price_statement`, and `ReturnPaySked` constructors each ran helper queries per row of a 50-passenger group - almost all of it avoidable repeat work.

**What I Built**
Six rounds, each starting from an xdebug cachegrind capture and ending with a byte-identical HTML diff. Collapsed 118 per-request MySQL connections into one via the shared PDO handle. Made `Booking::__construct` skippable so 50 passengers hydrate from a single batched `IN(…)` query, and added a `Price_statement::prefetch()` layer - static caches and batched queries warmed once per request, with per-instance fallbacks for other callers. Replaced a leading-wildcard `LIKE` in the cancellation-history query with an `Action_Type` column and composite index, cutting EXPLAIN's rows-examined from 4,165 to 1.

**Result**
The operations staff who print this manifest before every trip stopped waiting on it: page load dropped from 5-7s to about 1s, SQL statements per request from ~2,650 to 183 (−93%), with rendered HTML byte-identical at every step. Re-validated on a 244-sub-reservation outlier group: ~10s → ~3s and ~11,800 statements → ~2,400, still byte-identical - so the wins scale to worst-case data.

PHP
MySQL
xdebug
Profiling
Query Optimization
Schema & Indexing
Performance

[Organization](https://github.com/Educational-Travel-Adventures)

### Tourbot Alerts: Pre-Computed Cache Architecture

An architectural rework of Tourbot's internal alerts page - a screen staff hit constantly throughout the day - that moves alert computation off the request path entirely. A background PHP daemon pre-computes every alert into a dedicated cache table, so the page just reads pre-shaped rows - cutting load times from 5-10 seconds to under 1 second.

**Problem**
The alerts page recalculated every alert from scratch on each request, fanning out into 100+ MySQL queries against the same booking, item, and product-alert tables with no reuse across users. With staff hitting it many times a day, 5-10 second loads were normal and the slowness was eroding trust in the rest of Tourbot.

**What I Built**
Profiled the request path and hit the worst offenders first: composite indexes on the booking / item / product-alert joins, and per-reservation loops rewritten as batched IN (…) queries to kill the N+1 pattern. Then went further - pre-computed the alerts into a dedicated MySQL cache table populated by a background PHP daemon, so the page just reads pre-shaped rows. Invalidation is layered: writes trigger per-reservation recomputes, page loads refresh stale caches against a one-hour window, and a nightly cron rebuilds fully. Visible behavior didn't change.

**Result**
Page loads dropped from 5-10 seconds to under 1 second and per-page queries from 100+ to a handful, with most of the work now off the request path. The same cache table later became the read source for the SPM group page and the sales dashboard, so the win rippled outward.

PHP
MySQL
Caching
Query Optimization
Performance

[Organization](https://github.com/Educational-Travel-Adventures)

### Fleet Observability & Measured Database Tuning

A Prometheus, Grafana, and Alertmanager stack on a dedicated Terraform-provisioned droplet, instrumenting Educational Travel Adventures' 14-host fleet - followed by a measurement-driven tuning pass that removed ~80% of the production database's query load.

**Problem**
The bespoke status prober answered "is it up?" but not "why is database CPU at 65%?" - no metrics history, no capacity trends, and paging logic embedded in a dashboard rather than a dedicated alert pipeline. Performance work was anecdotal: pages felt slow, but nothing measured where the query time actually went.

**What I Built**
Node, mysqld, and blackbox exporters across all 14 hosts - covering host metrics, MySQL replication health, public-endpoint uptime, and TLS certificate expiry - scraped by Prometheus on a dedicated monitoring droplet provisioned with Terraform, visualized in Grafana, and paged through Alertmanager via email and Twilio SMS. The full rule-to-SMS chain was verified end to end with a live test alert before the old dashboard pager was switched off, so there was never a coverage gap. Then, with the database instrumented, a `performance_schema`-driven pass ranked 27 days of production queries by total time: three full-table-scan hot queries dominated, and three composite indexes - applied with online DDL (INPLACE, LOCK=NONE), EXPLAIN-verified before and after, rollback written first - eliminated them. The remaining load was diagnosed as application-level N+1 call volume and deliberately left for an app-layer fix rather than papered over with infrastructure.

**Result**
One paging pipeline with a single source of alerting truth; capacity questions answered from dashboards instead of SSH sessions; and ~80% of measured production query time (~404K of ~500K query-seconds) removed by three indexes that took under a second each to apply, with zero downtime. "It got faster" became a before/after with a graph behind it.

Prometheus
Grafana
Alertmanager
Terraform
MySQL
performance\_schema

[ADR 0011: why a metrics stack →](https://github.com/JacobStephens2/infrastructure-patterns/blob/main/adr/0011-instrumented-metrics-stack-over-bespoke-prober.md)

### Legacy Modernization: PHP 5 → 8, MySQL 5.7 → 8.4, CentOS → Rocky

A multi-year, codebase-wide modernization of Educational Travel Adventures' platform - PHP 5.6 to PHP 8, MySQL 5.7 to MySQL 8.4 LTS, and CentOS 7 to Rocky Linux 9 - done in production, without freezing the company.

**Problem**
A 40-person travel business was running on a stack whose support runway was visibly running out: PHP 5.6 long past EOL, MySQL 5.7 past its window, CentOS 7 aging out. Every other piece of the platform - payments, ACH, the customer portal, internal Tourbot, the guides PWA - had to keep shipping throughout.

**What I Built**
Executed an enterprise-wide PHP 5 → 8 migration across the codebase: removed magic quotes and deprecated `mysql_*` calls, declared properties on long-lived models, tightened type signatures. Shipped the MySQL 5.7 → 8.4 migration plan and the CentOS 7 → Rocky Linux 9 cutover, with deployment notes, schema-compatibility audits, and per-server runbooks. Coordinated cross-database export/import for customer accounts, dual-running old and new database servers during transition. Mentored the developer working alongside me on the same codebase.

**Result**
No one's workday was interrupted by the upgrade: a current, supported stack - PHP 8, MySQL 8.4 LTS, Rocky Linux 9 - now runs across the production fleet, completed without an outage customers or staff would have noticed. The same migration also unblocked downstream work: containerized manager sandboxes, an AI-agent orchestrator, and the modernized customer portal all assume the new baseline.

PHP 8
MySQL 8.4
Rocky Linux
CentOS
Migration
Refactoring
Production Ops

[Organization](https://github.com/Educational-Travel-Adventures)

## Client Work

Focused builds for payments, publishing, and straightforward business visibility.

### Chester County Life

A subscription payment system with Stripe integration for recurring revenue and secure transaction handling.

**Problem**
The site needed reliable recurring billing, not just a brochure.

**What I Built**
PCI-conscious subscription flows and payment infrastructure tied into Stripe.

**Result**
A site that collects and manages recurring revenue, not just describes the offering.

PHP
Stripe
Subscriptions

[Visit Site](https://chestercounty-life.com)
[GitHub](https://github.com/JacobStephens2/chestercounty-life-wordpress-theme)

### Wadadli Flare Catering

A marketing site for a catering business - clear presentation, service visibility, and lead generation.

**Problem**
The business needed a web presence that communicated the offering quickly and drove customer inquiries.

**What I Built**
A polished service site with clear structure, strong visual presentation, and direct paths for interested customers.

**Result**
A credible online front door and a better platform for discovery and inquiries.

Frontend
Brand Presence
Lead Generation

[Visit Site](https://wadadliflarecatering.com)
[GitHub](https://github.com/JacobStephens2/wadadliflarecatering.com)

### Coach's Call

A marketing and contact site for a faith-centered coach-mentoring practice - presenting one-on-one mentoring, seminars, and consulting for coaches at Christian athletic programs.

**Problem**
The practice needed a credible web presence to communicate its offering and make it easy for coaches across the country to get in touch.

**What I Built**
A React/Gatsby site with About, Work, and Contact sections and a working contact flow. When Gatsby Cloud shut down, I migrated the build onto a PHP/Apache host without disrupting the front-end.

**Result**
A stable, self-hosted site that survived its original platform's end-of-life and keeps driving coach inquiries.

React
Gatsby
PHP
Brand Presence

[Visit Site](https://coachscall.org)
[GitHub](https://github.com/JacobStephens2/coachs-call-website)

## Personal Projects

A curated set of personal builds, selected for relevance to platform and AI-infrastructure work. The full shelf of published apps lives at [stephens.page/apps](/apps.html).

### vaulted-agent-launcher

A Rust launcher (`va`) that gives Claude Code, Codex, Grok, and Kimi real vault credentials in-process - Bitwarden Secrets Manager, 1Password, pass, or sops - with per-agent manifests and optional prompt auth so the vault master token never has to live on disk.

**Why it matters**
AI coding agents need API keys and DB passwords, but a pile of `.env` files on every host is a secrets-management failure waiting to happen. Centralizing resolution in a launcher means rotation is one vault change, and a mis-scoped manifest is visible before the agent starts.

**What stands out**
Publishing the pattern found five production bugs - including one that handed every agent the credential that unlocks the whole vault. The fix is fail-closed manifests, scrub-then-exec so the child never inherits the manager token, and an honest threat model about what still lives on disk when auth is file-based. One curl install, `va setup`, `va claude`.

Rust
1Password
Bitwarden SM
AI Agents
Secrets

[Product page](https://vaultedagent.com/)
[Write-up →](/blog/one-vault-three-agents-writing-the-pattern-down-found-five-bugs/)
[GitHub](https://github.com/JacobStephens2/vaulted-agent-launcher)

### inkvoke

A single-binary Go CLI for OpenAI image models (gpt-image-2 by default): generate, edit, or batch from the terminal - with machine-readable `--json` output and exit codes so coding agents can drive it without scraping human text.

**Why it matters**
Image generation is useful inside agent workflows only if the tool speaks a stable contract. inkvoke ships an AGENTS.md interface, structured results (path, cost, tokens, errors), and exit codes that separate usage, auth, permanent API, retryable API, and I/O failures.

**What stands out**
One static binary for macOS/Linux/Windows (amd64 and arm64) - no Python runtime. Batch manifests, photo-edit paths, and a live try surface on the product page. Designed so an autonomous agent can install and invoke it from documentation alone.

Go
OpenAI
CLI
AI Agents
gpt-image-2

[Product site](https://inkvoke.dev)
[GitHub](https://github.com/JacobStephens2/inkvoke)

### muxboard

A Flask blueprint that puts a web dashboard over tmux - across one host or a whole fleet - with a live in-browser terminal, built default-deny with attach caps and a documented threat model for handing out remote-shell access over the web.

**Why it matters**
Babysitting a long-running agent or build session from a browser tab beats SSHing into each box and remembering which tmux socket belongs to which service account. The single-host case is just the n=1 instance of the same fleet inventory model - there is no separate code path for "just my laptop."

**What stands out**
Security-first design for an inherently dangerous capability: default-deny authorization, per-principal and global caps on concurrent attaches, a type-the-name kill confirmation, an SSH-backed inventory refreshed off the request path, and an honest threat model that treats a misconfigured gate as a root shell for a stranger.

Python
Flask
WebSocket
xterm.js
tmux
SSH

[Visit Site](https://muxboard.dev)
[GitHub](https://github.com/JacobStephens2/muxboard)

### Open-Source Fix: MySQL 8.4 Binlog Auth

A pull request to the php-mysql-replication library so its binlog client can complete the handshake against MySQL 8.4, whose default caching\_sha2\_password plugin left the existing code two packets out of sync.

**Why it matters**
MySQL 8.x defaults to caching\_sha2\_password; without draining the extra auth packets it sends, the client desynchronizes and crashes on the first event as a malformed packet. The fix unblocks anyone using this library for change-data-capture against a modern MySQL.

**What stands out**
Protocol-level debugging against the MySQL authentication-exchange spec: drain the AuthMoreData and OK packets on the fast-auth path, fail loudly with a clear exception when the server demands full authentication (unsupported without TLS), and guard the event consumer against short packets - submitted with unit and integration tests.

PHP
MySQL
Binlog / CDC
Protocol
Open Source

[View pull request](https://github.com/krowinski/php-mysql-replication/pull/148)

### DNS as Code (Terraform + Cloudflare)

Infrastructure-as-code for a personal web fleet of ~70 hostnames across 10 domains: DNS consolidated from four registrars onto Cloudflare and held as ~220 Terraform-managed records, with AWS S3 remote state, Ansible provisioning, and a plan-only disaster-recovery blueprint.

**Problem**
DNS for ten domains was scattered across four registrars - name.com, Porkbun, Bluehost, GoDaddy - with no version control, no review, and no way to reproduce it. One registrar (Bluehost) had no API at all, so the records there could not be managed as code without first moving them.

**What I Built**
Consolidated every domain's nameservers onto Cloudflare, then brought ~220 records across 9 zones (A, CNAME, MX, TXT, SRV) under Terraform by *importing the live records* rather than recreating them - reconciling the configuration to production until `terraform plan` reported zero changes, so no traffic-serving record was ever dropped or duplicated. Kept the state in AWS S3, deliberately off the compute provider, so a DigitalOcean outage cannot destroy both the box and the state that rebuilds it (S3-native locking, no DynamoDB). Wrote Ansible roles for one-command subdomain provisioning (Apache vhost plus Let's Encrypt, ordered so DNS resolves before the certbot challenge) and for rebuilding the base stack on a fresh host. Added a separate, plan-only Terraform module describing the droplet, volume, and firewall for disaster recovery, plus GitHub Actions running fmt, validate, and plan-on-PR.

**Result**
DNS is now changed by pull request instead of by clicking, with a reviewable diff and a no-op baseline plan that makes any real change visible. The decisions are written up as Architecture Decision Records, and the full repo is published as a sanitized public mirror.

Terraform
Cloudflare
AWS S3
Ansible
GitHub Actions
DNS
IaC

[Public repo](https://github.com/JacobStephens2/terraform-cloudflare-dns)
[Decision records](https://github.com/JacobStephens2/infrastructure-patterns/blob/main/adr/0014-import-live-dns-over-recreating-it.md)

### Kubernetes on k3s (live demo)

A live, HTTPS-secured two-tier Kubernetes app on a single k3s node - a stateless web Deployment in front of a Redis StatefulSet - provisioned end to end by Terraform and cloud-init. A deliberate Kubernetes exercise, kept separate from my systemd-based production fleet.

**Problem**
I run production as systemd services on one VPS, which is the right tool for a single box - an orchestrator there would add control-plane and networking complexity for no benefit. But I wanted a genuine, running Kubernetes artifact that demonstrates real cluster operations, without putting an orchestrator under live services.

**What I Built**
A small stateless Go service (static binary in a multi-stage, non-root `FROM scratch` image) fronting a Redis StatefulSet with a PersistentVolumeClaim, with production-grade manifests: a rolling-update Deployment with liveness/readiness probes, CPU/memory limits, a hardened securityContext (non-root, read-only root filesystem, dropped capabilities), ConfigMap and Secret injected via envFrom, a HorizontalPodAutoscaler, a Traefik ingress, and kustomize. Terraform plus cloud-init provision an AWS EC2 node and bootstrap k3s and the app; cert-manager and Let's Encrypt issue TLS. The manifests are schema-validated with kubeconform in CI, and a five-rule OPA Gatekeeper admission layer (non-root, resource limits, no `:latest`, the hardening triad, required probes) enforces the same posture at the API server - rejected-if-violated, not trusted-by-convention - with one rule re-expressed as a built-in ValidatingAdmissionPolicy in CEL to make the engine choice legible.

**Result**
A clickable, TLS-secured demo where `/count` increments a counter held in Redis and shared across both app pods - the whole path from `terraform apply` to a running, HTTPS-terminated cluster, as code.

Kubernetes
k3s
Terraform
AWS EC2
Docker
cert-manager
OPA Gatekeeper
Redis

[Live site](https://k3s-demo.stephens.page)
[Repo](https://github.com/JacobStephens2/k3s-demo)

### Sotto

A Flask web app that turns a Markdown document into an MP3 you can listen to, narrated by a neural text-to-speech model running entirely on my own server - no third-party API, and no text or audio ever leaves the box.

**Problem**
I wanted to listen to long Markdown notes and documents as audio, but the obvious route - a hosted TTS API - means shipping the full text to a third party and paying per character. I wanted the same result with the text never leaving a server I control.

**What I Built**
Paste Markdown (or upload a `.md`), pick a voice, and convert to a downloadable MP3. Synthesis runs on a self-hosted Kokoro-82M (ONNX) model in its own loopback-bound service, so no text or audio leaves the server and there is no external AI dependency or per-use cost. A Library keeps each rendering alongside its source-Markdown sidecar; sharing is via revocable capability-URL tokens (`/share/<token>`) - the only thing ever made public is a link you create and can revoke. Built as a seven-boundary safety demo: it never emails, posts, or acts on your behalf.

**Result**
A private, self-hosted "listen to your documents" tool with no third-party data exposure and no recurring API bill, published as a public reference for the seven-boundary approach to running a model safely.

Flask
Python
Kokoro-82M
ONNX
Self-hosted AI
Capability URLs

[Live site](https://sotto.stephens.page)
[Repo](https://github.com/JacobStephens2/sotto)

### Chart35

A privacy-first fertility-cycle charting app for the Creighton Model - local-first and offline, with auto-computed CrMS stamps, end-to-end encrypted sync, and provider sharing.

**Why it matters**
Product thinking around a sensitive, real-world workflow where accuracy, offline access, and long-term usability are non-negotiable. 83 iOS + 17 Android installs, 52 web accounts (40 verified, 47 synced) - App Store / Play / TestFlight, growth via organic search alone.

**What stands out**
Local-first IndexedDB source of truth, end-to-end encrypted sync (the server stores ciphertext only), and faithful CrMS domain logic - not generic CRUD.

TypeScript
Vite
IndexedDB
Web Crypto
PWA
Capacitor

[Read the case study](/blog/a-privacy-first-fertility-chart-encrypted-on-your-device/)
[Visit Site](https://chart35.com)
[Architecture showcase](https://github.com/JacobStephens2/chart35-showcase)
[webcrypto-envelope](https://www.npmjs.com/package/@stephenspage/webcrypto-envelope)
[webhook-verify](https://www.npmjs.com/package/@stephenspage/webhook-verify)

### Cascade

A waterfall white-noise player for focus and sleep - one headless Rust core driving 2 shipped shells (Web PWA, Android), with 4 architected to extend (macOS, Windows, iOS, watchOS).

**Why it matters**
A daily-use focus/sleep sound app, and an architecture kata: prove a single Rust core can power genuinely native UIs across platforms without a web wrapper. The core owns all behavior - state, pomodoro/sleep timers, custom durations, volume, persisted settings, and time via explicit tick events - and never touches an audio API.

**What stands out**
A coarse `dispatch(command) → {snapshot, effects}` boundary serialized as one JSON wire shape, so every shell is a thin native UI over shared Rust logic. 2 shipped shells (Web PWA via wasm-bindgen, Android via UniFFI/Kotlin) share the same core; macOS, Windows, iOS, and watchOS are architected to extend over that boundary.

Rust
WebAssembly
React
UniFFI
Jetpack Compose
SwiftUI
WinUI 3
PWA

[Visit Site](https://cascade.stephens.page)
[Source](https://github.com/JacobStephens2/cascade)

### Magisterium MCP Server

An MCP server that gives AI assistants access to the Magisterium API, returning Church teaching with source citations.

**Why it matters**
Bridges a meaningful content domain with emerging AI tooling in a practical, technically current way.

**What stands out**
MCP integration, source-aware responses, and a clean bridge between authoritative data and assistant workflows.

TypeScript
Node.js
MCP
AI Tooling

[Visit Site](https://magisterium.stephens.page/)
[GitHub](https://github.com/JacobStephens2/magisterium_mcp_server)

---

[Contact](https://stephens.page/contact.html)
