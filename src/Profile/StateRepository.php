<?php
declare(strict_types=1);
namespace EftWiki\Profile;

use EftWiki\Http\HttpError;
use EftWiki\Support\Clock;

final readonly class StateRepository
{
    public function __construct(private \PDO $db, private Clock $clock) {}

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^(?:kord-atlas-(?:progress-v1|view-v1|raid-progress-v1|raid-view-v1|game-mode|quests-(?:regular|pve)-v1|quest-view-(?:regular|pve)-v1|reference-view-(?:regular|pve)-v1)|kord-(?:hideout|achievements)-(?:regular|pve)|wiki-ui-v1)$/D', $key);
    }

    public function all(string $owner): array
    {
        $q = $this->db->prepare('SELECT state_key,value,version FROM user_state WHERE user_id=?'); $q->execute([$owner]); $result = [];
        foreach ($q as $row) { $result[$row['state_key']] = ['value' => json_decode($row['value'], true, 64, JSON_THROW_ON_ERROR), 'version' => (int) $row['version']]; }
        return $result;
    }

    public function put(string $owner, string $key, mixed $value, int $version): array
    {
        if (!self::validKey($key) || $version < 0) { throw new HttpError(422, 'Неизвестный раздел профиля.'); }
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 500000) { throw new HttpError(413, 'Раздел профиля слишком большой.'); }
        if (!is_array($value) && !($key === 'kord-atlas-game-mode' && in_array($value, ['regular', 'pve'], true))) {
            throw new HttpError(422, 'Неверный формат данных профиля.');
        }
        $next=false;
        // Каждая запись — атомарный CAS; версия ответа известна из условия UPDATE.
        if ($version > 0) {
            $q = $this->db->prepare('UPDATE user_state SET value=?,version=version+1,updated_at=? WHERE user_id=? AND state_key=? AND version=?');
            $q->execute([$encoded, $this->clock->now(), $owner, $key, $version]);
            if($q->rowCount()===1){$next=$version+1;}
        } else {
            try {
                $q=$this->db->prepare('INSERT INTO user_state(user_id,state_key,value,version,updated_at) VALUES (?,?,?,1,?)');
                $q->execute([$owner,$key,$encoded,$this->clock->now()]);$next=1;
            } catch(\PDOException $error){
                if(($error->errorInfo[1]??null)!==1062&&!str_contains($error->getMessage(),'UNIQUE constraint failed')){throw $error;}
            }
        }
        if ($next === false) { throw new HttpError(409, 'Профиль изменён в другой вкладке или на другом устройстве.', ['current' => $this->all($owner)[$key] ?? ['value' => null, 'version' => 0]]); }
        return ['value' => $value, 'version' => (int) $next];
    }
}
