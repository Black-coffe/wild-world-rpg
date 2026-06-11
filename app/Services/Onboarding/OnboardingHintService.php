<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\ActionLogModel;
use App\Models\ClaimedCellModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Request;

/**
 * ADR-103 Часть B Слой 1 — контекстные one-shot обучающие подсказки (just-in-time).
 *
 * Показывает подсказку РОВНО ОДИН РАЗ на персонажа. Трекинг — через `action_log`
 * (тот же паттерн, что у шагов обучения Robi: проверка существующей записи по
 * `character_id` + `action_name`), поэтому НОВОЙ таблицы/миграции схемы не требуется.
 *
 * Уважает: killswitch `onboarding.contextual_hints.enabled` (GameSettings) и
 * per-char opt-out — тот же тумблер, что у «Совета дня» (`characters.daily_tips_enabled`):
 * если игрок отключил советы, контекстные подсказки тоже молчат.
 *
 * Каталог текстов — {@see OnboardingHintCatalog} (расширяется с каждой механикой,
 * конституционное правило ONBOARDING-COVERAGE).
 */
class OnboardingHintService
{
    use GameSettingsReaderTrait;

    private const LOG_PREFIX = 'OnbHint_';

    public function __construct(private readonly ?ActionLogModel $log = null)
    {
    }

    /**
     * Слой 1 — подсказка «первая база» новичку без базы.
     * Гейты: killswitch + opt-out + не показано + level ≤ max + база ещё не разбита.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstBaseTip(array|CharacterEntity $character, int $chatId): bool
    {
        // Уровневый гейт ПЕРВЫМ (без DB) — ветераны отсекаются на каждом move дёшево,
        // per-character запросы (alreadyShown/hasActiveBase) делаем только для новичков.
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::FIRST_BASE)) {
            return false;
        }

        if ($this->hasActiveBase($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_BASE);
    }

    /**
     * E8 (ADR-109) Ф2 — интро-подсказка про ежедневные задания (just-in-time).
     * Гейты: level ≤ max (только новички — ветераны находят фичу кнопкой «🗓 Задания дня»
     * на карточке Перса, без масс-пинга всей популяции) + killswitch + opt-out + не показано.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendDailyTasksTip(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::DAILY_TASKS);
    }

    /**
     * Базовый one-shot отправитель: killswitch + opt-out + дедуп + отправка + запись.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSend(array|CharacterEntity $character, int $chatId, string $hintKey): bool
    {
        if (! $this->gsBool('onboarding.contextual_hints.enabled', true)) {
            return false;
        }
        if (! self::optedIn($character)) {
            return false;
        }
        $charId = self::intField($character, 'id');
        if ($charId <= 0) {
            return false;
        }

        $hint = OnboardingHintCatalog::get($hintKey);
        if ($hint === null || $this->alreadyShown($charId, $hintKey)) {
            return false;
        }

        $payload = ['chat_id' => $chatId, 'text' => $hint['text'], 'parse_mode' => 'Markdown'];
        if (isset($hint['reply_markup'])) {
            $payload['reply_markup'] = $hint['reply_markup'];
        }
        $this->send($payload);

        $this->recordShown($charId, $chatId, $hintKey);

        return true;
    }

    /**
     * Overridable seam отправки (тесты подменяют, чтобы не дёргать Telegram на CI —
     * memory feedback_taskhandler_telegram_init_in_tests).
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    public function alreadyShown(int $charId, string $hintKey): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::LOG_PREFIX . $hintKey)
            ->countAllResults() > 0;
    }

    protected function recordShown(int $charId, int $chatId, string $hintKey): void
    {
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::LOG_PREFIX . $hintKey,
            // action_status строго из enum('Pending','Completed','Skipped','REJECTED') —
            // 'shown' было вне enum → STRICT_TRANS_TABLES коэрсил в '' (37 строк на проде),
            // а в полном strict-режиме валил бы INSERT → подсказка спамила бы (фикс ADR-104).
            'action_status' => 'Completed',
            'description'   => 'ADR-103 contextual onboarding hint',
        ]);
    }

    protected function hasActiveBase(int $charId): bool
    {
        return (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }

    /**
     * opt-out = тумблер «Совета дня» (daily_tips_enabled); default — включено.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function optedIn(array|CharacterEntity $character): bool
    {
        $raw = $character['daily_tips_enabled'] ?? 1;

        return is_numeric($raw) ? (int) $raw === 1 : true;
    }

    /**
     * Безопасное чтение целочисленного поля персонажа (mixed → int).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function intField(array|CharacterEntity $character, string $field): int
    {
        $raw = $character[$field] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
