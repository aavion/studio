# Security

Access control, voters, captcha contracts, rate-limit helpers, and security extension points live here.

Security behavior should fail closed, avoid leaking protected data, and stay aligned with documented ACL boundaries.

`MaintenanceModeSubscriber` enforces the environment-backed `APP_MAINTENANCE` flag for main requests. When enabled, public requests receive `503 Service Unavailable` unless the authenticated user has at least the Admin role; operational paths such as `/admin`, `/user/login`, localized login paths, and static asset prefixes remain reachable.

Use `admin/` for system administration and `editor/` for content authoring, review, publishing, and content-management workflows. Editor routes should use ACL capability checks rather than Symfony roles.
