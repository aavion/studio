# Inline frontpage editor (Feature Draft)

> **Status**: Future  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Future draft placeholder for direct frontpage editing after the core editor and theme engine exist.

## Overview
- Defines a later inline editing experience for rendered pages.
- Covers content selection, permissions, draft preview, validation, and safe publishing.
- Depends on editor experience, theme engine, security, and content schemas.

## Outline
The inline frontpage editor is a future editing experience for modifying rendered public pages directly. It should build on stable structured content editing, theme resolution, preview, permissions, validation, and diff/review workflows.

This feature should not drive first-phase UI complexity. Current decisions should keep rendered content traceable to editable content records, schemas, fields, and templates so inline editing can later identify safe edit targets without rewriting the content model or theme engine.

## Technical Specifications
- Future implementation should reuse editor actions, preview, validation, and diff infrastructure.
- Editable regions should be declared by themes or renderers through documented hooks.
- Inline changes should preserve normal content permissions and CSRF protection.
- Inline editing should not bypass schema validation or publication workflows.
- Frontend behavior should use Stimulus and AssetMapper/importmap entrypoints unless a later need justifies heavier tooling.

## Testing & Validation
- Future validation should cover permissions, editable-region mapping, draft previews, input preservation, and publish boundaries.
- Visual and rendered-route checks will be required for every supported theme/editor interaction.

## Implementation Notes
- Deferred until structured content and editor workflows are stable.
