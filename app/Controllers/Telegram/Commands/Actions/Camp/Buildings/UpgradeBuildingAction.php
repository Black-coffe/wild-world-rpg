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
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найден.',
            ]);
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
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Не удалось определить ID здания для апгрейда.',
            ]);
        }

        // Run full validation
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgradeRequirements);

        if (!$res['ok']) {
            // Multi-line missing resources
            if (!empty($res['missingResources'])) {
                $nextLevel = $res['nextLevel'] ?? '?';
                $msg = "Недостаточно ресурсов для апгрейда до уровня {$nextLevel}:\n"
                     . implode("\n", $res['missingResources']);
                return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
            }
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $res['error']]);
        }

        // Validated → build confirm prompt
        $ctx             = $res['context'];
        $buildingInfo    = $ctx['buildingInfo'];
        $currentLevel    = $ctx['currentLevel'];
        $nextLevel       = $ctx['nextLevel'];
        $req             = $ctx['requirements'];
        $requiredGold    = (int) $req['gold'];
        $requiredCharLvl = (int) $req['level'];

        $buildingNameRu = $buildingInfo['name_ru'] ?? "ID={$buildingId}";
        $msg  = "Вы хотите поднять *{$buildingNameRu}* с уровня {$currentLevel} на уровень {$nextLevel}?";
        $msg .= "\n\nТребуется:\n";
        $msg .= "- Уровень персонажа >= {$requiredCharLvl} (у вас {$character['level']})\n";
        $msg .= "- Золото: {$requiredGold} (у вас {$character['gold']})\n";

        foreach ($req['resources'] as $rNameEn => $rQty) {
            $rRow    = $this->resourceModel->where('name_en', $rNameEn)->first();
            $rNameRu = $rRow ? $rRow['name'] : $rNameEn;
            $msg    .= "- {$rNameRu}: {$rQty}\n";
        }
        $msg .= "\nПодтвердите апгрейд?";

        // Формируем кнопки
        $confirmCallback = "confirm_upgrade_building_{$buildingId}";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить', 'callback_data' => $confirmCallback],
                    ['text' => '❌ Отмена',     'callback_data' => 'Base'],
                ]
            ],
        ];

        // Скрываем "загрузка" (answerCallbackQuery)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Проверка завершена.',
            'show_alert'        => false,
        ]);

        // Отправляем сообщение пользователю
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Шаг 2: пользователь подтвердил апгрейд (callback_data: "confirm_upgrade_building_X")
     */
    public function confirmUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найден.',
            ]);
        }

        // Parse buildingId з callback_data: "confirm_upgrade_building_4"
        $parts      = explode('_', $this->callbackQuery->getData());
        $buildingId = $parts[3] ?? null;
        if (!$buildingId) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Не определён ID здания при подтверждении апгрейда.',
            ]);
        }

        // v0.51.57 (Step 1) — re-validate всю chain (resources могли поменяться
        // після ask). Validator повертає detailed missing list.
        $res = $this->validator->validate($character, (int) $buildingId, $this->upgradeRequirements);
        if (!$res['ok']) {
            if (!empty($res['missingResources'])) {
                // confirmUpgrade UX: first-fail style. Беремо першу.
                $msg = "Не хватает ресурсов: " . $res['missingResources'][0];
                return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
            }
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $res['error']]);
        }

        $ctx          = $res['context'];
        $charBuilding = $ctx['charBuilding'];
        $buildingInfo = $ctx['buildingInfo'];
        $currentLevel = $ctx['currentLevel'];
        $nextLevel    = $ctx['nextLevel'];
        $req          = $ctx['requirements'];

        // Apply upgrade — списываем золото и ресурсы, повышаем уровень
        $newGold = $character['gold'] - $req['gold'];
        $this->characterModel->update($character['id'], ['gold' => $newGold]);

        foreach ($req['resources'] as $resNameEn => $needQty) {
            if ($needQty <= 0) {
                continue;
            }
            $resRow = $this->resourceModel->where('name_en', $resNameEn)->first();
            if (!$resRow) {
                continue;
            }
            $this->characterResourceModel->decreaseResources($character['id'], $resRow['id'], $needQty);
        }

        // Повышаем уровень постройки
        $this->characterBuildingModel->update($charBuilding['id'], [
            'level' => $nextLevel,
        ]);

        // Сообщение об успехе
        $bName = $buildingInfo['name_ru'] ?? "Здание #$buildingId";
        $msg   = "Поздравляем! «{$bName}» поднялось с уровня {$currentLevel} до уровня {$nextLevel}.\n";

        // Скрываем alert у кнопки
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Улучшение выполнено!',
            'show_alert'        => false,
        ]);

        // Отправляем финальное сообщение
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }
}
