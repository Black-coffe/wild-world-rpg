<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, отвечающий за вывод информации о крафте Антисептика (без запуска самого крафта).
 * Показывает доступные количества крафта (1, 5, 10, 25, 50, 100) в виде кнопок,
 * если у игрока достаточно ресурсов.
 */
class AntisepticCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Набор вариантов количественного крафта.
     * Можно изменить или вынести в конфиг.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
    }

    /**
     * Точка входа при отображении описания/кнопок крафта.
     */
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе или персонаж не определён.',
            ]);
        }

        // Идентификатор персонажа
        $characterId = $character['id'];

        // Название предмета в базе (англ. поле)
        $antisepticNameEng = 'Antiseptic';
        // Сколько уже есть «Антисептиков» у персонажа
        $antisepticQuantity = $this->getCraftedItemQuantity($characterId, $antisepticNameEng);

        // Заголовок с учётом количества
        $antisepticTitle = '🧴 Антисептик!';
        if ($antisepticQuantity > 0) {
            $antisepticTitle .= " (в инв. – {$antisepticQuantity} шт.)";
        }

        // Список ресурсов, необходимых для 1 штуки
        $requiredResources = [
            'Кактус' => 3,
            'Грибы'  => 1,
            'Вода'   => 10,
        ];

        // Узнаём, сколько у игрока ресурсов
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Определяем, сколько всего штук игрок может скрафтить максимально
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем текст о том, какие ресурсы нужны для 1 шт.
        $text = "*{$antisepticTitle}*\n\n"
            . "Для крафта *1 шт.* нужно:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $cost = $requiredResources[$resource['name']] ?? 0;
            $text .= ResourceIconHelper::for($resource['name']) . " {$resource['name']} - {$cost} ед. "
                . "(в наличии {$resource['quantity']} ед., редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _30_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _7 минут_\n\n"
            . "*Описание:* Средство из раздела лекарств, которое помогает "
            . "предотвратить болезни, даёт +4 к здоровью и +2 к выносливости.\n\n";

        // Формируем inline-кнопки: либо только Инвентарь/Продать/Купить, либо и кнопки крафта
        if ($maxCraftableItems < 1) {
            // Недостаточно ресурсов даже на 1 шт.
            $text .= "__Недостаточно ресурсов для крафта даже 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy']
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']
                    ],
                ]
            ];
        } else {
            // Можно крафтить как минимум 1 шт.
            // Генерируем кнопки «Крафт X шт.»
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в строке (на ваше усмотрение)
            $quantityRows = array_chunk($quantityButtons, 3);

            // Добавим финальный ряд с Инвентарь/Продать/Купить
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // Путь к картинке для Антисептика (меняется на ваш)
        $imagePath = base_url('uploads/telegram/craft/antiseptic_craft.jpg');

        // Ответим на callbackQuery, чтобы убрать "часики" в Telegram
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправим сообщение/фото с разметкой
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает количество "Антисептика" (англ. name_eng) у персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    /**
     * Проверяем, сколько у персонажа есть каждого из требуемых ресурсов (для 1 шт.).
     * Возвращает массив:
     * [
     *   ['name' => 'Кактус', 'quantity' => 10, 'rarity' => 2],
     *   ...
     * ]
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource       = $this->resourceModel->getResourceByName($name);
            $currentQuantity = 0;
            $rarity         = 0;

            if ($resource) {
                $characterResource = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);

                $currentQuantity = $characterResource ? $characterResource['quantity'] : 0;
                $rarity          = $resource['rarity'];
            }

            $results[] = [
                'name'     => $name,
                'quantity' => $currentQuantity,
                'rarity'   => $rarity,
            ];
        }
        return $results;
    }

    /**
     * Рассчитываем, какое максимальное количество предметов возможно скрафтить,
     * исходя из имеющегося набора ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name     = $res['name'];
            $have     = $res['quantity'];
            $required = $requiredResources[$name] ?? 0;

            if ($required > 0) {
                $maxByThisResource = (int) floor($have / $required);
                if ($maxByThisResource < $maxCraftable) {
                    $maxCraftable = $maxByThisResource;
                }
            }
        }

        // Если ни один ресурс не ограничивал, вернём 0 (на всякий случай).
        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерирует массив кнопок «Крафт {количество} шт», только для доступных чисел.
     * Пример результата: [
     *   ['text' => '🛠 Крафт 1шт',   'callback_data' => 'genericCraft_Antiseptic_1'],
     *   ['text' => '🛠 Крафт 5шт',   'callback_data' => 'genericCraft_Antiseptic_5'],
     *   ...
     * ]
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_Antiseptic_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
