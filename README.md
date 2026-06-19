# Studio

> **Version**: 0.2.6  
> **Status**: Active development  
> **Updated**: 2026-06-19  
> **Owner**: Dominik Letica  
> **Purpose:** A Symfony-based CMS foundation for structured, extensible project websites.  

**Note:** Studio is still in active development and is not ready for production use.

Studio is a CMS foundation for websites that need more than a pile of static pages: structured content, thoughtful access control, extension-based customization, and admin workflows that remain understandable when something goes wrong.

The goal is a quiet, dependable system for project websites, portfolios, documentation hubs, and small editorial sites. Content should be modelled clearly, extended through extensions, and managed through tools that explain what they are about to do before they do it.

Studio is built around:

- structured content with schema-driven fields;
- extension-scoped themes, modules, captcha providers, and editor integrations;
- a shared lifecycle for first-party and third-party extensions;
- explicit hooks, provider contracts, runtime contributions, and replaceable services;
- editorial workflows such as draft, publish, preview, diff, import, export, and backup;
- ACL-aware content, menus, users, media, APIs, resolvers, and search;
- operational admin tools with action logs and recoverable failure paths.

The project stays close to Symfony, Doctrine, Twig, AssetMapper, Tailwind, Stimulus, and PHPUnit. Core code should remain small and inspectable; extensions can extend or replace behavior through documented contracts.

## Current state

Studio currently contains the system foundation, setup flow, extension lifecycle pieces, extension runtime contributions, backend/admin surfaces, user management, ACL roles and groups, runtime translations, frontend asset rebuild tooling, action logs, and early content primitives.

The project is moving quickly. Before `1.0.0`, APIs, migrations, extension contracts, and UI details may still change when a simpler or safer design becomes clear.

## Extension model

Extensions live under `extensions/{extension-slug}/` and are described by a `.manifest` file. Active extensions may provide templates, assets, translations, settings, providers, hooks, scheduled tasks, and other documented runtime contributions through `extension.php`.

The extension boundary matters: an extension should be easy to inspect, activate, deactivate, update, and remove. Extension-owned names should stay under the extension slug so themes, modules, providers, and future third-party integrations do not collide with core or with each other.

Feature drafts live in [dev/draft](dev/draft/README.md), developer notes start in [dev/manual](dev/manual/README.md), and active work is tracked in [dev/WORKLOG.md](dev/WORKLOG.md).

## Development

Before making changes, read:

- [Repository agent guide](AGENTS.md)
- [Feature drafts](dev/draft/README.md)
- [Developer manual](dev/manual/README.md)
- [Worklog](dev/WORKLOG.md)

Start with `bin/init` after a clean checkout. Use focused checks while developing and run broader verification before opening a pull request:

```bash
bin/lint
php bin/phpunit
```

For smaller changes, prefer the focused form first, for example `bin/lint README.md` or a targeted PHPUnit test. If a check cannot run in your environment, note that clearly in the worklog or pull request notes.
