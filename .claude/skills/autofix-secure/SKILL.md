---
name: autofix-secure
description: Supervised sibling of `autofix` for security-labeled Linear issues. Same reproduce→fix→verify→draft-PR machine, but every outward write pauses for operator go and all public artifacts follow disclosure hygiene. Use only when explicitly asked to "autofix-secure NPPM-XXXX".
---

# `autofix-secure` — supervised, disclosure-hardened sibling

This is a **thin** skill. It does **not** re-describe the stage machine — that
lives in, and is governed by, the base `autofix` skill and its spec:

- Stage machine + all mechanical rules: `.claude/skills/autofix/SKILL.md`
- Governing spec (source of truth):
  `~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-10-autofix-skill-spec.md`
- This skill's design + decision record:
  `~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-22-autofix-secure-mode-amendment.md`

**Run the base `autofix` skill's Stages 0–7 exactly as written**, using the same
`tools/autofix/bin/` scripts and the same run ledger, with **only** the outward
layer replaced by the overrides below. Where a stage's mechanical behavior is
unchanged, follow the base skill verbatim; do not fork it here. If anything in
this file appears to conflict with a *safety* rule in the base skill, the
stricter rule wins.

The deliverable is unchanged: **a draft PR + evidence trail.** Never `pr-ready`,
never merge, never a non-draft PR.

## What makes this skill different (and nothing else does)

Base `autofix` bars Security-labeled issues at Stage 0 (`intake.sh check` exit
2, non-bypassable by any flag). `autofix-secure` is the single deliberately-
invoked entry point where such an issue is eligible — and in exchange it (1)
inverts that one eligibility rule, (2) confirm-gates every *disclosing* write,
and (3) applies disclosure hygiene to every public artifact. Everything else —
worktree isolation, the red/green signal, root-phpcs, impact-review, the
≥2-AI-reviewer floor, the resumable ledger — is inherited unchanged.

Start the run with the dedicated secure entry point, which requires the
`AUTOFIX_SECURE=1` **entry ack** and initializes a ledger with `.mode="secure"`
/ `.secure=true`:

```
AUTOFIX_SECURE=1 tools/autofix/bin/autofix run-secure <ISSUE-ID>
```

**The three secure signals, and what each is (not defense-in-depth — all three
are agent-settable):**
- `AUTOFIX_SECURE=1` (env) — the **entry ack**; without it `run-secure` refuses.
  It gates *entry only*; it does **not** drive per-write enforcement.
- `.secure=true` (ledger) — the **enforcement** source of truth. Every gated
  script reads it from the run's ledger (fail-closed), so the gates stay active
  after a `resume` in a fresh shell with no env var.
- `--confirmed=<digest>` (flag) — **operator approval of one specific artifact**
  (see Override 2). You pass it only after the operator has seen the preview.

## Override 1 — Eligibility inversion (Stage 0)

- Requires an **explicit issue ID** — there is no queued or scanned path into
  this skill, ever. Only operator-named invocation.
- A Security label is **eligible here and only here**. The base `autofix run`
  path still exit-2's on a Security label; `run-secure` uses the dedicated
  `intake.sh check-secure` path so the base tool's guarantee stays literally
  intact. (For a Security issue that already has an open PR, `check-secure`
  requires both `AUTOFIX_SECURE_ALLOW_EXISTING_PR=1` and `--allow-existing-pr`
  — a deliberate double-signal.)
- Every **other** Stage-1 no-go rubric criterion (a–f) and every hard safety
  rule bind **unchanged**. Secure mode relaxes the Security-label bar and
  nothing else.

## Override 2 — Confirm-gate the disclosing writes (not local commits, not assign/status)

The gate covers **disclosing writes only**: the branch **push**, the **draft PR**
open + body, **every Linear comment** (claim, bail, closeout — Overrides 3 & 4),
and the **Copilot** request. It does **not** cover local `git commit`s (they
disclose nothing until a push — keep the TDD loop fast) or claim-time
assign-self / move-to-In-Progress (working-state transitions the operator
authorized by invoking `run-secure`; their activity-feed notification is an
accepted, documented signal).

**How the gate works mechanically** — the two-phase, digest-bound protocol the
tooling enforces:

1. **Preview.** Invoke the gated command *without* `--confirmed`. In a secure
   run it does not perform the write; it writes the full artifact to a run-dir
   preview file, prints `GATED: <digest> <preview-file>` to stdout, and exits
   `7`. For a PR the artifact is the resolved PR body **plus the commit diff**
   that would be pushed — the real disclosure, not a filename.
2. **Halt** (see *Skill-side obligations*) and surface the preview file to the
   operator.
3. **Confirm.** Only after the operator approves, re-invoke with
   `--confirmed=<digest>` (the digest from the `GATED:` line). The tooling
   recomputes the digest and refuses if the artifact changed since preview, so
   approval binds to the exact bytes.

Gate decisions (preview / confirmed) are logged to `stage_history`
automatically. Decline Copilot with `--no-copilot` on `pr.sh create`.

## Override 3 — Claim comment is operator-gated (Stage 0)

