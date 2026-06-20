---
name: fix-review-findings
description: Fix one or more concrete review findings in this repository. Use when the user provides review comments, PR findings, inline review feedback, Cloud Review findings, or local review findings and explicitly asks Codex to fix or address them. Do not use for broad branch reviews, PR-readiness audits, or ordinary feature implementation without supplied findings.
---

# Fix Review Findings

Use this skill to address supplied review findings with code changes. This is a fix workflow, not a discovery review.

## Required Sources

Before editing, read:

- `AGENTS.md`, especially `Review Finding Fixes`
- `dev/WORKLOG.md`
- the cited files, tests, docs, and adjacent code paths for each finding
- all user comments, maintainer replies, product decisions, policy decisions, and requested scope limits attached to or near the supplied findings

Use `local-code-review` only when the user separately asks for a complete local branch or PR review. Use `audit-pr-readiness` only when the user asks for a PR-readiness audit.

## Workflow

1. Restate the findings briefly and deduplicate shared root causes.
2. Match each finding with any user or maintainer comments. Treat explicit user decisions as binding for the fix direction unless they conflict with higher-priority safety or project rules.
3. Do not implement a fix the user rejected. If a comment marks a finding as intentional, policy-driven, or out of scope, document that decision or record the requested follow-up instead of changing behavior.
4. For each root cause, trace the affected boundary from source to sink.
5. Inspect adjacent and analogous paths that share the same policy, validator, resolver, route family, lifecycle step, storage boundary, contribution type, or cleanup/rollback behavior.
6. Decide whether the finding is valid, invalid, already fixed, an explicit product decision, or a follow-up.
7. For valid findings, place the smallest central fix that covers all affected paths and follows the user's scope comments.
8. Add or update focused regression coverage for the failing path and any adjacent path changed by the fix.
9. Update docs, worklog, class map, translations, or follow-up notes only when the fix changes the documented contract or relevant callable map.
10. Run focused verification while developing, then a broader relevant slice before finishing.
11. If the user asks for separate commits, commit each logical fix separately with an imperative message.

## Fix Discipline

- Do not turn a review fix into a broad redesign.
- Do not hide larger or behavior-changing follow-ups inside a narrow fix; record them in `dev/WORKLOG.md`.
- Do not report success for a partially fixed finding. State residual risk or skipped verification clearly.
- Do not revert unrelated user or collaborator changes.
- Prefer structured `Message`, `WorkflowResult`, and domain-owned message catalogues where runtime feedback is needed.

## Output

When done, summarize:

- findings addressed and status
- commits created, if any
- verification commands and results
- intentionally deferred or rejected findings, with the reason
- remaining worktree state if not clean
