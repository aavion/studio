# Past Review Findings
**Updated:** 2026-06-21  
**Usage:** Only historic evidence - these findings are either already resolved, intentionally deferred with follow-ups, rejected invalid, or addressed by policy or project rule changes.

## Codex Cloud Review Findings Ordered By Branch-Name And PR-Number

### first-core-session #33

P1 | Preserve failed status when continuing after errors
When stopOnFailure is set to false, any non-success result still adds issues, but the post-loop branch always returns OperationResult::requiresReview(...). That downgrades failed/blocked actions into a recoverable status, so callers can treat a queue with hard failures as review-only and continue unsafe follow-up steps. This appears when at least one action returns OperationStatus::Failed or Blocked and the queue is configured to continue.

P2 | Reject package source paths that escape project root
PackageSource accepts any non-empty relativePath, and candidateDirectories() concatenates it directly with $projectDir. If a caller passes values like ../shared (or other traversal segments), discovery scans directories outside the project tree, which breaks package boundary assumptions and can pull manifests from unintended locations. Validating/normalizing this path with the same guard used elsewhere would prevent this.

P2 | Keep base path when redirecting /index.php URLs
Use a base-path-aware target here instead of hardcoding /$1. In deployments where Symfony is hosted under a subdirectory (for example via Apache Alias), this redirect drops the mount prefix and sends requests to the web root, so canonicalization from /index.php/... can produce wrong URLs or 404s. The previous rule handled prefixed installs via a computed base path, so this change regresses those environments.

P1 | Block writes through symlinked parent directories
ensureParentDirectory() treats is_dir($parent) as success without checking whether that parent is a symlink, so copy() can follow a pre-existing symlinked directory and write outside targetRoot. This bypasses the path boundary established by PathGuard and can overwrite unintended files when a target subdirectory (or one of its ancestors) is symlinked.

P2 | Preserve app base path in IIS index.php redirects
This redirect hardcodes an absolute root target (/{R:1}), so deployments hosted under an IIS virtual directory lose their mount prefix when canonicalizing /index.php/... URLs and can be redirected to the wrong location (often 404 at site root). The rule should preserve the current application base path instead of forcing /.

### second-core-session #34

P1 | Make root slug uniqueness independent of NULL parent
The uniq_content_item_parent_slug index uses ['parent_uid', 'slug'], but parent_uid is nullable, so PostgreSQL/SQLite allow multiple rows with parent_uid = NULL and the same slug. That lets duplicate root-level routes be inserted, and lookups like findOneContentBySlugAndParentUid($slug, null) become nondeterministic, which can serve the wrong content or make routing flaky for top-level pages.

P1 | Avoid rewriting ACL group primary keys on upsert
When an ACL group already exists, this update writes the seeded uid back into the existing row instead of preserving its current primary key. If that group is already referenced by user_acl_group (or any other FK), rerunning setup can fail due to FK constraints or break associations because those foreign keys are not declared with ON UPDATE CASCADE.

P2 | Include .mjs entrypoints in registry detection
The syncer treats .mjs as JavaScript elsewhere (isStyleOrScript() and rewrite logic), but isRegistryEntrypoint() only whitelists .js names. Packages that expose app.mjs/index.mjs/module.mjs/theme.mjs are mirrored but never added to JS registries, so their scripts are silently skipped at runtime.

P1 | Call existing issue API when rendering command warnings
This callback calls OperationIssue::messageKey(), but OperationIssue exposes translationKey() instead. When any action reports an issue and the command runs in non-JSON mode, this path throws a fatal error while printing output, masking the real failure and aborting command execution.

P2 | Rewrite dynamic import() specifiers during JS mirroring
The JavaScript rewrite regex only handles static import/export ... from forms and ignores dynamic import('./x.js'). After assets are mirrored into assets/packages/..., those untouched relative specifiers still point to the old package layout and can fail at runtime for modules that lazy-load chunks via dynamic import.

P2 | Fail setup when writing environment overrides fails
The setup writer ignores the return value of file_put_contents(), so permission or disk errors can leave .env.<env>.local unchanged while setup reports success. That creates a silent misconfiguration where subsequent runs use stale secrets/DB URLs even though the setup flow appeared to complete.

P1 | Use valid issue accessor in package sync CLI warnings
Like the rebuild command, this warning formatter calls OperationIssue::messageKey() even though the issue object provides translationKey(). Any non-JSON sync run that encounters an issue will crash while emitting warnings, which hides the underlying sync failure and breaks operator workflows.

