# Grav Plugin Inspiration Notes

> **Status**: Draft  
> **Updated**: 2026-05-20  
> **Owner**: OpenAI/Codex  
> **Purpose:** Capture reusable product and architecture ideas from the old Grav plugins without copying code into the Symfony rewrite.  

## Scope

These notes are inspiration only. The old Grav plugins should not define implementation details for this project, and no old code should be copied into the Symfony application.

The inspected plugins were:

- `../temp/refresolver`
- `../temp/sec-lookup`

The `sec-lookup` configuration contains production-style secrets and third-party license data. Do not copy those values into this repository, documentation, fixtures, screenshots, or logs.

## RefResolver Inspiration

Useful ideas to keep:

- Build a resolver index in two passes: first collect raw entities and metadata, then resolve references against the complete map.
- Keep `dry-run`, `rebuild`, and `export` as separate operations with clear reports.
- Store index metadata such as item hashes, map hash, modified timestamps, errors, and reference graph data.
- Track outgoing and incoming references so the system can navigate context in both directions.
- Collect visited references during resolution. This is useful for exports, context gathering, search, and later LLM workflows.
- Use explicit error codes for resolver failures such as missing target, missing field, empty value, and cycle detection.
- Protect recursive resolution with both maximum depth and a stack-based cycle guard.
- Support field-level resolution, including dot-path access for nested structured payloads.
- Support group expansion or derived relation groups, but keep this separate from direct field references.
- Support progression or visibility gates where content can be excluded from resolution depending on context.
- Use atomic writes and file locks for generated index artifacts if they are persisted outside the database.
- Keep report output available for UI and CLI workflows.
- Consider scheduled rebuild/export jobs later, but do not make them part of the first resolver implementation unless needed.

Possible Symfony shape:

```text
build_reference_index(persist):
    raw_entities = collect_content_entities()
    normalized = normalize_reference_targets(raw_entities)
    groups = derive_relation_groups(normalized)
    resolved = resolve_each_entity(normalized, groups, max_depth, stack_guard)
    graph = collect_outgoing_and_incoming_references(resolved)
    report = collect_hashes_stats_and_errors(resolved, graph)

    if persist:
        save_index_atomically(report.index)

    return report
```

The old resolver also contains export/import and JSON operation ideas. For this project, those concepts should stay aligned with the import/export draft: imports need dry-run and diff review, while exports should preserve dynamic resolver tokens in a non-destructive form.

## Security And GeoIP Inspiration

Useful ideas to keep:

- Treat GeoIP lookup as a provider-backed service with scheduled database updates, local storage, and clear failure handling.
- Keep privacy masking explicit for IPv4 and IPv6 before long-term statistics are generated.
- Separate exact IP allow/deny, CIDR allow/deny, country/continent policies, user-agent rules, and probe-path detection.
- Treat known probe paths such as `.env`, WordPress login paths, `.git/config`, and `phpinfo.php` as high-signal security events.
- Track suspicious behavior separately from normal traffic so analytics can highlight probes, repeated 404s, and rate-limit hits.
- Prefer anonymized rotated logs for historical analytics.
- Keep custom handler routes or templates for blocked requests, but route them through Symfony error handling where possible.

Rate limiting should be redesigned for the Symfony project. The old implementation is useful as a warning: a coarse per-IP bucket can block legitimate browsing or form use too often.

Better direction:

- Use Symfony RateLimiter where possible.
- Split buckets by intent, for example page views, form posts, login attempts, captcha refreshes, API calls, and suspicious probes.
- Penalize high-signal hostile behavior more strongly than ordinary browsing.
- Use progressive action where reasonable: allow, slow down, require captcha, temporarily block, then hard-block only for strong signals.
- Avoid charging harmless captcha refreshes and successful human form flows too aggressively.
- Include trusted session or authenticated-user context when available instead of relying only on IP.
- Keep allowlists and local development bypasses explicit and auditable.

Possible risk decision flow:

```text
evaluate_request(request):
    signals = collect_security_signals(request)

    if matches_explicit_allow_policy(signals):
        return allow

    if matches_explicit_deny_policy(signals):
        return block

    risk = score(
        probe_path_hits,
        route_specific_rate_limits,
        form_failures,
        captcha_failures,
        geo_policy,
        user_agent_policy,
        authenticated_user_trust
    )

    if risk >= hard_block_threshold:
        return block

    if risk >= challenge_threshold:
        return require_captcha_or_step_up

    if risk >= throttle_threshold:
        return throttle

    return allow
```

## IconCaptcha Inspiration

Useful ideas to keep:

- Generate deterministic challenge data from a server-side secret and request-bound context.
- Bind the challenge to session, user agent, and path so it cannot be replayed freely elsewhere.
- Use one-shot challenge IDs and expire them quickly.
- Keep two challenge slots on the frontend so refreshes can swap immediately and refill the standby challenge asynchronously.
- Refresh challenges after browser back-forward cache restores.
- Separate human-visible errors from silent bot failures.
- Combine captcha checks with honeypots, but do not make honeypots the only signal.
- Sanitize inline SVG assets before rendering them into buttons.

Symfony-oriented shape:

```text
create_challenge(request):
    challenge_id = random_id()
    timestamp = now()
    seed = hmac(secret, challenge_id, timestamp, session_id, user_agent, route)
    icons = deterministic_pick(icon_pool, seed)
    target = deterministic_target(icons, seed)
    order = deterministic_permutation(icons, seed)
    return signed_public_challenge(challenge_id, timestamp, target_display, icons, order)

validate_challenge(request, submitted_choice):
    reject_if_missing_or_expired()
    reject_if_challenge_id_was_used()
    expected = recompute_expected_choice(request_context)
    mark_challenge_as_used()
    return submitted_choice == expected
```

Implementation should later use Symfony forms, validators, CSRF/session handling, RateLimiter, cache pools, translation keys, and the project error-handling conventions.

## Draft Touchpoints For Later

Do not apply these notes to feature drafts automatically. When the related drafts are reviewed again, useful touchpoints are:

- `0.3.x-CrossReferenceIndexSearch.md`
- `0.4.x-IconCaptcha.md`
- `0.4.x-ContactMailLogging.md`
- `0.2.x-SecurityAccessControl.md`
- `0.4.x-OperationalAdminWorkflows.md`
- `0.4.x-ImportExportCollaboration.md`

## Open Questions

- Which resolver index data belongs in SQL tables, and which data can be generated/cache-backed?
- Should resolver rebuilds be synchronous admin actions, Messenger jobs, or both?
- Which resolver errors should block publishing, and which should remain warnings?
- Which security signals should trigger a captcha challenge instead of a block?
- Which GeoIP and security statistics are worth showing in the first admin UI?
- How much of the IconCaptcha challenge should be configurable by administrators?
