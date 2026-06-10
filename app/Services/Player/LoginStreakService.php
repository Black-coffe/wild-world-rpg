<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;
use Longman\TelegramBot\Request;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 3 — стрик входа (мягко, без FOMO).
 *
 * На ПЕРВОМ взаимодействии нового дня игрок получает награду за серию входов подряд.
 * Триггер — {@see \App\Controllers\Telegram\BotController::webhook()} ДО `handle()`
 * (чтобы карточка Перса, если это первое действие, показала уже обновлённую серию).
 *
 * **Мягко, без FOMO:** пропуск до `grace_days` дней НЕ обнуляет серию; награда —
 * золото (base + bonus×(день−1), cap). Серия привязана к РЕАЛЬНОМУ входу (а не к
 * крон-часу) — выдача на первом действии дня, не рассылкой.
 *
 * Гейты: killswitch `returnability.streak.enabled` (default false → dormant). One-shot
 * per день: `login_streak_last_day == today` → no-op.
 *
 * UX-discoverability: серия видна в карточке Перса ({@see streakLine()}) — доступна всем,
 * lock-state не нужен.
 *
 * Overridable seam'ы (unit без БД/Telegram): enabled / graceDays / baseGold / bonusPerDay
 * / maxGold / today / resolveContext / persist / grantGold / send. Чистые computeStreak /
 * computeReward тестируются напрямую.
 *
 * @see [[ADR-108-Player-returnability-digest-streaks]]
 */
class LoginStreakService
{
    use GameSettingsReaderTrait;

    /**
     * Главная точка (из webhook): если это первый вход за сегодня — продвинуть серию
     * и выдать награду.
     */
    public function maybeReward(int $telegramId, int $chatId): void
    {
        if ($telegramId <= 0 || $chatId === 0 || ! $this->enabled()) {
            return;
        }

        $ctx = $this->resolveContext($telegramId);
        if ($ctx === null) {
            return;
        }
        $charId   = $ctx['char_id'];
        $lastDay  = $ctx['last_day'];
        $oldStreak = $ctx['streak'];

        $today = $this->today();
        if ($lastDay === $today) {
            return; // уже засчитан сегодня
        }

        $newStreak = self::computeStreak($lastDay, $today, $oldStreak, $this->graceDays());
        $reward    = self::computeReward($newStreak, $this->baseGold(), $this->bonusPerDay(), $this->maxGold());

        $this->persist($charId, $newStreak, $today);
        if ($reward > 0) {
            $this->grantGold($charId, $reward);
        }

        $this->send([
            'chat_id'                  => $chatId,
            'text'                     => self::buildText($newStreak, $reward),
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Строка серии для карточки Перса (null, если выключено или серии ещё нет).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function streakLine(array|CharacterEntity $character): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $raw    = $character['login_streak'] ?? 0;
        $streak = is_numeric($raw) ? (int) $raw : 0;
        if ($streak <= 0) {
            return null;
        }
        return '🔥 *Серия входов:* ' . $streak . ' ' . self::plural($streak, 'день', 'дня', 'дней');
    }

    // ── Чистая логика ────────────────────────────────────────────────────────

    /**
     * Новая длина серии. lastDay null → 1 (первый вход). Пропуск ≤ grace_days дней
     * продолжает серию (+1); больше — сброс к 1. Защита от обратного хода часов.
     */
    public static function computeStreak(?string $lastDay, string $today, int $oldStreak, int $graceDays): int
    {
        $old = max(0, $oldStreak);
        if ($lastDay === null || $lastDay === '') {
            return 1;
        }
        $lastTs  = strtotime($lastDay);
        $todayTs = strtotime($today);
        if ($lastTs === false || $todayTs === false) {
            return 1;
        }
        $daysSince = intdiv($todayTs - $lastTs, 86400);
        if ($daysSince <= 0) {
            return max(1, $old); // тот же день / часы назад — без изменений
        }
        if ($daysSince <= 1 + max(0, $graceDays)) {
            return $old + 1; // подряд или в пределах grace
        }
        return 1; // серия прервана
    }

    /** Награда за день серии: base + bonus×(streak−1), но не выше cap. */
    public static function computeReward(int $streak, int $base, int $bonus, int $maxGold): int
    {
        $streak = max(1, $streak);
        $reward = $base + $bonus * ($streak - 1);
        if ($maxGold > 0 && $reward > $maxGold) {
            $reward = $maxGold;
        }
        return max(0, $reward);
    }

    // ── Overridable seam'ы ───────────────────────────────────────────────────

    protected function enabled(): bool
    {
        return $this->gsBool('returnability.streak.enabled', false);
    }

    protected function graceDays(): int
    {
        return max(0, $this->gsInt('returnability.streak.grace_days', 1));
    }

    protected function baseGold(): int
    {
        return max(0, $this->gsInt('returnability.streak.base_gold', 50));
    }

    protected function bonusPerDay(): int
    {
        return max(0, $this->gsInt('returnability.streak.bonus_per_day', 25));
    }

    protected function maxGold(): int
    {
        return max(0, $this->gsInt('returnability.streak.max_gold', 500));
    }

    /** Серверная дата 'Y-m-d' (seam — инжектируется в тестах). */
    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * @return array{char_id:int,streak:int,last_day:?string}|null
     */
    protected function resolveContext(int $telegramId): ?array
    {
        try {
            $res = Database::connect()
                ->table('characters c')
                ->select('c.id AS char_id, c.login_streak AS streak, c.login_streak_last_day AS last_day')
                ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'inner')
                ->where('tu.telegram_id', $telegramId)
                ->get();
            $row = $res === false ? null : $res->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', '[LoginStreakService] resolveContext: ' . $e->getMessage());
            return null;
        }
        if (! is_array($row) || ! isset($row['char_id']) || ! is_numeric($row['char_id'])) {
            return null;
        }
        $rawStreak = $row['streak'] ?? 0;
        $lastDay   = $row['last_day'] ?? null;
        return [
            'char_id'  => (int) $row['char_id'],
            'streak'   => is_numeric($rawStreak) ? (int) $rawStreak : 0,
            'last_day' => is_string($lastDay) && $lastDay !== '' ? $lastDay : null,
        ];
    }

    protected function persist(int $charId, int $streak, string $today): void
    {
        ($this->charModel())->update($charId, [
            'login_streak'          => $streak,
            'login_streak_last_day' => $today,
        ]);
    }

    protected function grantGold(int $charId, int $gold): void
    {
        $this->charModel()->increaseGold($charId, (float) $gold);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    // ── Текст ────────────────────────────────────────────────────────────────

    public static function buildText(int $streak, int $reward): string
    {
        $rewardLine = $reward > 0 ? "\n\nЗа возвращение — 💰 *{$reward}* золота." : '';

        if ($streak <= 1) {
            return "🔥 *Новый день на острове.*{$rewardLine}\n\n"
                . '_Возвращайся завтра — начнётся серия, и награда будет расти._';
        }

        return "🔥 *Серия входов: {$streak} " . self::plural($streak, 'день', 'дня', 'дней') . " подряд!*{$rewardLine}\n\n"
            . '_Не пропадай — чем длиннее серия, тем щедрее награда._';
    }

    private function charModel(): CharacterModel
    {
        return new CharacterModel();
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }
        return $many;
    }
}
