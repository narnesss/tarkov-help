<?php
declare(strict_types=1);
namespace EftWiki;

use EftWiki\Http\{HttpError, Request, Response};
use EftWiki\Identity\{AccountService, Session};
use EftWiki\Profile\StateRepository;
use EftWiki\Prices\PriceService;
use EftWiki\View\PageRenderer;

final readonly class Application
{
    public function __construct(private AccountService $accounts, private StateRepository $states,
        private PriceService $prices, private PageRenderer $views, private string $secret,
        private string $origin, private bool $secure = true, private ?\Closure $registration = null) {}

    private function session(Request $request): Session
    {
        return $this->accounts->session($request->cookies['eft_session'] ?? null)
            ?? throw new HttpError(401, 'Сессия истекла. Обновите страницу или войдите снова.');
    }

    private function csrf(Request $request, Session $session): void
    {
        if (($request->headers['origin'] ?? $this->origin) !== $this->origin
            || !hash_equals($session->csrf($this->secret), $request->headers['x-csrf-token'] ?? '')) {
            throw new HttpError(403, 'Запрос не прошёл проверку сессии.');
        }
    }

    private function withSession(Session $session): Response
    {
        $response = Response::json($session->publicData($this->secret));
        $response->headers['Set-Cookie'] = 'eft_session=' . $session->token . '; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax' . ($this->secure ? '; Secure' : '');
        return $response;
    }

    public function handle(Request $request): Response
    {
        try { $response = $this->route($request); }
        catch (HttpError $error) { $response = Response::json(['error' => $error->getMessage(), ...$error->details], $error->status); }
        catch (\Throwable $error) {
            error_log('[EftWiki] ' . $error::class . ': ' . $error->getMessage());
            $response = Response::json(['error' => 'Сервис временно недоступен. Ваши сохранённые данные не удалены.'], 503);
        }
        $response->headers += ['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'X-Frame-Options' => 'SAMEORIGIN'];
        if ($request->method === 'HEAD') { $response->body = ''; }
        return $response;
    }

    private function route(Request $request): Response
    {
        $path = rtrim($request->path, '/') ?: '/';
        if ($path === '/api/session' && $request->method === 'GET') {
            $session = $this->accounts->session($request->cookies['eft_session'] ?? null) ?? $this->accounts->guest();
            return $this->withSession($session);
        }
        if (str_starts_with($path, '/api/auth/')) {
            if ($request->method !== 'POST') { throw new HttpError(405, 'Нужен запрос POST.'); }
            $session = $this->session($request); $this->csrf($request, $session); $data = $request->json();
            $action = substr($path, strlen('/api/auth/'));
            if (!in_array($action, ['login', 'register', 'logout'], true)) { throw new HttpError(404, 'Раздел не найден.'); }
            if ($action !== 'logout') {
                $this->accounts->rateLimit($request->ip, $action);
                if (!is_string($data['username'] ?? null) || !is_string($data['password'] ?? null)) { throw new HttpError(422, 'Укажите логин и пароль.'); }
                $create=fn()=>$this->accounts->$action($data['username'],$data['password']);
                $next=$action==='register'&&$this->registration!==null?($this->registration)($data,$create):$create();
            } else { $next = $this->accounts->guest(); }
            $this->accounts->revoke($session);
            return $this->withSession($next);
        }
        if ($path === '/api/state' && $request->method === 'GET') {
            return Response::json(['state' => (object) $this->states->all($this->session($request)->userId)]);
        }
        if (str_starts_with($path, '/api/state/')) {
            if ($request->method !== 'PUT') { throw new HttpError(405, 'Нужен запрос PUT.'); }
            $session = $this->session($request); $this->csrf($request, $session); $data = $request->json();
            if (!array_key_exists('value', $data) || !is_int($data['version'] ?? null)) { throw new HttpError(422, 'Нужны данные и версия раздела.'); }
            return Response::json($this->states->put($session->userId, rawurldecode(substr($path, 11)), $data['value'], $data['version']));
        }
        if (in_array($path, ['/api/prices', '/api/prices/daily'], true) && $request->method === 'GET') {
            $response = Response::json(['ok' => false, 'code' => 'prices_disabled',
                'error' => 'Обновление и показ цен барахолки отключены.'], 410);
            $response->headers['Cache-Control'] = 'no-store';
            return $response;
        }
        $routes = ['/' => 'index', '/index' => 'index', '/index.html' => 'index', '/index.php' => 'index', '/raid' => 'raid', '/raid.html' => 'raid', '/quest' => 'quest', '/quest.html' => 'quest', '/quests' => 'quests', '/quests.html' => 'quests', '/reference' => 'reference', '/reference.html' => 'reference', '/boss' => 'boss', '/boss.html' => 'boss'];
        if (isset($routes[$path]) && in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response(200, $this->views->render($routes[$path]), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=0, must-revalidate']);
        }
        if (in_array($request->method, ['GET', 'HEAD'], true)) {
            $page = $this->views->pageForPath($path);
            if ($page !== null) {
                return new Response(200, $this->views->render($page), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=0, must-revalidate']);
            }
        }
        throw new HttpError(404, 'Страница не найдена.');
    }
}
