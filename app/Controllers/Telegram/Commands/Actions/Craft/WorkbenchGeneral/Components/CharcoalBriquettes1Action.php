<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Добавляем модель логов
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, выводящий информацию о «Угольных брикетах» (Charcoal Briquettes)
 * и формирующий кнопки количественного крафта (1,5,10,25,50,100).
 */
class CharcoalBriquettes1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Поле для хранения экземпляра модели логов
    private CraftCardHelper $craftCardHelper;

    /**
     * Возможные пакеты крафта: 1, 5, 10, 25, 50, 100
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- Инициализируем модель логов
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не создан.',
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

        $characterId = $character['id'];

        // 1) Узнаём, сколько у игрока уже есть «Угольных брикетов» (англ. поле name_eng = "CharcoalBriquettes")
        $charcoalItemNameEng = 'CharcoalBriquettes';
        $charcoalQty = $this->getCraftedItemQuantity($characterId, $charcoalItemNameEng);

        // 2) Формируем заголовок с учётом количества
        $title = '🪨 Угольные брикеты!';
        if ($charcoalQty > 0) {
            $title .= " (в инв. – {$charcoalQty} шт.)";
        }

        // Ресурсы на 1 шт. угольных брикетов
        $requiredResources = [
            'Древесина'       => 10,
            'Глина'           => 2,
            'Вода'            => 2,
            'Угольная порода' => 20,
        ];

        // Проверяем, сколько у игрока ресурсов — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Считаем, на сколько штук хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // 3) Формируем описание
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];
            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _130_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _~5–16 мин._\n\n"
            . "*Описание:* Компонент, предназначенный для производства изделий, "
            . "сооружений и плавки.\n\n";

        // 4) Формируем клавиатуру
        if ($maxCraftableItems < 1) {
            // Недостаточно ресурсов на 1 шт.
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('CharcoalBriquettes'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в строке
            $quantityRows = array_chunk($quantityButtons, 3);

            // Добавим блок кнопок "Персонаж / Инвентарь" и "Продать / Купить"
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy']
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // 5) Отправляем сообщение
        $imagePath = base_url('uploads/telegram/craft/components/craftCharcoalBriquettes.png');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяем, сколько предмета (по name_eng) уже имеется в crafted_items_log
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предположим, у CraftedItemsLogModel есть метод:
        // getItemByNameEngAndCharacterId($nameEng, $charId)
        // Возвращает запись типа ['quantity' => ..., ...] или null
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    /**
     * Считаем, на сколько шт. максимум хватает: min( floor(have/need) ) по каждому компоненту.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int) floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт X шт."
     * Пример callback_data: "craftCharcoalBriquettes_5"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_CharcoalBriquettes_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
