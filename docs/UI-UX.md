# DigiOps UI / UX Baseline

DigiOps uses a calm operations-console model: strong hierarchy, low visual noise, explicit state, and contextual guidance instead of dense dashboards.

## Shell

- dark control-plane sidebar separates navigation from the working surface
- white/light operational workspace
- compact top bar with page context, application search, contextual help, and build identity
- rounded cards with fine borders and restrained shadows
- blue primary actions
- status colors are reserved for meaningful operational state
- network-expensive checks remain explicit; overview pages use last-known local state
- responsive mobile sidebar and stacked operational cards

## Primary navigation

### Operate
- Command Center
- Applications
- Deployment Center
- Health & Readiness

### Infrastructure
- Deployment Targets
- Connections & Runtime

### Control
- Audit & Governance
- Help & Guide

## Application tabs

- Overview
- Deploy
- Releases
- Files
- Health
- Settings

## Contextual help

Every major area can open a right-side help drawer explaining:

- what the page represents
- whether data is live or last-known
- safe next actions
- deployment and rollback semantics
- health-state meaning
- target/agent capability requirements

The full Help & Guide page acts as the operating map for new and occasional users.

## Design rule

DigiOps should expose complexity progressively. The first view answers **what needs attention and what can I do next**. Exact workflow IDs, artifact IDs, commit SHAs, paths, runtime identity, and diagnostic detail remain available one level deeper.

Future modules should reuse this shell and navigation model instead of introducing unrelated layouts.

## Background deployment verification

Deployment is a handoff, not a blocking page state.

- DigiOps records the exact project, commit, workflow artifact and deployment request ID before starting.
- The server continues deployment even if the browser request is interrupted.
- After a short foreground handoff, a persistent browser watcher follows authoritative deployment state.
- The watcher continues while navigating around DigiOps and resumes after reload or sign-in using browser-local persisted watch metadata.
- When the exact request is confirmed deployed, DigiOps runs the application health check automatically.
- The UI distinguishes **deployed + health verified**, **deployed + health needs attention**, and **deployed + health verification unavailable**.
- A second deploy for the same application is disabled while an active deployment watch exists.
- If the browser is completely closed, server deployment continues; verification resumes the next time DigiOps is opened.
