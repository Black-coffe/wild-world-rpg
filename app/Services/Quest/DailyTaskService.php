<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Entities\CharacterEntity;
use App\Services\GameSettings\GameSettingsReaderTrait;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * ADR-109 (E8) — сервис ежедневных заданий: ленивая генерация набора за день + baseline-дельта.
 *
 * Дейлик считается по МОНОТОННОМУ счётчику: при назначении снимаем baseline, задание выполнено
 * когда `counter(char,type) − baseline ≥ qty` (см. ADR-109 — почему baseline, а не «за сегодня»).
 *
 * Реюзит счётную вокабуляр ADR-088 (те же таблицы), но НЕ его cumulative-движок. Killswitch
 * `quests.daily.enabled` (default false → dormant). Класс НЕ final, методы overridable — тесты.
 */
class DailyTaskService
{
    use GameSettingsReaderTrait;

    public const TABLE = 'character_daily_tasks';

    /** Активна ли вся фича (killswitch). */
    public function enabled(): bool
    {
        return $this->gsBool('quests.daily.enabled', false);
    }

    /** Разовый бонус золота за весь набор (для витрины экрана; источник истины — handler). */
    public function allDoneBonusGold(): int
    {
        return $this->gsInt('quests.daily.all_done_bonus_gold', 500);
    }

