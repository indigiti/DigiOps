# Cloudways Deployment Rule Set

These rules are mandatory for DigiOps changes that affect deployment, packaging, runtime layout, release metadata, or Cloudways Git.

## Branch roles

1. `main` is the source and review branch.
2. `cloudways` is the Cloudways deployment bootstrap branch. It is intentionally not a mirror of `main`.
3. Never merge `cloudways` back into `main`.
4. Update `cloudways` only from an approved `main` build that has completed the required CI checks.
5. Every Cloudways update must pin the exact source commit, release artifact ID, and artifact SHA-256 digest used for deployment.

## Cloudways deployment contract

Cloudways is configured to pull:

- repository: `git@github.com:indigiti/DigiOps.git`
- branch: `cloudways`
- deployment path: `public_html/digiops/`

The bootstrap branch installs the pinned release into:

- release public payload -> `public_html/digiops/`
- release private payload -> `private_html/digiops/`

The running build must be traceable as:

`main commit -> GitHub Actions release artifact -> cloudways pin -> Cloudways pull -> installed runtime`

## Public/private ownership

Git owns deployable code. Runtime state must never become Git-owned.

Deployable/private code includes:

- `private_html/digiops/app/`
- `private_html/digiops/build/`

Persistent runtime state includes, at minimum:

- `private_html/digiops/users/`
- `private_html/digiops/vault/`
- `private_html/digiops/registry/`
- `private_html/digiops/projects/`
- `private_html/digiops/jobs/`
- `private_html/digiops/audit/`
- `private_html/digiops/rate/`
- `private_html/digiops/config/`
- `private_html/digiops/bootstrap-install.json`

A release may overlay deployable private code, but must never delete, reset, replace, or version-control persistent runtime state.

## Deployment safety

1. Cloudways Git deployment and DigiOps application deployment must not concurrently mutate the same managed path.
2. No manual production file edit may become the source of truth. Changes belong in Git and must flow through the release chain.
3. DigiOps must retain source SHA, artifact identity, artifact digest, installed timestamp, and deployment identity sufficient to detect runtime drift.
4. A Cloudways pull is a platform deployment action; a DigiOps release rollback is an application release action. Their histories and rollback semantics must remain distinguishable.
5. Any change to packaging, bootstrap installation, public/private path mapping, or persistent-state directories requires review against this rule set.

## Terminology

Use **release**, **verified artifact**, or **approved build** for deployment status. Do not introduce certification gates or certification terminology into the development workflow.
