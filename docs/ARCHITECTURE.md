# DigiOps Architecture

## Purpose

DigiOps is a web-first deployment control plane for multiple independent applications hosted on one or more Cloudways applications/servers.

## Managed layout

DigiOps manages approved public/private roots only:

```text
public_html/
  digiops/
  app1/
  app2/

private_html/
  digiops/
    app/
    build/
    registry/
    jobs/
    audit/
    vault/
    users/
    projects/
    rate/
    config/
  app1/
  app2/
```

Public folders contain browser/runtime payloads. Under `private_html/digiops/`, deployable code and persistent runtime state are deliberately separated. Git/release deployment may update `app/` and `build/`; persistent state such as users, vault, registry, projects, jobs, audit, rate and configuration must survive every deployment.

## Cloudways Git rule set

DigiOps uses two repository branch roles:

- `main` is the source/review branch.
- `cloudways` is the Cloudways deployment bootstrap branch and is intentionally not a mirror of `main`.

The Cloudways branch pins an approved `main` source SHA together with the exact release artifact ID and SHA-256 digest. Cloudways pulls that branch into `public_html/digiops/`; the bootstrap then installs the pinned public and private release payloads.

Required traceability is:

`main commit -> GitHub Actions release artifact -> cloudways pin -> Cloudways pull -> installed runtime`

The Cloudways branch must be updated only from an approved `main` build after required CI checks complete. Never merge `cloudways` back into `main`, and never let Cloudways Git deployment delete or replace persistent private runtime state.

The complete mandatory rules are defined in [CLOUDWAYS-RULESET.md](CLOUDWAYS-RULESET.md).

## Control-plane invariants

1. No arbitrary shell/command console.
2. Managed paths cannot escape approved public/private roots.
3. Deployments identify an exact GitHub workflow run, artifact and commit.
4. A cryptographic deployment request ID follows one deployment attempt end-to-end.
5. Active deployment identity is persisted server-side, not owned by the browser.
6. Deploy and rollback share the same per-project operation lock.
7. Public publication is transactional: current is parked, new is switched in, and current is restored automatically if cutover fails.
8. Remote agents must advertise request-aware status and transactional-switch capabilities before deployment.
9. Secrets never belong in the public tree or repository.
10. Operators can leave/reload the UI while server-side deployment continues.
11. Post-deploy health is recorded against the deployment job.
12. Audit events are hash chained under an exclusive writer lock.
13. Cloudways Git deployment must preserve persistent state under `private_html/digiops/`.
14. Every deployed DigiOps build must be traceable to its exact `main` commit and release artifact.

## Deployment lifecycle

```text
candidate-validation
  -> preflight
  -> downloading-artifact
  -> artifact-verified
  -> validating / remote-upload
  -> snapshotting
  -> staging-release
  -> publishing
  -> switching
  -> deployed
  -> health-verified | health-attention
```

DigiOps treats phase names as authoritative. UI percentages are only internal animation aids and are not deployment completion guarantees.

## Deployment jobs

Each deployment has a durable JSON job record under:

`private_html/digiops/jobs/deployments/<request-id>.json`

The record contains project, target, commit, workflow/artifact identity, artifact digest when available, actor, state, phase, timestamps, release and post-deploy health result. It also retains a chronological `verification` ledger. Each checkpoint records phase, human label, status, evidence source, exact error when present, and start/update/completion timestamps.

The browser reconstructs active work from this server journal after navigation, reload or sign-in. Browser state is not the system of record. The Verification Center reads this local journal only, so opening it does not fan out to GitHub or deployment targets; explicit recheck actions are used when fresh authoritative confirmation is required.

## Artifact integrity

DigiOps revalidates the workflow run, branch, commit, artifact name and artifact ID immediately before deployment. When GitHub supplies an artifact SHA-256 digest, DigiOps verifies the downloaded ZIP against it before mutation. Release metadata stores the downloaded SHA-256 and available GitHub digest.

## Remote targets

Remote Cloudways applications use a signed DigiOps agent with timestamp + nonce + HMAC replay protection. Required deployment capabilities include:

- deploy-chunked
- deployment-status
- deployment-request-id
- atomic-switch-v1

## State storage

DigiOps remains database-free by design. Durable control-plane state is file-backed with atomic writes and exclusive locks around read-modify-write mutations.

Redis remains optional infrastructure and is not required for correctness.

## Frontend

- Vite
- Alpine.js CSP build
- Tailwind CSS
- Lucide
- contextual help and route-based workspaces
- explicit/on-demand remote checks

## Backend

- PHP 8.2+
- encrypted secret vault
- server-side deployment job journal
- transactional filesystem publication
- hash-chained audit log
- no SQL/SQLite requirement
