<?php

declare(strict_types=1);

namespace App\TaskHandlers\Tips;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\TipService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-038 Фаза C4 — ежедневная авто-рассылка «Совет дня».
 *
 * Tasks.php everyMinute + внутренние guard'ы (как QuestObjectiveHandler):
 *   1. killswitch `tips.daily_enabled` (GameSettings) — off → выход.
 *   2. hour-guard — рассылаем только в час `tips.daily_hour` (серверное время).
 *   3. once/day-guard — cache-маркер с сегодняшней датой (идемпотентность при everyMinute);
 *      ставится ДО цикла отправки → краш в середине не вызывает повторную рассылку тем,
 *      кто уже получил (защита 364 игроков от спама ценой того, что при краше часть
 *      пропустит совет за этот день).
 *
 * Каждому игроку с `daily_tips_enabled=1` (+ есть telegram-связь) — персональный совет
 * из его недедупнутого пула (та же логика, что /tips: {@see TipService}), лог в общий
 * дедуп `character_game_tips` + та же микро-награда. Throttle между сообщениями (Telegram
 * ~30 msg/sec). Send через safeSendMessage (ловит rate-limit/сетевые ошибки, не падает).
 *
 * Daily = серверная дата + час; глобальный once/day guard (не per-timezone игрока) — осознанно (ADR-038).
 */
#[HandlerKey(
    key: 'tips_daily_broadcast',
    displayName: 'Совет дня (ADR-038)',
    description: 'everyMinute + hour/once-day guard: раз в сутки шлёт каждому игроку (opt-in daily_tips_enabled) персональный совет с микро-наградой. Killswitch tips.daily_enabled, час tips.daily_hour.',
)]
class DailyTipBroadcastHandler extends BaseTaskHandler
{
    private const CACHE_KEY = 'tips_daily_last_sent';
    private const CACHE_TTL = 172800; // 2 дня
    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec

    private GameSettingsService $settings;
    private TipService $tips;

    public function __construct(?GameSettingsService $settings = null, ?TipService $tips = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->tips     = $tips ?? new TipService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        // 1. killswitch
        if ($this->settings->get('tips.daily_enabled', true) !== true) {
            return;
        }

        // 2. hour-guard
        $hourRaw = $this->settings->get('tips.daily_hour', 10);
        $hour    = is_numeric($hourRaw) ? (int) $hourRaw : 10;
        if ((int) date('G') !== $hour) {
            return;
        }

        // 3. once/day-guard
        $cache = \Config\Services::cache();
        $today = date('Y-m-d');
        $last  = $cache->get(self::CACHE_KEY);
        if (is_string($last) && $last === $today) {
            return;
        }
        // Помечаем день ДО цикла → защита от повторной рассылки при ре-запуске/краше.
        $cache->save(self::CACHE_KEY, $today, self::CACHE_TTL);

        $this->broadcast();
    }

    private function broadcast(): void
    {
        $this->telegram();

        $query = (new CharacterModel())->builder()
            ->select('characters.id AS character_id, telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.daily_tips_enabled', 1)
            ->get();
        /** @var list<array<string,mixed>> $rows */
        $rows = $query === false ? [] : $query->getResultArray();

        $sent = 0;
        $seen = [];
        foreach ($rows as $row) {
            $charRaw = $row['character_id'] ?? null;
            $tgRaw   = $row['telegram_id'] ?? null;
            if (! is_numeric($charRaw) || ! is_numeric($tgRaw)) {
                continue;
            }
            $charId = (int) $charRaw;
            $tgId   = (int) $tgRaw;
            if ($charId <= 0 || $tgId === 0 || isset($seen[$tgId])) {
                continue;
            }
            $seen[$tgId] = true;

            $tip = $this->tips->serveTip($charId);
            if ($tip === null) {
                continue; // пул советов исчерпан для этого игрока
            }

            $this->sendTip($tgId, TipService::renderTip($tip));
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        log_message('info', "[DailyTipBroadcast] sent {$sent} советов");
    }

    /**
     * Seam для тестов: реальная отправка совета игроку. Переопределяется в db-тесте.
     */
    protected function sendTip(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }
}
