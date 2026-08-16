<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\ActionLogModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Telegram\Request;

/**
 * ADR-104 Фаза 3b — гарантированный «момент удачи» на ПЕРВЫЙ ход новичка.
 *
 * One-shot находка (золото + Железная руда) + флавор-сообщение при первом move'е
 * новичка (хук в MoveCharacterToDirectionAction рядом с подсказкой Слоя 1). Триггер
 * именно на движение — прямой удар по «58% новичков без единого хода» (E1/E5):
 * награда за действие, которого мы хотим, закрепляет поведение.
 *
 * Гейты: killswitch `onboarding.lucky_find.enabled` (default false → dormant) +
 * level ≤ `onboarding.contextual_hints.max_level` (реюз, default 6). Идемпотентность:
 * флаг `LUCKY_FIND_FIRST` в action_log (action_status строго из enum — урок E3/m8k2b).
 *
 * MEDIA-OFF (ADR-020): сообщение текстовое, весь смысл в тексте.
 *
 * Overridable seam'ы (тесты без БД на CI): enabled / maxLevel / amount / alreadyGranted
 * / grantGold / grantResource / writeFlag / send.
 *
 * @see [[ADR-104-First-30-minutes-density]]
 */
class LuckyFindService
{
    use GameSettingsReaderTrait;

    /** action_log.action_name флага «находка выдана» (idempotency). */
    public const GRANTED_FLAG = 'LUCKY_FIND_FIRST';

    /** Железная руда (resources.id), rarity 7, level_required 2 — «ценный хлам». */
    private const IRONSTONE_ID = 72;

    public function __construct(
        private readonly ?CharacterModel $characters = null,
        private readonly ?CharacterResourceModel $resources = null,
        private readonly ?ActionLogModel $log = null,
    ) {
    }

    /**
     * Выдать «момент удачи» на первый ход новичка. Возвращает true, если находка
     * выдана (иначе no-op: выключено / ветеран / уже выдано / нечего выдавать).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeGrantFirstMove(array|CharacterEntity $character, int $chatId): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if (self::intField($character, 'level') > $this->maxLevel()) {
            return false;
        }
        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyGranted($charId)) {
            return false;
        }

        $gold      = $this->amount('onboarding.lucky_find.gold', 300);
        $ironstone = $this->amount('onboarding.lucky_find.ironstone', 5);
        if ($gold <= 0 && $ironstone <= 0) {
            return false; // нечего выдавать — флаг не пишем
        }

        if ($gold > 0) {
            $this->grantGold($charId, $gold);
        }
        if ($ironstone > 0) {
            $this->grantResource($charId, self::IRONSTONE_ID, $ironstone);
        }

        $this->writeFlag($charId, $chatId);
        $this->send([
            'chat_id'                  => $chatId,
            'text'                     => $this->buildText($gold, $ironstone),
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);

        return true;
    }

    // ── Overridable seam'ы ───────────────────────────────────────────────────

    protected function enabled(): bool
    {
        return $this->gsBool('onboarding.lucky_find.enabled', false);
    }

    protected function maxLevel(): int
    {
        return $this->gsInt('onboarding.contextual_hints.max_level', 6);
    }

    protected function amount(string $key, int $default): int
    {
        return max(0, $this->gsInt($key, $default));
    }

    protected function alreadyGranted(int $charId): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::GRANTED_FLAG)
            ->countAllResults() > 0;
    }

    protected function grantGold(int $charId, int $gold): void
    {
        ($this->characters ?? new CharacterModel())->increaseGold($charId, (float) $gold);
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        ($this->resources ?? new CharacterResourceModel())->addOrIncreaseResource($charId, $resourceId, $amount);
    }

    protected function writeFlag(int $charId, int $chatId): void
    {
        // action_status строго из enum('Pending','Completed','Skipped','REJECTED').
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::GRANTED_FLAG,
            'action_status' => 'Completed',
            'description'   => 'ADR-104 Ф3b: момент удачи (находка) на первый ход',
        ]);
    }

    /**
     * Overridable seam отправки (тесты подменяют — без Telegram на CI).
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    // ── Текст ────────────────────────────────────────────────────────────────

    private function buildText(int $gold, int $ironstone): string
    {
        $lines = '';
        if ($gold > 0) {
            $lines .= "• 🪙 Монеты ×{$gold}\n";
        }
        if ($ironstone > 0) {
            $lines .= "• ⛏ Железная руда ×{$ironstone}\n";
        }

        return "✨ *Удачная находка!*\n\n"
            . "Под ржавым хламом блеснул чей-то тайник — видимо, прежний выживший "
            . "припрятал и не вернулся.\n\n"
            . "Ты забрал:\n"
            . $lines . "\n"
            . "Двигайся дальше — пустошь полна таких сюрпризов.";
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }

    /**
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function intField(array|CharacterEntity $character, string $field): int
    {
        $raw = $character[$field] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
