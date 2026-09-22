# DigiOps Cloudways Installation

Target URL:

```text
https://stage.digiti.in/digiops/
```

DigiOps uses two Cloudways-managed areas:

```text
public_html/digiops/
private_html/digiops/
```

## Build package

GitHub Actions creates the `digiops-release` artifact, or locally:

```bash
npm install
npm run build
php tools/package-release.php
```

Copy:

```text
release/public/*  -> public_html/digiops/
release/private/* -> private_html/digiops/
```

The public API bootstrap automatically discovers:

```text
private_html/digiops/app/php/bootstrap.php
```

No database is required.

Configure a trusted local health origin in the Cloudways application environment:

```text
DIGIOPS_CANONICAL_ORIGIN=https://stage.digiti.in
```

`APP_URL` is also accepted as a fallback. DigiOps does not derive health-check destinations from the incoming HTTP `Host` header.

## Browser setup

Open:

```text
https://stage.digiti.in/digiops/
```

On first run DigiOps shows the installer. Create the administrator account with a password of at least 12 characters. An optional Base32 TOTP secret can be supplied.

Then open **Connections** and save a GitHub fine-grained token with read access to repositories and Actions/artifacts that DigiOps will manage.

## Target repository contract

A managed repository should have a successful GitHub Actions workflow that uploads a deployment artifact. Default artifact name:

```text
digiops-release
```

The artifact must contain a deployable entrypoint. Supported layouts include:

```text
dist/index.html
public/index.php
index.html
index.php
```

DigiOps does not run arbitrary shell commands on Cloudways.

## Required PHP extensions

- curl
- zip
- openssl
- sodium recommended

## Important

Keep `private_html/digiops/` in Cloudways backups. It contains users, encrypted credentials, audit history, release metadata and the vault master key.
