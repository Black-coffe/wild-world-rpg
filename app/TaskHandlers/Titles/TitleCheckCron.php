<?php

declare(strict_types=1);

namespace App\TaskHandlers\Titles;

use App\Attributes\HandlerKey;
use App\Models\TelegramUserModel;
use App\Services\Player\TitleService;
use App\TaskHandlers\BaseTaskHandler;
use Config\Database;

/**
 * E11 (ADR-112) — выдача титулов (cron-poll state-driven, зеркало AchievementCheckCron).
 *
 * Recurring (Tasks.php everyMinute): для каждого включённого титула set-based SQL находит
 * персонажей, выполнивших условие (level≥ref / наличие достижения) и ещё не получивших,
 * выдаёт (idempotent, первый авто-экипируется) и уведомляет БАТЧЕМ per-player. Throttle
 * `titles.max_awards_per_tick`. Killswitch `titles.enabled` (default OFF → no-op до активации).
 *
 * Класс НЕ final, sendNotice overridable — тесты (без eager telegram-init).
 */
#[HandlerKey(
    key: 'title_check',
    displayName: 'Титулы: выдача по state-условиям (cron-poll)',
    description: 'Recurring (everyMinute): выдаёт титулы по level/achievement-источнику, батч-уведомление per-player, cap per tick, первый авто-экип. Killswitch titles.enabled. ADR-112.',
)]
class TitleCheckCron extends BaseTaskHandler
{
    protected TitleService $service;
    protected TelegramUserModel $telegramUserModel;

    public function __construct(?TitleService $service = null)
    {
        $this->service           = $service ?? new TitleService();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->service->enabled()) {
            return;
        }

        $cap     = $this->service->maxAwardsPerTick();
        $awarded = 0;

        /** @var array<int, list<array<int|string,mixed>>> $perPlayer charId => list of title rows */
        $perPlayer = [];

        foreach ($this->service->definitions() as $title) {
            if ($awarded >= $cap) {
                break;
            }
            $titleId = is_numeric($title['id'] ?? null) ? (int) $title['id'] : 0;
            if ($titleId <= 0) {
                continue;
            }

            $remaining = $cap - $awarded;
            $charIds   = $this->service->qualifyingCharacterIds($title, $remaining);

            foreach ($charIds as $charId) {
                if ($awarded >= $cap) {
                    break;
                }
                if ($this->service->award($charId, $titleId)) {
                    $awarded++;
                    $perPlayer[$charId][] = $title;
                }
            }
        }

        foreach ($perPlayer as $charId => $titleList) {
            $this->notify($charId, $titleList);
        }

        if ($awarded > 0) {
            log_message('info', "[TitleCheck] awarded={$awarded} to " . count($perPlayer) . ' chars');
        }
    }

    /**
     * Батч-уведомление одному персонажу обо всех его новых титулах за тик.
     *
     * @param list<array<int|string,mixed>> $titleList
     */
    protected function notify(int $characterId, array $titleList): void
    {
        if (empty($titleList)) {
            return;
        }

        $db   = Database::connect();
        $cRes = $db->table('characters')->select('telegram_user_id')->where('id', $characterId)->get();
        $cRow = $cRes === false ? null : $cRes->getRowArray();
        if (! is_array($cRow)) {
            return;
        }
        $tgUserId = is_numeric($cRow['telegram_user_id'] ?? null) ? (int) $cRow['telegram_user_id'] : 0;
        if ($tgUserId <= 0) {
            return;
        }
        $tg = $this->telegramUserModel->find($tgUserId);
        if (! is_array($tg) || empty($tg['telegram_id'])) {
            return;
        }
        $chatId = is_numeric($tg['telegram_id']) ? (int) $tg['telegram_id'] : 0;
        if ($chatId === 0) {
            return;
        }

        $plural = count($titleList) > 1 ? 'Новые титулы' : 'Новый титул';
        $msg    = "🎖 *{$plural}!*\n\n";
        foreach ($titleList as $t) {
            $icon = is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖';
            $name = is_string($t['name'] ?? null) ? $t['name'] : '';
            $msg .= "{$icon} *{$name}*\n";
        }
        $msg .= "\n_Выбрать активный титул — «🎖 Титулы» в карточке Персонажа._";

        $this->safeSendMessage($chatId, $msg, ['parse_mode' => 'Markdown']);
    }
}
