# REI3 tickets integration (Feature Draft)

> **Status**: Future  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Future draft placeholder for a plugin-module integration with REI3 tickets.  

## Overview
- Defines a later integration feature that should live outside the core CMS.
- Covers ticket synchronization, permissions, API access, and module packaging.
- Depends on plugin modules, API conventions, and security decisions.

## Outline
REI3 tickets integration is a future plugin-module feature for connecting project websites with external ticket workflows. It should remain outside the CMS core unless a later product decision makes ticketing a first-party domain.

Current architecture should keep API adapters, module-owned tables, permissions, background synchronization, and external-service configuration possible without coupling the core content model to REI3-specific concepts.

## Technical Specifications
- Future implementation should be a plugin module with its own manifest metadata.
- Ticket-specific data should use module-owned tables.
- External API credentials must use environment configuration or Symfony secrets, not manifests.
- Synchronization should use Messenger when it can run asynchronously.
- Public or admin routes should declare module permissions before activation.
- Content references to tickets should use documented cross-reference/index hooks.

## Testing & Validation
- Future validation should cover API adapter failures, synchronization retries, permission checks, and uninstall/disable behavior.
- Tests should use fixtures or test doubles rather than live external REI3 services.

## Implementation Notes
- Deferred until the plugin module system and API layer are stable.
