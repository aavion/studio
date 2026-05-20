# Security Policy

> **Status**: Active  
> **Updated**: 2026-05-20   
> **Owner**: Dominik Letica  
> **Purpose:** Support and reporting policy.  

## Supported Versions

### main-Channel
Stable builds (`main`-branch or stable releases, where `main` always reflects the latest stable release) are supported at least until the next version is publicly available.  
Please consider to keep your environment always up to date for security patches to apply.  
If you're facing any problems, please feel free to [report](https://github.com/aavion/studio/issues) them to get assistance.  
**Note:** Versions prior to the first major release are considered `dev` (see below).  

| Version | Supported          |
| ------- | ------------------ |
| < 1.0.0 | :x: (see `dev`)    |

### beta-Channel
Beta builds (`beta-*` branch or releases with `beta`-tag) receive limited support via GitHub Issues at least until the next version is publicly available.  
Please note that beta-versions might behave unstable and contain bugs and untreated security risks. 
**Do not use in production environments!**.  
Feel free to [report](https://github.com/aavion/studio/issues) any problems or security concerns but don't expect any personal assistance.  
**Note:** Versions prior to the first major release are considered `dev` (see below).  

| Version | Supported          |
| ------- | ------------------ |
| any     | :warning:          |

### dev-Channel
Developer builds (`dev-*`-branch or releases with `dev`-tag) doesn't receive any support. 
Be aware that using development-versions might end up in unexpected behaviour, data-loss and contain bugs and untreated security risks when development procedes. **Use at your own risk!**.  
If you're facing any problems, please consider switching to a more stable build.  

| Version | Supported          |
| ------- | ------------------ |
| any     | :x:                |

## Reporting a Vulnerability

- For non-critical security concerns, open a [GitHub Issue](https://github.com/aavion/studio/issues).  
- To privately report possible exploits that may expose critical user data or break functionality, click the `Report a Vulnerability`-Button above or [send a Mail](mailto:github@aavion.media) to the repository's owner.