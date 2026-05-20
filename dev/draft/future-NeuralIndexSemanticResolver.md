# Neural-like index and semantic resolver (Feature Draft)

> **Status**: Future  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Future draft placeholder for semantic reference suggestions, weighted context discovery, and neural-like indexing on top of the deterministic resolver foundation.

## Overview
- Defines a later resolver enhancement outside the first deterministic resolver implementation.
- Covers aliases, proper-name recognition, mention frequency, weighted context suggestions, and richer semantic search.
- Depends on stable content entities, schema field identifiers, deterministic reference indexing, ACL handling, and search adapter boundaries.

## Outline
The first resolver implementation should stay deterministic. It should index explicit references, query fields, field identifiers, variants, and resolver-token targets without duplicating all content into a resolved JSON store.

This future feature can build on that foundation once the deterministic resolver is stable. It may analyze titles, aliases, repeated mentions, nearby references, project-local relationship graphs, and other semantic signals to suggest likely references or related context.

The goal is not to replace direct references or controlled query fields. Neural-like indexing should extend them with weighted suggestions that remain reviewable, reversible, ACL-aware, and bounded by project scope.

## Technical Specifications
- Build on the database-backed deterministic resolver index.
- Treat semantic suggestions as hints, not authoritative links.
- Keep generated suggestions reviewable before modifying stored content.
- Respect ACL restrictions during indexing, suggestion generation, search, export, and editor autocomplete.
- Start with title and alias matching before adding heavier semantic analysis.
- Keep resource-intensive indexing asynchronous and configurable.
- Provide clear diagnostics for why a suggestion was produced where practical.

## Testing & Validation
- Future validation should cover suggestion quality, ACL filtering, performance limits, index rebuild behavior, and non-destructive editor workflows.
- Suggestions must not silently create stored links without editor review.

## Implementation Notes
- Deferred until deterministic content, schema fields, resolver index, editor autocomplete, and import/export workflows are stable.
