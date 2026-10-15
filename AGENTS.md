# AGENTS.md

Platform-Manager is a PHP web application for managing core facility data: resources,
booking, services, invoicing, shared documents and bibliography.

## Agent skills

### Issue tracker

Issues and specs live as GitHub issues on this repository (`Nurtal/platformmanager`), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` at the repo root, ADRs in `docs/adr/`. See `docs/agents/domain.md`.

## Repository notes

- `docs/` is generated output: Hugo builds it from `doc/` and it is deployed to GitHub Pages.
  Edit documentation in `doc/`, never in `docs/`. The exception is `docs/agents/` and
  `docs/adr/`, which are hand-written and not produced by Hugo.
- This repository is a public fork of `bgo-bioimagerie/platformmanager`. Anything committed
  or filed here is public: never include working exploit payloads or unredacted credentials.
