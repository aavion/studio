# Project Rules

> **Status**: Active  
> **Updated**: 2026-05-23  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Record project-wide decisions agents should remember across sessions.  

## Pre-1.0 Development

- No production deployment must be supported before the first stable `1.0.0` release.
- Before `1.0.0`, prefer clean design over compatibility shims, data migrations, or legacy behavior.
- Keep Doctrine migrations consolidated: the project should contain one current baseline migration before `1.0.0`, not a long chain of development migrations.
- When changing database shape before `1.0.0`, edit the baseline migration, entities, tests, docs, and class map together.
- Remove obsolete code paths instead of preserving backward compatibility unless the user explicitly asks otherwise.

## Database Support

- The application should support MariaDB/MySQL, SQLite, and PostgreSQL through Doctrine DBAL/ORM where practical.
- The automated test environment uses SQLite at `var/test/test.db` via `.env.test`.
- Migration tests should verify that the current baseline migration applies cleanly to SQLite.
- Prefer portable Doctrine types, portable indexes, explicit columns for frequently filtered values, and app-level validation over vendor-specific SQL behavior.
- JSON columns are acceptable for flexible configuration, profile data, schema definitions, labels, ACL group lists, metadata, and field content.
- Do not rely on vendor-specific JSON operators for core read paths unless the feature explicitly declares a minimum database/version requirement.
- If a flexible JSON value becomes a common filter/sort/list field, add a portable explicit column or read-model/index table instead of requiring database-specific JSON indexes.

## Content Revisions

- Content revisions are the stable unit for import previews, structured diffs, review, revert, and retention.
- Imports may stage proposed changes as new revisions, diff those revisions against the active revision, and activate them only after review.
- Cleanup and retention should be driven by nullable active pointers and configuration, not by hard-deleting historical rows by default.
