# Abuse foundation branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-abuse-foundation` implementation plan.  

## Goal

Introduce Studio-owned request classification, subject resolution, action costs, and passive suspicious-signal recording before any broad enforcement or auto-ban behavior is enabled.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- [Security policy defaults](policy-defaults.md).
- Existing visitor identity, access logging, audit logging, API-key authentication, Scheduler API authentication, and `/api/live/**` route boundaries.
- Symfony Request data and Turbo/browser prefetch headers.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for suspicious-request categories, passive-signal examples, and diagnostics language. Current subject-resolution, privacy, database-portability, and no-enforcement decisions in this branch have priority. Do not copy legacy logic or framework-specific request handling directly.

## Implementation sequence

1. Add an abuse namespace with value objects for subject, request family, request intent, action cost, and passive signal.
2. Add subject resolution for IP bucket, visitor ID, authenticated user UID, API key UID/prefix, and safe combined subject keys through one reviewed client-identity resolver.
3. Add request-intent classification for browser navigation, Turbo/browser prefetch, form submit, API read, API write, CORS preflight, exact `/cron/run` scheduler trigger, login, registration, password reset, exact setup review apply, package/admin operation, upload/archive validation, export/download, import, public form submits, and suspicious probe.
4. Add a central action-cost catalogue with website and API families. Costs are symbolic defaults, not limiter calls yet.
5. Add database-backed message, audit, and access log projections in parallel to the existing 30-day rotating file logs.
6. Move Admin/API log browsing from file scanning to the database projection, with one tab/source for message, audit, access, and security-signal events.
7. Add database-backed passive suspicious-signal recording with TTL-ready metadata, cleanup support, and redacted message/audit reporting.
8. Add explicit `/api/live/**` classification: no ordinary enforcement, but passive signal recording can happen for clear abuse patterns.

## Public interfaces and data decisions

