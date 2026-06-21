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
4. For each root cause, restate the concrete invariant, attacker or actor input when relevant, closest control or broken control, sink or state transition, impact, and preconditions.
5. Trace the affected boundary from source to sink. Before editing, establish that the reported weakness is concretely reachable in the checked-out code; if it is already fixed or invalid, prove that instead of patching a nearby concern.
6. Inspect adjacent and analogous paths that share the same policy, validator, resolver, route family, lifecycle step, storage boundary, contribution type, cleanup/rollback behavior, wrapper, or dangerous sink.
7. Decide whether the finding is valid, invalid, already fixed, an explicit product decision, or a follow-up.
8. Reproduce, encode, or otherwise pin the issue before fixing when feasible. Prefer a focused failing test or realistic-interface reproduction; if runtime proof is disproportionate, document the static proof and gap.
9. For valid findings, place the smallest central fix that covers all affected paths and follows the user's scope comments.
10. Add or update focused regression coverage for the failing path and any adjacent path changed by the fix. Include positive coverage for legitimate behavior that must continue to work.
11. Re-check the original source/control/sink path after the fix and search nearby bypasses or equivalent call paths that might avoid the new control.
12. Update docs, worklog, class map, translations, or follow-up notes only when the fix changes the documented contract or relevant callable map.
13. Run focused verification while developing, then a broader relevant slice before finishing.
14. If the user asks for separate commits, commit each logical fix separately with an imperative message.

## Fix Discipline

- Do not turn a review fix into a broad redesign.
- Do not hide larger or behavior-changing follow-ups inside a narrow fix; record them in `dev/WORKLOG.md`.
- Do not report success for a partially fixed finding. State residual risk or skipped verification clearly.
- Do not revert unrelated user or collaborator changes.
- Prefer structured `Message`, `WorkflowResult`, and domain-owned message catalogues where runtime feedback is needed.
- Do not weaken authentication, authorization, tenant isolation, input validation, sandboxing, logging, auditability, or lifecycle rollback semantics to make a finding or test pass.
- Treat setup errors, missing generated files, missing dependencies, or slow validation commands as evidence to investigate with bounded effort, not as immediate proof that runtime validation is impossible.
- Do not claim the original issue is fixed until the changed code and the original vulnerable path or broken invariant have both been checked.

## Output

When done, summarize:

- findings addressed and status
- commits created, if any
- verification commands and results
- intentionally deferred or rejected findings, with the reason
- how the original path was shown closed, or the exact proof gap if runtime validation was not feasible
- remaining worktree state if not clean
