# ComeCome Roadmap & Planning Archive

This folder preserves the planning and review documents that were previously
stranded on short-lived `claude/*` working branches. They are kept here as
project history and forward-looking roadmap.

## Canonical roadmap

**[`.claude/SPRINT-PLAN_reconciled.md`](../../.claude/SPRINT-PLAN_reconciled.md)** is the
**canonical** sprint plan. It reconciles the source plans below into the agreed
sequence and guiding principle (*the child's interaction surface stays flat; new
depth goes to the guardian/clinician layers only*).

## Status

| Item | State |
|------|-------|
| Sprint 0 — Bug fixes (duplicate food, favorites persistence) | ✅ Shipped (v0.9) |
| Sprint 1 — Feature visibility toggles | ✅ Shipped (v0.9) |
| Sprint 2 — Sleep tracking | ✅ Shipped (v0.9) |
| Sprint 3 — Percentiles Foundation (gender/DOB/height) | 📋 Planned |
| Sprint 4 — Percentiles Full (WHO reference + engine) | 📋 Planned |
| Sprint 5 — Growth-Support Nutrition Intelligence | 📋 Planned |
| Database at-rest encryption (SQLCipher) | ⏸️ Deferred to roadmap (see review) |

## Source documents

- **[SPRINT-PLAN.md](SPRINT-PLAN.md)** — full v0.9+ sprint plan with codebase review and per-task breakdown.
- **[SPRINT-PLAN_follow-ups.md](SPRINT-PLAN_follow-ups.md)** — follow-up analysis and open questions.
- **[PLAN-db-encryption.md](PLAN-db-encryption.md)** — SQLCipher AES-256 at-rest encryption proposal.
- **[REVIEW-encryption-timing.md](REVIEW-encryption-timing.md)** — timing review; verdict: sound but **defer** until the app leaves single-family/pre-1.0 scope.
