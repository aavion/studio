# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-14  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceeding.

- [x] **0.1.x Foundation**

- [ ] **0.2.x Security and extension baseline**
  - [ ] Admin interface and setup UI

- [ ] **0.3.x Structured authoring and resolver foundation**
  - [ ] Schema-driven content fields
  - [ ] Structured editor experience
  - [ ] Draft and publish workflow
  - [ ] Diff and review tools
  - [ ] Media library and file management
  - [ ] Navigation and sitemap builder
  - [ ] Cross-reference index and resolver foundation
  - Open: content/schema storage baseline exists; first minimal field type UI, autosave/draft storage, commit vs publish separation, media MIME/upload/thumbnail defaults and exact private-delivery strategy, menu types/depth/sitemap formats, resolver-token/query syntax, depth, loop protection, ACL behavior, and export/import normalization remain open.

- [ ] **0.4.x External interfaces and operations**
  - [ ] Operational security and audit coverage
  - [ ] API layer
  - [ ] Frontend delivery and caching
  - [ ] Operational admin workflows
  - [ ] Scheduler
  - [ ] Import/export and LLM collaboration
  - [ ] Backup and restore
  - [ ] Contact, mail, logging, and statistics
  - [ ] IconCaptcha integration
  - Open: ActionLog live-operation foundation exists; finish durable audit retention, API write scope, public delivery snapshot vs cache-backed read model, backup/log/submission retention defaults, Scheduler execution implementation, IconCaptcha provider interface, broader secret-rotation policy, and asset policy details.
  - [ ] Logging and statistics
    - [ ] Decide long-term statistic-event compaction after the final reporting dimensions are known; granular anonymized events remain intentionally un-compacted for now.

- [ ] **0.5.x Release lifecycle**
  - [ ] Self-update and release workflow
  - Open: package signature/checksum strategy; direct vs staged updates; rollback scope.

- [ ] **Future**
  - [ ] Neural-like index and semantic resolver
  - [ ] First-party modules and admin add-ons
    - [ ] Referrer/promo system with reusable tokens
    - [ ] CommunityHub
    - [ ] Inline frontpage editor
    - [ ] REI3 tickets integration

## To-Do
**Usage:** Track deferred tasks and keep the list up-to-date.

- ! Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-06-06.
- ! Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- ! Prefer repository/database queries over full-table PHP filtering for lists, pagination, ACL impact checks, and other scalable read paths.
- [ ] Keep database-prefix coverage hardened by keeping Doctrine metadata self-checked against `TablePrefix::TABLES`, validating prefixed ORM metadata, and covering raw DBAL insert/update/join/delete prefix rewriting.
- ! Keep Symfony service discovery narrow so DTOs, value objects, messages, events, enums, and other non-services do not bloat the container.
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Editor/API follow-up: when the final content/editor model lands, replace provisional API content list filtering with a domain-owned actor-aware content list/read resolver covering canonical paths, language, variants, optional version selection, pagination, filtering, and sorting.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.
- [x] API branch planning: before implementation, turn `dev/draft/0.4.x-ApiLayer.md` into a concrete endpoint/resource plan covering initial read/write scope, API-key method gating, response DTOs, error envelope, pagination, filtering, sorting, audit signals, and tests.
- [ ] Audit follow-up: add a durable package lifecycle operation journal/coordinator for multi-step activation, deactivation, install, rollback, and cleanup flows.
- [ ] Audit follow-up: design copied-session plus copied-visitor-cookie risk scoring in the Security branch; current hard session binding intentionally covers visitor changes, not complete cookie-pair duplication.
- [ ] Audit follow-up: implement remember-me with Symfony-style persistent server-side tokens, visitor binding, explicit revocation, token rotation, and audit signals in the Security branch.
- [ ] Audit follow-up: replace the debug account-link mail/message-log delivery stub with the real Mailer delivery contract and a dedicated Mail Message/API catalogue.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Continue appending new session notes under the active branch so reviewers can see the full PR context in one place. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top. Record every meaningful committed or completed change, including verification and follow-ups.

