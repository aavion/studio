# Studio

> **Version**: 0.2.5  
> **Status**: Active development  
> **Updated**: 2026-06-17  
> **Owner**: Dominik Letica  
> **Purpose:** A Symfony-based CMS foundation for structured, extensible project websites.  

**Note:** Studio is still in active development and is not ready for production use yet.

Studio is a CMS foundation for websites that need more than a pile of static pages: structured content, thoughtful access control, package-based customization, and admin workflows that stay understandable when something goes wrong.

The goal is a quiet, dependable system for project websites, portfolios, documentation hubs, and small editorial sites. Content should be modelled clearly, extended through packages, and managed through tools that explain what they are about to do before they do it.

Studio is built around:

- structured content with schema-driven fields;
- package-scoped themes, modules, captcha providers, and editor integrations;
- a shared lifecycle for first-party and third-party packages;
- explicit hooks, provider contracts, and replaceable services;
- editorial workflows such as draft, publish, preview, diff, import, export, and backup;
- ACL-aware content, menus, users, media, APIs, resolvers, and search;
- operational admin tools with action logs and recoverable failure paths.

The project stays close to Symfony, Doctrine, Twig, AssetMapper, Tailwind, Stimulus, and PHPUnit. Core code should remain small and inspectable; packages can extend or replace behavior through documented contracts.

## Current state

Studio currently contains the first system foundations, setup flow, package lifecycle pieces, backend/admin surfaces, user management, ACL roles and groups, runtime translations, asset rebuild tooling, action logs, and early content primitives. It is moving quickly, so expect APIs, migrations, and UI details to change before `1.0.0`.

Feature drafts live in [dev/draft](dev/draft/README.md), developer notes start in [dev/manual](dev/manual/README.md), and active work is tracked in [dev/WORKLOG.md](dev/WORKLOG.md).

## Development

Before making changes, read:

- [Repository agent guide](AGENTS.md)
- [Feature drafts](dev/draft/README.md)
- [Developer manual](dev/manual/README.md)
- [Worklog](dev/WORKLOG.md)

Start with `bin/init` after a clean checkout. Use focused checks while developing and run broader verification before opening a pull request.
