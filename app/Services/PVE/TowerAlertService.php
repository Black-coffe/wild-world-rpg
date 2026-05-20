<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * S26b (ADR-031) — alert-range detection вышки (WatchTower).
 *
 * Когда любой игрок завершает шаг марша, MarchingTaskHandler зовёт
 * notifyTowersNear(). Сервис ищет active WatchTower'ы (hp>0) ДРУГИХ игроков в
 * радиусе defense.tower.alert_range_cells (Евклидова метрика — как
 * PlayerDetectionService, ADR-007 deviation) от новой позиции игрока и шлёт
 * владельцу базы Telegram-пинг «к тебе приближается X». Анти-спам: cache-кулдаун
 * по паре (owner, mover) на defense.tower.alert_cooldown_sec.
 *
 * Сам пинг боевого эффекта НЕ даёт (только информация). Combat-эффект вышки
 * (initiative) — в DefenseStructureService. Всё в try/catch — поход не падает.
 */
class TowerAlertService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();

        $apiKey      = getenv('telegram.API_KEY');
        $botUsername = getenv('telegram.BOT_USERNAME');
        try {
            $telegram = new Telegram((string) $apiKey, (string) $botUsername);
            Request::initialize($telegram);
        } catch (TelegramException $e) {
            log_message('error', '[TowerAlertService] telegram init: ' . $e->getMessage());
        }
    }

    /**
     * Пингует владельцев баз с active WatchTower в радиусе от позиции игрока.
     *
     * @param int $moverId персонаж, который переместился
     * @param int $newX    его новая X-координата
     * @param int $newY    его новая Y-координата
     * @return int число отправленных пингов
     */
    public function notifyTowersNear(int $moverId, int $newX, int $newY): int
    {
        if ($moverId <= 0) {
            return 0;
        }
        $range = $this->intSetting('defense.tower.alert_range_cells', 5);
        if ($range <= 0) {
            return 0;
        }
        $cooldown = $this->intSetting('defense.tower.alert_cooldown_sec', 1800);

        $towers = $this->towersInBox($moverId, $newX, $newY, $range);
        if ($towers === []) {
            return 0;
        }

        $moverName = $this->moverName($moverId);
        $rangeSq   = $range * $range;
        $cache     = \Config\Services::cache();
        $sent      = 0;
        /** @var array<int,bool> $pinged один пинг на владельца за проход */
        $pinged = [];

        foreach ($towers as $t) {
            $ownerId = is_numeric($t['character_id'] ?? null) ? (int) $t['character_id'] : 0;
            $tx      = is_numeric($t['coordinate_x'] ?? null) ? (int) $t['coordinate_x'] : 0;
            $ty      = is_numeric($t['coordinate_y'] ?? null) ? (int) $t['coordinate_y'] : 0;
            if ($ownerId <= 0 || $ownerId === $moverId || isset($pinged[$ownerId])) {
                continue;
            }

            $dx = $tx - $newX;
            $dy = $ty - $newY;
            $distSq = ($dx * $dx) + ($dy * $dy);
            if ($distSq > $rangeSq) {
                continue; // вне Евклидова радиуса
            }

            $cacheKey = "tower_alert_cd_{$ownerId}_{$moverId}";
            if ($cache->get($cacheKey) !== null) {
                continue; // на кулдауне
            }

            $dist = (int) round(sqrt((float) $distSq));
            if ($this->sendAlert($ownerId, $moverName, $dist, $newX, $newY)) {
                $cache->save($cacheKey, time(), max(1, $cooldown));
                $pinged[$ownerId] = true;
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Active WatchTower'ы в bounding box (грубый фильтр; точный Евклидов — в caller).
     *
     * @return list<array<string,mixed>>
     */
    private function towersInBox(int $moverId, int $x, int $y, int $range): array
    {
        try {
            $db    = Database::connect();
            $query = $db->table('character_buildings cb')
                ->select('cb.character_id, m.coordinate_x, m.coordinate_y')
                ->join('buildings b', 'b.id = cb.building_id')
                ->join('map m', 'm.cell_number = cb.map_cell_id')
                ->where('b.name_en', 'WatchTower')
                ->where('cb.building_type', 'defensive')
                ->where('cb.hp >', 0)
                ->where('cb.character_id <>', $moverId)
                ->where('m.coordinate_x >=', $x - $range)
                ->where('m.coordinate_x <=', $x + $range)
                ->where('m.coordinate_y >=', $y - $range)
                ->where('m.coordinate_y <=', $y + $range)
                ->get();
            if ($query === false) {
                return [];
            }
            /** @var list<array<string,mixed>> $rows */
            $rows = $query->getResultArray();
            return $rows;
        } catch (Throwable $e) {
            log_message('error', '[TowerAlertService] towersInBox failed: ' . $e->getMessage());
            return [];
        }
    }

    private function moverName(int $moverId): string
    {
        try {
            $db  = Database::connect();
            $row = $db->table('characters')->select('name')->where('id', $moverId)->get();
            if ($row === false) {
                return '';
            }
            $r = $row->getRowArray();
            return is_array($r) && is_string($r['name'] ?? null) ? $r['name'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Шлёт владельцу вышки Telegram-пинг. true — если отправлено.
     * protected — тесты подменяют доставку (как StubDetector для PlayerDetection).
     */
    protected function sendAlert(int $ownerId, string $moverName, int $dist, int $x, int $y): bool
    {
        $chatId = $this->ownerChatId($ownerId);
        if ($chatId === null) {
            return false;
        }

        $nameTag = $moverName !== '' ? "*{$moverName}*" : 'кто-то';
        $message = "🗼 *Дозорная вышка!*\n\n"
            . "Засекла игрока {$nameTag} в {$dist} " . $this->plural($dist, 'клетке', 'клетках', 'клетках') . " от твоей базы (X={$x}, Y={$y}).\n"
            . '_Возможно, стоит вернуться к защите._';

        try {
            $resp = Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
            return $resp->isOk();
        } catch (Throwable $e) {
            log_message('error', '[TowerAlertService] sendAlert failed: ' . $e->getMessage());
            return false;
        }
    }

    private function ownerChatId(int $ownerId): ?int
    {
        try {
            $db  = Database::connect();
            $row = $db->table('characters c')
                ->select('tu.telegram_id')
                ->join('telegram_users tu', 'tu.id = c.telegram_user_id')
                ->where('c.id', $ownerId)
                ->get();
            if ($row === false) {
                return null;
            }
            $r = $row->getRowArray();
            if (!is_array($r) || !is_numeric($r['telegram_id'] ?? null)) {
                return null;
            }
            $chatId = (int) $r['telegram_id'];
            return $chatId !== 0 ? $chatId : null;
        } catch (Throwable $e) {
            log_message('error', '[TowerAlertService] ownerChatId failed: ' . $e->getMessage());
            return null;
        }
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n  = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
