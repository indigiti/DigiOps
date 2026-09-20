# DigiOps v1.2.0 — Multi-Server Control Plane

## Scope

DigiOps can manage applications on the local Cloudways application and on remote Cloudways applications/servers through a signed remote agent.

## Deployment targets

- `local` is built in and requires no agent.
- Remote targets use HTTPS and a per-target shared secret stored in the encrypted DigiOps vault.
- Opening the dashboard or an application does not contact remote targets.
- Remote calls occur only for explicit health, releases, files, deploy, rollback or target test operations.

## Remote agent

Download `digiops-agent.php` from **Targets → Download agent**.

Install it at a dedicated HTTPS endpoint on the remote Cloudways application, for example:

`https://example.com/digiops-agent.php`

Configure on the remote application:

`DIGIOPS_AGENT_SECRET=<same 64-character secret generated in DigiOps>`

Optional:

`DIGIOPS_AGENT_HOME=/home/.../application-root`

The agent validates:

- HMAC-SHA256 signature
- timestamp freshness
- one-time nonce replay protection
- managed deployment paths
- ZIP path traversal
- artifact SHA-256
- deployment lock

## Remote capabilities

- capability/ping test
- health probe
- releases
- managed file listing
- chunked artifact deployment
- rollback

## Application assignment

Existing applications default to `local`.

For remote applications choose a target and configure:

- Application HTTPS URL
- Remote public path, e.g. `public_html/` or `public_html/app/`
- Remote private path, e.g. `private_html/` or `private_html/app/`

Local applications keep the original automatic isolated paths.
