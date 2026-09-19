# DigiOps

Web-first multi-application deployment and operations manager for shared Cloudways applications.

Current baseline: **v0.1.0 — Foundation & Admin Shell**

## Core goals

- Manage independent applications such as `/sahakarxv/`, `/app1/`, and `/app2/` from one browser UI.
- Keep public releases under `public_html/<app>/`.
- Keep credentials, manifests, release metadata and private runtime data under `private_html/`.
- GitHub-first integration with approval-oriented deployments.
- No SQL/SQLite/Redis requirement.
- No arbitrary web shell or unrestricted command execution.
- Release history, audit trail, health checks and rollback-ready architecture.
- Folder-portable UI built with Vite + Alpine.js + Tailwind CSS.

## Development

```bash
npm install
npm run dev
```

Production build:

```bash
npm run build
```

See `docs/ARCHITECTURE.md` and `docs/BUILD-STATUS.md`.