P2 | Reject unsupported sqlite URL variants early in setup
The explicit URL validator accepts any URL whose scheme is sqlite, but the connection factory only supports the sqlite:///... form. Inputs like sqlite:/tmp/app.db pass validation and then fail later as an unsupported scheme during connection setup, producing a late/confusing failure path for users.

### feat-package-and-design-system #38

P1 | Reject unusable accounts during form login
With the new entity provider/form_login setup, Symfony authenticates any UserAccount with a valid password, but there is no user_checker (checked config/packages/security.yaml and src/Security) that consults UserAccountStatus::isUsable(). Inactive or deleted accounts that still have a password and ACL groups can therefore sign in and reach /admin//editor like active users; add a user checker or equivalent guard to reject non-active accounts during authentication.

P2 | Load persisted protected menu items for authorized actors
For database-backed site_menu_item rows with view_min_level > 0 or non-empty view_group_identifiers, this query filters the rows out before filterByAccess() sees the current actor, so editors/admins never get their persisted restricted menu entries. Event-added items still work because their access metadata is added after this query, but the ACL columns on persisted menu items are effectively unusable unless the rows are loaded and mapped into item metadata for the access filter.

P2 | Validate package translations against the manifest slug
When an uploaded ZIP has a top-level directory whose name differs from PACKAGE_SLUG (for example the common demo-module-1.2.0/ wrapper), this uses that temporary directory name as the expected pkg.* namespace. The installer later copies the package to packages/<PACKAGE_SLUG>, so a valid languages/en/demo_module.yaml rooted at pkg.demo-module is incorrectly rejected during ZIP verification; derive the expected namespace from the manifest slug instead of basename($candidate->directory()).

P2 | Add a new migration for package settings
If any environment already ran Version20260523210000 before this change, Doctrine records it as executed and will not revisit this edited migration, so the newly used package_setting_entry table is never created there. The new PackageSettings code then catches SQL failures and package settings silently fail to persist; add a follow-up migration instead of modifying the already-existing one.

P2 | Preserve the old package until replacement succeeds
When overwriting an existing package, this removes and flushes the current package before the new files are copied, rediscovered, or reactivated. If the later copy/discovery/activation step fails because of permissions, disk space, or an unexpected validation/runtime error, apply() returns a failure with the previous package already deleted and marked removed, taking a working active package offline; stage/backup and swap only after the replacement path has succeeded, or roll back on failure.

P2 | Deactivate dependents before deleting an active package
When deleting an active package that has active dependents, this path only deactivates the selected package before removing its files. PackageActivator::deactivate() computes a deactivation cascade for dependents, but the delete flow bypasses that, leaving dependent packages marked active even though their required package has been removed; those dependents will still be loaded by ActivePackageProvider and can break asset/template/runtime hooks until manually repaired.

P2 | Set installed version when syncing installed packages
For an existing package row with installed_version already populated, installing a newer ZIP updates manifestVersion here but never updates installedVersion; PackageZipInstaller::installedPackageVersion() and PackageDependencyResolver both prefer installedVersion, so later version gates and dependency checks keep seeing the old version. In practice, after upgrading such a package from 1.0.0 to 1.1.0, activating a package that requires >=1.1.0 can still be blocked, and downgrade checks can compare against the stale 1.0.0 value.

P2 | Check denied content before static route fallback
When a real content item exists at this path but PublishedContentResolver returns Denied or NotPublic, view() is null, so this static-injection fallback runs before the unauthorized/forbidden checks below. A public package/static injection using the same path as restricted content will therefore render for an actor who should have received 401/403; only use static injections after confirming the content result is actually not found or context-unavailable.

P1 | Stop processing setup posts after setup is locked
When setup is already complete, BackendRouteResolver returns the locked 404 result, but this block still calls setupVariables() for /setup; on a POST with a still-valid/stale setup-web CSRF token, setupVariables() runs SetupRunner again. That can rewrite environment values, rerun migrations, and reseed the admin/database after installation despite the setup lock, so the setup form handling needs to be skipped once the resolver has locked the route.

