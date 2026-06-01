# Test Suite Performance Audit

> **Status**: Active  
> **Updated**: 2026-06-01  
> **Branch**: `audit-test-suite-performance`  
> **Purpose:** Preserve the test-suite audit plan, measurements, decisions, and continuation notes for agents.

## Resume Instructions

1. Start on branch `audit-test-suite-performance`.
2. Run `git status --short --branch` and preserve any user changes.
3. Read this file before editing tests.
4. Prefer targeted test edits with clear test-value justification.
5. Do not remove tests only to reduce counts; keep security, lifecycle, persistence, controller behavior, and regression coverage.
6. Remove or trim assertions that only verify CSS classes, decorative shell structure, or full DOM details unless the selector is the behavior contract.
7. Keep `.codex` helper scripts out of the project PHPUnit suite.
8. For every audit edit, record the decision under "Audit Log" before commit.
9. After each slice, run targeted tests plus `php bin/phpunit` when the slice changes test discovery, bootstrap, controller tests, or shared fixtures.
10. Keep commits small: discovery/bootstrap hardening, assertion trimming, lifecycle consolidation, and fixture cleanup should be separate commits.

## Baseline

Measured after clean setup on 2026-06-01:

- Before audit edits: `802 tests`, `4960 assertions`, about `36-38s`, peak `119-123 MB`.
- After current committed edits:
  - `php bin/phpunit`: `793 tests`, `4906 assertions`, `37.306s`, peak `121 MB`.
  - PHPUnit discovery is restricted to `*Test.php`.
  - `.codex` helper script tests are removed.
  - Some low-value DOM/CSS assertions are trimmed.
  - `LiveOperationQueueFactoryTest` is consolidated from many small kernel boots into two behavior tests.
- After the current working slice:
  - `php bin/phpunit`: `791 tests`, `4876 assertions`, `39.879s`, peak `121 MB`.
  - Short setup-password validation is covered at factory level instead of through an additional browser/controller flow.
  - Password-meter and API-key toggle selectors are no longer treated as backend behavior.
  - Duplicate anonymous user-route login fallback checks are consolidated into one browser test.
  - Setup landing coverage is consolidated into the language/preflight flow.

## Hotspots

Measured from `php bin/phpunit --log-junit var/test-suite-audit.xml` after the current committed edits:

| Rank | Class/Test | Time | Decision |
| --- | --- | ---: | --- |
| 1 | `BackendControllerTest` | ~9.4s total | Keep setup and backend registry integration; trim only UI-detail assertions. |
| 2 | `AdminUserControllerTest` | ~8.3s total | Keep for now; most tests cover ACL/security/lifecycle regressions. |
| 3 | `PackageZipInstallerTest::testItRestoresActiveReverseDependentsAfterSuccessfulOverwrite` | ~4.2s | Keep for now; expensive but covers ZIP staging, overwrite, discovery, deactivation, and reactivation integration. |
| 4 | `UserControllerTest` | ~3.4s total | Audit for DOM/style trimming; keep token, enumeration, recovery, and profile persistence coverage. |
| 5 | `SetupPasswordResetRunnerTest` | ~2.3s total | Keep; verifies prefixed DB recovery behavior. |
| 6 | `SetupRunnerTest` | ~2.1s total | Keep; expensive but setup integration is high-risk. |

## Audit Rules

### Keep

- Status codes and redirects that define route/security behavior.
- Database/entity state changes.
- Token, invitation, recovery, registration, ACL, role, and last-owner guardrails.
- Message keys or user-visible error/success states when they define behavior.
- Live-operation payload/status behavior.
- Security and privacy regressions.
- Integration tests that exercise multiple subsystems where service-level tests would miss wiring.

### Trim Or Convert

- Assertions for shell classes, button styling classes, `aria-current`, decorative card classes, or generic wrappers.
- Multiple assertions proving the same route rendered.
- Controller assertions for validation logic already covered by a factory/service unit test.
- Kernel tests that can instantiate the subject directly without Symfony services.
- Repeated `createClient()` route-smoke tests that can be a route matrix if they do not mutate state.

### Avoid

- Full DOM/string comparisons.
- Assertions against generated runtime or `.codex` helper internals from the project test suite.
- Parallel PHPUnit invocations; the suite uses a `var/test` lock and must run sequentially.

## Candidate Backlog

- [ ] `BackendControllerTest`: move validation-only setup cases into `SetupWebInputFactoryTest` where possible.
- [x] `BackendControllerTest`: review package/detail lifecycle assertions for CSS-heavy checks.
- [ ] `AdminUserControllerTest`: trim list/table assertions to behavior markers and persistence checks.
- [x] `UserControllerTest`: trim password-meter and API-key toggle CSS assertions where a route/form behavior assertion exists.
- [ ] `ViewTwigExtensionTest`: consider whether granular partial rendering belongs in a smaller Twig/template smoke test or should remain as integration coverage.
- [ ] Investigate whether setup wizard controller tests can share more prepared wizard state without losing route/security coverage.
- [ ] Consider a future package-installer fixture strategy for overwrite/dependency flows; avoid weakening the current integration test casually.
- [ ] Re-measure after every meaningful slice with JUnit and record changes below.

## Audit Log

### 2026-06-01

- Restricted PHPUnit discovery to `*Test.php` so editor/cloud conflict copies cannot be collected as tests.
- Removed `tests/Operations/CodexHelperScriptsTest.php`; `.codex` tools are agent helpers, not project behavior.
- Trimmed low-value shell/CSS assertions in `BackendControllerTest` and `DemoControllerTest`.
- Consolidated `LiveOperationQueueFactoryTest` to reduce repeated kernel boots while preserving supported/invalid operation coverage.
- Moved short setup admin-password validation from a full controller/browser flow to `SetupWebInputFactoryTest`; kept route-walk and no-JS setup flows for integration coverage.
- Trimmed `UserControllerTest` assertions for password-meter widgets and API-key toggle CSS while keeping token rendering, password-change, API-key persistence, and revocation behavior.
- Consolidated duplicate anonymous `/user` login-fallback checks into one browser test with two requests.
- Trimmed `BackendControllerTest` assertions for active navigation and password-meter UI details where route success, forms, operation rows, and static-view injection behavior already define the test contract.
- Consolidated the setup landing smoke test into the selected-language/preflight flow to remove one duplicate `/setup` browser test while preserving CSRF, form, heading, language, and navigation checks.
- Trimmed package/theme backend assertions that coupled tests to theme-card classes, hero media markup, markdown emphasis tags, and Stimulus wiring while keeping route, lifecycle, action, metadata, and unsafe-link behavior.
- Removed direct `operation-overlay` Stimulus wiring assertions where the same review form is immediately submitted and verified through the backend result.
- Re-measured with JUnit. High assertion counts mostly come from fast key/enum coverage, while runtime remains concentrated in kernel/controller setup flows and package ZIP integration.
- Repaired local `vendor/` after iCloud conflict-copy directories caused missing package files; use `composer install --no-scripts --optimize-autoloader` if this happens again during the audit.
