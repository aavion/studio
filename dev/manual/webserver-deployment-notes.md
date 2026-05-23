# Webserver deployment notes

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Collect deployment edge cases and review notes for Apache, nginx, IIS, and front-controller routing.

## Overview

This page complements the shorter web server configuration guide. Keep operational edge cases here until production deployment patterns settle.

## Apache notes

Preferred deployment:

- serve `public/` as document root;
- use server-level vhost configuration;
- keep `AllowOverride None`;
- use `FallbackResource /index.php` where supported.

Fallback deployment:

- use `public/.htaccess`;
- require `mod_rewrite`;
- preserve base paths for `/index.php/...` canonical redirects;
- avoid requiring broad `Options` or `DirectoryIndex` overrides.

## nginx notes

The template uses `try_files` for static files and routes dynamic requests to `index.php`. Keep arbitrary PHP files inaccessible.

Production deployments still need decisions for:

- TLS termination;
- trusted proxy headers;
- upload limits;
- cache headers for built assets;
- access log format;
- error log retention.

## IIS notes

IIS deployments need PHP FastCGI and URL Rewrite. The canonical `index.php` redirect should preserve virtual directory prefixes instead of redirecting to `/`.

If `HTTP_AUTHORIZATION` cannot be set by rewrite rules, configure allowed server variables in IIS or pass authorization headers at the FastCGI/reverse-proxy layer.

## Base-path checklist

Check these paths when deploying under aliases or virtual directories:

```text
/studio/
/studio/index.php
/studio/index.php/admin
/studio/assets/...
```

Canonical redirects should stay under `/studio/`.

## References

- [Web server configuration](web-server-configuration.md)
- [Security guard snippets](security-guard-snippets.md)
- `config/webserver/apache-vhost.conf`
- `config/webserver/nginx.conf`
- `public/.htaccess`
- `public/web.config`