P2 | Rebuild assets when active packages are updated
If an already-active package is rediscovered with changed manifest metadata/version, this branch records it as updated but never adds an asset rebuild trigger. That leaves generated package asset registries, runtime translations, Tailwind sources, and cache state stale for active packages updated in place until some unrelated lifecycle action rebuilds assets; trigger the same rebuild path when changed is true and the package remains active.

P2 | Pass environment when creating reset reporter
When bin/setup --reset-password reaches this branch with a non-empty database URL, this call invokes workflowResultMessageReporter() with one argument even though the helper now requires both $projectDir and $environment (line 168). That raises an ArgumentCountError before SetupPasswordResetRunner can look up or reset the user, so the documented CLI password-reset flow is broken; pass the selected/app environment here as the normal setup path does.

P2 | Validate macro paths against the manifest slug
When an uploaded ZIP is wrapped in a directory whose basename differs from PACKAGE_SLUG, valid package-owned macros under templates/macros/<PACKAGE_SLUG>/... are still checked against the wrapper basename in packageSlug(). Because this diagnostic was upgraded to an error here, package verification now rejects otherwise valid ZIPs such as demo-module-1.2.0/ containing templates/macros/demo-module/widget.html.twig; derive the macro namespace from the manifest slug before making this blocking.

P2 | Restrict purge to already removed packages
For active, inactive, or faulty packages this always exposes purge, but PackageRemover::purge() only runs the cleanup runner and deletes the registry row; it does not deactivate dependents, remove the package files, or rebuild package assets. An admin who purges an active package can therefore leave active package assets/templates/runtime state stale while the package has disappeared from the registry; only offer purge for already-removed packages, or route non-removed packages through the normal remove/deactivation flow first.

P2 | Reject dependency strings that cannot be parsed
If a package supplies malformed PACKAGE_DEPENDENCIES, such as ["demo-base >=1.0"] or a typo in the documented pair syntax, this preg_match_all() simply returns no matches and the resolver treats the package as having no dependencies. That lets activation and ZIP replacement preflight pass even though the manifest declared a required package/version; fail validation or add a blocked dependency issue when a non-empty dependency field contains unparseable entries.

P2 | Avoid registering partial contributions on loader failure
When package.php returns an iterable and one later item is unsupported, this loop has already added earlier injections/settings to the shared registry before throwing. The loader catches the exception and marks the package faulty, but those earlier contributions remain available for the rest of the current request, so a package that just failed runtime loading can still add routes/templates/settings until the next request; validate the iterable before mutating the registry or roll back contributions for the package on failure.

P2 | Reject unsafe package metadata URLs
Package metadata comes from discovered/uploaded .manifest files, and these PACKAGE_HOMEPAGE/PACKAGE_SOURCE values are not scheme-validated before being copied into the admin detail model. Rendering them directly in href lets a package manifest set something like javascript:..., creating an executable link in the admin package detail page if an admin clicks it. Please restrict these links to safe schemes such as https?:// or render untrusted values as plain text.

P2 | Reject unsafe menu item URLs
When a persisted or hook-added navigation item uses target_type = 'url', this returns the raw target_value, which the frontend/backend navigation templates render directly as an href. Twig escaping does not block schemes like javascript:, so a menu entry imported from data or supplied by a package hook can create an executable navigation link for users who click it; validate URL targets to relative paths or safe external schemes before exposing them.

P2 | Enforce a minimum setup admin password length
This setup validation only rejects empty passwords, so the first admin account can be created with a one-character password through the web installer; the CLI path also defaults the admin password to admin when run non-interactively. Since the normal password-change flow requires at least 12 characters, setup can leave a production instance with credentials that users cannot later choose through the regular UI; add the same minimum-length validation before seeding the admin user.

P2 | Deactivate dependents when a runtime package faults
When an active package's package.php throws, this marks only that package faulty; active packages that depend on it are left active because this path does not use the dependency cascade that registry removal/fault handling uses. On the same or next request those dependents can still be loaded by ActivePackageProvider even though their required package is now faulty and its runtime contributions are unavailable, so runtime fault handling should deactivate active dependents or run the same cascade before rebuilding assets.

P2 | Move setup lock after fallible final steps
When the final cache:clear step fails, for example because cache warmup hits a permission or configuration error, the previous line has already written the setup-complete marker. BackendRouteResolver then locks /setup on subsequent requests, so the user sees a failed setup result but cannot retry the web setup flow to recover; either make cache clearing non-blocking/rollback the marker on failure, or only mark setup complete after all blocking steps have succeeded.

