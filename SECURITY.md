# Security Policy

> **Status**: Draft  
> **Updated**: 2026-05-15   
> **Owner**: Core  
> **Purpose:** Support and reporting policy.  

## Supported Versions

### dev-Channel
> Developer builds (dev-*-branch or releases with `dev`-tag) doesn't receive any support.  
> If you're facing any problems, please consider switching to a more stable build.

| Version | Supported          |
| ------- | ------------------ |
| any     | :x:                |

### beta-Channel
> Beta builds (beta-* branch or releases with `beta`-tag) receive limited support via GitHub Issues at least until the next version is publicly available.  
> Feel free to report any problems or security concerns but don't expect any personal assistance.

| Version | Supported          |
| ------- | ------------------ |
| any     | :warning:          |

### main-Channel
> Stable builds (main-branch or stable releases) are supported at least until the next version is publicly available.
> Please consider to keep your environment always up to date for security patches to apply.
> If you're facing any problems, please feel free to report them to get assistance.
> **Note:** Versions prior to the first major release are considered `dev` (see above).

| Version | Supported          |
| ------- | ------------------ |
| < 1.0.0 | :x: (see `dev`)    |

## Reporting a Vulnerability

- For non-critical security concerns or discovered vunerabilities, open a [GitHub Issue](https://github.com/aavion/studio/issues).  
- For reporting possible exploits that may expose critical user data or break functionality, [send a Mail](mailto:github@aavion.media) to the repository's owner.