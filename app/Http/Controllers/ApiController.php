<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use EftWiki\Application;
use App\Services\PlayerProfiles;
use EftWiki\Http\Request as DomainRequest;
use Illuminate\Http\{Request, Response};

/** Laravel HTTP-адаптер проверенного контракта сессий, CSRF и версий прогресса. */
final readonly class ApiController
{
    public function __construct(private Application $application, private PlayerProfiles $profiles) {}

    /** Передаёт заголовки/opaque cookie доменному API и запрещает общий HTTP-кэш. */
    public function __invoke(Request $request): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) { $headers[$name] = implode(', ', $values); }
        $stream = $request->getContent(true);
        $body = is_resource($stream) ? stream_get_contents($stream, 524289) : substr((string) $stream, 0, 524289);
        $result = $this->application->handle(new DomainRequest($request->method(), '/'.$request->path(), $headers,
            $request->cookies->all(), $body ?: '', $request->query->all(), $request->attributes->get('wiki_client_ip') ?? $request->ip() ?? '127.0.0.1'));
        $data=json_decode($result->body,true);
        if($result->status===200 && isset($data['profile']['id'])){
            $data['profile']=$this->profiles->enrich($data['profile']);
            $result->body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        return response($result->body, $result->status, $result->headers)->header('Cache-Control', 'private, no-store');
    }
}