P2 | Reject symlink entries in uploaded package ZIPs
When an uploaded ZIP contains symlink entries, for example one created with zip --symlinks, this validation only checks the entry name and then extractTo() recreates the symlink in the staging tree. During apply, copyDirectory() treats file symlinks as files and copy() follows them, so an active package replacement can smuggle readable host files into packages/<slug> and then publish them through the package asset mirror if the symlink is under assets/; reject symlink entry attributes before extraction or fail validation after extraction.

P2 | Clear stale live-operation status URLs
If a stored live operation is later removed by cleanup before the user revisits this form, polling returns 404 here but the sessionStorage entry is left in place and only Refresh/Close are shown. Submitting the same live-enabled form then keeps retrying the dead status URL instead of starting a new operation, so that admin action remains stuck until sessionStorage is manually cleared; clear the stored operation or expose the cancel/retry path on not-found/status errors.

P2 | Guard package-provided runtime providers
A package.php loader can return a StaticViewInjectionProviderInterface, which is accepted during package loading, but its methods are invoked later without any error boundary or package attribution. If that package provider throws while building navigation or resolving static views, the exception escapes the request instead of being converted into a package runtime fault, so one active package can take down public/admin rendering; wrap these provider calls the same way loader/hook failures are handled.

P2 | Propagate failed asset rebuild dispatches
When an active package is marked faulty, removed, or updated, this call can return a failed WorkflowResult if the Messenger transport cannot enqueue the rebuild, but the result is discarded after the registry changes are flushed. In that scenario package discovery reports a successful sync while the generated package asset registry, translations, and Tailwind sources remain stale; capture the dispatch result and surface the failure or retry information to the caller.

P2 | Restore statuses even when rollback discovery fails
If replacement rollback gets this far after deactivating the old package and its dependents, a rollback discovery failure returns before restorePackageStatuses() runs. In that failure path the backup files have been moved back, but the registry rows can remain inactive/faulty from the attempted install, leaving the previously working package stack disabled until manual repair; restore the saved statuses even when rollback discovery reports issues.

P2 | Expose continuation for review operations
For package-install verification runs that finish with requires_review, LiveOperationRunStore::report() exposes report.result.can_continue, but this detail page only renders the result messages and never provides a continue action. The non-JS fallback in BackendController::packageInstall() redirects to /admin/operations after starting verification, and users who close or reload the overlay can also lose the sessionStorage-only Continue button, leaving staged installs impossible to apply from the admin UI.

### feat-log-foundation #39

P2 | Use the trusted client IP for visitor IDs
When the app is reachable without an edge proxy that strips these headers, clients can send X-Forwarded-For/Forwarded themselves and this method will prefer that user-controlled value over Symfony's trusted getClientIp(). Because generate() and the GeoIP calls use sourceIp(), a visitor can spoof arbitrary visitor IDs and locations, corrupting the access statistics and unique-visitor counts; prefer getClientIp() for the canonical source and keep the raw proxy chain only as diagnostic log context.

P2 | Aggregate statistics before applying row limits
For sites with more than 10,000 recorded events in the selected window, this query fetches only the newest MAX_ROWS rows and then computes total_requests, unique visitors, status families, top routes, and averages from that truncated sample. The Admin Statistics view will therefore undercount traffic and skew every aggregate on busy installations; the limit needs to be removed or applied only after SQL/database-side aggregation.

P2 | Avoid logging raw query strings
When requests carry secrets in the URL, such as reset tokens, OAuth code values, or signed links, this stores the complete raw query string in the 30-day access log and exposes it through the Admin Logs browser. The message/audit loggers redact sensitive keys, but this access-log field bypasses that protection; redact or omit known-sensitive query parameters before writing the log entry.

P2 | Keep access logging failures off the response path
If the log directory becomes unwritable/full or the access logger throws for any other reason, this response subscriber lets the exception escape after the controller has already produced a response, so otherwise successful public/admin requests can be turned into 500s and statistics recording is skipped. Since access/statistics logging is operational telemetry, wrap these calls the same way the recorder/audit paths do and report failures without breaking the request.

P2 | Preserve empty audit category selections
When an admin clears every checkbox in the new Audit event categories multiselect, the browser submits no security.audit.events field, and the form layer stores null; this fallback then treats that saved null as DEFAULT_CATEGORIES, so the attempted “log no audit categories” configuration silently turns all audit categories back on. Handle a saved null from this form as an empty list or ensure the form posts [] for an empty multiselect.

