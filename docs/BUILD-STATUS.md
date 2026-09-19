# DigiOps Build Status

## Release

**v1.0.0 — Production Feature Complete / Runtime Certification Pending**

## P00 — Product / security baseline
- [x] Single-domain multi-folder model
- [x] `public_html/<app>/` + `private_html/<app>/`
- [x] No database
- [x] No arbitrary browser shell
- [x] Approval-oriented artifact deployment

## P01 — UI/UX
- [x] Reference-inspired admin shell
- [x] Dashboard
- [x] Application cards/search/filter
- [x] Create application
- [x] Project tabs
- [x] Connection UI
- [x] Audit UI
- [x] Installer/login UI
- [x] Responsive mobile sidebar

## P02 — Registry / path security
- [x] File-backed registry
- [x] Strict slug validation
- [x] Managed public/private path generation
- [x] Traversal rejection

## P03 — GitHub
- [x] Encrypted token vault
- [x] Repository validation
- [x] Branch list
- [x] Commit list
- [x] Workflow runs
- [x] Update detection
- [x] Artifact discovery/download

## P04 — Deployment
- [x] Deployment lock
- [x] ZIP traversal checks
- [x] Symlink rejection
- [x] Payload entrypoint validation
- [x] Pre-deploy snapshot
- [x] Staged publication
- [x] Release metadata/hash
- [x] Retention
- [x] Rollback

## P05 — Files / releases
- [x] Restricted public/private listing
- [x] Release history
- [x] Rollback controls
- [x] Disk-size reporting
- [ ] Browser file mutation intentionally excluded from v1.0; deploy artifacts remain source of truth

## P06 — Health / logs / audit
- [x] HTTP health probe
- [x] PHP extension/runtime check
- [x] Storage check
- [x] Hash-chained audit events

## P07 — Authentication
- [x] First-run installer
- [x] Password hashing
- [x] Hardened sessions
- [x] CSRF
- [x] Login rate limiting
- [x] Roles
- [x] Optional TOTP

## P08 — CI / packaging
- [x] Frontend build
- [x] PHP lint
- [x] Security leak check
- [x] PathGuard test
- [x] Production release package
- [x] GitHub Actions artifact

## Remaining certification

The codebase is feature-complete for v1.0.0. Final production certification requires deploying the generated release package on the actual Cloudways application and testing real filesystem permissions, PHP extensions, HTTPS/session behavior, GitHub token permissions, artifact deployment and rollback against `stage.digiti.in`.