### 2026-06-13 feat-symfony-ux-integration
- Added namespace-aware Twig component primitives for root, frontend, and backend alert stacks, buttons, page headers, and empty states, while keeping the existing override-friendly partial entry points as thin wrappers.
- Added a targeted UI-alert Mercure foundation with stable private user/session topics, a `UiAlert` payload object, `UiAlertPublisherInterface`, Mercure publisher, Twig stream-topic helper, and a Stimulus stream subscriber that feeds the existing alert stack.
- Extracted reusable live JSON polling into `assets/js/live/live_poll.js` and `live-poll` Stimulus controller, then switched the operation overlay to consume that shared polling layer for `/api/live/**` status flows.
- Reworked the alert stack into a sessionStorage-backed notification center with a bell badge, `auto`/`hidden`/`persistent` display modes, quiet text actions that close their alert, Mercure/client-created alert parity, extracted alert JS helpers, and live-operation runner alerts that replace the default full-screen overlay until details are requested.
- Polished the notification center presentation with a structured panel header, hide control, status icons, card spacing, bounded scroll area, and quieter market-ready alert action styling.
- Stabilized operation runner notifications so repeated poll payloads update the same alert node without flashing, added operation labels as alert titles, and gave the notification-center panel a subtle shell wrapper for clearer overlay separation.
- Fixed request-time alert closing by initializing the alert stack state before Stimulus target callbacks can register server-rendered alert nodes.
- Added a quiet notification-center "close all" action and an empty-state fallback for panels without active alerts.
- Aligned alert heading markup so runner spinners render inline with operation titles.
- Added a unified UI alert dispatcher API (`addAlert`, `addAlertToUser`, `addAlertToSession`, `addAlertToTopic`) with explicit delivery and presentation value objects so request-time alerts, queued inbox alerts, Mercure push attempts, polling fallback alerts, titles, actions, loading state, and `auto`/`hidden`/`persistent` modes share one documentable entry point.
- Added a DB-backed `ui_alert_inbox`, `/api/live/alerts` polling endpoint, 15-second Stimulus polling controller, and Messenger message boundary so alert delivery remains available when Mercure publish is not reachable.
- Added optional Mercure local-binary tooling with YAML-configured fixed version, fixed OS/architecture release asset names, storage below `var/mercure/{version}`, a `bin/mercure` wrapper, non-blocking setup `mercure:health` integration, and graceful `mercure:health`/`mercure:start` fallbacks unless explicitly required.
- Hardened Mercure local tooling with legacy hub Bolt storage at `var/mercure/updates.db`, self-healing `mercure:health` startup/probe retries, `mercure:stop` debugging support, OS-aware process termination, and a best-effort macOS quarantine release for downloaded Darwin binaries.
- Gated Mercure stream URLs and push publishing behind the stored Mercure health state, and extended health checks to require both internal publish access and public `MERCURE_PUBLIC_URL` subscribe reachability; public subscribe failures now stop the local hub instead of keeping a stale process alive.
- Derived the default `MERCURE_PUBLIC_URL` from `DEFAULT_URI` and expanded the web-server notes with copyable Apache, nginx, and IIS reverse-proxy snippets for Mercure Server-Sent Events.
- Added `mercure:check` as a read-only diagnostic command that reports binary availability, tracked process state, listen endpoint reachability, publish endpoint status, and public endpoint reachability without installing, starting, stopping, or writing health state.
- Hardened Mercure endpoint probes so Symfony `403` responses are not mistaken for a reachable Mercure hub; read-only diagnostics can still report Mercure-style missing-topic responses or authenticated `401 Unauthorized` responses as hub fingerprints.
- Tightened the Mercure public endpoint probe for UI-alert push delivery to require an anonymous `text/event-stream` subscription, and updated the local hub start command to pass `--allow-anonymous` so public HMAC-bound alert topics can be consumed without subscriber cookies.
- Made `mercure:check` show the configured publish URL separately from its status and changed the publish probe to test `MERCURE_URL` directly without masking stale configuration through a local hub fallback.
- Relaxed the local hub endpoint diagnostic to count a direct publishable local hub URL as reachable while keeping public endpoint checks tied to a real Mercure/SSE fingerprint.
- Made Mercure process detection and stop handling fall back from a missing or stale PID file to processes running the exact configured Mercure binary path.
- Normalized Mercure Bolt transport URLs for Windows drive-letter paths so the local hub storage path remains valid across supported operating systems.
- Changed `mercure:install` to fail with a non-zero exit code when installation fails, while setup keeps `mercure:health` non-blocking.
- Expanded `mercure:health` output so recovery attempts, public endpoint failures, and automatic hub shutdown are visible instead of being reported as a generic publish failure.
- Added `mercure:health` to the setup ActionQueue after database initialization so the Mercure availability config key can be seeded during setup while keeping hub failures non-blocking.
- Removed `mercure:health` from the package-aware asset rebuild queue because Mercure availability is runtime infrastructure, not an asset dependency.
- Gated `/api/live/alerts` behind setup completion so setup pages return an empty polling payload without touching Doctrine or the alert inbox before the database is ready.
- Migrated existing controller/admin responder request alerts to the unified alert interface instead of direct `addFlash()` calls.
- Routed translated UI keys through the same `addAlert()` dispatcher path via `UiAlertTranslation` instead of a separate translated-alert method.
- Added the optional native-notification profile setting gate based on Mercure publish-health state.
- Changed UI alert Mercure delivery to use HMAC-bound public topics without EventSource credentials by default, avoiding cross-origin credential/CORS friction for the optional local hub.
- Added client-side closed-alert ID storage so polling-delivered alerts do not reappear after being dismissed and the page reloads.
- Made the Mercure EventSource subscriber recover from closed streams with bounded reconnect backoff and active/online wakeups while polling remains the reliable delivery fallback.
- Simplified UI-alert delivery semantics to `Direct`, `Queue`, and low-level `Push`: direct alerts only flash into the current request, queued alerts write the inbox and best-effort push through Mercure, and push-only alerts remain reserved for volatile/debug notifications.
- Added server-side fallback IDs for queued or pushed UI alerts so Mercure live delivery and polling fallback share the same dedupe key even when producers do not provide an explicit alert ID.
- Added package-owned live endpoint registration below `/api/live/{package_slug}/...` with provider/handler registries, runtime package contributions, reserved system live slug validation, and a shared dispatch controller for future package live interactions such as captcha seed reloads.
- Updated the shared frontend live poller so an explicit `next_poll_ms: 0` response disables automatic follow-up polling for manual one-shot live endpoints.
- Added reusable root `ChartPanel` and `MapView` Twig components for future Chart.js and Symfony UX Map surfaces; `MapView` intentionally accepts coordinates/markers only and does not silently geocode addresses through public OSM/Nominatim endpoints.
- Added a reusable `filter-form` Stimulus controller for debounced GET-list filters, immediate select-driven filtering, busy submit state, pagination reset, and focus/caret restoration across GET refreshes, then applied it to the existing Admin log, statistics, user, group, and user-review filter forms.
- Added optional Symfony UX Autocomplete wiring to shared frontend/backend select partials and dynamic backend form fields on the reserved `/_autocomplete/{alias}` route so future hand-written and generated forms can enable local or AJAX autocomplete without blocking content slugs.
- Added reusable `dialog` and `clipboard` Stimulus controllers, migrated the package install modal away from inline JavaScript, and added a copy action for revealed API keys.
- Added `ChartFactory` helpers for line, bar, and doughnut charts, admin-scoped user/group autocomplete field classes, and reusable `disclosure`/`tabs` Stimulus controllers for compact future Admin panels.
- Updated the live-operation overlay controller so the triggering submit button is disabled while a background operation runs, successful operations automatically execute their OK redirect/reload action after a short grace period unless the ActionLog overlay is opened, and review, warning, or failure states remap the trigger to a color-coded details opener.
- Added a native browser-notification bridge for UI alerts that only displays OS notifications when the saved user setting is enabled and browser permission is already granted; the permission prompt is now requested only when the profile form is saved with the setting checked.
- Added a package-extendable cookie consent foundation with necessary/optional cookie definitions, central consent-aware cookie get/set helpers, response-time optional cookie filtering, DNT/GPC-aware defaults, long-lived consent-cookie storage, and a banner/overlay that stays hidden until optional cookies are registered.
- Added smooth notification-center panel open/close transitions with outside-click and Escape hide behavior while keeping active alerts available behind the bell.
- Removed the temporary analytics-style optional-cookie preview definition from the core consent provider so only packages or explicit providers can make optional consent choices appear.
- Adjusted synchronous backend action alert selection so successful workflows flash the first success-level message instead of an incidental debug message, keeping admin feedback aligned with result status.
- Repaired the demo frontend theme CSS namespace and aligned package CSS syntax validation with `bin/lint` Tailwind directive tolerance so demo packages can leave `faulty` state after a lifecycle reset; verified the demo module public routes render after activation.
- Removed locally generated `public/assets` and clarified that `asset-map:compile` is production/release-only rather than a local verification command.
- Updated `bin/lint --diff` focused CSS handling so known Tailwind directives are informational parser skips while `tailwind:build` remains the authoritative CSS validation step.
- Removed the unused Alpine and ApexCharts application wiring, dropped the stale custom Apex chart Stimulus controller, and switched the package asset rewriter fixture to a neutral external import name.
- Updated the design-system draft and class map for scoped Twig components, targeted UI alerts, notification-center behavior, reusable live polling, and the removed chart controller.
- Verification: `php -l` for new alert, Mercure, migration, controller, and wrapper files; `node --check assets/controllers/ui_alert_poll_controller.js`; `php bin/console lint:container`; `php bin/console debug:router api_live_alerts`; `php bin/phpunit tests/View/Alert/UiAlertTest.php tests/Core/Asset/AssetRebuildQueueFactoryTest.php tests/Command/AssetRebuildCommandTest.php`; `bin/lint --diff`.
- Verification: `php -l` for new alert/Twig-extension classes and `bin/lint`, `node --check` for alert/operation Stimulus controllers, `bin/lint --diff`, full `bin/lint`, `php bin/console lint:twig templates`, `php bin/console lint:container`, `php bin/console tailwind:build`, `php bin/console assets:rebuild --trigger=codex-alert-center`, `php bin/console debug:asset-map | rg "alpine|apex|live_poll|ui_alert_stream|live-poll"`, `php bin/console render:route --include-status --role=public /user/login`, `php bin/console render:route --include-status --role=admin /admin`, `php bin/console render:route --include-status --setup-completed=0 /setup`, targeted PHPUnit runs, and full `php bin/phpunit` with 1153 tests and 7908 assertions.
- Verification: `php -l src/View/Alert/UiAlertDispatcherInterface.php src/View/Alert/UiAlertDispatcher.php src/View/Alert/UiAlertMessageFactory.php src/Core/Mercure/MercureBinaryManager.php`, `php bin/console lint:container`, `php bin/phpunit tests/Controller/LiveAlertControllerTest.php tests/Core/Asset/AssetRebuildQueueFactoryTest.php tests/Command/AssetRebuildCommandTest.php tests/Scheduler/SchedulerRunnerTest.php tests/Controller/AdminSchedulerControllerTest.php tests/Controller/SchedulerControllerTest.php tests/View/Alert/UiAlertTest.php`, `bin/lint --diff`, and unsandboxed `php bin/console mercure:health`.
- Verification: `php -l src/Command/MercureStopCommand.php src/Core/Mercure/MercureRuntime.php src/Setup/SetupRuntimeCommandRunner.php`, `php bin/console list mercure --raw`, `php bin/console lint:container`, and `php bin/phpunit tests/Setup/SetupRunnerTest.php tests/Controller/LiveAlertControllerTest.php tests/Core/Asset/AssetRebuildQueueFactoryTest.php tests/Command/AssetRebuildCommandTest.php tests/Scheduler/SchedulerRunnerTest.php tests/Controller/AdminSchedulerControllerTest.php tests/Controller/SchedulerControllerTest.php tests/View/Alert/UiAlertTest.php`.
- Verification: `php -l src/Core/Mercure/MercureRuntime.php src/Core/Process/DetachedProcessStarter.php src/Core/Asset/AssetRebuildQueueFactory.php tests/Core/Mercure/MercureRuntimeTest.php`, `php bin/phpunit tests/Core/Mercure/MercureRuntimeTest.php tests/Core/Process/DetachedProcessStarterTest.php tests/Core/Asset/AssetRebuildQueueFactoryTest.php`, `php bin/console lint:container`, `bin/lint --diff`, confirmed `public/assets` is absent, and manually verified `mercure:health` plus `mercure:check` with matching publish/public URLs on `127.0.0.1:3000`.
- Verification: `php -l` for new Live/Cookie controller, registry, guard, Twig extension, and tests; `bin/lint src/Live src/Privacy src/Controller/CookieConsentController.php src/Controller/LiveEndpointController.php src/Core/Package/PackageRuntimeContributionRegistry.php src/Core/Package/PackageContributions.php src/Core/Package/PackageLiveContributionGuard.php tests/Core/Package/PackageLiveContributionGuardTest.php tests/Privacy/Cookie/CookieConsentManagerTest.php assets/controllers assets/js/live templates/base.html.twig templates/components/CookieConsent.html.twig templates/components/AlertStack.html.twig templates/frontend/partials/forms/fields/toggle.html.twig templates/frontend/user/profile.html.twig translations/languages/en/message.yaml translations/languages/de/message.yaml translations/languages/en/ui.yaml translations/languages/de/ui.yaml config/services.yaml`; `php bin/console lint:container`; `php bin/phpunit tests/Core/Package/PackageLiveContributionGuardTest.php tests/Privacy/Cookie/CookieConsentManagerTest.php tests/Core/Package/PackageApiContributionGuardTest.php`; full `bin/lint`; full `php bin/phpunit` with 1172 tests and 7977 assertions; Browser verification on `/admin` and `/user/profile` with no console errors and no cookie banner while only necessary cookies are registered.
- Added reusable cookie-consent reopening hooks through `cookie_consent_trigger_attributes()`, kept the consent overlay in the DOM for later privacy/footer links and styling checks even when only required cookies exist, and made reopened consent settings preserve stored optional-cookie selections.
- Scoped the cookie-consent redirect field to `_cookie_consent_target_path` so auth forms remain the only producers of Symfony's `_target_path`, and rejected protocol-relative consent redirect targets.
- Replaced the missing Tabler font cookie glyph with the locally imported Symfony UX Icons `tabler:cookie` SVG in the consent overlay.
- Added explicit one-shot live polling actions (`live-poll#poll`/`live-poll#refresh`) on top of `next_poll_ms: 0` manual-mode responses for package-driven interactions such as future captcha refresh flows.
- Fixed repeatable operation runner alerts so direct operation status alerts can reopen a previously closed operation alert ID without weakening closed-alert dedupe for polling-delivered inbox alerts.
- Simplified cookie-consent details to optional-cookie choices only, added a temporary Google Analytics-style optional cookie stub for UI preview, made optional-cookie choices scroll independently, and aligned the save action with the primary button style.
- Reworked the log line reader to tail large log files from the end in bounded byte chunks instead of seeking to `PHP_INT_MAX`, preventing application-log views from timing out on large `var/log/{env}.log` files.
- Verification: `php -l src/Privacy/Cookie/CookieConsentManager.php src/Privacy/Cookie/CookieConsentTwigExtension.php src/Controller/CookieConsentController.php tests/Privacy/Cookie/CookieConsentManagerTest.php`; `node --check assets/controllers/cookie_consent_controller.js assets/controllers/live_poll_controller.js assets/js/live/live_poll.js`; `bin/lint assets/controllers/cookie_consent_controller.js assets/controllers/live_poll_controller.js assets/js/live/live_poll.js templates/components/CookieConsent.html.twig src/Privacy/Cookie src/Controller/CookieConsentController.php tests/Privacy/Cookie translations/languages/en/ui.yaml translations/languages/de/ui.yaml`; `php bin/phpunit tests/Privacy/Cookie/CookieConsentManagerTest.php`; `php bin/phpunit tests/Controller/SecurityControllerTest.php --filter testLoginRouteAllowsOnlyLocalReturnTargets`; `php bin/console ux:icons:warm-cache`; Browser check on `/user/profile` confirmed the hidden consent overlay remains in the DOM with its controller, empty-state copy, and rendered UX icon while no console errors were logged.
- Verification: `node --check assets/controllers/alert_stack_controller.js assets/controllers/operation_overlay_controller.js`; focused `bin/lint` for alert/operation controllers, consent template/styles/translations, and cookie provider; `php bin/console tailwind:build`; Browser check on `/user/profile` confirmed primary consent save button styling, alert stack z-index, optional-cookie scroll wrapper, and no console errors.
- Verification: `php bin/phpunit tests/Core/Log/LogLineReaderTest.php tests/Core/Log/LogFileBrowserTest.php`; `bin/lint src/Core/Log/LogLineReader.php tests/Core/Log/LogLineReaderTest.php`; `php bin/console render:route --include-status --role=admin '/admin/logs?source=application&time_window=24h&level=&q=&match=contains&audit_action=&per_page=50&page=1'` returned `HTTP 200`.
- Verification: `node --check assets/controllers/filter_form_controller.js`; focused `bin/lint` for the filter controller, new ChartPanel/MapView components, touched Admin filter templates, and system CSS; `php bin/console lint:twig` for touched templates; `php bin/console debug:twig-component root:ChartPanel`; `php bin/console debug:twig-component root:MapView`; `php bin/console tailwind:build`; `php bin/console render:route --include-status --role=admin /admin/logs`, `/admin/statistics`, `/admin/users`, `/admin/users/groups`, and `/admin/users/reviews` returned `HTTP 200`; Browser check on `/admin/logs` confirmed the `filter-form` controller is wired through the importmap, restores focus/caret after a debounced text filter refresh, and logs no console errors.
- Verification: `node --check assets/controllers/dialog_controller.js assets/controllers/clipboard_controller.js`; focused `bin/lint` for dialog/clipboard/filter controllers, touched package/API-key/select/dynamic-form templates, UI translations, class map, and worklog; `php bin/console lint:twig` for touched templates; `php bin/console debug:router ux_entity_autocomplete` confirmed `/_autocomplete/{alias}`; `php bin/console render:route --include-status --role=admin /admin/packages` returned `HTTP 200`; `php bin/phpunit tests/Controller/UserApiKeyControllerTest.php`, `php bin/phpunit tests/View/Twig/ViewTwigExtensionTest.php`, and `php bin/phpunit tests/Controller/BackendControllerTest.php --filter 'testAdminRegisteredBackendViewRouteRendersThroughRegistry|testAdminPackageDetailAndLifecycleReviewRoutesRender'`; Browser check on `/admin/packages` confirmed the package install dialog opens through the `dialog` controller, has no inline `onclick`, and logs no console errors.
- Verification: `php -l src/View/Chart/ChartFactory.php src/Form/Autocomplete/AdminUserAutocomplete.php src/Form/Autocomplete/AdminAclGroupAutocomplete.php tests/View/Chart/ChartFactoryTest.php tests/Form/Autocomplete/AdminAutocompleteTest.php`; `node --check assets/controllers/disclosure_controller.js assets/controllers/tabs_controller.js`; focused `bin/lint` for the new chart/autocomplete/disclosure/tabs files, admin translations, class map, and worklog; `php bin/phpunit tests/Form/Autocomplete/AdminAutocompleteTest.php tests/View/Chart/ChartFactoryTest.php`; `php bin/console lint:container`; `php bin/console debug:container 'App\Form\Autocomplete\AdminUserAutocomplete' --show-hidden`; `php bin/console debug:router ux_entity_autocomplete`.
- Verification: `node --check assets/controllers/operation_overlay_controller.js`; focused `bin/lint assets/controllers/operation_overlay_controller.js assets/styles/system/base.css dev/CLASSMAP.md dev/WORKLOG.md`; `php bin/console tailwind:build`; `php bin/console render:route --include-status --role=admin /admin/packages` and `/admin/operations` returned `HTTP 200`; `php bin/console render:route --include-status --setup-completed=0 /setup/review` returned the expected setup redirect; confirmed `public/assets` is absent.
- Verification: `php -l src/Privacy/Cookie/CoreCookieConsentProvider.php tests/Privacy/Cookie/CookieConsentManagerTest.php`; `php bin/phpunit tests/Privacy/Cookie/CookieConsentManagerTest.php`; focused `bin/lint src/Privacy/Cookie/CoreCookieConsentProvider.php tests/Privacy/Cookie/CookieConsentManagerTest.php dev/WORKLOG.md`; confirmed `public/assets` is absent.
- Final verification: `bin/lint`; `php bin/phpunit` with 1180 tests and 8004 assertions; `bin/lint --diff=dev-latest..HEAD`; confirmed `public/assets` is absent.
- Follow-up: when the next feature branch starts, evaluate focused controller foundations for public consent/privacy settings, package live endpoint documentation/navigation, captcha provider/live seed flows, notification preference detail settings, and package-owned webhook/job callback endpoints before adding more broad UI surface.
- Follow-up: evaluate converting high-use backend filters from GET-refresh enhancement to Symfony UX LiveComponent slices with URL-bound writable `LiveProp`s so filter input updates can re-render only the list component while keeping shareable query parameters.
- Follow-up: revisit the full operation overlay controller after the first real UI/UX feature slice; the polling core is now shared, but renderer/storage responsibilities can still be split further when more live consumers exist.

