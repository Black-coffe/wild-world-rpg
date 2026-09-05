<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, показывающий информацию о "Обезболивающем порошке",
 * а также динамически формирующий кнопки крафта (1, 5, 10, 25, 50, 100).
 */
class PainReliefPowerCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Варианты количественного крафта (можно вынести в конфиг).
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        // name_eng в таблице crafted_items (или эквивалент) для "Обезболивающего порошка"
        // Допустим, "AnalgesicPowder" или "PainReliefPowder"
        $itemNameEng = 'AnalgesicPowder';

        // Сколько уже есть у игрока
        $existingQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок
        $itemTitle = "🌡️ Обезболивающий порошок!";
        if ($existingQuantity > 0) {
            $itemTitle .= " (в инв. – {$existingQuantity} шт.)";
        }

        // Что нужно для 1 шт. (пример)
        $requiredResources = [
            'Ядовитые растения'       => 1,
            'Кора деревьев'           => 3,
            'Береговая растительность'=> 2,
            'Цветы орхидей'           => 1,
        ];

        // Проверим, сколько ресурсов есть у игрока — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Максимальное количество, доступное для крафта
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Собираем текст
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта *1 шт.* необходимо:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $reqAmount  = $requiredResources[$resource['name']];
            $haveAmount = $resource['quantity'];
            $rarity     = $resource['rarity'];
            $text      .= ResourceIconHelper::for($resource['name']) . " {$resource['name']} - {$reqAmount} ед. "
                . "(в наличии {$haveAmount} ед., редк. {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _30_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _5 мин._\n\n"
            . "*Описание:* Можно принять для быстрого улучшения самочувствия. "
            . "Восстанавливает +12 здоровья, но снижает выносливость на -6.\n\n";

        // Формируем кнопки
        if ($maxCraftableItems < 1) {
            // Недостаточно ресурсов даже на 1 шт.
            $text .= "__Недостаточно ресурсов для крафта даже 1 шт.__";
            // Клавиатура без кнопок "Крафт"
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '◀️ Я', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('PainReliefPower'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']
                    ],
                ]
            ];
        } else {
            // Можно крафтить (хотя бы 1 шт.)
            // 1. Собираем кнопки крафта
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // 2. Разбиваем по 3 кнопки в ряд
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавляем стандартные кнопки (Инвентарь, Продать, Купить)
            $quantityRows[] = [
                ['text' => '◀️ Я', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/analgesic_powder.jpg');
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
     * Возвращает, сколько у персонажа предметов (itemNameEng) в крафт-логе.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $row = $this->craftedItemsLogModel
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int)$row['quantity'] : 0;
    }

    /**
     * Считаем, на сколько штук (max) хватает ресурсов, исходя из их наличия и затрат на 1 шт.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;
            if ($need > 0) {
                $possibleByThis = (int)floor($have / $need);
                if ($possibleByThis < $maxCraftable) {
                    $maxCraftable = $possibleByThis;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Формируем кнопки "Крафт {X} шт." на основе массива $this->craftQuantities,
     * но только те, что не превышают $maxCraftableItems.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        // Пример колбэка: "craftPainReliefPower_10"
        // чтобы потом отдельный класс мог распарсить, что крафт = PainReliefPower, кол-во = 10
        // Если хотите более универсально, используйте "craftItem_PainReliefPower_{qty}".
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_PainReliefPower_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Проверяем, достаточно ли ресурсов на 1 шт.
     * (старый метод, но можно убрать, если используем вышеуказанные проверки).
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $resource) {
            $need = $requiredResources[$resource['name']] ?? 0;
            if ($resource['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
