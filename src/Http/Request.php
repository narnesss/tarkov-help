<?php
declare(strict_types=1);
namespace EftWiki\Http;

final readonly class Request
{
    public function __construct(
        public string $method, public string $path, public array $headers = [],
        public array $cookies = [], public string $body = '', public array $query = [],
        public string $ip = '127.0.0.1',
    ) {}

    public static function globals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'] ?? '';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $headers, $_COOKIE,
            file_get_contents('php://input', false, null, 0, 524289) ?: '', $_GET,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function json(): array
    {
        if (strlen($this->body) > 524288) { throw new HttpError(413, 'Слишком большой запрос.'); }
        if (!str_starts_with(strtolower($this->headers['content-type'] ?? ''), 'application/json')) {
            throw new HttpError(415, 'Нужен формат JSON.');
        }
        try { $value = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpError(400, 'Не удалось прочитать JSON.'); }
        if (!is_array($value) || array_is_list($value) && $value !== []) { throw new HttpError(400, 'Нужен JSON-объект.'); }
        return $value;
    }
}