- Controllers and future packages call the Studio-owned `AbuseRequestInspector` facade instead of Symfony RateLimiter directly. The facade combines `AbuseSubjectResolver`, `RequestIntentClassifier`, and `ActionCostCatalogue`; later branches may add enforcement around this boundary without moving classification logic into controllers.
- Existing Monolog file channels remain the raw operational fallback with 30-day retention; database log projection tables are lookup/read-model copies for Admin UI, Admin API, and later abuse correlation.
- The first projection uses one table per log family: `message_log_entry`, `audit_log_entry`, and `access_log_entry`; passive security signals use the domain event table `security_signal_event`.
- Projection writes must happen after the normal file-log payload has been normalized/redacted and must never store raw secrets, credentials, API keys, visitor-cookie material, captcha answers, unredacted tokenized URLs, or duplicated full raw log lines.
- Message projections keep a level because message logs carry meaningful severities. Security signals keep severity. Current access and audit projections omit level fields because their existing writers only emit one operational level and the value would not support useful filtering.
- Database projection retention is configurable but bounded to 1-30 days. Purge happens directly after successful writes so expired lookup rows do not persist indefinitely when the scheduler is unavailable. Read paths must also bound selected time windows by the configured retention so quiet sites or recently lowered retention settings cannot expose older projected rows until the next write.
- Setup requests may already write file logs before the database exists. Database log projections and passive-signal storage must check the existing database-ready boundary before any DBAL read/write, including retention-setting lookups; when `APP_SETUP_COMPLETED` is not truthy and no explicit unready override is active, they must no-op instead of touching Doctrine/DBAL.
- Admin Logs and `/api/v1/admin/logs/**` read through `AdminLogBrowser`: structured message, audit, access, and security-signal sources come from database projections, while the Symfony application log remains a deliberate file-backed source because it is not projected into the database. The file-backed source is limited to the existing reverse-tail window and uses stable synthetic file-line IDs for detail links.
- This branch preserves the existing Admin Logs access boundary. Fine-grained visibility/mutation restrictions for security-signal rows, IP-bearing access projections, exports, cleanup actions, and future signal review decisions are deferred to `feat-security-admin-acl-enforcement`, where Owner-only or configurable ACL gates can be applied consistently across Admin UI, Admin API, live operations, and service boundaries.
- Log browsing is split by source tabs. Each tab exposes only meaningful filters and compact columns for that log family.
- The free-text search must remain broad enough to match values not shown in the compact table, including request IDs, visitor IDs, user/API identifiers, subject identifiers, route names, paths, IP-derived fields within retention, and raw redacted context JSON.
- JSON context search must stay portable across SQLite, MariaDB/MySQL, and PostgreSQL. Database-backed log browsing casts JSON context columns to text for broad case-insensitive free-text matching instead of applying string operators directly to JSON-typed columns or materializing the full result set in PHP.
- Log browsing must use explicit bounded page sizes only. The former `all` option is intentionally treated as a 500-row page with normal pagination so large logs cannot silently exhaust memory/time or hide rows behind a misleading one-page view. The effective page must be clamped before fetching rows so UI/API metadata and row contents stay consistent after filters reduce the total.
- The log-level/severity filter is multi-select and appears only for sources where multiple levels are meaningful, such as message and security-signal events. By default, `DEBUG` and `INFO` are hidden for those sources to keep Admin review usable; callers may explicitly include them. Access and audit logs do not expose or apply a level filter.
- Security identity uses Symfony's resolved request client IP as provided by deployment/webserver configuration. This branch does not add app-level trusted-proxy settings and security signals, rate-limit subjects, bans, GeoIP, and audit decisions must not trust raw forwarding headers. Operators should configure trusted reverse proxies at the webserver/Symfony boundary, for example through `mod_remoteip` or equivalent server config.
- Visitor ID generation may use raw forwarding-header values only as untrusted differentiation entropy, for example to reduce accidental visitor merging when the same resolved IP presents different `X-Forwarded-For` chains. Those raw header values must not become Security subject keys, GeoIP inputs, ban keys, or signal evidence.
- Visitor ID remains the primary continuity key for browser traffic so different browsers behind the same untrusted proxy can still receive separate visitor subjects. This is a deliberate trade-off: spoofable forwarding entropy may change a cookie-less fallback Visitor-ID, but it must not change the stable IP-bucket HMAC derived from Symfony's resolved client IP. Later rate-limit and auto-ban enforcement must evaluate Visitor-ID and IP-bucket evidence together, with IP thresholds kept laxer than Visitor-ID thresholds to reduce false positives on shared or untrusted-network IPs while still catching clients that rotate cookies or forwarding entropy.
- Prefetch detection uses `X-Sec-Purpose: prefetch` and `Sec-Purpose: prefetch`; spoofable hints only lower confidence for classification, never bypass checks.
- Signals store only normalized subject keys, intent, reason code, count/weight, timestamps, and safe request metadata.
- Subject resolution emits visitor, IP-bucket, authenticated-user, API-key, safe API-key-prefix, and combined subjects. IP buckets and combined IP subjects are HMAC-derived and never expose raw IP addresses; invalid Bearer tokens may contribute only a validated public prefix, never submitted secret material.
- Probe-path detection is configurable through a simple editable pattern-list setting, not a raw JSON field. The UI should present one regular expression per line and may accept quoted CSV imports for convenience; unquoted newline entries must be preserved as-is so commas inside regex syntax such as `{4,6}` remain valid. Empty or invalid lists fall back to protected defaults for `.env`, VCS metadata, backup/database dumps, common foreign admin panels, upload shells, and known scanner paths.
- The normalized probe-pattern list may use a small Symfony-native cache to keep the passive subscriber out of the request-time configuration hot path. Security settings saves must invalidate that cache, and the future unified caching strategy should re-evaluate whether this feature-local cache should move into a shared namespace/invalidation model.
- Request classification is passive and deterministic. `/api/live/**`, safe browser prefetch, and anonymous CORS preflight receive no ordinary enforcement cost in this branch; credentialed API preflights are classified by their requested method, while suspicious probes, exact setup review apply, and mutating admin/API workflows receive higher symbolic costs for later limiter branches.
- Public-facing unsafe requests that are not classified as a more specific workflow, for example future contact alternatives, comments, forum posts, package-provided public forms, or other user-submitted public content actions, must fall back to the dedicated `website_form`/`FormSubmit` bucket instead of the cheap navigation bucket.
- Contact forms, captcha challenges, and package-owned public workflows must not be inferred from invented or path-only slugs such as `/contact` or `/captcha/refresh`. Public content may legitimately use those slugs; later feature branches must opt into special intents through real route names, explicit workflow metadata, or provider-owned `/api/live/**` endpoints.
- `PassiveAbuseSignalSubscriber` records only clear passive signals in this branch, starting with high-signal probe paths and unsafe prefetch attempts. It writes Visitor-ID and IP-bucket HMAC context where available, never raw IP or forwarding-header values, and does not alter the response.
- `SessionVisitorBindingSubscriber` also records the already-enforced session/visitor mismatch as a high-risk passive signal before terminating the session. This does not solve copied session plus copied visitor-cookie risk scoring by itself; that deeper scoring remains a later Security/remember-me concern.
- First implementation uses the portable `security_signal_event` table for short-lived passive signals. Suggested fields are normalized subject type/key, request family, intent, reason code, confidence, weight/count, timestamps, expiry timestamp, safe context, and optional audit reference.
- Passive-signal rows are observational only in this branch. The rate and auto-ban branches decide how to consume them for enforcement.
- Keep passive signals separate from raw file logs and from the message/audit/access projections. Later branches may consume `security_signal_event`, but this branch does not enforce from it.
- Security signals use one shared retention setting, bounded to 1-30 days. Signal rows may include an IP-bucket HMAC for review/correlation, but never raw IP addresses or raw forwarding-header values, and the entire row must stop being visible once the shared expiry is reached.
- TTL and expiry use Symfony's injectable clock/time boundary for deterministic tests.
- Security-signal list and detail reads must filter expired rows by `expires_at` as well as the selected time window, so short-retention signals stop being visible even on quiet sites where no later write has triggered purge cleanup.
- Classification must expose enough request-family, intent, subject, Admin/Owner context, `/api/live/**`, and recovery-login metadata for later branches to follow the Security policy enforcement order without re-reading controllers.
- Probe-path configuration uses anchored, normalized patterns and must be tested against normal app/package/media/editor routes to avoid false positives.
- High-impact operation intents must exist even when their first implementation only records passive signals: setup apply, settings mutation, user/ACL mutation, package lifecycle, backup/restore, import apply, export/download, self-update, scheduler run-now, diagnostics/support bundles, and upload/archive validation.
- Admin-family unsafe requests must be classified before broad public keyword rules such as `password` or `reset`, so Admin user password-reset actions and package `reset-fault` lifecycle actions are assigned to Admin/ACL/package buckets instead of public password-reset buckets.
- Classification should include the resolved Admin/Owner authority outcome for high-impact operations so rate/ban diagnostics can distinguish a denied delegated Admin action from anonymous/API abuse.
- CORS preflight classification must distinguish allowed preflights from invalid origin/method/header combinations so the API layer can stay cheap for valid browser clients while still recording suspicious probing.

