<?php
declare(strict_types=1);
namespace EftWiki\Identity;

final readonly class Session
{
    public function __construct(public string $token, public string $userId, public ?string $username, public int $expiresAt) {}
    public function csrf(string $secret): string { return hash_hmac('sha256', $this->token, $secret); }
    public function publicData(string $secret): array
    { return ['profile' => ['id' => $this->userId, 'name' => $this->username ?? 'Мой профиль', 'authenticated' => $this->username !== null], 'csrf' => $this->csrf($secret), 'storage' => 'server']; }
}
