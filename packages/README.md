# Packages

Installable extension packages live here. Discovery reads only direct child package directories with a `.manifest` file and does not include package PHP during discovery.

Packages use `PACKAGE_*` manifest keys and declare one or more scopes through `PACKAGE_SCOPE`, for example `[frontend-theme, module]`.