## Edge cases

- Missing visitor cookie uses the existing fallback visitor identity.
- Invalid Bearer API keys should still classify as API activity without trusting the key as an authenticated subject.
- Authenticated Owner requests still classify normally; Owner lockout protection is enforced in later branches.
- High-signal probe paths are suspicious even when the route does not exist or is only a honeypot; later enforcement should return a generic `400` without revealing route existence.
- Prefetch for state-changing methods is suspicious; normal GET prefetch remains low-confidence.
- Setup/install requests happen before an Owner session exists, so classification must not depend on authenticated recovery context for pre-setup protection.
- During setup and pre-database states, file logging remains available but database projections and signal persistence must be skipped before any DBAL call.
- Upload, package, import, backup, and restore paths must not be classified as high-signal probes solely because their filenames resemble archive/database defaults; failed validation results should emit separate upload/archive signals.
- Expired passive signals must not affect later enforcement once rate/ban branches start consuming the store.
- Passive-signal storage failure must not change request outcome in this foundation branch. Safe diagnostics may be added later if they do not create logging loops or setup/database readiness problems.
- Passive signal recording must be best-effort and must not change request outcome.
- Database log projection storage failure must not break the request or the raw file log write. The UI may show fewer projected rows while file logs remain the operational fallback.
- Cleanup must remove or anonymize expired IP-derived signal keys before any Admin export, support bundle, or statistics projection can expose them.

