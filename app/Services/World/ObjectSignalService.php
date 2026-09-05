<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\Other\WorldObjectGeneratorHandler;
use Config\Database;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * ADR-098 — «Радио-сигнал к редким объектам».
 *
 * Сестринский сервис к {@see \App\Services\PVE\TowerAlertService}: когда игрок
 * завершает шаг марша, {@see \App\TaskHandlers\MarchingTaskHandler} зовёт
 * {@see signalNearbyObjects()}. Сервис ищет active strategic world-объекты
 * (Bunker / Technopark / GhostCity / IslandFarm / IslandHeart — список из
 * {@see WorldObjectGeneratorHandler::STRATEGIC_SPAWN_TYPES}) в радиусе
 * `world.signal.range_cells` (Евклидова метрика — как PlayerDetection/TowerAlert)
 * от новой позиции и шлёт игроку «слабый сигнал» с направлением (8 румбов) и
 * грубой дистанцией до БЛИЖАЙШЕГО объекта. Анти-спам: cache-кулдаун на пару
 * (player, instance) — `world.signal.cooldown_sec`.
 *
 * «Пропадает, когда первый добрался» достаётся бесплатно: лутнувший помечает
 * инстанс `status='cleared'` ({@see \App\TaskHandlers\Objects\StrategicLootHandler}),
 * генератор затем удаляет cleared → скан видит только 'active' → сигнал гаснет
 * для всех. Чистая ИНФОРМАЦИЯ, боевого/балансового эффекта НЕТ. Весь вызов в
 * try/catch (caller) — поход не падает. Killswitch `world.signal.enabled`.
 *
 * media-off native: только текст (без фото), сообщение самодостаточно (объект +
 * направление + дистанция + смысл) — см. конституционное правило MEDIA-OFF.
 */
class ObjectSignalService
{
    private GameSettingsService $settings;

    /** @var array<string,string> человекочитаемые направления (эмодзи + текст). */
    private const DIR_LABEL = [
        'north'     => '⬆️ к северу',
        'south'     => '⬇️ к югу',
        'west'      => '⬅️ к западу',
        'east'      => '➡️ к востоку',
        'northwest' => '↖️ к северо-западу',
        'northeast' => '↗️ к северо-востоку',
        'southwest' => '↙️ к юго-западу',
        'southeast' => '↘️ к юго-востоку',
    ];

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();

