# DigiOps Build Status

## Release

**v1.8.0 — Verification Center**

## Control plane

- [x] Multi-application local/remote target model
- [x] Exact GitHub workflow/artifact/commit candidate selection
- [x] Server-side deployment request identity
- [x] Durable deployment job journal
- [x] Background verification recoverable across browser reload/device changes
- [x] Post-deploy health attached to deployment jobs
- [x] Durable checkpoint-by-checkpoint verification ledger with exact failure stage and evidence source
- [x] Health freshness retained in project registry

## Publication safety

- [x] Per-project deploy lock
- [x] Rollback uses the same operation lock
- [x] Pre-deploy snapshot
- [x] Transactional public-directory switch
- [x] Automatic restoration if final cutover rename fails
- [x] Same transactional switch on remote agent
- [x] Remote capability gate for transactional publication
- [x] ZIP traversal and symlink rejection
- [x] Payload entrypoint validation
- [x] Local storage/disk preflight

## Supply chain

- [x] Workflow run revalidation
- [x] Branch and commit revalidation
- [x] Exact artifact name + ID
- [x] GitHub SHA-256 artifact digest verification when supplied
- [x] Download SHA-256 stored in release provenance

## State integrity

- [x] Atomic JSON writes
- [x] Locked project registry mutations
- [x] Locked target registry mutations
- [x] Locked rate-limit counters
- [x] Serialized hash-chain audit writes + audit head
- [x] Encrypted GitHub/target secrets
- [x] Serialized secret-vault mutations and master-key creation
- [x] TOTP secrets stored in encrypted vault for new/migrated users

## Browser / session hardening

- [x] Hardened session cookie flags
- [x] Cookie path scoped to DigiOps
- [x] CSRF protection
- [x] Health probes require POST + CSRF and bind deployment request IDs to projects
- [x] Local health probes use a configured canonical origin, never the request Host header
- [x] CSP-compatible Alpine build
- [x] Deployed Content-Security-Policy
- [x] nosniff / frame denial / referrer / permissions headers
- [x] reduced-motion and focus-visible support

## UI / operator workflow

- [x] Command Center
- [x] Applications
- [x] Deployment Center
- [x] Verification Center with active/passed/attention/failed counts and exact error evidence
- [x] Health & Readiness
- [x] Deployment Targets
- [x] Connections & Runtime
- [x] Audit & Governance
- [x] Help & Guide
- [x] durable deployment activity instead of browser-only progress
- [x] health freshness displayed in the UI
- [x] application overview exposes workflow run, artifact ID, candidate/deployed commit and source/deployable gap without opening the deploy tab

## CI

- [x] PHP lint
- [x] all PHP behavioral tests
- [x] frontend CSP expression check
- [x] route/workspace checks
- [x] deployment reliability contract
- [x] production package validation
- [x] uploaded artifact verification
- [x] secret/data leak guard

## Deliberate constraints

- Browser file mutation remains excluded; deployment artifacts remain source of truth.
- Redis is optional, not required for correctness.
- Application-private payload is still overlaid onto application private runtime storage for compatibility. A future artifact contract may split immutable private code from explicit shared runtime directories.
