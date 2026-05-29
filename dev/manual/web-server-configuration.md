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
The default values shipped with OpCache won't work with aavion Studio (or any other Symfony based project).

To also improve realpath-cache performance, also change these parameters in `php.ini`:
```ini
; maximum memory allocated to store the results
realpath_cache_size=4096K

; save the results for 10 minutes (600 seconds)
realpath_cache_ttl=600
```