        $apiKey      = getenv('telegram.API_KEY');
        $botUsername = getenv('telegram.BOT_USERNAME');
        try {
            $telegram = new Telegram((string) $apiKey, (string) $botUsername);
            Request::initialize($telegram);
        } catch (TelegramException $e) {
            log_message('error', '[ObjectSignalService] telegram init: ' . $e->getMessage());
        }
    }

    /**
     * Шлёт игроку сигнал к ближайшему active strategic-объекту в радиусе.
     *
     * @param int $moverId персонаж, который переместился
     * @param int $newX    его новая X-координата
     * @param int $newY    его новая Y-координата
     * @return int 1 — если сигнал отправлен, иначе 0
     */
    public function signalNearbyObjects(int $moverId, int $newX, int $newY): int
    {
        if ($moverId <= 0) {
            return 0;
        }
        if (! (bool) $this->settings->get('world.signal.enabled', true)) {
            return 0; // killswitch
        }
        $range = $this->intSetting('world.signal.range_cells', 20);
        if ($range <= 0) {
            return 0;
        }
        $cooldown = $this->intSetting('world.signal.cooldown_sec', 600);

        $nearest = $this->nearestActiveStrategic($newX, $newY, $range);
        if ($nearest === null) {
            return 0;
        }

        $cache    = \Config\Services::cache();
        $cacheKey = "obj_signal_cd_{$moverId}_{$nearest['instance_id']}";
        if ($cache->get($cacheKey) !== null) {
            return 0; // на кулдауне по этой паре (player, instance)
        }

        $dir = $this->compass($nearest['dx'], $nearest['dy']);
        if ($this->sendSignal($moverId, $nearest['name'], $dir, $nearest['dist'], $range)) {
            $cache->save($cacheKey, time(), max(1, $cooldown));
            return 1;
        }
        return 0;
    }

    /**
     * Ближайший active strategic-объект в bounding box + Евклидов фильтр.
     *
     * @return array{instance_id:int,name:string,dx:int,dy:int,dist:int}|null
     */
    private function nearestActiveStrategic(int $x, int $y, int $range): ?array
    {
        try {
            $db    = Database::connect();
            $query = $db->table('biome_world_object_map bwom')
                ->select('bwom.id AS instance_id, wo.name, m.coordinate_x, m.coordinate_y')
                ->join('world_objects wo', 'wo.id = bwom.world_object_id')
                ->join('map m', 'm.id = bwom.map_id')
                ->where('bwom.status', 'active')
                ->whereIn('wo.name_en', WorldObjectGeneratorHandler::STRATEGIC_SPAWN_TYPES)
                ->where('m.coordinate_x >=', $x - $range)
                ->where('m.coordinate_x <=', $x + $range)
                ->where('m.coordinate_y >=', $y - $range)
                ->where('m.coordinate_y <=', $y + $range)
                ->get();
            if ($query === false) {
                return null;
            }
            /** @var list<array<string,mixed>> $list */
            $list = $query->getResultArray();
        } catch (Throwable $e) {
            log_message('error', '[ObjectSignalService] query failed: ' . $e->getMessage());
            return null;
        }

        $rangeSq = $range * $range;
        /** @var array{instance_id:int,name:string,dx:int,dy:int,distSq:int}|null $best */
        $best = null;
        foreach ($list as $r) {
            $ox = is_numeric($r['coordinate_x'] ?? null) ? (int) $r['coordinate_x'] : 0;
            $oy = is_numeric($r['coordinate_y'] ?? null) ? (int) $r['coordinate_y'] : 0;
            $dx = $ox - $x;
            $dy = $oy - $y;
            $distSq = ($dx * $dx) + ($dy * $dy);
            if ($distSq === 0) {
                continue; // персонаж на самой клетке объекта → это discovery, не сигнал
            }
            if ($distSq > $rangeSq) {
                continue; // вне Евклидова радиуса
            }
            if ($best === null || $distSq < $best['distSq']) {
                $best = [
                    'instance_id' => is_numeric($r['instance_id'] ?? null) ? (int) $r['instance_id'] : 0,
                    'name'        => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : 'неизвестный объект',
                    'dx'          => $dx,
                    'dy'          => $dy,
                    'distSq'      => $distSq,
                ];
            }
        }

        if ($best === null || $best['instance_id'] <= 0) {
            return null;
        }

        return [
            'instance_id' => $best['instance_id'],
            'name'        => $best['name'],
            'dx'          => $best['dx'],
            'dy'          => $best['dy'],
            'dist'        => (int) round(sqrt((float) $best['distSq'])),
        ];
    }

    /**
     * 8-румбовый компас от вектора (dx, dy). dy: ось Y растёт на ЮГ — как
     * {@see \App\TaskHandlers\MarchingTaskHandler}::DIRECTIONS (north => [0,-1]).
     * Возвращает ключ из {@see DIR_LABEL}. public — для прямого юнит-теста.
     */
    public function compass(int $dx, int $dy): string
    {
        $ax = abs($dx);
        $ay = abs($dy);
        $ns = $dy < 0 ? 'north' : ($dy > 0 ? 'south' : '');
        $ew = $dx > 0 ? 'east' : ($dx < 0 ? 'west' : '');

        if ($ns === '' && $ew === '') {
            return 'north'; // вырожденный (не должно случаться: distSq==0 отфильтрован)
        }
        if ($ns === '') {
            return $ew;
        }
        if ($ew === '') {
            return $ns;
        }
        if ($ax > $ay * 2) {
            return $ew; // доминирует горизонталь → кардинальное З/В
        }
        if ($ay > $ax * 2) {
            return $ns; // доминирует вертикаль → кардинальное С/Ю
        }
        return $ns . $ew; // диагональ: northeast / southwest / …
    }

    /**
     * protected — тесты подменяют доставку (как StubDetector для PlayerDetection,
     * StubTowerAlerts для TowerAlertService). true — если сообщение отправлено.
     */
    protected function sendSignal(int $moverId, string $objectName, string $dir, int $dist, int $range): bool
    {
        $chatId = $this->moverChatId($moverId);
        if ($chatId === null) {
            return false;
        }

        $dirLabel = self::DIR_LABEL[$dir] ?? $dir;
        $strength = $this->strengthPhrase($dist, $range);
        $cells    = $this->plural($dist, 'клетка', 'клетки', 'клеток');

        $message = "📡 *Слабый сигнал в эфире…*\n\n"
            . "Откуда-то {$dirLabel} пробивается сигнал — похоже, там *{$objectName}*.\n"
            . "{$strength}: ~{$dist} {$cells}.\n\n"
            . "Дойди на саму клетку — там появится кнопка *«🔍 Обыскать»* (для некоторых объектов нужен инструмент).\n"
            . '_Источник умолкнет, как только кто-то доберётся туда первым._';

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'],
                ],
            ],
        ];

        try {
            $resp = Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $message,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
            return $resp->isOk();
        } catch (Throwable $e) {
            log_message('error', '[ObjectSignalService] sendSignal failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Качественная сила сигнала по доле дистанции от радиуса. */
    private function strengthPhrase(int $dist, int $range): string
    {
        if ($range <= 0) {
            return '📶 Сигнал слабый';
        }
        $frac = $dist / $range;
        if ($frac <= 0.34) {
            return '📶 Сигнал сильный — совсем близко';
        }
        if ($frac <= 0.67) {
            return '📶 Сигнал крепнет';
        }
        return '📶 Сигнал ещё далёкий';
    }

    private function moverChatId(int $moverId): ?int
    {
        try {
            $db  = Database::connect();
            $row = $db->table('characters c')
                ->select('tu.telegram_id')
                ->join('telegram_users tu', 'tu.id = c.telegram_user_id')
                ->where('c.id', $moverId)
                ->get();
            if ($row === false) {
                return null;
            }
            $r = $row->getRowArray();
            if (! is_array($r) || ! is_numeric($r['telegram_id'] ?? null)) {
                return null;
            }
            $chatId = (int) $r['telegram_id'];
            return $chatId !== 0 ? $chatId : null;
        } catch (Throwable $e) {
            log_message('error', '[ObjectSignalService] moverChatId failed: ' . $e->getMessage());
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
