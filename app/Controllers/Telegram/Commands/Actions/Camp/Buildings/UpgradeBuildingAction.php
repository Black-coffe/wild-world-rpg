<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\Endgame\EndgameProgressionService;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeMessageFormatter;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
use Config\BuildingUpgrades;

/**
 * Двухэтапный апгрейд здания:
 *   1) askForUpgrade()  -> Показываем требования, кнопку «Подтвердить»
 *   2) confirmUpgrade() -> Если подтверждено, списываем ресурсы и повышаем уровень
 */
class UpgradeBuildingAction extends BaseAction
{
    protected BuildingUpgradeValidator $validator;
    protected BuildingUpgradeMessageFormatter $formatter;
    protected BuildingUpgradeApplier $applier;
    protected BuildingUpgrades $upgrades;
    protected EndgameProgressionService $endgameService;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        // v0.51.62 (Step 5 polish) — services use null-default ctor pattern
        // (each instantiates own model deps), Action no longer holds direct
        // model refs. Steps 1-4 history: Validator (v0.51.57) + Formatter
        // (v0.51.58) + Applier (v0.51.60) + Config\BuildingUpgrades (v0.51.61).
        $this->validator      = new BuildingUpgradeValidator();
        $this->formatter      = new BuildingUpgradeMessageFormatter();
        $this->applier        = new BuildingUpgradeApplier();
        $this->upgrades       = config(BuildingUpgrades::class);
        $this->endgameService = new EndgameProgressionService();
    }

    /**
     * Helper (v0.51.58): send message via Request::sendMessage з payload-array.
     *
     * @param array<string,mixed> $payload
     */
    private function send(int|string $chatId, array $payload): ServerResponse
    {
        return Request::sendMessage(array_merge(['chat_id' => $chatId], $payload));
    }

    /**
     * Обязательный метод из BaseAction.
     * По умолчанию направляем пользователя в askForUpgrade().
     */
    public function handle(): ServerResponse
    {
        // Можно выводить сообщение об ошибке или перенаправлять в askForUpgrade().
        // Для удобства вызовем "шаг 1" как поведение «по умолчанию».
        return $this->askForUpgrade();
    }

    /**
     * Шаг 1: проверяем возможность апгрейда и предлагаем подтверждение (кнопка).
     *
     * v0.51.57 (Step 1): validation chain extracted у BuildingUpgradeValidator.
     */
    public function askForUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->send($chatId, $this->formatter->userOrCharacterNotFound());
        }

        // Active relocation block (handled у service зі своїм response)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Parse buildingId з callback_data ("upgrade_building_4")
        $parts      = explode('_', $this->callbackQuery->getData());
        $buildingId = $parts[2] ?? null;
        if (!$buildingId) {
            return $this->send($chatId, $this->formatter->buildingIdMissingAsk());
        }

        // Run full validation
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgrades->requirements);

        if (!$res['ok']) {
            if (!empty($res['missingResources'])) {
                return $this->send($chatId, $this->formatter->missingResourcesAsk(
                    (int) ($res['nextLevel'] ?? 0),
                    $res['missingResources']
                ));
            }
            return $this->send($chatId, $this->formatter->simpleError($res['error']));
        }

        // Validated → build confirm prompt
        $ctx          = $res['context'];
        $buildingInfo = $ctx['buildingInfo'];
        $req          = $ctx['requirements'];

        $buildingNameRu = $buildingInfo['name_ru'] ?? "ID={$buildingId}";

        // Скрываем "загрузка" (answerCallbackQuery)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Проверка завершена.',
            'show_alert'        => false,
        ]);

        return $this->send($chatId, $this->formatter->askPrompt(
            (int) $buildingId,
            $buildingNameRu,
            $ctx['currentLevel'],
            $ctx['nextLevel'],
            (int) $req['level'],
            (int) $req['gold'],
            $req['resources'],
            $character
        ));
    }

    /**
     * Шаг 2: пользователь подтвердил апгрейд (callback_data: "confirm_upgrade_building_X")
     */
    public function confirmUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->send($chatId, $this->formatter->userOrCharacterNotFound());
        }

        // Parse buildingId з callback_data: "confirm_upgrade_building_4"
        $parts      = explode('_', $this->callbackQuery->getData());
        $buildingId = $parts[3] ?? null;
        if (!$buildingId) {
            return $this->send($chatId, $this->formatter->buildingIdMissingConfirm());
        }

        // v0.51.57 — re-validate всю chain (resources могли поменяться після ask)
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgrades->requirements);
        if (!$res['ok']) {
            if (!empty($res['missingResources'])) {
                return $this->send($chatId, $this->formatter->missingResourcesConfirm(
                    $res['missingResources'][0]
                ));
            }
            return $this->send($chatId, $this->formatter->simpleError($res['error']));
        }

        $ctx          = $res['context'];
        $charBuilding = $ctx['charBuilding'];
        $buildingInfo = $ctx['buildingInfo'];
        $currentLevel = $ctx['currentLevel'];
        $nextLevel    = $ctx['nextLevel'];
        $req          = $ctx['requirements'];

        // v0.51.60 (Step 3) — apply chain extracted у BuildingUpgradeApplier
        $this->applier->apply($character, $charBuilding, $nextLevel, $req);

        // v0.51.112 endgame hook: building upgrade → faction score.
        $buildingNameEn = null;
        if (isset($buildingInfo['name_eng']) && is_string($buildingInfo['name_eng'])) {
            $buildingNameEn = $buildingInfo['name_eng'];
        } elseif (isset($buildingInfo['name_en']) && is_string($buildingInfo['name_en'])) {
            $buildingNameEn = $buildingInfo['name_en'];
        }
        if ($buildingNameEn !== null) {
            $this->endgameService->recordBuildingUpgrade($buildingNameEn);

            // v0.51.118 quest hook: FarmersHarvest auto-completion.
            // Greenhouse upgrade → lvl 3+ марк active FarmersHarvest quest done.
            if ($buildingNameEn === 'Greenhouse' && $nextLevel >= 3) {
                $this->checkFarmersHarvestQuest((int) $character['id']);
            }
        }

        // Скрываем alert у кнопки
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Улучшение выполнено!',
            'show_alert'        => false,
        ]);

        $buildingNameRu = $buildingInfo['name_ru'] ?? "Здание #{$buildingId}";
        return $this->send($chatId, $this->formatter->upgradeSuccess(
            $buildingNameRu,
            $currentLevel,
            $nextLevel
        ));
    }

    /**
     * v0.51.118: Auto-complete FarmersHarvest quest на Greenhouse upgrade lvl 3+.
     */
    private function checkFarmersHarvestQuest(int $characterId): void
    {
        $questModel      = new QuestModel();
        $questStepsModel = new QuestStepsModel();
        $characterModel  = new CharacterModel();

        $quest = $questModel->where('title_en', 'FarmersHarvest')->first();
        if (!$quest) {
            return;
        }

        $step = $questStepsModel
            ->where('quest_id', $quest['id'])
            ->where('character_id', $characterId)
            ->where('is_completed', 0)
            ->first();
        if (!$step) {
            return;
        }

        $questStepsModel->update($step['id'], ['is_completed' => 1]);

        $reward = (int) ($quest['reward'] ?? 0);
        if ($reward > 0) {
            $characterModel->increaseGold($characterId, $reward);
        }

        $this->endgameService->recordQuestCompletion($characterId);

        log_message('info', "[UpgradeBuildingAction] Auto-completed FarmersHarvest for char_id={$characterId} (+{$reward} gold)");
    }
}
