<?php

namespace App\Services\Player\BuildingUpgrade;

use App\Models\ResourceModel;

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
 *  - askPrompt(...) — happy path з requirements list + inline buttons
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
        array|\App\Entities\CharacterEntity $character
    ): array {
        $msg  = "Вы хотите поднять *{$buildingNameRu}* с уровня {$currentLevel} на уровень {$nextLevel}?";
        $msg .= "\n\nТребуется:\n";
        $msg .= "- Уровень персонажа >= {$requiredCharLvl} (у вас {$character['level']})\n";
        $msg .= "- Золото: {$requiredGold} (у вас {$character['gold']})\n";

        foreach ($requirementResources as $rNameEn => $rQty) {
            $rRow    = $this->resourceModel->where('name_en', $rNameEn)->first();
            $rNameRu = $rRow ? $rRow['name'] : $rNameEn;
            $msg    .= "- {$rNameRu}: {$rQty}\n";
        }
        $msg .= "\nПодтвердите апгрейд?";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить', 'callback_data' => "confirm_upgrade_building_{$buildingId}"],
                    ['text' => '❌ Отмена',     'callback_data' => 'Base'],
                ],
            ],
        ];

        return [
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
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