P1 | Keep the existing migration version stable
Renaming the already-existing initial migration means any database that has recorded DoctrineMigrations\Version20260523210000 will see DoctrineMigrations\Version20260527120000 as a brand-new pending migration, and this up() method starts by creating tables such as messenger_messages and config_entry that already exist. That blocks upgrades for those installs instead of only adding access_statistic_event; keep the old migration class/version and add a separate additive migration for the new table.

P2 | Stop recording when statistics are disabled
With the shipped settings UI only statistics.enabled is exposed, so when an admin turns off “Enable access statistics” this branch still allows DatabaseAccessStatisticsRecorder to insert every request because recording_follows_display is unset and defaults to false. The dashboard then says statistics recording/display is disabled while new rows continue accumulating; make statistics.enabled gate recording too, or expose/seed the separate recording toggle so the behavior is explicit.

P2 | Redact tokenized path segments in access telemetry
When a secret is part of the path rather than the query string, for example the existing /user/invitation/{token} flow, these fields persist the raw token into the rotating access log and expose it in the Admin Logs detail view; the query-string redaction added below does not cover this case. Please sanitize known token-bearing routes or route parameters before writing path/requested_path (and apply the same normalization to the statistics recorder, which stores the same path values).

P2 | Redact sensitive referrer paths before logging
When a request includes a Referer whose path contains a secret, such as the existing /user/invitation/{token} route in src/Controller/UserController.php, this logs referrer() into the access context and exposes it in the Admin Log detail view. AccessRequestMetadata::referrer() strips the query string but keeps path segments verbatim, so clicking away from an invitation URL can persist the token in the rotating access logs; apply the same sensitive-path redaction used for the current request or omit the referrer path.

P2 | Keep message logging failures non-fatal
When the rotating message log cannot be opened, for example because var/log is unwritable or the disk is full, this logger->log() exception now bubbles through MessageReporter::report() because neither layer catches it. That makes unrelated recovery paths such as config reads, package validation, setup/reporting, and operation summaries fail while trying to report an issue; the old file logger swallowed append failures, so this wrapper should catch Monolog write errors and return without breaking the caller.

P2 | Gate error references to actual error contexts
When this partial is included outside an error response, such as the normal /user/login page in templates/frontend/user/login.html.twig, studio_request_trace() still returns IDs and this new block renders the error reference panel on a regular login screen. Keep the request reference behind an http_error check or move it to only the error-page templates so normal auth pages are not labeled with error diagnostics.

### feat-user-management #40

[incomplete]

### improve-asset-rebuild #90

P2 | Create an empty staging directory before replacement
When there are no translation sources, the loop never creates translations/runtime/{env}.tmp-*, but this call still tries to swap it into place. In that scenario aggregate([]) now fails after attempting to rename a non-existent staging directory (and preserves any stale runtime catalogues), whereas the previous implementation succeeded with zero targets and removed generated catalogues; create the staging directory before the loop or handle the empty catalogue case explicitly.

P2 | Preserve runtime metadata when swapping catalogues
When translations/runtime/{env} contains any non-catalogue file, this directory swap followed by deleting the backup removes it because the staging directory only contains messages.{locale}.yaml. The previous implementation only removed messages.*.yaml, and TranslationRuntimePath even exposes .manifest.json under the same directory, so a successful aggregate can now silently discard runtime metadata or other files not owned by catalogue generation.

P2 | Commit the mirror after registries are written
If a registry write fails after this point, for example because one of the generated registry paths is a directory/symlink or otherwise cannot be replaced, sync() returns a failed result but assets/packages has already been swapped to the new mirror. That leaves the previous registries paired with the new package mirror, so the failed rebuild no longer preserves a consistent generated state; stage or validate/write the registries before committing the mirror, or roll the mirror back on registry failure.

### feat-setup-wizard #91

P1 | Preserve pre-existing env files during rollback
When setup is rerun or started in an environment that already has .env.<env>.local, SetupEnvironmentWriter merges new values into that file, but any later setup failure calls this rollback path and unconditionally unlinks the whole file. In that scenario a failed migration/cache/package step deletes unrelated existing configuration and secrets instead of restoring only the installer-written keys, leaving the installation harder to recover.

