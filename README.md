# DigiOps

**DigiOps v1.8.6** is a web-first deployment and operations manager for multiple independent applications hosted beneath one Cloudways application/domain.

Example managed routes:

- `https://stage.digiti.in/sahakarxv/`
- `https://stage.digiti.in/app1/`
- `https://stage.digiti.in/app2/`

## What is built

- first-run browser installer
- admin authentication, hardened sessions, CSRF and login rate limiting
- optional TOTP validation
- encrypted GitHub token vault
- GitHub repository/branch/commit/workflow intelligence
- application registry with strict Cloudways path guards
- GitHub Actions artifact deployment
- ZIP traversal/symlink checks
- release snapshots, retention and rollback
- deployment locks
- durable step-by-step deployment verification with exact failure-stage evidence
- restricted file browser
- HTTP/runtime/storage health checks
- hash-chained audit log
- responsive reference-inspired admin UI
- no SQL/SQLite/Redis requirement
- no arbitrary browser shell

## Development

```bash
npm install
npm run dev
```

Production build:

```bash
npm run build
php tools/package-release.php
```

GitHub Actions publishes a `digiops-release` artifact containing:

```text
release/
  public/     # deploy to public_html/digiops/
  private/    # deploy to private_html/digiops/
  RELEASE.json
```

See `docs/INSTALL.md`, `docs/ARCHITECTURE.md` and `docs/BUILD-STATUS.md`.
