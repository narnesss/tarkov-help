#!/usr/bin/env sh
# В Scheduled tasks указывать этот стабильный путь через tarkov-api, не путь релиза.
set -eu
umask 077
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
exec php artisan wiki:backup --kind=daily --no-interaction
