<?php
declare(strict_types=1);
namespace EftWiki\Identity;

use EftWiki\Http\HttpError;
use EftWiki\Support\Clock;

final readonly class AccountService
{
    public function __construct(private \PDO $db, private Clock $clock) {}

    public function session(?string $token): ?Session
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/D', $token)) { return null; }
        $q = $this->db->prepare('SELECT s.user_id, s.expires_at, u.username FROM sessions s JOIN users u ON s.user_id=u.id WHERE s.token_hash=? AND s.expires_at>?');
        $q->execute([hash('sha256', $token), $this->clock->now()]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $expiry = (int) $row['expires_at'];
        if ($expiry < $this->clock->now() + 86400 * 15) {
            $expiry = $this->clock->now() + 86400 * 30;
            $this->db->prepare('UPDATE sessions SET expires_at=? WHERE token_hash=?')->execute([$expiry, hash('sha256', $token)]);
        }
        return new Session($token, $row['user_id'], $row['username'], $expiry);
    }

    public function guest(): Session
    {
        $id = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO users(id, created_at) VALUES (?, ?)')->execute([$id, $this->clock->now()]);
        return $this->issue($id, null);
    }

    private function issue(string $id, ?string $name): Session
    {
        $token = bin2hex(random_bytes(32)); $expiry = $this->clock->now() + 86400 * 30;
        $this->db->prepare('INSERT INTO sessions(token_hash, user_id, expires_at) VALUES (?, ?, ?)')->execute([hash('sha256', $token), $id, $expiry]);
        return new Session($token, $id, $name, $expiry);
    }

    /** Выдаёт прежнюю opaque-сессию игроку после проверки внешнего способа входа. */
    public function issueFor(string $owner): Session
    {
        $q=$this->db->prepare('SELECT username FROM users WHERE id=?'); $q->execute([$owner]);
        $row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row || $row['username']===null){throw new HttpError(401,'Аккаунт не найден.');}
        return $this->issue($owner,$row['username']);
    }

    public function rateLimit(string $ip, string $action): void
    {
        $window = $action === 'register' ? 86400 : 900;
        $bucket = hash('sha256', $ip) . ':' . $action . ':' . intdiv($this->clock->now(), $window);
        $mysql=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $q = $this->db->prepare($mysql
            ?'INSERT INTO rate_limits(bucket,count,expires_at) VALUES (?,LAST_INSERT_ID(1),?) ON DUPLICATE KEY UPDATE count=LAST_INSERT_ID(count+1)'
            :'INSERT INTO rate_limits(bucket,count,expires_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1 RETURNING count');
        $q->execute([$bucket, $this->clock->now() + $window]);
        $count=$mysql?(int)$this->db->lastInsertId():(int)$q->fetchColumn();
        if ($count > ($action === 'register' ? 10 : 8)) { throw new HttpError(429, 'Слишком много попыток. Попробуйте позже.'); }
    }

    public function register(string $name, string $password): Session
    {
        $name = mb_strtolower(trim($name));
        if (!preg_match('/^[\p{L}\p{N}_-]{3,32}$/uD', $name)) {
            throw new HttpError(422, 'Логин: 3–32 буквы, цифры, дефис или _.');
        }
        if(($error=PasswordPolicy::error($password,$name))!==null){throw new HttpError(422,$error);}
        $id = bin2hex(random_bytes(16));
        try {
            $this->db->prepare('INSERT INTO users VALUES(?,?,?,?)')->execute([$id, $name, password_hash($password, PASSWORD_DEFAULT), $this->clock->now()]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) { throw new HttpError(409, 'Этот логин уже занят.'); }
            throw $e;
        }
        return $this->issue($id, $name);
    }

    public function login(string $name, string $password): Session
    {
        // Проверка пароля и выдача сессии не могут обойти одновременный сброс пароля.
        $ownsTransaction=!$this->db->inTransaction();
        if($ownsTransaction){$this->db->beginTransaction();}
        try {
            $q = $this->db->prepare('SELECT id, username, password_hash FROM users WHERE username=?'.($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
            $q->execute([mb_strtolower(trim($name))]); $user = $q->fetch(\PDO::FETCH_ASSOC);
            // A fixed valid hash keeps missing-user work comparable to a wrong password.
            $hash = $user['password_hash'] ?? '$2y$12$0SVHOdslyUrSFAVdnTZH2.Ovmh0qaOBHP50laGRQZtsNpPJqpPBf.';
            if (strlen($password) > 72 || !password_verify($password, $hash) || !is_string($user['password_hash'] ?? null)) { throw new HttpError(401, 'Неверный логин или пароль.'); }
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            $session=$this->issue($user['id'], $user['username']);
            if($ownsTransaction){$this->db->commit();}
            return $session;
        }catch(\Throwable $error){if($ownsTransaction&&$this->db->inTransaction()){$this->db->rollBack();}throw $error;}
    }

    public function revoke(Session $session): void
    { $this->db->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256', $session->token)]); }

    public function cleanup(): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE expires_at<=?')->execute([$this->clock->now()]);
        $this->db->prepare('DELETE FROM rate_limits WHERE expires_at<=?')->execute([$this->clock->now()]);
    }
}
