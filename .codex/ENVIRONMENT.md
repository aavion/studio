# Environment
**Note:** Only applicable when working in a local environment. For cloud/container environments use linux defaults.

## Operating System
- **OS**: macOS 26.5.1  
- **Shell**: `/bin/zsh`  
- **CLI-Tools**: `/opt/homebrew/bin`  
- **Repository root**: `/Volumes/Projekte/studio`  

## Paths & Interpreters
- **php**: `/opt/homebrew/bin/php` - Version: 8.5.7 + Xdebug 3.5.1
- **python**: `/opt/homebrew/bin/python3` - Version: 3.14.5
- **composer**: `/opt/homebrew/bin/composer` - Version: 2.10.1
- **symfony**: `/opt/homebrew/bin/symfony` - Version (CLI): 5.17.1
- **perl**: `/usr/bin/perl` - Version: 5.34.1

## Helpful CLI patterns
- Lint PHP file: `php -l <path>`
- Test DB migrations: `php bin/console doctrine:migrations:migrate --no-interaction --env=test`
- If Composer vendor packages are incomplete or missing files, run `bin/init` instead of repairing packages one by one.