### 2026-06-12 docs-cleanup
- Refreshed the `.codex` context inventory: marked the branding-neutral naming migration and first readiness audit as completed/historical, removed the obsolete standalone Symfony docs notes, made the framework recap the version-pinned dependency documentation cache, and updated it with current installed-dependency guidance for Symfony 8.1, Doctrine ORM/DBAL, Twig 3.27, Tailwind v4/TailwindBundle, Symfony UX, CommonMark, and PHPUnit 13.
- Extended `bin/lint` into the all-in-one diff linting entry point: it now supports `--diff`, `--diff=<target..source>`, and `--diff:<target..source>`, collects staged/unstaged or explicit Git diff files when Git is available, lints extensionless PHP scripts such as `bin/lint`, and runs a non-Markdown Git whitespace check that preserves intentional Markdown hard line breaks.
- Added Markdown parse coverage to `bin/lint` using the existing League CommonMark/GFM dependency so Markdown targets produce a real parse/render smoke-check instead of being reported as unsupported.
- Documented the Git whitespace/Markdown hard-break rule in `AGENTS.md`, updated the `.codex` tool index and class map for the new lint modes, and compacted the old 2026-06-07 API session into `dev/WORKLOG_HISTORY.md`.
- Moved the binding project rules from `.codex/PROJECT_RULES.md` into `AGENTS.md` so architecture, naming, pre-`1.0.0`, database, content-revision, security, and audit rules remain available when Codex project context changes or the `.codex` notes are not loaded.
- Removed the obsolete `.codex/PROJECT_RULES.md` duplicate, updated `.codex/ENVIRONMENT.md` for the new `/Volumes/Projekte/studio` checkout path, and refreshed `.codex/README.md` with active context, historical audit, tool, and cleanup guidance.
- Verified `.codex/resolve_cloud_artifacts.php` reports no cloud conflict artifacts; `.codex/clean_ignored_artifacts.php` dry-run still lists normal ignored generated/dependency directories such as `vendor/`, `var/`, `assets/vendor/`, `translations/runtime/`, and package build outputs, so no deletion was applied.

