# Release and update snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Capture package update, release, checksum, rollback, and compatibility notes before the self-update workflow is implemented.  

## Overview

Release and update behavior is intentionally deferred. These notes exist so early package, checksum, dry-run, and action-log primitives remain compatible with future updater needs.

## Package integrity notes

Future release packages should likely include:

- package manifest;
- package type;
- version;
- required core version;
- checksum map;
- signature metadata;
- dependency map;
- migration metadata;
- rollback metadata.

## Update flow sketch

```text
download or receive package
  -> verify signature
  -> verify checksums
  -> inspect manifest
  -> validate compatibility
  -> build dry-run
  -> require confirmation
  -> execute staged update
  -> rebuild assets
  -> clear cache
  -> record action log
```

## Rollback notes

Rollback scope needs a concrete policy:

- files only;
- files plus database migrations;
- config changes;
- assets/cache;
- module-owned data.

Avoid promising rollback until each operation type has an inverse action or snapshot strategy.

## Compatibility notes

Compatibility checks may include:

- PHP version;
- PHP extensions;
- Composer package constraints;
- Symfony version;
- core version;
- enabled modules;
- active theme;
- database platform.

## References

- [Package lifecycle snippets](package-lifecycle-snippets.md)
- [Security guard snippets](security-guard-snippets.md)
- [Self-update and release workflow draft](../draft/0.5.x-SelfUpdateReleaseWorkflow.md)
