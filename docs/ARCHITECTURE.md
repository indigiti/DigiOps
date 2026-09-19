# DigiOps Architecture

## Purpose

DigiOps is a web-first control plane for multiple independent applications hosted beneath one Cloudways application/domain.

Example:

- `https://stage.digiti.in/sahakarxv/`
- `https://stage.digiti.in/app1/`
- `https://stage.digiti.in/app2/`

## Managed layout

DigiOps treats the Cloudways application as two approved roots:

```text
public_html/
  digiops/
  sahakarxv/
  app1/
  app2/

private_html/
  digiops/
  sahakarxv/
  app1/
  app2/
```

Public folders contain deployable browser/runtime payloads only. Private folders contain credentials, registry files, release metadata, manifests, logs and application-private runtime data.

## Security invariants

1. No arbitrary shell/command console.
2. No path may escape `public_html/<slug>/` or `private_html/<slug>/`.
3. Application slugs are strictly normalized.
4. Secrets never belong in the Git repository or public tree.
5. Deployment is approval-oriented.
6. Rollback metadata is created before destructive publication.
7. GitHub/API credentials are stored only in the private runtime.
8. The browser never receives server credentials.

## Integration direction

DigiOps will use GitHub APIs and verified GitHub Actions artifacts as the preferred deployment transport. Direct server-side Git/CLI execution is an optional later adapter, not the default architecture.

## Frontend

- Vite
- Alpine.js
- Tailwind CSS
- Lucide
- Relative asset base for subfolder portability

## Backend

- PHP 8.2+
- File-backed registry under `private_html/digiops/`
- No SQL/SQLite/Redis requirement
