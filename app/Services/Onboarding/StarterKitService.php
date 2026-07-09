<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\ActionLogModel;
use App\Models\CharacterResourceModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-104 Фаза 1 — «Стартовый набор выжившего».
 *
 * При создании персонажа на /start выдаёт небольшой паёк базовых ресурсов
 * (Вода/Древесина/Кора/Галька) и возвращает текст сообщения от Роби. Бьёт по
 * прод-разрыву №1 (E1/E5): 54% новичков L1 имеют ПУСТОЙ инвентарь и не делают
 * ни одного действия → набор заполняет инвентарь и разблокирует ранний цикл
 * обучающей цепочки (ADR-103: первый крафт/продажа возможны сразу, не
 * дожидаясь 10-минутной добычи).
 *
 * Гейты: killswitch `onboarding.starter_kit.enabled` (default false → dormant).
 * Идемпотентность: грант пишется один раз — флаг STARTER_KIT_GRANTED в
 * action_log (action_status строго из enum, урок E3/m8k2b).
 *
 * MEDIA-OFF (ADR-020): сообщение Роби текстовое, весь смысл в тексте.
 *
 * Overridable seam'ы (тесты подменяют — без БД на CI): enabled / amount /
 * alreadyGranted / grantResource / writeGrantedFlag.
 *
 * @see [[ADR-104-First-30-minutes-density]]
 */
class StarterKitService
{
    /** action_log.action_name флага «набор выдан» (idempotency). */
    public const GRANTED_FLAG = 'STARTER_KIT_GRANTED';

    /**
     * Состав набора: setting_key количества → [resource_id, эмодзи, имя].
     * Порядок = порядок в сообщении Роби.
     *
     * @var array<string, array{0:int,1:string,2:string}>
     */
    private const KIT = [
        'onboarding.starter_kit.water'  => [17, '💧', 'Вода'],
        'onboarding.starter_kit.wood'   => [2,  '🪵', 'Древесина'],
        'onboarding.starter_kit.bark'   => [8,  '🪨', 'Кора деревьев'],
        'onboarding.starter_kit.pebble' => [19, '⚪', 'Галька'],
        // Травы+Водоросли — недостающие инпуты Повязки: снимают ресурс-стену
        // онбординг-шага 3 «Руки помнят» (первый крафт обучающей цепочки).
        'onboarding.starter_kit.herbs'  => [3,  '🌿', 'Травы'],
        'onboarding.starter_kit.algae'  => [20, '🪸', 'Водоросли'],
    ];

    /** Дефолтные количества (на случай отсутствия game_settings-строки). */
    private const DEFAULTS = [
        'onboarding.starter_kit.water'  => 10,
        'onboarding.starter_kit.wood'   => 10,
        'onboarding.starter_kit.bark'   => 8,
        'onboarding.starter_kit.pebble' => 8,
        'onboarding.starter_kit.herbs'  => 4,
        'onboarding.starter_kit.algae'  => 6,
    ];

    public function __construct(
        private readonly ?GameSettingsService $settings = null,
        private readonly ?CharacterResourceModel $resources = null,
        private readonly ?ActionLogModel $log = null,
    ) {
    }

    /**
     * Выдать стартовый набор новому персонажу.
     *
     * @return string|null текст сообщения Роби (Markdown) или null, если набор
     *                     выключен / уже выдан / нечего выдавать.
     */
    public function grant(int $charId, int $tgUserId, int $chatId): ?string
    {
        if ($charId <= 0 || ! $this->enabled()) {
            return null;
        }
        if ($this->alreadyGranted($charId)) {
            return null;
        }

        /** @var array<int, array{0:string,1:string,2:int}> $granted */
        $granted = [];
        foreach (self::KIT as $key => [$resId, $emoji, $title]) {
            $amount = $this->amount($key);
            if ($amount <= 0) {
                continue;
            }
            $this->grantResource($charId, $resId, $amount);
            $granted[] = [$emoji, $title, $amount];
        }

        if ($granted === []) {
            return null;
        }

        $this->writeGrantedFlag($charId, $chatId);

        return $this->buildRobiText($granted);
    }

    // ── Overridable seam'ы ───────────────────────────────────────────────────

    /** Killswitch набора (default false → dormant). */
    protected function enabled(): bool
    {
        return (bool) $this->settingsService()->get('onboarding.starter_kit.enabled', false);
    }

    /** Количество ресурса из game_settings (≥0). */
    protected function amount(string $key): int
    {
        $val = (int) $this->settingsService()->get($key, self::DEFAULTS[$key] ?? 0);

        return max(0, $val);
    }

    /** Был ли набор уже выдан этому чару (idempotency). */
    protected function alreadyGranted(int $charId): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::GRANTED_FLAG)
            ->countAllResults() > 0;
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        $this->resourceModel()->addOrIncreaseResource($charId, $resourceId, $amount);
    }

    protected function writeGrantedFlag(int $charId, int $chatId): void
    {
        // action_status строго из enum('Pending','Completed','Skipped','REJECTED') —
        // STRICT_TRANS_TABLES на проде валит INSERT с любым другим значением.
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::GRANTED_FLAG,
            'action_status' => 'Completed',
            'description'   => 'ADR-104 onboarding: выдан стартовый набор выжившего',
        ]);
    }

    // ── Текст ────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array{0:string,1:string,2:int}> $granted [emoji, title, amount]
     */
    private function buildRobiText(array $granted): string
    {
        $lines = '';
        foreach ($granted as [$emoji, $title, $amount]) {
            $lines .= "• {$emoji} {$title} ×{$amount}\n";
        }

        return "🎒 *Аварийный паёк.*\n\n"
            . "Роби: «Покопался в обломках и собрал тебе на первое время — "
            . "без воды и материала тут не протянуть.»\n\n"
            . "В рюкзаке:\n"
            . $lines . "\n"
            . "Этого хватит на первый крафт или первую продажу. "
            . "Загляни в «🧑 Я», чтобы увидеть запасы.";
    }

    // ── Резолверы зависимостей ───────────────────────────────────────────────

    private function settingsService(): GameSettingsService
    {
        return $this->settings ?? new GameSettingsService();
    }

    private function resourceModel(): CharacterResourceModel
    {
        return $this->resources ?? new CharacterResourceModel();
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }
}
