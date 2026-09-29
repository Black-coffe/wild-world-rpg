<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\ResourceModel;
use App\Services\Bases\BaseCallbackSuffix;

/**
 * v0.51.58 (UpgradeBuildingAction decomp Step 2) — extract Markdown templates
 * у dedicated formatter service.
 *
 * Усі методи повертають sendMessage payload-array:
 *   ['text' => string, 'parse_mode'? => 'Markdown', 'reply_markup'? => string]
 *
 * Templates:
 *  - userOrCharacterNotFound() — простий error
 *  - buildingIdMissingAsk() / buildingIdMissingConfirm() — parse errors
 *  - missingResourcesAsk(nextLevel, lines) — multi-line list (askForUpgrade UX)
 *  - missingResourcesConfirm(firstLine) — first-fail style (confirmUpgrade UX)
 *  - simpleError(msg) — generic wrapper для validator $error string
 *  - askPrompt(...) — happy path з requirements list + inline buttons (подтверждение с уровнем, confirmCallback();
 *    строка эффекта «сейчас → после» — effectLine())
 *  - markdownError(msg) — отказ ядра, текст которого несёт Markdown бота (переезд базы)
 *  - upgradeSuccess(buildingNameRu, currentLevel, nextLevel) — final notification
 *
 * Resource name resolution (name_en → name_ru) injected через ResourceModel
 * для self-contained askPrompt builder.
 */
class BuildingUpgradeMessageFormatter
{
    private ResourceModel $resourceModel;

    public function __construct(?ResourceModel $resourceModel = null)
    {
        $this->resourceModel = $resourceModel ?? new ResourceModel();
    }

    /** @return array{text: string} */
    public function userOrCharacterNotFound(): array
    {
        return ['text' => 'Пользователь или персонаж не найден.'];
    }

    /** @return array{text: string} */
    public function buildingIdMissingAsk(): array
    {
        return ['text' => 'Не удалось определить ID здания для апгрейда.'];
    }

    /** @return array{text: string} */
    public function buildingIdMissingConfirm(): array
    {
        return ['text' => 'Не определён ID здания при подтверждении апгрейда.'];
    }

    /**
     * Multi-line missing resources error для askForUpgrade.
     *
     * @param array<string> $missingLines
     * @return array{text: string}
     */
    public function missingResourcesAsk(int $nextLevel, array $missingLines): array
    {
        $msg = "Недостаточно ресурсов для апгрейда до уровня {$nextLevel}:\n"
             . implode("\n", $missingLines);
        return ['text' => $msg];
    }

    /**
     * First-fail style для confirmUpgrade UX.
     *
     * @return array{text: string}
     */
    public function missingResourcesConfirm(string $firstMissingLine): array
    {
        return ['text' => "Не хватает ресурсов: " . $firstMissingLine];
    }

    /** @return array{text: string} */
    public function simpleError(string $msg): array
    {
        return ['text' => $msg];
    }

    /**
     * Happy-path confirm prompt з requirements list + inline buttons.
     *
     * @param array<string,int> $requirementResources name_en => qty
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character (для current level/gold display)
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function askPrompt(
        int $buildingId,
        string $buildingNameRu,
        int $currentLevel,
        int $nextLevel,
        int $requiredCharLvl,
        int $requiredGold,
        array $requirementResources,
        array|\App\Entities\CharacterEntity $character,
        ?int $baseId = null,
        ?string $effectNow = null,
        ?string $effectNext = null
    ): array {
        $msg  = "Вы хотите поднять *{$buildingNameRu}* с уровня {$currentLevel} на уровень {$nextLevel}?";
        // w2-n4-tails-02: что даёт уровень — до оплаты (строка ядра, markdown-safe).
        $msg .= self::effectLine($effectNow, $effectNext);
        $msg .= "\n\nТребуется:\n";
        $msg .= "- Уровень персонажа >= {$requiredCharLvl} (у вас {$character['level']})\n";
        $msg .= "- Золото: {$requiredGold} (у вас {$character['gold']})\n";

        foreach ($requirementResources as $rNameEn => $rQty) {
            $rRow    = $this->resourceModel->where('name_en', $rNameEn)->first();
            $rNameRu = $rRow ? $rRow['name'] : $rNameEn;
            $msg    .= "- {$rNameRu}: {$rQty}\n";
        }
        $msg .= "\nПодтвердите апгрейд?";

        // w2-n4-tails-01: подтверждение несёт уровень «с N» — повторный тап после апгрейда ядро отвергает (`stale`).
        $confirmCallback = self::confirmCallback($buildingId, $currentLevel);
        if ($baseId !== null) {
            $confirmCallback = BaseCallbackSuffix::append($confirmCallback, $baseId);
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить', 'callback_data' => $confirmCallback],
                    // w2-n4-base (ask 5): «назад» — на ту же базу, если она известна, а не в пикер.
                    ['text' => '❌ Отмена',     'callback_data' => $baseId !== null ? BaseCallbackSuffix::append('Base', $baseId) : 'Base'],
                ],
            ],
        ];

        return [
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /**
     * «\n\n✨ Эффект: сейчас → после»; уровень эффект не меняет (флэт-постройки) — «✨ Эффект: X — от уровня
     * не меняется»; эффекта нет — пусто.
     */
    public static function effectLine(?string $effectNow, ?string $effectNext): string
    {
        if ($effectNow === null || $effectNext === null) {
            return '';
        }

        return $effectNow === $effectNext
            ? "\n\n✨ Эффект: {$effectNow} — от уровня не меняется"
            : "\n\n✨ Эффект: {$effectNow} → {$effectNext}";
    }

    /** `confirm_upgrade_building_<id>_l<уровень>`; суффикс базы `_b<id>` дописывает вызывающий. */
    public static function confirmCallback(int $buildingId, int $fromLevel): string
    {
        return "confirm_upgrade_building_{$buildingId}_l{$fromLevel}";
    }

    /**
     * Отказ ядра с Markdown бота (переезд базы — {@see \App\Services\Tasks\ActiveTasksService::TEXT_RELOCATION}).
     *
     * @return array{text: string, parse_mode: string}
     */
    public function markdownError(string $msg): array
    {
        return ['text' => $msg, 'parse_mode' => 'Markdown'];
    }

    /** @return array{text: string, parse_mode: string} */
    public function upgradeSuccess(string $buildingNameRu, int $currentLevel, int $nextLevel): array
    {
        return [
            'text'       => "Поздравляем! «{$buildingNameRu}» поднялось с уровня {$currentLevel} до уровня {$nextLevel}.\n",
            'parse_mode' => 'Markdown',
        ];
    }
}
