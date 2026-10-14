<?php
declare(strict_types=1);
namespace EftWiki\Support;

interface Clock { public function now(): int; }
