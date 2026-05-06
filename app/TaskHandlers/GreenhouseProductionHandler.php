<?php

namespace App\TaskHandlers;

use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Config\GameBalance;

/**
 * v0.51.19 (F2.9 batch-1): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — це історично-неправильно (handler НЕ контроллер).
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendMessage → safeSendMessage.
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * v0.51.24 (C/F6 expansion): greenhouseLevels + water shortage cooldown + threshold
 * читаються через config('GameBalance'). Раніше hardcoded private $greenhouseLevels.
 */
class GreenhouseProductionHandler extends BaseTaskHandler
{
    protected $characterBuildingModel;
    protected $characterResourceModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterModel;
    protected $telegramUserModel;

    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->telegramUserModel      = new TelegramUserModel();
    }

    /**
     * Вызывается по крону. Обрабатывает всех игроков, у кого есть Теплица.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // 1) Получаем ID здания "Greenhouse"
        $greenhouseId = $this->getGreenhouseId();
        if (!$greenhouseId) {
            log_message('error', '[GreenhouseProductionHandler] "Greenhouse" building not found in DB.');
            return;
        }

        // 2) Ищем все character_buildings, указывающие на Greenhouse
        $charsGreenhouse = $this->characterBuildingModel
            ->where('building_id', $greenhouseId)
            ->findAll();

        foreach ($charsGreenhouse as $charBuild) {
            $characterId = $charBuild['character_id'];
            $level       = (int) $charBuild['level'];

            // Проверяем корректность уровня
            if (!isset($this->cfg->greenhouseLevels[$level])) {
                log_message('error', "[GreenhouseProductionHandler] Invalid greenhouse level: $level");
                continue;
            }

            $waterNeeded = $this->cfg->greenhouseLevels[$level]['water'];

            // Массив ресурсов, который теплица будет генерировать (Fruit, Berries и т.п.)
            $harvest = $this->cfg->greenhouseLevels[$level];
            unset($harvest['water']); // убираем ключ water, оставляем только еду

            // Получаем ресурс "Water"
            $waterResource = $this->resourceModel
                ->where('name_en', 'Water')
                ->first();
            if (!$waterResource) {
                log_message('error', '[GreenhouseProductionHandler] Resource "Water" not found in DB.');
                continue;
            }

            // Ищем, сколько у персонажа сейчас воды
            $charResWater = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $waterResource['id'])
                ->first();

            // Если у игрока нет воды / ноль
            if (!$charResWater || $charResWater['quantity'] <= 0) {
                continue;
            }

            // === (1) Проверяем, не надо ли отправить уведомление "мало воды" (<= threshold) ===
            if ($charResWater['quantity'] <= $this->cfg->greenhouseWaterShortageThreshold) {
                $this->checkAndNotifyWaterShortage($charResWater, $characterId);
            }

            // === (2) Если воды меньше, чем нужно — пропускаем списание и генерацию ===
            if ($charResWater['quantity'] < $waterNeeded) {
                continue;
            }

            // === (3) Иначе списываем воду и добавляем еду ===
            $newQuantity = $charResWater['quantity'] - $waterNeeded;
            $this->characterResourceModel->update($charResWater['id'], ['quantity' => $newQuantity]);

            // Начисляем harvest (Fruit / Berries / Mushrooms / Crops и т.д.)
            foreach ($harvest as $resourceNameEn => $count) {
                $this->addResourceToCharacter($characterId, $resourceNameEn, $count);
            }
        }
    }

    /**
     * Проверяет, не пора ли отправить уведомление о малом количестве воды.
     *
     * Правила простые:
     *  - Если quantity <= 3, тогда проверяем, сколько времени прошло с момента последней отправки (хранимой в custom_data).
     *  - Если разница >= 30 минут — шлём новое уведомление и записываем новую дату отправки в custom_data.
     *  - Если уведомление не отправлялось (либо не прошло 30 минут), ничего не меняем.
     */
    private function checkAndNotifyWaterShortage(array $charResWater, int $characterId): void
    {
        // Если воды больше threshold (default 3), ничего не делаем
        if ($charResWater['quantity'] > $this->cfg->greenhouseWaterShortageThreshold) {
            return;
        }

        // Извлекаем custom_data
        $oldCustomData = $charResWater['custom_data'] ?? null;
        $customData = $oldCustomData ? json_decode($oldCustomData, true) : [];
        if (!is_array($customData)) {
            $customData = [];
        }

        // Достаем дату последнего уведомления
        $lastNotification = $customData['last_shortage_notification'] ?? null;
        $now = new \DateTime();

        // Проверяем, если в поле уже была дата
        if ($lastNotification) {
            // Вычисляем разницу во времени
            $lastTime = new \DateTime($lastNotification);
            $diff = $now->getTimestamp() - $lastTime->getTimestamp();
            // Если прошло меньше cooldown (default 1800 секунд = 30 минут), выходим
            if ($diff < $this->cfg->greenhouseWaterShortageCooldownSec) {
                return;
            }
        }

        // Если (a) даты нет, или (b) она есть, но прошло >= 30 минут — шлём уведомление
        $this->notifyWaterShortage($characterId, $charResWater['quantity']);

        // Запоминаем дату отправки (текущую)
        $newNotificationDate = $now->format('Y-m-d H:i:s');
        $customData['last_shortage_notification'] = $newNotificationDate;

        // Преобразуем в JSON
        $newCustomData = json_encode($customData);

        // ПРЯМОЙ SQL-ЗАПРОС ДЛЯ ОБНОВЛЕНИЯ custom_data
        try {
            $db = \Config\Database::connect(); // получаем экземпляр соединения с БД
            $sql = "UPDATE character_resources SET custom_data = ? WHERE id = ?";
            $db->query($sql, [$newCustomData, $charResWater['id']]);
        } catch (\Exception $e) {
            log_message('error', 'Update custom_data exception: ' . $e->getMessage());
        }
    }

    /**
     * Возвращает ID здания "Greenhouse" (по name_en).
     */
    private function getGreenhouseId(): ?int
    {
        $greenhouse = $this->buildingModel
            ->where('name_en', 'Greenhouse')
            ->first();
        return $greenhouse ? (int) $greenhouse['id'] : null;
    }

    /**
     * Начисляет (или создаёт) ресурс персонажу.
     */
    private function addResourceToCharacter(int $characterId, string $resourceNameEn, int $quantity): void
    {
        // Ищем ресурс
        $resource = $this->resourceModel->where('name_en', $resourceNameEn)->first();
        if (!$resource) {
            log_message('error', "[GreenhouseProductionHandler] Resource {$resourceNameEn} not found.");
            return;
        }

        // Ищем, есть ли такая запись у персонажа
        $charRes = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->where('id_resources', $resource['id'])
            ->first();

        if ($charRes) {
            // Увеличиваем quantity
            $newQty = $charRes['quantity'] + $quantity;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        } else {
            // Создаём новую запись
            $this->characterResourceModel->insert([
                'id_characters' => $characterId,
                'id_resources'  => $resource['id'],
                'quantity'      => $quantity,
            ]);
        }
    }

    /**
     * Отправляет предупреждение, что воды мало (<= 3).
     */
    private function notifyWaterShortage(int $characterId, int $waterQuantity): void
    {
        // 1) Найдём персонажа
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "[GreenhouseProductionHandler] Character ID {$characterId} not found.");
            return;
        }

        // 2) У персонажа должен быть telegram_user_id
        $telegramUserId = $character['telegram_user_id'];
        if (!$telegramUserId) {
            return; // нет привязки к телеге
        }

        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "[GreenhouseProductionHandler] Telegram user not found for ID {$telegramUserId}.");
            return;
        }

        // 3) Формируем и отправляем
        $chatId = $telegramUser['telegram_id'];
        $text = "Внимание!\n"
            . "У вас осталось всего *{$waterQuantity}* единиц воды.\n"
            . "Если вы не пополните запас, Теплица скоро перестанет приносить урожай!";

        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }
}
