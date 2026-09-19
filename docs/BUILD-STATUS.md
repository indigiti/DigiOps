# DigiOps Build Status

## Release

**v0.1.0 — Foundation & Admin Shell**

## P00 — Product / security baseline

- [x] Product name frozen: DigiOps
- [x] Single-domain multi-folder deployment model
- [x] `public_html/<app>/` + `private_html/<app>/` isolation
- [x] No database baseline
- [x] No arbitrary web shell
- [x] Approval-oriented deployment model
- [x] GitHub artifact-first architecture

## P01 — UI/UX shell

- [x] Responsive reference-inspired admin shell
- [x] Sidebar navigation
- [x] Global search surface
- [x] Dashboard
- [x] Application cards
- [x] Filters
- [x] Create-application modal
- [x] Project-detail header
- [x] Project-detail tabs
- [x] Mobile sidebar behavior

## P02 — Registry / path security foundation

- [x] PHP bootstrap
- [x] File-backed private project registry
- [x] Public/private path guard
- [x] Status endpoint
- [x] Project registry endpoint
- [x] Flexible dev/Cloudways backend bootstrap resolution

## P03 — GitHub connection

- [ ] GitHub credential vault
- [ ] Repository connection wizard
- [ ] Branch/tag/commit browser
- [ ] GitHub Actions build status
- [ ] Artifact discovery/download
- [ ] Commit/update detection

## P04 — Deployment engine

- [ ] Manifest validation
- [ ] Pre-deploy snapshot
- [ ] Staged extraction
- [ ] Atomic publication strategy
- [ ] Maintenance gate
- [ ] Deployment lock
- [ ] Rollback

## P05 — Files / releases

- [ ] Restricted file browser
- [ ] Upload/download policy
- [ ] Release registry
- [ ] Retention policy
- [ ] Release diff
- [ ] Restore controls

## P06 — Health / logs / audit

- [ ] HTTP health probes
- [ ] PHP/runtime checks
- [ ] Storage checks
- [ ] Deployment logs
- [ ] Audit chain
- [ ] Error lifecycle

## P07 — Authentication / permissions

- [ ] Admin authentication
- [ ] CSRF
- [ ] TOTP/2FA
- [ ] Session hardening
- [ ] Roles/permissions
- [ ] Optional IP allowlist

## Production note

v0.1.0 is a development foundation, not a production deployment authority yet. Deployment-mutating actions stay non-operational until authentication, credential vault, artifact verification and rollback gates are complete.