    /**
     * ADR-109 Ф2 — webhook-хук: ленивое назначение набора за день по Telegram-id игрока +
     * one-shot интро-подсказка новичку (just-in-time, ONBOARDING-COVERAGE). Гейт killswitch
     * внутри ensureAssigned/maybeSend; defensive — вызывается из BotController::webhook.
     *
     * $telegramId — Telegram-сторона (`telegram_users.telegram_id`), НЕ внутренний PK; резолвим
     * персонажа join'ом (как LoginStreakService::resolveContext). Тянем ровно нужные поля
     * (id/level/daily_tips_enabled) — плоский array<string,mixed> для ensureAssigned/maybeSend.
     */
    public function ensureForTelegramUser(int $telegramId, int $chatId): void
    {
        if (! $this->enabled() || $telegramId <= 0) {
            return;
        }

        $res = Database::connect()
            ->table('characters c')
            ->select('c.id, c.level, c.daily_tips_enabled')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'inner')
            ->where('tu.telegram_id', $telegramId)
            ->get();
        $row = $res === false ? null : $res->getRowArray();
        if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
            return;
        }

        $this->ensureAssigned($row);

        // Интро-подсказка про дейлики: one-shot, level-gated (≤max_level), opt-out — фича
        // обучаема и находима БЕЗ предзнаний (ONBOARDING-COVERAGE). Ветераны (>max_level)
        // находят через кнопку «🗓 Задания дня» на карточке Перса (UX-DISCOVERABILITY),
        // без масс-пинга всей популяции при активации.
        (new \App\Services\Onboarding\OnboardingHintService())->maybeSendDailyTasksTip($row, $chatId);
    }

    /**
     * Текущее значение МОНОТОННОГО счётчика для дейлик-типа (никогда не убывает).
     *
     * @param BaseConnection<object, object>|null $db коннект (для тестов/реюза); по умолчанию default.
     */
    public function counter(int $charId, string $objectiveType, ?BaseConnection $db = null): int
    {
        $db = $db ?? Database::connect();

        switch ($objectiveType) {
            case 'd_explore':
                return (int) $db->table('explored_cells')->where('character_id', $charId)->countAllResults();

            case 'd_craft':
                return (int) $db->table('crafted_items_log')->where('character_id', $charId)->countAllResults();

            case 'd_sell':
                return (int) $db->table('action_log')
                    ->where('character_id', $charId)
                    ->whereIn('action_name', ['SELL_RESOURCE', 'BULK_SELL'])
                    ->countAllResults();

            case 'd_npc_kill':
                $r = $db->table('characters')->select('npc_kills')->where('id', $charId)->get();
                $row = $r === false ? null : $r->getRowArray();
                return is_array($row) && is_numeric($row['npc_kills'] ?? null) ? (int) $row['npc_kills'] : 0;

            case 'd_battle_win':
                return (int) $db->table('battle_logs')->where('winner_id', $charId)->countAllResults();

            default:
                return 0;
        }
    }

    /**
     * Ленивая генерация набора за сегодня (идемпотентно). Гейты: killswitch + наличие.
     * Выбор шаблонов детерминирован по (char, дата, key) — без mt_rand (стабильно для тестов,
     * варьируется по дням). baseline снимается на момент назначения.
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return bool назначены/уже есть (true) или фича выключена (false)
     */
    public function ensureAssigned(array|CharacterEntity $character): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $idRaw  = $character['id'] ?? 0;
        $charId = is_numeric($idRaw) ? (int) $idRaw : 0;
        if ($charId <= 0) {
            return false;
        }
        $lvlRaw   = $character['level'] ?? 0;
        $level    = is_numeric($lvlRaw) ? (int) $lvlRaw : 0;
        $taskDate = $this->currentDate();
        $db       = Database::connect();

        // Идемпотентность: набор за сегодня уже есть.
        if ($db->table(self::TABLE)->where('character_id', $charId)->where('task_date', $taskDate)->countAllResults() > 0) {
            return true;
        }

        $count    = max(1, $this->gsInt('quests.daily.count', 3));
        $mult     = $this->gsFloat('quests.daily.reward_multiplier', 1.0);
        $picked   = $this->pick($charId, $taskDate, $level, $count);
        $now      = date('Y-m-d H:i:s');

        $slot = 1;
        foreach ($picked as $tpl) {
            $type = $tpl['objective_type'];
            $db->table(self::TABLE)->insert([
                'character_id'   => $charId,
                'task_date'      => $taskDate,
                'slot'           => $slot,
                'task_key'       => $tpl['key'],
                'objective_type' => $type,
                'objective_qty'  => DailyTaskCatalog::qtyFor($tpl['key'], $level),
                'baseline'       => $this->counter($charId, $type, $db),
                'reward_gold'    => (int) round(DailyTaskCatalog::goldFor($tpl['key'], $level) * $mult),
                'is_completed'   => 0,
                'created_at'     => $now,
            ]);
            $slot++;
        }

        return true;
    }

    /**
     * Детерминированный выбор $count РАЗНЫХ доступных шаблонов: сортировка по crc32(char.date.key).
     *
     * @return list<array{key:string, category:string, objective_type:string, min_level:int, title:string, verb:string, qty:array{int,int,int}, gold:array{int,int,int}}>
     */
    public function pick(int $charId, string $taskDate, int $level, int $count): array
    {
        $available = DailyTaskCatalog::availableFor($level);
        usort($available, static function (array $a, array $b) use ($charId, $taskDate): int {
            $ka = crc32($charId . $taskDate . $a['key']);
            $kb = crc32($charId . $taskDate . $b['key']);
            return $ka <=> $kb;
        });

        return array_slice($available, 0, $count);
    }

    /**
     * Сегодняшние задания персонажа с вычисленным прогрессом (current − baseline, cap qty).
     *
     * @return list<array{
     *   id:int, slot:int, task_key:string, title:string, verb:string,
     *   objective_qty:int, progress:int, is_completed:bool, reward_gold:int
     * }>
     */
    public function today(int $charId): array
    {
        $db   = Database::connect();
        $rows = $db->table(self::TABLE)
            ->where('character_id', $charId)
            ->where('task_date', $this->currentDate())
            ->orderBy('slot', 'ASC')
            ->get();
        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows->getResultArray() as $r) {
            $type     = is_string($r['objective_type'] ?? null) ? $r['objective_type'] : '';
            $qty      = is_numeric($r['objective_qty'] ?? null) ? (int) $r['objective_qty'] : 1;
            $baseline = is_numeric($r['baseline'] ?? null) ? (int) $r['baseline'] : 0;
            $done     = (int) ($r['is_completed'] ?? 0) === 1;
            $progress = $done ? $qty : max(0, min($qty, $this->counter($charId, $type, $db) - $baseline));
            $tpl      = DailyTaskCatalog::find(is_string($r['task_key'] ?? null) ? $r['task_key'] : '');

            $out[] = [
                'id'            => is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0,
                'slot'          => is_numeric($r['slot'] ?? null) ? (int) $r['slot'] : 0,
                'task_key'      => is_string($r['task_key'] ?? null) ? $r['task_key'] : '',
                'title'         => $tpl['title'] ?? ('?' . ($r['task_key'] ?? '')),
                'verb'          => $tpl['verb'] ?? '',
                'objective_qty' => $qty,
                'progress'      => $progress,
                'is_completed'  => $done,
                'reward_gold'   => is_numeric($r['reward_gold'] ?? null) ? (int) $r['reward_gold'] : 0,
            ];
        }

        return $out;
    }

    /** Серверная дата (overridable для тестов). */
    public function currentDate(): string
    {
        return date('Y-m-d');
    }
}
