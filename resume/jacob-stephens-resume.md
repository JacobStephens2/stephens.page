# Jacob Stephens

[Contact](https://stephens.page/contact.html) · jacob@stephens.page · Philadelphia, PA

[stephens.page](https://stephens.page/) · [thirdshift](https://thirdshift.app/) · [GitHub](https://github.com/JacobStephens2) · [LinkedIn](https://www.linkedin.com/in/jacob-c-stephens)

Platform and infrastructure engineer with 4+ years of production ownership. Lead a two-engineer team responsible for a specialty-travel company's reservations, payments, Linux fleet, backend services, and agent infrastructure. Built thirdshift, an open-source Rust orchestrator for unattended issue-to-PR delivery.

## Experience

### Head of Engineering
Educational Travel Adventures | Feb 2022 - Present | Remote

- Lead a two-engineer team with end-to-end responsibility for architecture, operations, deployment, and on-call response across the production stack of a multi-million-dollar travel business.
- Built three Docker/Traefik Manager Sandboxes with separate application containers, a shared writable database refreshed daily, and per-manager credentials. Shipped 14 manager-prototyped features through human review.
- Built an operations console (Python, Flask, SQLite) for fleet health, deploy review, branch previews, and browser terminals, with Google sign-in, passkeys, and per-permission access. Deployed Prometheus, Grafana, and Alertmanager across 14 hosts.
- Reduced group-manifest SQL statements per request from about 2,650 to 183 with byte-identical output. A separate measurement-led index pass eliminated about 80% of measured query time on the live primary using online DDL.
- Shipped a mobile-first guide PWA with offline support and receipt-attached expenses: 77 guides filed 410 tour reports and 1,800+ expense reports (about $154K) over a 12-month period.
- Led PHP 5-to-8, MySQL 5-to-8, and CentOS-to-Rocky Linux migrations. Modernized payment integrations and ACH/NACHA workflows, including bank-account encryption, as part of a PCI compliance initiative.
- Built a deployment pipeline with dirty-worktree rejection, serialized deploys, ancestor-only refs, health checks, and rollback; validated with PHPUnit and GitHub Actions across PHP 8.1-8.4.

### Web Developer and Client Support Specialist
Sharp Innovations | Nov 2021 - Feb 2022 | Conestoga, PA

- Automated SQL/PHP database migrations, reducing execution from hours to minutes; supported client websites, security fixes, and Linux server configuration.

### Full-Stack Developer (Independent)
Steward Goods | Mar 2020 - Nov 2021 | Remote

- Designed and shipped a multi-tenant possessions tracker in PHP and MySQL with full frontend, backend, and deployment ownership; implemented Stripe subscription payments for client work.

## Selected Projects

- [thirdshift](https://thirdshift.app/) ([source](https://github.com/JacobStephens2/thirdshift) | [review evaluation](https://stephens.page/blog/fresh-eyes-beat-a-second-family/)): A deterministic Rust orchestrator for unattended issue-to-PR delivery across six coding-agent harnesses, with dependency-aware parallel tickets in separate Git worktrees, standards and spec reviews, CI checks, bounded repair loops, and resumable runs. Designed an evaluation of 183 replayed code-review sessions with fresh-session controls and predefined acceptance bars, and published the results and limitations.
- [vaulted-agent](https://github.com/JacobStephens2/vaulted-agent): Launches coding agents with vault-resolved secrets injected in-process, per-agent manifests, and vault-manager tokens removed from the child environment. Supports 1Password, Bitwarden Secrets Manager, pass, and SOPS.
- [infrastructure-patterns](https://github.com/JacobStephens2/infrastructure-patterns): Public, sanitized architecture decisions covering agent isolation, scoped credentials, human merge gates, audit trails, and rollback.
- [Infrastructure as code](https://github.com/JacobStephens2/terraform-cloudflare-dns): Imported about 220 live DNS records across nine zones into Terraform without recreating them; added S3 remote state, Ansible provisioning, and plan-on-PR checks.

- [Cascade](https://github.com/JacobStephens2/cascade): A headless Rust audio core shared by four released shells (web PWA, Android, Windows, macOS) through a single JSON interface, with wasm-bindgen, UniFFI, and C ABI bindings; iOS and watchOS shells are in TestFlight.
- [Chart35](https://stephens.page/chart35.html): A shipped fertility tracker with client-side AES-256-GCM encryption, offline-first storage, and expiring provider-share links.

## Core Technologies

**Languages & databases:** Rust, Python, TypeScript/JavaScript, PHP, SQL, MySQL, PostgreSQL, SQLite

**Agent & delivery systems:** Coding-agent orchestration, Git worktrees, review evaluations, CI/CD, GitHub Actions, PHPUnit, Vitest, Playwright

**Infrastructure & applications:** Linux, Docker/Compose, Traefik, Terraform, Ansible, Prometheus/Grafana, FastAPI, Flask, React/Next.js, REST APIs

## Education & Certification

**BA, Sociology** | Gordon College | 2017

**CompTIA A+** | Earned November 2017
