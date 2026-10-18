# Staging release candidates

DigiOps applications keep the original `branch` configuration (normally `main`). Authorized operators of **Stage/Staging** applications can inspect and deploy a verified GitHub Actions artifact from a separate `staging/...` or `fix/staging-...` branch without changing that configuration or creating a second app that targets the same filesystem paths.

## Operator procedure

1. Open the application **Deploy** tab.
2. Under **Release source branch**, leave the field blank for the configured branch or enter the exact staging branch name. Example: `staging/vela-0.8.4-rc1`.
3. Select **Check update**; review the workflow run, artifact name/ID, SHA, branch and expiry date. DigiOps refreshes the candidate from the selected branch without caching the main-branch candidate in its place.
4. Deploy only after review. The confirmation includes the selected branch, artifact/run identity and target URL.
5. Verify the deployed release identity and live behavior. Use the existing rollback flow if health or smoke tests fail.

## Safety and compatibility

- In environments other than `Stage` and `Staging`, any branch override is rejected server-side.
- Staging overrides are limited to `staging/...` and `fix/staging-...`; traversal, ambiguous slashes and trailing separators are rejected.
- The deploy endpoint revalidates GitHub workflow status, the **exact selected branch**, the full commit SHA, artifact name and artifact ID. An override requires explicit nonzero workflow/artifact IDs and the full 40-character SHA; a fallback to a different successful run is not accepted for a staged override.
- Current permissions, CSRF, artifact ZIP/digest integrity, path restrictions, transactional publish, logging, rollback and post-deploy health remain in place.
- The application's stored branch stays `main` (or the configured value), and the frontend retains the original default when switching applications.
- Candidate display does not deploy. No automated cutover or repository merge occurs.

## QNeXT Vela staging candidate (18 October 2026)

- Repository: `indigiti/QNext`
- Source branch: `staging/vela-0.8.4-rc1`
- Release workflow run: `39152203865`, successful
- Exact `digiops-release` artifact ID: `12144619910`
- Commit: `232553b4ed2ef4114f7f156171bcd61aefc2c3e2`
- Artifact expiry: 1 November 2026
- Browser/live acceptance gate: 15s/30s/1m candle updates; NIFTY/SYN/SYN+ synthetic-volume semantics; idle-tab/WS reconnection and gap recovery; Pine v6 custom indicators; saved Vela drawings and rollback state.
- The staging candidate is not a production release, and its existence is **not** proof of remote activation or live-browser validation.

## Known UI behavior

The branch selector is a text input rather than an incomplete GitHub-branch dropdown, because repositories with more than 100 branches may not show the desired staging branch on the first API page.
