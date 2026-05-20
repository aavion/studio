# Using GitHub branches

> **Status**: Active  
> **Updated**: 2026-05-20   
> **Owner**: Dominik Letica  
> **Purpose:** Guideline for using GitHub branches  

## Channel Policy
### dev-Channel
- The `dev-latest` branch will always be the main working tree for development.
- dev-builds won't get packed for release.

### beta-Channel
- When `dev-latest` offers meaningful changes compared to the latest beta release, a beta release may be concidered.
- After successful testing and stability checks, `dev-latest` will be merged into `beta-latest` using a PR.
- Before packing and releasing, all dev-dependencies will be removed, the manifest gets updated and the project tree gets cleaned up for production use.

### main-Channel
- beta-releases that are considered stable for a public release will be merged into `main` using a PR.
- Before packing and releasing, the manifest gets updated and the project tree gets cleaned up for production use.

## Update and Versioning Policy
### Feature Updates
- Feature-Updates are developed in seperate `feat-*` branches.
- After implementation and successful testing they will (or will not be) merged into `dev-latest` using a PR.
- Every successful PR merge will bump the project version number by 0.1.0

### Security Updates, Bugfixes and Minor Patches
- Minor or urgent fixes are developed in seperate `fix-*` branches.
- After implementation and successful testing they will be pushed to all (and only to) affected channels (`dev-latest`, `beta-latest` and `main`) using a PR.
- Every successful PR merge will bump the project version number by 0.0.1 and trigger an instant release (following the treatments described in the channel policy).

## Other Branches
- Stale `feat-*` and `fix-*` branches will be deleted after PR merge (or close).
- Old release-flag branches may get removed when no longer needed:
  - Retention for beta-releases: Only supported release-branches won't get deleted.
  - Retention for stable/main-releases: Only the latest release-branch per major version won't get deleted.
- `testing-*` branches might get created to test different approaches during development. They will be removed when no longer needed.