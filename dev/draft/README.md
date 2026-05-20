# Project Outline and Feature Drafts

> **Status**: Draft  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Description of planned features and technical specification drafts for use as guidance alongside implementation  

## Project Goal

aavion.studio is a Symfony 8 based content-management system for project websites that need a clean technical foundation, structured content workflows, and room for long-term extension. The project should provide a reliable core for creating, managing, rendering, exporting, and maintaining website content without locking the implementation into one fixed presentation model.

The first development phase focuses on defining a modular architecture before public release. Core functionality should stay lightweight and predictable, while plugin modules, themes, event hooks, and schema-driven content structures make it possible to add or replace behavior over time. This includes a theme engine for local template and asset overrides, extensible security and verification flows, import/export support for human and LLM collaboration, and operational tooling for setup, backup, restore, release packaging, and self-updates.

The system should favor graceful production behavior: recoverable errors should keep user input intact where possible, validation and linting flows should provide actionable feedback, and administrative tools should support safe review before content or configuration changes are applied. Developer-facing drafts, manuals, class maps, and worklogs should remain close to the implementation so contributors and coding agents can reason about the project with minimal hidden context.

## Architecture Notes

Development should stay close to the Symfony application model. Prefer native Symfony components, bundles, and extension mechanisms before introducing custom infrastructure. Controllers, services, form types, validators, voters, event subscribers, message handlers, Twig integrations, AssetMapper assets, Doctrine mappings, and Messenger buses should remain recognizable to Symfony contributors.

The core should provide stable boundaries and a small set of documented extension points instead of broad custom frameworks. Feature work should be split into reviewable vertical slices with nearby tests, documentation, class map entries, and worklog notes. Files should stay focused so future contributors and coding agents can inspect, change, and review behavior without loading a large part of the project into context.

Plugin modules should be able to integrate with the system through explicit extension categories:

- **Observe:** Modules may react to lifecycle events without changing the core result, for example audit logging, statistics, indexing, or notifications.
- **Extend:** Modules may add behavior through documented hooks or tagged services, for example editor actions, export formats, form extensions, template candidates, or navigation entries.
- **Replace:** Modules may replace selected strategies only through explicit contracts, resolvers, service decoration, or configuration, for example captcha providers, storage adapters, search adapters, export formatters, or theme resolution strategies.

Events and hooks should mark meaningful lifecycle boundaries and extension points, not ordinary internal method calls. Replaceable behavior should prefer interfaces, tagged services, priority-based resolvers, service decoration, and configuration over hidden event-side overrides. Events can still accompany replacement flows for logging, indexing, or follow-up work.

## Feature Drafts
**Note:** Use [template.md](template.md) for new feature drafts and link them here.  
Use filename format: targeted.project.version-FeatureName.md (e.g. `0.1.x-TemplateEngine.md`).

### Draft creation plan

The feature drafts should be created in dependency order. Start with architecture and recoverable workflows, then define content and extension mechanisms, then add external interfaces and operational features, and finally define update and release lifecycle behavior.

### 0.1.x foundation drafts

- [Core architecture](0.1.x-CoreArchitecture.md)
- [Error handling and validation](0.1.x-ErrorHandlingValidation.md)
- [Setup and test automation](0.1.x-SetupTestAutomation.md)
- [Static and dynamic content model](0.1.x-StaticDynamicContent.md)
- [Theme engine](0.1.x-ThemeEngine.md)

### 0.2.x extensibility and content drafts

- [Plugin modules](0.2.x-PluginModules.md)
- [Event hooks and buses](0.2.x-EventHooksBuses.md)
- [Schema-driven content fields](0.2.x-SchemaContentFields.md)
- [Editor experience](0.2.x-EditorExperience.md)
- [Cross-reference index and search](0.2.x-CrossReferenceIndexSearch.md)

### 0.3.x integration and operations drafts

- [Security and access control](0.3.x-SecurityAccessControl.md)
- [API layer](0.3.x-ApiLayer.md)
- [Import, export, and collaboration](0.3.x-ImportExportCollaboration.md)
- [Backup and restore](0.3.x-BackupRestore.md)
- [Contact, mail, and logging](0.3.x-ContactMailLogging.md)

### 0.4.x release lifecycle drafts

- [Self-update and release workflow](0.4.x-SelfUpdateReleaseWorkflow.md)

### Future drafts

- [Community hub](future-CommunityHub.md)
- [Inline frontpage editor](future-InlineFrontpageEditor.md)
- [REI3 tickets integration](future-Rei3TicketsIntegration.md)

## Expectations

Every feature implementation should start from its matching draft or update the draft first when the planned behavior changes. Drafts should describe the smallest useful implementation sequence, expected Symfony integration points, extension boundaries, tests, documentation updates, and deferred follow-ups.

Native Symfony capabilities should be used wherever they are a good fit. Custom abstractions should be introduced only when they reduce real complexity, define a required extension contract, or protect future plugin and theme work from large rewrites. Preparing for later features is expected, but speculative full implementations should be avoided.

Implementation work should remain context-friendly:

- Keep feature changes small enough to review and reason about independently.
- Prefer clear service boundaries over large coordinating classes.
- Keep user-facing strings translated and synchronized.
- Keep behavior, tests, documentation, worklog entries, and class map references aligned.
- Record deferred work in the worklog instead of leaving hidden assumptions in code.
- Preserve recoverable workflows with validation feedback, input retention, preview, diff, or rollback paths where applicable.

When a change starts to require a broad refactor, split the work into smaller drafts or implementation steps before continuing. This keeps future development possible without forcing large context-heavy rewrites.

## Draft Workflow

1. Create or update the feature draft before implementation starts.
2. Identify native Symfony features and bundles that should be used.
3. Define whether each extension point is observable, additive, or replaceable.
4. Break implementation into small vertical slices with matching tests and documentation.
5. Update the class map, worklog, and relevant manuals when behavior or callable entry points change.
6. Move completed draft references into the implemented section after code, tests, and documentation are aligned.

## Implemented

- No feature drafts have been implemented yet. This section should list completed drafts once their code, tests, documentation, and worklog entries are aligned.

## Future Plans

Future features are not part of the first implementation phase, but architecture decisions should avoid blocking them. They should remain possible without large rewrites of the content model, permission system, plugin module lifecycle, theme engine, editor experience, or API layer.

- CommunityHub with comments, forums, profiles, and moderation workflows.
- Inline frontpage editing after the theme engine, structured content, and editor workflow are stable.
- REI3 tickets integration as a plugin module after plugin and API foundations exist.
