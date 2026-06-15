# GeoIP observability branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-geoip-observability` implementation plan.  

## Goal

Make GeoIP useful for access logs, statistics, diagnostics, and later security review without using it for blocking decisions.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Existing `GeoIpResolverInterface` and `NullGeoIpResolver` decisions from the logging/statistics draft.
- Existing protected settings, scheduler, message, audit, access-log, and statistics foundations.
- MaxMind/GeoIP2 package already listed as the first provider choice.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for GeoIP provider behavior, update diagnostics, fallback handling, and operator-facing status ideas. Current Symfony product decisions, protected-settings rules, resolver boundaries, and redaction requirements have priority. Do not copy legacy logic or storage shape directly.

## Implementation sequence

0. Establish the provider-neutral foundation: `GeoIpProviderInterface`, `GeoIpProviderStatus`, a delegating `GeoIpResolver`, and a null provider that keeps current log/statistic placeholders as the default output.
1. Add a MaxMind-backed resolver behind the existing GeoIP resolver interface.
2. Add protected administrator-only Statistics settings for GeoIP enablement, the local database path, and the MaxMind license key.
3. Keep `NullGeoIpResolver` active whenever the provider is disabled, unconfigured, missing a local database, or unable to read data.
4. Add a scheduler-ready update task definition for GeoIP database refresh; keep it inactive by default until an administrator enables the task and stores a MaxMind license key.
5. Add safe Admin diagnostics for provider status, last update attempt, database freshness, and disabled/unconfigured state.
6. Wire access logs and statistics to consume normalized provider output only through the resolver interface and the shared client-identity resolver.

## Public interfaces and data decisions

- GeoIP output uses normalized nullable or `n/a` fields for country, region, city, latitude/longitude where available, provider status, and lookup status.
- The foundation keeps `n/a` placeholders as the stable default for access logs and statistics whenever lookup input is missing, providers are disabled/unconfigured/unavailable, or a provider throws.
- Providers expose only safe status fields: provider key, coarse status, database edition/build date, update timestamps, next suggested update, and redacted failure code. No raw paths, IP inputs, license/account data, or full exception messages belong in provider status.
- Lookup input uses the shared client-identity resolver and Symfony trusted-proxy configuration; raw forwarding headers are never parsed directly by the provider.
- Provider secrets are protected config values and never rendered outside authorized Admin settings.
- Scheduler task identifiers use stable system-owned names and do not expose provider credentials. The MaxMind database update task is a trusted callable scheduled daily by default and remains inactive until an operator activates it in Scheduler.
- Update state records last attempt, last success, database edition, database build date, next suggested update, and redacted failure code when persistent update-state storage is added. The first foundation reports equivalent context through Operations, Scheduler runs, and the Message layer.
- No public API response adds GeoIP data in this branch.
- GeoIP enablement, database path/status, and the MaxMind license key are protected/audited Statistics configuration surfaces; license material remains secret-only. Disabled, unconfigured, expired, or failed providers must fall back to `NullGeoIpResolver`.
- The first MaxMind implementation uses the installed `geoip2/geoip2` package against a configured local `.mmdb` database. Request-time lookups must not download databases or require outbound network access.
- The first production settings surface intentionally avoids a provider dropdown, Account ID field, and explicit GeoIP locale field until the product has a concrete need for them. MaxMind Reader locales are derived from `localization.default_language` with `en` as stable fallback.
- License key configuration is sensitive. Empty sensitive form submissions preserve existing stored values, API/settings read models return redacted display values, and PHPUnit coverage must use fakes or dummy strings rather than real MaxMind credentials.
- The default local database path is `var/geoip2/GeoLite2-City.mmdb`. Admin-triggered downloads and scheduler downloads must write through a temporary workspace and atomically replace the configured target where the platform supports atomic rename.
- The Statistics settings page may link operators to the official MaxMind GeoLite signup page for a free license key. A saved key reveals the database download action; missing keys make the scheduler callable fail with a translated Message-layer diagnostic so normal scheduler failure policy can disable repeatedly failing tasks.

## Edge cases

- Missing MaxMind key, unreadable database, expired database, failed download, unsupported IP, private/local IP, and lookup exceptions all degrade to normalized empty fields.
- Diagnostics must not include raw license keys, request IP lists, full provider exceptions, or filesystem paths that expose secrets.
- GeoIP failures must not block the user request that triggered logging/statistics.
- GeoIP update/download failures must leave the previous usable database in place when possible and record only redacted diagnostics.

## Tests and validation

- Unit-test resolver success, null fallback, private/invalid IP handling, and exception fallback.
- Test protected settings visibility and redaction.
- Test access-log/statistics enrichment with provider data and with disabled/missing provider.
- Test trusted-proxy/client-identity behavior for lookup input.
- Test scheduler task definition and missing-key failure message behavior.
- Test that the task remains inactive until explicitly activated by an operator and that missing credentials produce clear failure context.
- Test protected configuration redaction and null fallback for disabled, missing, invalid, and expired provider states.
- Run focused container lint when services/config are added.

## Documentation and tracking

- Update Contact/Mail/Logging notes with provider status and Admin diagnostics behavior.
- Update Scheduler notes if a task definition is added.
- Update class map for resolver, task, settings, and diagnostics entry points.
- Add worklog verification notes for redaction and fallback tests.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No geo-blocking.
- No country allow/deny lists.
- No abuse scoring based on GeoIP.

## Acceptance criteria

- Operators can see whether GeoIP is configured and fresh.
- Logs/statistics gain GeoIP fields when available and continue cleanly when unavailable.
- No secret or sensitive provider detail leaks through logs, diagnostics, tests, or exports.
