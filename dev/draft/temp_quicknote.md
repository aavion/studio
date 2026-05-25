# Quicknote

> **Status**: Draft  
> **Updated**: 2026-05-20  
> **Owner**: Dominik Letica  
> **Purpose:** Collection of ideas for crafting the project-outline and feature-drafts. Temporary: May get deleted after use.  

## First Ideas (Unordered List)

- Error-handling and verification/linting-workflow with preferably non-throwing recoverable error-messages, sending original input back to form-fields (where applicable) to prevent unwanted data-loss.
- Decisions about code-modularity and expandability (preparation for plugin modules adding/replacing functionality and themes)
- Expandability through Plugin Modules and Themes
- Theme-engine that automatically chooses, what template-files and assets to load based on local overrides
- Event-handling with busses and hooks to improve modularity
- Database-features with variable fieldsets (schema-based)
- API-features (REST?)
- Basic backup/restore features with support for database-cloning for dev/staging environments
- Import/export functionality for LLM collaboration (export content with modifiable scope and formatting, op-based interaction to add/remove/modify content with diff-view before processing)
- Contact-form for mail-contact
- Logger with statistics and GeoIP
- Static pages vs dynamic content handling and project organization
- Security-features: ACL, rate-limiting, transfer IconCaptcha from Grav as basic captcha solution (possibility to be replaced by adddon modules), ...?
- Discuss and outline UI/UX features
- URL-structure and hierarchy
- Editor functionality
- Automatic resolver for cross-references with neural-like content-indexing (that can also be used for a search-function)
- Script-based setup/init-procedure with base-configuration in env:test for phpunit and code-review automations
- Self-Update aavion Studio, Themes and Plugin-Modules using .manifest (decision: update directly via git or release-packages? dev-channel should use git, if possible)
- Script-based release-workflow (auto-cleanup of unnecessary files, release-packaging)
- Ideas for upcoming features in future releases: CommunityHub (Comments, Forum, Profiles), Inline Frontpage-Editor, REI3-tickets integration via plugin module