P2 | Normalize CLI database prefixes before storing them
When a CLI install uses --db-prefix=studio (the same user-facing form the web wizard accepts), this stores studio unchanged, but TablePrefix::fromEnvironment() only applies prefixes that already end in _. The setup then succeeds with APP_DATABASE_PREFIX='studio' while migrations/seeding run unprefixed, so CLI prefixing is silently ignored unless the user knows to pass a trailing underscore.

P1 | Avoid persisting setup secrets in live-operation payloads
Starting setup through the web wizard passes the full normalized form into the live-operation payload, including admin_password, database_password, and any custom app_secret. LiveOperationRunStore::create() persists that payload as JSON under var/operations/{env} until cleanup, so a normal setup apply leaves the first owner password and infrastructure secrets in plaintext on disk; pass only a redacted/indirect payload or otherwise avoid storing the raw secrets.

P2 | Keep review continuations resumable after reload
For live operations that finish as requires_review (for example package install or ACL apply flows), finish() intentionally leaves the stored operation with its continueUrl, but a page reload immediately calls this terminal check and clears any stored requires_review run. If the user refreshes or navigates back before pressing Continue, the overlay loses the continuation URL and the staged review flow cannot be resumed from session storage.

P1 | Keep package registry stubs committed for asset builds
With a fresh checkout, this ignore rule removes the generated package registry files from version control, but assets/styles/app.css and assets/app.js still import ./packages/extension, frontend-theme, and backend-theme. bin/init recreates them, but a plain composer install runs the Symfony/Tailwind auto-scripts before any package sync, so the documented install path fails on missing imports unless those empty registry stubs are present or created before Composer scripts run.

P2 | Make schema table checks prefix-aware
When APP_DATABASE_PREFIX is set (the web setup defaults to studio_), this wrapper causes the migrated messenger table to exist as studio_messenger_messages, but the package discovery/rebuild dispatchers still check createSchemaManager()->listTableNames() for the literal messenger_messages (src/Core/Package/PackageDiscoveryDispatcher.php:98 and the same check in PackageAssetRebuildDispatcher). Schema-manager results are physical table names, so those dispatchers report messenger_storage_unavailable and refuse to queue package discovery/asset rebuild even though the prefixed messenger table is present; the readiness check needs to compare against TablePrefix::apply('messenger_messages') or otherwise account for prefixes.

P1 | Avoid storing setup secrets in session state
When the wizard advances past the database/admin steps, $submitted still contains database_password, admin_password, admin_password_confirm, and any custom app_secret, and this assignment keeps those values in _studio_setup_wizard; saveState() then writes them to the Symfony session and there is no cleanup after apply. With the default file-backed sessions, a normal setup leaves the first owner password and infrastructure secrets in plaintext session files under var/ until session GC, so the wizard should avoid persisting raw secret fields or explicitly clear them once the live operation has been started.

P2 | Keep applied migrations resumable
Renaming the already-existing initial migration class to Version20260531000000 while also deleting Version20260530230000 breaks any database that has run the previous migrations: Doctrine will see Version20260531000000 as a new pending migration and try to create tables such as user_account again, instead of applying only the role backfill that was in the deleted migration. Keep the original migration version/class and add a forward migration for the schema changes so existing installs can run doctrine:migrations:migrate without failing on existing tables.

P2 | Preserve non-JavaScript setup apply flow
On the review step this always returns the live-operation JSON response, even for a normal form POST where _operation_live is absent and the request is not XHR. In browsers with JavaScript disabled or if the Stimulus controller fails to load, clicking Apply leaves the operator on a raw 202 JSON payload instead of a setup result/progress page, so the controller should only use the JSON live-operation responder for live requests and provide an HTML fallback otherwise.

P1 | Leave migration metadata unprefixed
When APP_DATABASE_PREFIX is set (the web wizard defaults to studio_), adding doctrine_migration_versions to the SQL-rewrite list makes the migrate command create and write studio_doctrine_migration_versions, but Doctrine Migrations still checks its configured metadata table name through schema introspection as doctrine_migration_versions. After the initial setup migration, the next doctrine:migrations:migrate cannot see the existing prefixed metadata table and tries to create it again, blocking future schema updates on default installs.

P1 | Snapshot tables before rollback drops them
If setup is retried against a database that already contains Studio tables (or any tables with the selected prefix) and a later step such as package discovery/cache clearing fails, this rollback path drops every known table unconditionally. Because setup only tests SELECT 1 and does not record which tables were created by this run, a failed retry can delete pre-existing application data instead of only undoing the installer's own changes.

