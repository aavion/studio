# Web server configuration

> **Status**: Draft  
> **Updated**: 2026-05-22  
> **Owner**: Core  
> **Purpose:** Describe the supported web server entry points for local, staging, and production deployments.  

The application must be served from the `public/` directory. Requests for real public files should be served directly by the web server; all other requests should be routed to `public/index.php`.

## Apache

Use `config/webserver/apache-vhost.conf` as the preferred starting point for Apache deployments. It keeps rewrite behavior in the virtual host and uses `AllowOverride None`, so Apache does not need broad `.htaccess` overrides.

The generated `public/.htaccess` is intentionally minimal and is only a shared-hosting fallback. It requires rewrite overrides, keeps redirects aware of Apache alias or subdirectory base paths, and does not use `Options` or `DirectoryIndex`, so deployments should not need `AllowOverride All` just to satisfy Symfony's fallback file.

Required Apache modules:

- `mod_rewrite` when using `public/.htaccess`.
- `mod_dir` for `DirectoryIndex` when using the virtual host template.

### Native asset build binaries

Studio can rebuild Tailwind assets automatically from setup and extension maintenance flows. This uses the Tailwind standalone binary through Symfony Process. Some Linux systemd hardening profiles for Apache block native binaries with `MemoryDenyWriteExecute=yes`; setup then continues, but reports a warning and asks the operator to run `php bin/console tailwind:build` through CLI, SSH, or a terminal.

If automatic web-triggered Tailwind rebuilds are required, add a service override for the web server and restart it:

```ini
[Service]
MemoryDenyWriteExecute=no
```

Keep this setting scoped to the web server service. Other setup subprocess checks still report disabled PHP process functions, PHP safe mode, or missing PHP CLI binaries separately.

### Reverse proxy client IPs

When Apache runs behind a reverse proxy such as Cloudflare, prefer `mod_remoteip` at the web-server layer. This rewrites `REMOTE_ADDR` before PHP handles the request, so Symfony's normal `Request::getClientIp()` resolution and Studio access logging use the verified client IP without application-level proxy lists.

### Mercure push notifications

Studio treats Mercure push delivery as an optional enhancement. The polling alert inbox remains the portable fallback and must keep working on shared hosting without reverse-proxy support.

For push delivery, configure the public Mercure endpoint so browser `EventSource` requests reach the Mercure hub:

```text
Browser -> https://example.com/.well-known/mercure -> reverse proxy -> http://127.0.0.1:3000/.well-known/mercure
Symfony -> http://127.0.0.1:3000/.well-known/mercure
```

Default environment:

```dotenv
MERCURE_HUB_LISTEN=127.0.0.1:3000
MERCURE_URL=http://${MERCURE_HUB_LISTEN}/.well-known/mercure
MERCURE_PUBLIC_URL=${DEFAULT_URI}/.well-known/mercure
MERCURE_JWT_SECRET=...
```

`MERCURE_JWT_SECRET` must provide at least 256 bits of HMAC-SHA256 key material. The committed default derives it from `APP_SECRET`, and setup validates/generates a long enough `APP_SECRET`; manual environments should keep that derivation or use a dedicated high-entropy `MERCURE_JWT_SECRET` with at least 32 bytes.

Override `MERCURE_PUBLIC_URL` only when the browser-facing URL differs from the canonical `DEFAULT_URI` host, for example when Mercure is exposed through a dedicated subdomain, Cloudflare Tunnel, or a supported public HTTPS port.

The reverse proxy must keep Server-Sent Events usable: disable response buffering for `/.well-known/mercure`, use a long read timeout, preserve the request host and scheme with forwarded headers, and forward the request to the local Mercure hub port.

Studio UI-alert push uses unguessable HMAC-bound public URN topics under `urn:system:ui-alerts:*`. The local `mercure:start` command therefore starts the hub with anonymous subscribers enabled. External Mercure hub deployments must allow anonymous subscribers for public UI-alert topics or provide an equivalent subscriber authorization strategy before `mercure:health` can mark push delivery as available.

If no public Mercure endpoint is reachable, `mercure:health` stores Mercure as unavailable. Studio then skips EventSource stream URLs and push publishing attempts while continuing to deliver alerts through the polling inbox. Use `php bin/console mercure:check` for read-only diagnostics without starting or stopping the hub.

Apache example:

```apache
# Required modules: mod_proxy, mod_proxy_http, mod_headers.
ProxyPreserveHost On

ProxyPass "/.well-known/mercure" "http://127.0.0.1:3000/.well-known/mercure" retry=0 timeout=86400 flushpackets=on
ProxyPassReverse "/.well-known/mercure" "http://127.0.0.1:3000/.well-known/mercure"

# Use "http" instead when this virtual host is intentionally served without TLS.
RequestHeader set X-Forwarded-Proto "https" early
SetEnvIf Request_URI "^/\.well-known/mercure" no-gzip=1
```

nginx example:

```nginx
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000/.well-known/mercure;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_cache off;
    gzip off;
    proxy_read_timeout 24h;
}
```

IIS example using URL Rewrite and Application Request Routing:

```xml
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="Mercure reverse proxy" stopProcessing="true">
          <match url="^\.well-known/mercure(.*)$" />
          <action type="Rewrite" url="http://127.0.0.1:3000/.well-known/mercure{R:1}" />
          <serverVariables>
            <set name="HTTP_X_FORWARDED_PROTO" value="https" />
            <set name="HTTP_X_FORWARDED_HOST" value="{HTTP_HOST}" />
          </serverVariables>
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
```

For IIS, enable ARR proxying at server level and allow the `HTTP_X_FORWARDED_PROTO` and `HTTP_X_FORWARDED_HOST` server variables if IIS blocks them by default. Keep ARR response buffering disabled or minimized for this route when available; if the hosting environment cannot stream long responses reliably, leave Mercure unavailable and use the polling fallback.

## nginx

Use `config/webserver/nginx.conf` as a template. Adjust `server_name`, `root`, `fastcgi_pass`, TLS, log paths, and upload limits for the target system.

The nginx template uses `try_files` for static files and routes dynamic requests to `index.php`. Direct access to arbitrary PHP files returns `404`.

## IIS

The `public/web.config` file provides the IIS URL Rewrite rules for the front controller. IIS deployments require PHP FastCGI and the IIS URL Rewrite module. The `index.php` canonical redirect preserves the current IIS virtual directory prefix instead of redirecting to the site root.

If IIS blocks `HTTP_AUTHORIZATION` server variables, allow the variable in the IIS URL Rewrite settings or remove the `serverVariables` section and pass authorization headers at the FastCGI/reverse-proxy layer instead.

## PHP OpCache Optimization

If using OpCache to accelerate PHP pre-caching, make sure to set the following parameters in your server's `php.ini`:

```ini
; add these two Parameters and change them to fit your setup:
opcache.preload=/path/to/project/config/preload.php
opcache.preload_user=www-data

; change these four parameters to increase OpCache performance for Symfony:
opcache.memory_consumption=256
opcache.max_accelerated_files=32531
opcache.interned_strings_buffer=32
opcache.validate_timestamps=0
```

After restarting/reloading your webserver, call `opcache_reset()` once to activate these settings.
The default values shipped with OpCache won't work with Studio (or any other Symfony based project).

To also improve realpath-cache performance, also change these parameters in `php.ini`:
```ini
; maximum memory allocated to store the results
realpath_cache_size=4096K

; save the results for 10 minutes (600 seconds)
realpath_cache_ttl=600
```
