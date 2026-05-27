# aavion.studio

> **Version**: 0.1.0-dev  
> **Status**: Active development  
> **Updated**: 2026-05-27  
> **Owner**: Dominik Letica  
> **Purpose:** Symfony 8 based content-management system for structured project websites.  

**Note:** This repository is not ready for production use yet.

aavion.studio is an experimental CMS foundation for project websites that need structured content, modular extension points, theme support, and safe operational workflows. The project is currently in its planning and early development phase.

The intended direction is a Symfony-native application with:

- schema-driven content and variable fieldsets;
- package-scoped frontend themes, backend themes, modules, captcha providers, and editor providers;
- first-party and third-party packages under one lifecycle;
- explicit event hooks, provider contracts, and replaceable services;
- draft, publish, preview, diff, import, export, and backup workflows;
- ACL-aware content, media, API, resolver, and search behavior;
- operational admin tools with action logs and recoverable failure handling.

The project favors native Symfony components and bundles over custom framework code. Core features should stay small and inspectable, while packages can extend or replace behavior through documented contracts.

## Current state

The repository currently contains planning drafts and project scaffolding. Feature drafts live in [dev/draft](dev/draft/README.md), developer documentation starts in [dev/manual](dev/manual/README.md), and active work is tracked in [dev/WORKLOG.md](dev/WORKLOG.md).

## Development

This project targets Symfony 8 and uses Composer, Doctrine, Twig, AssetMapper, Tailwind, Stimulus, and PHPUnit. The expected workflow is still being finalized. Before making changes, read:

- [Repository agent guide](AGENTS.md)
- [Feature drafts](dev/draft/README.md)
- [Developer manual](dev/manual/README.md)
- [Worklog](dev/WORKLOG.md)

Common verification commands will be documented as implementation progresses.

## License

License information will be added before a public release.