The base claim protocol is: assign-self → move **In Progress** → post
`🤖 autofix run <run-id> started`. Here:

- **assign-self and move-to-In-Progress happen automatically** — they carry no
  exploit content and establish ownership.
- **`run-secure` defers the claim comment**: it does not post one, records
  `claim_comment=deferred`, writes a preview, and verifies ownership by assignee
  alone (accepting a weaker cross-machine race check — the run's acknowledged
  weakest point). If the operator later wants the claim comment posted, route it
  through `claim.sh comment <ISSUE> <RUN> --body-file <f> --confirmed=<digest>`
  like any other gated comment; it must satisfy the disclosure checklist below.

## Override 4 — Disclosure hygiene on every public artifact

The base redaction gate (`redact.sh`) scans for **secrets** and still runs,
unchanged, before Stage 5 review and before every push. Layer this orthogonal
discipline — **don't telegraph the vulnerability** — on top:

- **Stage 1/2 bail briefs are NOT auto-posted.** `claim.sh release` in a secure
  run performs the conditional state restore (ungated) but **gates the
  `np-agent-failed` label and the brief**: invoked without `--confirmed` it
  restores state, previews the brief, and exits `7`. Surface the preview to the
  operator; post the approved brief (and label) via a confirmed re-invocation
  (`release … --confirmed=<digest>`). Route any standalone comment through
  `claim.sh comment` so redaction + gate + audit apply — never raw MCP.
- **Stage 4 adjacent-input follow-ups are drafted, not auto-filed.** A
  cross-repo sibling found by the adjacent-input probe becomes a *draft*
  follow-up for the operator (a public Linear issue could telegraph the sibling
  flaw), not an auto-created issue.
- **Stage 6 PR + closeout** wording is neutral (below); PoC/exploit payloads
  and any fuzzing harness stay under the run dir, **never committed**. The
  regression test that lands is the minimal behavioral assertion under a
  **neutral name**.
- **Exploit detail lives in the Stage 7 run report** (local knowledge repo,
  never pushed) — the honest technical record goes there, not on GitHub/Linear.

### Disclosure-hygiene checklist

Applies to every **public-facing** artifact: code comments, commit
subjects/bodies, PR title/description, changelog entries, test/fixture names,
and any Linear comment text. (`newspack-plugin` and most product plugins are
**public** repos — their artifacts are the most sensitive; treat the private
`newspack-manager-admin` the same.)

- Describe the **what** neutrally — frame as ordinary correctness/validation
  (e.g. "validate the OAuth access token was issued to this site's configured
  Google client"), never the **threat**.
- Avoid the words *exploit, attacker, victim, takeover, vulnerability, proof of
  identity* on any public surface.
- Internal/gitignored artifacts (`.agent-knowledge/` plan docs, the run report)
  may stay fully detailed.
- Issue refs (`NPPM-XXXX`) are fine — the autolink is not a disclosure; keep the
  surrounding human-readable text neutral.
- Coordinated-disclosure rationale: telegraphing the flaw before it is patched
  everywhere (and the publisher-plugin update long tail has caught up) hands
  attackers a roadmap.

## Hard rules (unchanged from base — never override)

All base-skill hard rules bind here in full: no `pr-ready`, no merge, no
non-draft PR; worktree isolation with the root checkout on `main`; no upstream
pushes of the tooling; no pushes of the run report or its knowledge repo; the
resumable ledger is the only supported re-entry point (`autofix resume`). This
skill **adds** pauses and hygiene; it never adds authority.

## Skill-side obligations (the halt the tooling cannot enforce)

The confirm-gate is **audit + friction for a cooperative agent under
supervision, not a control** — the same agent that runs a gated command owns
the shell and could pass `--confirmed` unprompted. These obligations are the
load-bearing steps the tooling cannot enforce; honoring them is what makes the
gate real:

1. **Halt for a real operator turn on `GATED:` / exit 7.** Stop, surface the
   preview file, and get explicit approval. **Never self-issue
   `--confirmed=<digest>` in the same autonomous step** — the digest is passed
   only after the operator has seen the actual artifact.
2. **Route every Linear comment through `claim.sh comment`**, never raw MCP, so
   redaction + gate + audit apply uniformly. (Unenforced by tooling — an
   obligation, not a guarantee.)
3. **Never weaken the preview.** Surface the actual preview-file bytes to the
   operator; do not paraphrase or summarize the artifact in its place.

## Tooling reference (built — see the bin spec)

Secure behavior lives in `tools/autofix/bin/` (design + review record:
`~/Repositories/A8C/newspack-agent-knowledge.git/_tooling/specs/2026-07-22-autofix-secure-bin-tooling-spec.md`).
Key commands: `autofix run-secure <ISSUE>` (entry), `claim.sh comment … [--confirmed=<d>]`,
`claim.sh release … [--confirmed=<d>]`, `pr.sh create … [--confirmed=<d>] [--no-copilot]`.
Enforcement is ledger-driven (`.secure`), fail-closed; gated writes emit
`GATED: <digest> <preview-file>` and exit 7 until confirmed.
