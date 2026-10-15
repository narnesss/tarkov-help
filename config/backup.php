<?php
declare(strict_types=1);

return [
    'daily_keep' => (int) env('SQLITE_BACKUP_DAILY_KEEP', 7),
    'deploy_keep' => (int) env('SQLITE_BACKUP_DEPLOY_KEEP', 3),
    'max_bytes' => (int) env('SQLITE_BACKUP_MAX_MIB', 100) * 1024 * 1024,
];
