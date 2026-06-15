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

## Implementation sequence

1. Add a MaxMind-backed resolver behind the existing GeoIP resolver interface.
2. Add protected administrator-only settings for provider selection, database path/status, account/license key, and update policy.
3. Keep `NullGeoIpResolver` active whenever the provider is disabled, unconfigured, missing a local database, or unable to read data.
4. Add a scheduler-ready update task definition for GeoIP database refresh; default inactive unless an existing scheduler policy already enables safe maintenance tasks.
5. Add safe Admin diagnostics for provider status, last update attempt, database freshness, and disabled/unconfigured state.
6. Wire access logs and statistics to consume normalized provider output only through the resolver interface.

## Public interfaces and data decisions

- GeoIP output uses normalized nullable or `n/a` fields for country, region, city, latitude/longitude where available, provider status, and lookup status.
- Provider secrets are protected config values and never rendered outside authorized Admin settings.
- Scheduler task identifiers use stable system-owned names and do not expose provider credentials.
- No public API response adds GeoIP data in this branch.

## Edge cases

- Missing MaxMind key, unreadable database, expired database, failed download, unsupported IP, private/local IP, and lookup exceptions all degrade to normalized empty fields.
- Diagnostics must not include raw license keys, request IP lists, full provider exceptions, or filesystem paths that expose secrets.
- GeoIP failures must not block the user request that triggered logging/statistics.

## Tests and validation

- Unit-test resolver success, null fallback, private/invalid IP handling, and exception fallback.
- Test protected settings visibility and redaction.
- Test access-log/statistics enrichment with provider data and with disabled/missing provider.
- Test scheduler task no-op and failure message behavior.
- Run focused container lint when services/config are added.

## Documentation and tracking

- Update Contact/Mail/Logging notes with provider status and Admin diagnostics behavior.
- Update Scheduler notes if a task definition is added.
- Update class map for resolver, task, settings, and diagnostics entry points.
- Add worklog verification notes for redaction and fallback tests.

## Non-goals

- No geo-blocking.
- No country allow/deny lists.
- No abuse scoring based on GeoIP.

## Acceptance criteria

- Operators can see whether GeoIP is configured and fresh.
- Logs/statistics gain GeoIP fields when available and continue cleanly when unavailable.
- No secret or sensitive provider detail leaks through logs, diagnostics, tests, or exports.
