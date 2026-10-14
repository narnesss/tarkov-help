<?php
declare(strict_types=1);
namespace EftWiki\Http;

final class Response
{
    public function __construct(public int $status = 200, public string $body = '', public array $headers = []) {}

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) { header($name . ': ' . $value); }
        echo $this->body;
        exit;
    }
}
