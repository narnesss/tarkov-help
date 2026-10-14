<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
// Optional live contract check, separate from deterministic offline tests.
$raw=(new EftWiki\Prices\CurlTransport())('https://json.tarkov.dev/regular/items',['Accept: application/json']);
$items=EftWiki\Prices\PriceService::normalize($raw,'tarkov.dev');
if(count($items)<100)throw new RuntimeException('Incomplete response');
echo 'HTTPS upstream contract: '.count($items).' items; '.PHP_VERSION."\n";
