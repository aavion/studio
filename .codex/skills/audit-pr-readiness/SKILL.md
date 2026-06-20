---
name: audit-pr-readiness
description: Run the repository PR-readiness audit before marking a branch or feature slice ready for review. Use when the user explicitly asks for PR readiness, ready-for-review preparation, final pre-PR audit, filling the PR template, or checking whether a branch is ready to submit. Do not use for ordinary code review, review-finding fixes, or broad local branch review unless readiness sign-off is requested.
---

# Audit PR Readiness

Use this skill to prepare or audit a branch against the repository PR-readiness expectations. This is not a substitute for code review; use `local-code-review` for a complete bug-finding pass and `fix-review-findings` for supplied review comments.

## Required Sources

Before auditing, read:

- `AGENTS.md`, especially `PR Readiness Audits`
- `.github/PULL_REQUEST_TEMPLATE.md`
- `dev/WORKLOG.md`
- relevant changed docs, drafts, manuals, tests, and class-map entries

For the two PR-template references, use these sources directly:

- `#57`: fetch GitHub issue `aavion/studio#57` and use it as the primary source for project-rules, architecture, naming, and documentation drift expectations.
- `#109`: fetch GitHub issue `aavion/studio#109` and use it as the primary source for codebase readability, naming, hierarchy, class map, frontend structure, and test-suite clarity.

Do not copy the PR checklist into this skill as a second source of truth. Always use `.github/PULL_REQUEST_TEMPLATE.md` as the checklist basis.

## Workflow

1. Determine the review base and changed scope.
2. Read the current PR template and turn each checkbox into an evidence-backed audit item.
3. Inspect the branch diff and affected runtime surfaces for each applicable item.
4. Run or confirm the required verification commands:
   - `bin/phpunit`
   - `bin/jstest`
   - `bin/lint`
   - focused commands for changed surfaces when full suites do not cover them well
5. Check documentation alignment:
   - `README.md`
   - `dev/draft/*.md`
   - `dev/CLASSMAP.md`
   - `dev/WORKLOG.md`
   - `dev/manual/*.md`
   - `docs/*.md`
6. Check translation and user-facing copy alignment when copy changed.
7. Check setup/init/CI, cross-platform behavior, disabled-feature fallbacks, process/env handling, and extension/API/live route boundaries when touched.
8. Capture follow-ups in `dev/WORKLOG.md`'s To-Do section when they are real but not appropriate for the current branch.
9. Fill or update the PR template with honest checkboxes. Leave unchecked anything not actually audited or verified.

## Evidence Standard

- A checked box needs evidence from code inspection, docs inspection, tests, linting, rendering, command output, or an explicit "not applicable" reason.
- Do not mark a checklist item complete because similar work was done earlier in the branch.
- If a command was run outside the current session by the user, record it as user-reported unless you also ran it.
- If a full suite was skipped, state why and list the focused substitute checks.

## Output

Return a PR-ready note based on the project template:

```md
## Summary

## Testing

## Documentation

## Additional Checks

## Linked Issues / Discussions

## Review Notes
```

Also summarize:

- small readiness fixes made
- skipped checks or proof gaps
- follow-ups recorded in `dev/WORKLOG.md`
- whether the branch is ready, blocked, or ready with caveats
