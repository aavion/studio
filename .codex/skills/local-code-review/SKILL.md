---
name: local-code-review
description: Run a complete broad local branch or pre-PR code review for this repository. Use only when the user explicitly asks for a full local branch review, complete local PR review, broad pre-PR review, exhaustive mini-model review, or Cloud-Review-like full pass that reports findings without modifying production code. Do not use for focused review comments, narrow file checks, review-finding fixes, or small "please check this" requests.
---

# Local Code Review

Use this skill only for a complete local review of the current branch or a specified diff before a pull request or before another broad review round. Treat it as a reporting task: inspect, validate, and report findings; do not edit production code, tests, docs, generated files, or Git history unless the user separately asks for fixes.

Do not use this skill for narrow review-finding fixes, focused checks of a few files, single-topic audits, or quick sanity checks. Those should follow `AGENTS.md` review rules without loading this full-pass workflow.

## Ground Rules

- Follow `AGENTS.md` and the project review rules.
- Report real behavioral, security, lifecycle, data integrity, runtime, compatibility, or contract risks. Avoid speculative style findings.
- Trace each suspected issue from source to sink before reporting it.
- Inspect adjacent and analogous code paths that share the same policy, lifecycle, resolver, subscriber, validator, storage boundary, route family, process helper, or contribution path.
- Prefer the smallest precise finding over broad rewrite advice.
- Do not stop after the first few findings. Complete the planned passes unless blocked by missing context or tool failure.
- If a finding overlaps a known explicit product decision, mark it as such instead of reporting it as a bug.
- If a suspected issue is invalid after tracing, discard it silently or mention it only under "Reviewed But Not Reported" when useful.
- Use absolute file paths and line references in findings.

## Scope Setup

1. Read `AGENTS.md`, `dev/WORKLOG.md`, and relevant feature docs or drafts touched by the branch.
2. Determine the review base:
   - If the user provides a base, use it.
   - Otherwise use the merge base with the upstream target branch when available.
   - If no upstream target is known, ask for the intended base or state the assumed base explicitly.
3. Collect the changed files and commits:
   - `git diff --name-status <base>...HEAD`
   - `git log --oneline <base>..HEAD`
   - `git status --short`
4. Identify generated, vendored, asset snapshot, lockfile, and pure rename files. Skim them for obvious hazards, but spend review depth on behavior-bearing code and contract documentation.

## Review Passes

Run these passes over the branch diff and the affected current code. Use focused `rg`, `git diff`, and file reads. Rebuild context from the repository after every context reset or model compaction.

### Pass 1: Entry Points And Lifecycles

Inspect controllers, commands, API/live endpoints, operations, schedulers, event hooks, subscribers, installers, activation/deactivation flows, setup/init, and process runners.

Look for:
- broken precondition checks, authorization gaps, or scope mismatches
- rollback paths that restore only part of a state change
- success results after failed side effects
- stale cache, stale registry, stale lock, or stale filesystem state
- async/detached work that hides failure or changes ordering assumptions
- faulty/disabled fallback paths that do not match the normal path

### Pass 2: Persistence And Data Integrity

Inspect Doctrine entities, repositories, DBAL access, migrations, config storage, content revisions, extension-owned data, schemas, and cleanup/purge paths.

Look for:
- partial destructive operations without rollback, journal, or explicit best-effort contract
- stale foreign-key-like references, orphaned records, or historical revision breakage
- inconsistent identifiers, owner prefixes, namespace collisions, or slug normalization collisions
- update/install/purge flows that handle active state but miss inactive, faulty, removed, or stale states
- database portability issues across SQLite, MySQL/MariaDB, and PostgreSQL

### Pass 3: Boundaries And Validation

Inspect validators, file policies, manifests, contribution registries, extension/module/theme boundaries, asset/template/language policies, and public extension-facing interfaces.

Look for:
- validator/consumer drift where validation accepts data that runtime ignores or rejects data runtime accepts
- shallow scans followed by deep recursive copy, sync, or execution
- unsafe files becoming publicly reachable
- scope gates missing on API/live/routes/assets/templates/settings/database/content contributions
- owner checks missing on contribution objects or settings
- manifest/documentation promises that are not enforced by code

### Pass 4: Security And Privacy

Inspect public input, request identity, session use, tokens, HMACs, secrets, browser storage, cookies, logs, audit records, subprocess environment, and user-provided files.

Look for:
- token replay, weak binding, missing one-shot consumption, or predictable IDs
- unredacted secrets in logs, messages, docs, fixtures, or errors
- path traversal, symlink, archive extraction, or public asset exposure
- SSRF, command injection, unsafe deserialization, unsafe dynamic class loading, or unsafe PHP execution
- consent, cookie, privacy, or cross-site behavior that violates the documented boundary

### Pass 5: Runtime Integration And Compatibility

Inspect service wiring, DI config, environment handling, test bootstrap, init scripts, Composer/Node handling, cache warmup, translation aggregation, asset rebuild, and cross-platform path handling.

Look for:
- dev-only assumptions in production setup
- missing optional dependency fallbacks
- platform-specific shell/path behavior
- translation catalogue drift
- generated/runtime file mismatches
- tests that pass only because of shared global state

### Pass 6: Tests And Documentation Drift

Inspect tests and documentation for the changed behavior.

Look for:
- missing regression coverage for new branches, failure paths, and rollback paths
- assertions pinned to incidental counts, exact versions, or implementation ordering
- docs that promise unsupported behavior
- worklog/classmap omissions for relevant callable or contract changes
- tests that skip too broadly or hide actual failure

## Validation Standard

Before reporting a finding:

1. Name the invariant or contract that should hold.
2. Show the exact path where the invariant can be broken.
3. Confirm the issue is reachable from a public, admin, operation, CLI, extension, setup, or runtime path.
4. Check whether adjacent paths already solve the same issue differently.
5. Estimate impact and priority.
6. Prefer one finding for the shared root cause instead of many duplicates.

## Output Format

Start with findings, ordered by severity. If there are no findings, say that clearly and list residual risks or unverified areas.

Use this shape:

```md
## Findings

[P1] Short imperative title
File: /absolute/path/to/file.php:123
Issue: What is wrong.
Impact: Why it matters.
Evidence / failure scenario: How the issue is reached.
Suggested minimal fix: The smallest safe fix or contract clarification.

## Reviewed But Not Reported
- Optional: invalidated concerns, explicit product decisions, or deferred known follow-ups.

## Coverage
- Base reviewed: `<base>...HEAD`
- Passes completed: entry points/lifecycle, persistence, boundaries, security/privacy, runtime, tests/docs
- Commands or inspections used: concise list
- Not verified: honest gaps, tool failures, or skipped generated/vendor surfaces
```

Use priorities consistently:

- `P0`: data loss, critical security, or system-wide breakage likely on normal use
- `P1`: serious reachable bug, privilege/security boundary break, or destructive lifecycle failure
- `P2`: important edge case, stale-state, rollback, contract, or compatibility bug
- `P3`: low-risk drift, misleading docs, missing focused coverage, or minor operational hazard