### 2026-06-13 docs-cleanup
- Moved route rendering from the `.codex` helper into project code with `php bin/console render:route /path`, including optional debug role, existing user, method, host, HTTPS, setup-completion, browser-auth, and API debug context support; removed the obsolete `.codex/render.php` helper and updated render-review references.
- Extended `bin/lint` with `--staged` and `--changed=<target..source>` while keeping Git-dependent target collection and whitespace checks graceful when Git or a work tree is unavailable.
- Reviewed the newly installed Symfony UX package set, kept optional UX Stimulus controllers lazy, removed generated React/Vue/Icon demo files, and tied committed Mercure defaults to `DEFAULT_URI` and `APP_SECRET` for development while documenting production override expectations.
- Updated the class map, dependency recap, local agent tooling notes, and active worklog/history to reflect the render command, lint modes, Symfony UX baseline, and branch-oriented worklog boundary.
- Changed worklog retention from per-session compaction to branch-scoped archival, restored the current `docs-cleanup` branch context from history, and mirrored the rule in `AGENTS.md`.
- Added Symfony UX icon locking to `bin/init` and the package-aware asset rebuild queue as non-blocking dependency steps, and registered active package template paths for icon/AssetMapper console scans so core and package icon references can be imported locally when Iconify is reachable without breaking offline CI or admin rebuilds.
- Added a local-only Symfony UX icon reference check to `bin/lint` so static Twig icon references fail when the required locked SVG is missing, without running the mutating network-backed `ux:icons:lock` command.
- Documented that locked SVGs in `assets/icons` should be committed as reviewable dependency snapshots while avoiding bulk-locking complete upstream icon sets by default.
- Declared `ext-sodium` as a direct Composer platform requirement and added it to the PR verification runner because the Symfony Mercure/JWT dependency chain requires `lcobucci/jwt`, which requires Sodium.
- Clarified `AGENTS.md` wording around session notes with branch/PR context and the boundary between agent-only `.codex` helpers and project-wide tooling.
- Normalized `AGENTS.md` wording so the document reads as a standalone first-version guide rather than as a patch over earlier agent habits.
- Disabled UX Translator TypeScript type dumps in production because the current AssetMapper setup uses JavaScript, not TypeScript, and recorded the UX Turbo 3.1 stream-listen deprecation in the dependency recap.
- Added cache warmup to `bin/init` and `ux:translator:warm-cache` to the package-aware asset rebuild queue so `var/translations/index.js` exists before AssetMapper resolves `assets/translator.js`.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
