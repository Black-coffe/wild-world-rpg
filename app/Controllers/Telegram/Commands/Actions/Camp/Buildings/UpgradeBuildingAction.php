<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeMessageFormatter;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
use App\Services\Player\PlayerStateService;

/**
 * Двухэтапный апгрейд здания:
 *   1) askForUpgrade()  -> Показываем требования, кнопку «Подтвердить»
 *   2) confirmUpgrade() -> Если подтверждено, списываем ресурсы и повышаем уровень
 */
class UpgradeBuildingAction extends BaseAction
{
    protected $characterBuildingModel;
    protected $buildingModel;
    protected $characterModel;
    protected $characterResourceModel;
    protected $resourceModel;
    protected $playerStateService;

    // v0.51.57 (Step 1) — validation chain extracted у service
    protected BuildingUpgradeValidator $validator;

    // v0.51.58 (Step 2) — Markdown templates extracted у formatter
    protected BuildingUpgradeMessageFormatter $formatter;

    // v0.51.60 (Step 3) — DB write block extracted у applier
    protected BuildingUpgradeApplier $applier;

    /**
     * Требования для перехода на каждый уровень (2..10):
     * - gold     = нужное количество золота
     * - level    = минимальный уровень персонажа
     * - resources= массив необходимых ресурсов (name_en => кол-во)
     */
    protected $upgradeRequirements = [
        2 => [
            'gold'      => 50000,
            'level'     => 1,
            'resources' => [],
        ],
        3 => [
            'gold'      => 75000,
            'level'     => 12,
            'resources' => [],
        ],
        4 => [
            'gold'      => 100000,
            'level'     => 14,
            'resources' => [
                'Water' => 15000,
                'Wood'  => 10000,
            ],
        ],
        5 => [
            'gold'      => 150000,
            'level'     => 20,
            'resources' => [
                'Water'  => 15000,
                'Wood'   => 15000,
                'Pebble' => 15000,
            ],
        ],
        6 => [
            'gold'      => 200000,
            'level'     => 22,
            'resources' => [
                'Water' => 18000,
                'Wood'  => 20000,
                'Pebble'=> 22000,
                'Sand'  => 15000,
            ],
        ],
        7 => [
            'gold'      => 300000,
            'level'     => 24,
            'resources' => [
                'Water' => 24000,
                'Wood'  => 30000,
                'Pebble'=> 32000,
                'Sand'  => 28000,
            ],
        ],
        8 => [
            'gold'      => 368000,
            'level'     => 26,
            'resources' => [
                'Water' => 28200,
                'Wood'  => 36400,
                'Pebble'=> 34800,
                'Sand'  => 31400,
            ],
        ],
        9 => [
            'gold'      => 472000,
            'level'     => 28,
            'resources' => [
                'Water' => 31200,
                'Wood'  => 39340,
                'Pebble'=> 37150,
                'Sand'  => 34120,
            ],
        ],
        10 => [
            'gold'      => 1000000,
            'level'     => 30,
            'resources' => [],
        ],
    ];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterModel         = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->playerStateService     = new PlayerStateService();

        $this->validator              = new BuildingUpgradeValidator(
            $this->characterBuildingModel,
            $this->buildingModel,
            $this->characterResourceModel,
            $this->resourceModel,
            $this->playerStateService
        );
        $this->formatter              = new BuildingUpgradeMessageFormatter($this->resourceModel);
        $this->applier                = new BuildingUpgradeApplier(
            $this->characterModel,
            $this->characterResourceModel,
            $this->resourceModel,
            $this->characterBuildingModel
        );
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
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgradeRequirements);

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
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgradeRequirements);
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
}