P2 | Honor db-prefix during password resets
Adding --db-prefix to the shared setup CLI options makes it look usable for --reset-password, but handlePasswordReset() only reads --database-url and never applies the prefix before SetupPasswordResetRunner opens its prefixed connection. In a recovery flow such as bin/setup --reset-password=admin --database-url=sqlite:///... --db-prefix=studio, the command queries user_account instead of studio_user_account and reports the existing user as missing (or updates the wrong unprefixed table).

P2 | Store a queued status for continuations
When a user clicks Continue, continueOperation() stores the new statusUrl with storeOperation(payload.value.status_url, 0) and no status before polling starts. If the page reloads or the tab crashes in that gap, this branch treats the stored operation as terminal and clears it, so the already-started continuation can no longer be resumed from the overlay. Store the continuation the same way as a fresh start, e.g. with an initial queued status.

P2 | Avoid opening SQLite during dry-run preparation
For CLI --dry-run installs that use the default SQLite URL and do not already have var/data_<env>.db, this rollback snapshot opens the SQLite connection before the dry-run plan is returned. PDO SQLite creates the database file on connect/listing tables, so a dry run still mutates var/ even though the dry-run steps promise not to write files or touch the data

P2 | Disable server-only required fields for SQLite without JS
When Stimulus is unavailable or JavaScript is disabled, the setup database step renders the server connection fields as normal required controls even while the default SQLite driver is selected; database_port is empty by default, so browser validation blocks submitting the SQLite form before Symfony can apply its driver-aware validation. Render these fields initially hidden/disabled for SQLite or avoid required unless the selected driver is a server database.

P2 | Keep long-running operations resumable
This ten-minute client-side expiry clears any stored operation even if it is still running or still retained by LiveOperationRunStore for its one-hour stale/cleanup window. In slow setup/package flows, closing the tab and returning after more than ten minutes loses the only statusUrl the overlay has, so the operator cannot resume or continue the still-running operation from the setup page; align the client expiry with the server retention/stale window or verify the status endpoint before discarding active stored runs.

P2 | Prefix generated index names for shared schemas
When table prefixes are used for multiple Studio installs in the same PostgreSQL or SQLite schema, the SQL rewriter prefixes table references but leaves fixed index/unique-index names such as uniq_user_account_username unchanged. The second prefixed install still attempts to create the same schema-level index names on different physical tables and fails during migrations, so the prefix support is not safe for the shared-schema use case unless index names are made prefix-specific as well.

P2 | Honor --env when loading reset prefixes
When bin/setup --env=prod --reset-password=... is used without an explicit --db-prefix, this fallback reads APP_DATABASE_PREFIX from the environment that was already booted before option parsing, not from the target environment selected by --env. In the common case where the production prefix lives in .env.prod.local (or differs from dev), a reset against a supplied production --database-url still queries the unprefixed/dev-prefixed user_account table and reports the user missing or updates the wrong schema; load the target env before deriving the fallback prefix or require/pass the prefix consistently for the selected env.

P2 | Allow clearing the database password
On the database step, if a password was previously stored in the wizard state, submitting the field as blank unsets the submitted value and keeps the old password. This makes it impossible to correct a MySQL/PostgreSQL configuration from a non-empty password back to an empty password (for example after a failed connection test), because the subsequent test/apply still builds DATABASE_URL with the stale secret; reserve the “blank means keep” behavior for the admin password fields or only when the DB password control was omitted.

P2 | Let --db-prefix= override inherited prefixes
For CLI setup runs that inherit APP_DATABASE_PREFIX from the shell or loaded env, passing --db-prefix= should be the only way to request an unprefixed install, but this call goes through option(), which treats an explicitly empty string as missing and falls back to the inherited prefix. In that scenario a supposedly unprefixed install still writes and migrates with the old prefix; handle db-prefix with array_key_exists() before applying the environment default.

P2 | Keep dry-run rollback snapshots type-safe
When a dry-run step throws after preparation (for example an unavailable --language makes select_language throw), this stores null as the table snapshot, but the failure path calls SetupRunner::rollback() with that value and its parameter is typed as SetupDatabaseTableSnapshot. The resulting TypeError escapes instead of returning the intended setup failure/rollback result, so dry-run error handling crashes before SetupRollbacker can use its own dry-run skip logic.