## Tests and validation

- Test subject resolution for anonymous, visitor-cookie, authenticated user, valid API key, invalid API key, and scheduler trigger.
- Test intent classification for browser, prefetch, API read/write/preflight, `/api/live/**`, login, registration, password reset, exact setup review apply, exact `/cron/run` scheduler trigger, privileged admin operations, upload/archive validation, export/download, and suspicious probes.
- Test configurable probe-path defaults, newline and quoted-CSV pattern parsing, invalid-pattern fallback, comma-bearing regex syntax, cached config reads, and high-signal probe classification.
- Test suspicious probe rules do not collide with legitimate upload, package, import, backup, restore, media, and editor routes.
- Test probe-pattern normalization and false-positive avoidance for ordinary application routes.
- Test redaction in passive signal messages.
- Test session/visitor mismatch signal recording without changing the existing forced logout and audit behavior.
- Test database log projection writes for message, audit, and access logs without bypassing the existing file-log path.
- Test database projection and signal recorder no-op before touching DBAL while setup/database readiness is false.
- Test projection retention purge-after-write behavior and the 30-day maximum for configurable lookup retention.
- Test Admin/API log browsing reads database projections, uses UUID detail links, exposes source tabs, keeps broad free-text matching for hidden identifiers/context, shows only meaningful filters per tab, omits raw-line storage, omits access/audit level filters, and hides `DEBUG`/`INFO` by default for level-aware sources unless selected.
- Test passive-signal persistence, aggregation by normalized subject/intent/reason, expiry filtering, and cleanup command/task behavior.
- Test shared security-signal retention stays below 30 days, applies consistently to Visitor-ID and IP-bucket context, and filters expired rows from list and detail reads even when no later write has purged them yet.
- Test client-identity behavior by asserting the foundation uses Symfony's resolved request IP for security subjects and does not trust raw forwarding headers for signals, bans, GeoIP, or audit decisions; do not introduce app-managed trusted-proxy configuration in this slice.
- Test storage-failure degradation.
- Test no limiter or ban enforcement occurs in this branch.

## Documentation and tracking

- Update Security draft with facade and classification names if they become stable public extension points.
- Update class map for the facade, resolver, classifier, catalogue, and value objects when they are added.
- Update class map for the database log browser/projector, retention policy, and passive-signal recorder if they are added.
- Record default cost catalogue decisions in the worklog.
- Update Security policy defaults if implementation evidence changes signal retention, subject composition, or suspicious-intent weighting.
- Record whether the branch keeps only the passive-signal store or also introduces/reuses a broader security event projection.
- Carry a follow-up into `feat-security-admin-acl-enforcement` for Owner/ACL-controlled visibility and mutation of security signals, IP-bearing access projections, exports, cleanup operations, and future signal review actions.
- Carry a follow-up into the Editor/Content slice: when an editor sets or changes a content route/slug that would match a configured suspicious probe path, show a non-blocking warning before saving so legitimate content is not accidentally placed under a high-signal probe namespace.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No `429` responses.
- No rate limiter bucket consumption.
- No auto-ban or captcha provider logic.

## Acceptance criteria

- Later branches can enforce limits and bans through one facade.
- Passive signal output is useful for review without affecting user traffic.
