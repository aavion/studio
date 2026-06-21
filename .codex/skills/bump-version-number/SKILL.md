---
name: bump-version-number
description: Bump the project or extension version in this repository. Use when the user explicitly asks to raise, bump, set, or update the version number of the root project or a specific extension/package. Do not use for dependency-only updates, release-note drafting, or general package maintenance unless a project or extension version bump is requested.
---

# Bump Version Number

Use this skill when the user asks to bump the root project version or the version of a specific extension. Treat the manifest as the source of truth.

## Version Target

1. Identify the intended target:
   - root project: the root `.manifest`
   - extension: the requested extension manifest, usually under `extensions/{slug}/.manifest` or in the extension repository
2. If the user provides an explicit version, use that exact version.
3. If no explicit version is provided:
   - patch version `+1` for small changes, fixes, review fixes, docs-maintenance bumps, and routine compatibility updates
   - minor version `+1` for larger feature slices or contract-visible feature work
   - major version `+1` only after explicit user instruction and a brief confirmation of intent
4. Preserve the existing version format and manifest style.

## Dependency And Compatibility Checks

After changing the version:

1. Resolve known manifest dependencies that reference the bumped project or extension.
2. Report dependency constraint conflicts immediately instead of silently widening them.
3. Update internal dependency constraints only when the new version clearly requires it or the user requested it.
4. Prefer dynamic tests that read from manifests over static assertions pinned to a concrete version.
5. If a test would fail only because it hardcodes the old version, update that assertion to compare against the manifest value.

## External Update Awareness

Check for available external updates and report safely applicable candidates. Do not update external dependencies unless the user asks for dependency updates.

Run:

```bash
COMPOSER_MEMORY_LIMIT=256M bin/composer outdated
bin/console importmap:outdated
```

If either command cannot run, report the reason.

## Verification

Run the full suites after the version bump unless the user explicitly narrows verification:

```bash
bin/phpunit
bin/jstest
bin/lint
```

If full suites are too slow, blocked, or already user-reported, say exactly what was run and what remains unverified.

## Output

Summarize:

- old version and new version
- manifest path changed
- dependency conflicts or compatible dependency references found
- external update candidates from Composer/importmap checks
- static version assertions converted to manifest-derived expectations
- verification results