P2 | Require SQLite URLs for the SQLite driver
When the wizard is on the SQLite driver, accepting mysql:///postgresql:// here leaves database_driver as sqlite but still passes the server URL through SetupWebInputFactory::create(); DatabaseUrlFactory then prioritizes that explicit URL and the setup runs against the server database instead of the selected SQLite database. This is especially easy to hit when an inherited DATABASE_URL points at MySQL while the UI defaults back to SQLite because the MySQL extension is unavailable, so validate the URL scheme against the selected driver or clear incompatible inherited URLs.

P2 | Reject CLI database URL/driver mismatches
When a non-interactive CLI install is given both --db-driver and an explicit --database-url, this early return keeps the URL without verifying it matches the selected driver. DatabaseUrlFactory later prioritizes databaseUrl over databaseDriver, so a command such as bin/setup --db-driver=mysql --database-url=sqlite:///tmp/app.db ... writes and migrates SQLite while the setup context reports MySQL; the CLI path should either derive the driver from the URL or reject mismatched combinations as the web path does.

P2 | Use prefixed names when reverting prefixed migrations
On installs using APP_DATABASE_PREFIX (the web setup default stores studio_), the up() SQL is rewritten to create physical tables and FK names such as studio_content_item and studio_fk_content_item_active_revision, but down() looks up the unprefixed table and constraint names. Running a down migration on a prefixed install therefore fails before dropping anything because the schema contains only the prefixed objects; apply the same prefixing to the getTable()/FK names used during rollback.

### feat-scheduler #95

[incomplete]

### feat-php-cli-resolver #96

P2 | Fall back when stored locale is unsupported
If an account has a stale or crafted settings['language'] value such as fr, userLocale() wins the ?? chain and this check returns before trying the session locale or configured default. That leaves all subsequent requests for that user on Symfony's previous/default locale instead of the site's configured fallback; validate the user locale before selecting it, or continue to the next source when it is not in availableLanguages().

P2 | Keep dry-run planning independent of PHP CLI resolution
In dry-run mode steps() still calls migrationCommand() before handing off to SetupDryRunPlanner, and this changed line now resolves and validates a real PHP CLI binary. On hosts where the resolver fails (the exact scenario the dry-run planner otherwise represents with a php-cli-unavailable:* placeholder), a dry run throws during plan construction instead of returning the skipped action log, so the UI cannot show the setup plan or the actionable CLI-unavailable context. Build the dry-run migration command with the same non-throwing placeholder path used by the planner.

P2 | Map PHP CLI failure reasons before translating
When CLI resolution fails because validation rejects the binary (for example php_version_too_old, extension_missing, console_unreadable, or process_failed), this raw reason is used directly as a setup.preflight.values.* key, but the setup catalog only defines a few resolver reasons such as binary_not_found and process_disabled. In those environments the preflight row renders an untranslated/missing key instead of useful guidance; normalize unknown validation reasons to existing value keys or add translations for every reason the manager can return.

P2 | Preserve failures from Tailwind builds
When tailwind:build exits non-zero for a real build problem, such as invalid CSS, a bad import, or a broken Tailwind config, this path converts the failed RunCommandAction into WorkflowResult::success(). The operation executor therefore marks the action and the whole studio:assets:rebuild command successful, so production can continue with stale or missing CSS instead of blocking on the asset error; only the known web-hosting/native-binary-unavailable case should be downgraded, while normal Tailwind command failures should remain failures.

P2 | Honor URL locale before stored preferences
When localized content route prefixes are enabled, a request such as /de/... is resolved by ContentRouteLocalization::resolve() as German content, but this subscriber has already forced the request and translator locale from the logged-in user or session before considering the URL language. That leaves page chrome and Twig translations in the stored/default locale while the content body is rendered in the prefixed language; include the route/path locale as the first candidate or let public content routes override the locale after resolution.

### feat-api #98

[incomplete]

### feat-symfony-ux-integration #100

[incomplete]

### feat-security-geoip-observability #104 

[incomplete]

### feat-security-abuse-foundation #105

[incomplete]

### feat-security-admin-acl-enforcement #106

[incomplete]

### feat-security-rate-enforcement #107

[incomplete]

### feat-security-auto-ban #108

[incomplete]

### feat-security-captcha-contract #110

[incomplete]

