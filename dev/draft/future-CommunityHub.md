# Community hub (Feature Draft)

> **Status**: Future  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Future draft placeholder for comments, forums, profiles, and community-facing interaction features.

## Overview
- Defines a later community feature area outside the first core release.
- Covers comments, forums, profiles, moderation, notifications, and plugin integration.
- Depends on security, content, notification, and module foundations.

## Outline
CommunityHub is a future feature family for public interaction around project websites. It may include comments, forums, user profiles, moderation workflows, notifications, and public contribution tools.

This feature should not be implemented during the first core phase. Current architecture decisions should only avoid blocking it. The content model, security layer, plugin module lifecycle, notification system, and API boundaries should remain flexible enough that community features can later be added as first-party or third-party modules.

## Technical Specifications
- Future implementation should prefer plugin-module boundaries.
- Community domain data should use module-owned tables instead of generic content fieldsets.
- Permissions, moderation actions, and public forms should build on the security, captcha, rate-limit, and event-hook drafts.
- Notifications should use Messenger and documented lifecycle events where applicable.
- Public profile and forum routes should not conflict with reserved core route prefixes.

## Testing & Validation
- Future validation should cover moderation permissions, public submission abuse controls, notification delivery, and content relationship integrity.
- Load and permission tests will be required before enabling public interaction features.

## Implementation Notes
- Deferred until the core CMS architecture is stable.
