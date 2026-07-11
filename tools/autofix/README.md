# `autofix` — Linear-to-PR bug-fix workflow (operator tooling)

Deterministic per-stage scripts backing the `autofix` orchestrator skill
(`.claude/skills/autofix/SKILL.md`). This README covers running the CLI; the
skill covers the judgment layer (triage rubric, scope guardrails, review
gates). The full design is in the spec — see **Docs** below.

## Prerequisites

- `LINEAR_API_KEY` set in the environment, or in the workspace root `.env`
  (`cp default.env .env` and add it there). Required for any live Linear
  call; not required when `AUTOFIX_LINEAR_MOCK_DIR` is set (tests only —
  see below).
- `gh auth status` — authenticated GitHub CLI with push access to this repo
  (branch push + draft PR creation + Copilot review request).
- `jq` on `PATH`.
- Run from a `newspack-workspace` checkout with the usual `n` tooling
  available (env provisioning, test suites, and lint all shell out to `n`).

## Commands

Four dispatcher subcommands, all via `tools/autofix/bin/autofix`:

```bash
# List queue-eligible issues without claiming anything (v1: dry-run only;
# label-queue claiming is v1.1 — see Phasing below).
tools/autofix/bin/autofix intake --dry-run

# Start a new run against an explicit issue (v1's only claiming mode).
# Mints a run ID, claims the issue in Linear, and prints RUN_ID=<rid> on
# success. See SKILL.md for the exit-code contract (2/3/4/5 are terminal).
tools/autofix/bin/autofix run NPPM-2993
tools/autofix/bin/autofix run NPPM-2993 --allow-existing-pr

# Resume an interrupted run: reclaims a dead lock, reports the recorded
# stage/terminal, and expects the orchestrator to reconcile against
# Linear/GitHub/env reality before continuing.
tools/autofix/bin/autofix resume autofix-nppm-2993-20260710-a3f1

# Run the terminal-state-keyed cleanup sweep on demand. (Every `run` and
# `resume` invocation also sweeps first — this is for an idle check.)
tools/autofix/bin/autofix cleanup
```

Each stage script (`intake.sh`, `claim.sh`, `ledger.sh`, `env.sh`,
`verify.sh`, `redact.sh`, `pr.sh`) is also directly invocable; their exact
invocations are documented stage-by-stage in `SKILL.md`, not here — this
README only covers the operator-facing dispatcher.

## Run-dir layout

```
tools/autofix/
├── bin/
│   ├── autofix          # dispatcher: intake | run | resume | cleanup
│   ├── intake.sh         # eligibility check + queue dry-run listing
│   ├── claim.sh          # claim protocol (race verification, same-issue guard, conditional release)
│   ├── ledger.sh         # locked init/get/set/history/drift/evidence/reclaim
│   ├── env.sh            # n env create/up/destroy + setup flags + anchor-tag/push-check safeguard
│   ├── verify.sh         # signal re-run, plugin test suite, root-phpcs lint
│   ├── redact.sh         # outward-artifact redaction scanner (scan-only, never edits)
│   ├── pr.sh             # push, adopt-or-create draft PR, Copilot review request
│   └── lib/               # common.sh (config + helpers), linear.sh (GraphQL client)
├── runs/                 # gitignored — one directory per run
│   └── <run-id>/
│       ├── ledger.json   # resumable run state (schema documented in the spec)
│       └── .lock/         # mkdir-based lock; owner PID+host+timestamp in .lock/owner
└── tests/                 # shell test suite (tests/run-tests.sh) + fixtures/ for AUTOFIX_LINEAR_MOCK_DIR
```

A run's env and worktree live in the workspace's usual locations (`n env
create autofix-<issue>-<4hex> --worktree <repo>:<branch>`), tracked in the
ledger's `.env`/`.branch` fields — not under `tools/autofix/`.

## Configuration

All config is environment variables with defaults in `bin/lib/common.sh`.

| Variable | Default | Meaning |
|---|---|---|
| `AUTOFIX_TEAM` | `Product Maintenance` | Linear team name scoping the label-queue query (v1.1). |
| `AUTOFIX_ELIGIBLE_STATUSES` | `Backlog` | Comma-separated Linear status names eligible for label-queue claiming (v1.1); widen later as the workflow proves out. |
| `AUTOFIX_READY_LABEL` | `np-agent-ready` | Label name marking an issue as queue-eligible (v1.1). NPPM team-scoped; do not confuse with the `ai-ready`/`ai-suggested` labels used by an unrelated pipeline. |
| `AUTOFIX_READY_LABEL_ID` | `f0c48c5e-9a4c-4228-b325-5fe6b8c17442` | Linear label id for `np-agent-ready`. |
| `AUTOFIX_FAILED_LABEL` | `np-agent-failed` | Label applied on a no-go/cannot-reproduce/lost-race bail. |
| `AUTOFIX_FAILED_LABEL_ID` | `5de9635c-ac7a-4b00-ab5b-e7680f162cf8` | Linear label id for `np-agent-failed`. |
| `AUTOFIX_ESCALATED_ENV_TTL_DAYS` | `14` | Days an `escalated` run's env/worktree survives before the cleanup sweep flags it for an operator decision (does not auto-destroy). |
| `AUTOFIX_MAX_ATTEMPTS` | `3` | Shared retry/attempt cap: Linear GraphQL retries, env-provisioning attempts, PR-creation attempts. Exhaustion escalates rather than proceeding silently. |
| `AUTOFIX_LINEAR_MOCK_DIR` | (unset) | **Tests only.** When set, `lib/linear.sh` reads response fixtures from this directory (`<opname>.json`) and logs requests to `requests.log` instead of calling the live Linear API. See `tests/fixtures/` for the fixture set the test suite uses. |

Two lower-level overrides exist for testing/tooling but are not part of the
normal operator surface: `AUTOFIX_ROOT` (defaults to `tools/autofix`) and
`AUTOFIX_WORKSPACE_ROOT` (defaults to the workspace root, two directories up
from `tools/autofix`). `AUTOFIX_REDACT_ALLOWLIST`, if set, points
`redact.sh` at a file of known-safe fragments to exempt from findings.

## Docs

- **Spec** (source of truth for every rule this tooling encodes — stage
  machine, no-go rubric, loop bounds, standing authorizations, ledger
  schema): `~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-10-autofix-skill-spec.md`
- **Orchestrator skill** (the judgment layer that drives these scripts
  stage-by-stage): `.claude/skills/autofix/SKILL.md`

## Phasing (v1 → v1.2)

- **v1** (current): operator-named mode only, end-to-end (all eight
  stages). Label-queue and auto-scan code paths exist in `intake.sh` but
  only as `--dry-run` listings. First 2–3 runs supervised by the operator.
- **v1.1**: enable label-queue claiming, once duplicate-claim and
  bail/restore behavior has been exercised in v1, and `autofix intake
  --dry-run` confirms the configured queue (`AUTOFIX_TEAM` +
  `AUTOFIX_READY_LABEL` + `AUTOFIX_ELIGIBLE_STATUSES`) is actually
  non-empty.
- **v1.2**: auto-scan nominations — still report-only (lists candidates in
  the run report for a human to promote; no claiming, no label
  application).
