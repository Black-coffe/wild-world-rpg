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
     */
    public function askForUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        // Проверка: есть ли пользователь и персонаж
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найден.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // Проверка: игрок на базе?
        if (!$this->playerStateService->isCharacterOnBase($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы не на базе или база отсутствует. Нельзя улучшать постройки.",
            ]);
        }

        // Извлекаем buildingId из callback_data ("upgrade_building_4")
        $callbackData = $this->callbackQuery->getData(); // "upgrade_building_4"
        $parts        = explode('_', $callbackData);     // ["upgrade","building","4"]
        $buildingId   = $parts[2] ?? null;               // "4"

        if (!$buildingId) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Не удалось определить ID здания для апгрейда.',
            ]);
        }

        // Проверяем наличие здания у игрока
        $charBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();
        if (!$charBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет здания с ID=$buildingId.",
            ]);
        }

        // Справочная запись о самом здании
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Справочная информация о здании (ID=$buildingId) не найдена.",
            ]);
        }

        $maxLevel     = 10;
        $currentLevel = (int)$charBuilding['level'];
        if ($currentLevel >= $maxLevel) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Здание уже достигло максимального уровня ($maxLevel).",
            ]);
        }

        // Следующий уровень
        $nextLevel = $currentLevel + 1;
        if (!isset($this->upgradeRequirements[$nextLevel])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нет данных для апгрейда до уровня $nextLevel.",
            ]);
        }

        $req            = $this->upgradeRequirements[$nextLevel];
        $requiredGold    = (int)$req['gold'];
        $requiredCharLvl = (int)$req['level'];
        $requiredResArr  = $req['resources'];

        // Проверяем уровень персонажа
        if ($character['level'] < $requiredCharLvl) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нужно иметь уровень >= {$requiredCharLvl}, у вас: {$character['level']}.",
            ]);
        }

        // Проверяем золото
        if ($character['gold'] < $requiredGold) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нужно золото: {$requiredGold}, у вас: {$character['gold']}.",
            ]);
        }

        // Проверяем необходимые ресурсы
        $missingResources = [];
        foreach ($requiredResArr as $resNameEn => $needQty) {
            if ($needQty <= 0) {
                continue;
            }
            // Ищем ресурс в таблице resources по name_en
            $resRow = $this->resourceModel->where('name_en', $resNameEn)->first();
            if (!$resRow) {
                $missingResources[] = "- Неизвестный ресурс {$resNameEn}";
                continue;
            }
            // Ищем, сколько у игрока
            $charRes = $this->characterResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources',  $resRow['id'])
                ->first();
            $playerHas = $charRes ? (int)$charRes['quantity'] : 0;

            if ($playerHas < $needQty) {
                $missingResources[] = "- {$resRow['name']} (нужно: {$needQty}, есть: {$playerHas})";
            }
        }

        // Если что-то не хватает, сообщаем
        if (!empty($missingResources)) {
            $msg  = "Недостаточно ресурсов для апгрейда до уровня {$nextLevel}:\n";
            $msg .= implode("\n", $missingResources);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $msg,
            ]);
        }

        // Иначе всего хватает — предлагаем подтвердить
        $buildingNameRu = $buildingInfo['name_ru'] ?? "ID=$buildingId";
        $msg  = "Вы хотите поднять *{$buildingNameRu}* с уровня {$currentLevel} на уровень {$nextLevel}?";
        $msg .= "\n\nТребуется:\n";
        $msg .= "- Уровень персонажа >= {$requiredCharLvl} (у вас {$character['level']})\n";
        $msg .= "- Золото: {$requiredGold} (у вас {$character['gold']})\n";

        foreach ($requiredResArr as $rNameEn => $rQty) {
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
        // Проверяем, что игрок на базе
        if (!$this->playerStateService->isCharacterOnBase($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы не на базе или база отсутствует. Нельзя улучшать постройки.",
            ]);
        }

        // Разбираем callback_data: "confirm_upgrade_building_4"
        $callbackData = $this->callbackQuery->getData();
        $parts = explode('_', $callbackData); // ['confirm','upgrade','building','4']
        $buildingId = $parts[3] ?? null;

        if (!$buildingId) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Не определён ID здания при подтверждении апгрейда.',
            ]);
        }

        // Ещё раз проверяем, есть ли у игрока это здание
        $charBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();
        if (!$charBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет здания с ID=$buildingId.",
            ]);
        }

        // Инфа о здании
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Справочная информация о здании (ID=$buildingId) не найдена.",
            ]);
        }

        $maxLevel     = 10;
        $currentLevel = (int)$charBuilding['level'];
        if ($currentLevel >= $maxLevel) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Здание уже на максимальном уровне ($maxLevel).",
            ]);
        }

        // Определяем следующий уровень
        $nextLevel = $currentLevel + 1;
        if (!isset($this->upgradeRequirements[$nextLevel])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нет данных для апгрейда до уровня $nextLevel.",
            ]);
        }
        $req = $this->upgradeRequirements[$nextLevel];

        // Проверяем уровень персонажа
        if ($character['level'] < $req['level']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Уровень персонажа недостаточен (нужно >= {$req['level']}).",
            ]);
        }

        // Проверяем золото
        if ($character['gold'] < $req['gold']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Недостаточно золота (нужно {$req['gold']}, у вас {$character['gold']}).",
            ]);
        }

        // Проверяем ресурсы (снова, т.к. после "шаг 1" игрок мог что-то потратить)
        foreach ($req['resources'] as $resNameEn => $needQty) {
            if ($needQty <= 0) {
                continue;
            }
            $resRow = $this->resourceModel->where('name_en', $resNameEn)->first();
            if (!$resRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Неизвестный ресурс: {$resNameEn}.",
                ]);
            }
            $charRes = $this->characterResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources',  $resRow['id'])
                ->first();
            $playerHas = $charRes ? (int)$charRes['quantity'] : 0;

            if ($playerHas < $needQty) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Не хватает ресурса {$resRow['name']} (нужно {$needQty}, у вас {$playerHas}).",
                ]);
            }
        }

        // Всё в порядке — списываем золото и ресурсы, повышаем уровень
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
