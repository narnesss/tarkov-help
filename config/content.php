<?php
declare(strict_types=1);

// Срок жизни готового представления; актуальность SQL-ссылок проверяется при каждом чтении.
return ['directory'=>env('CONTENT_DIRECTORY'), 'public_cache_ttl'=>86400];
