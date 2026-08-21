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
 * Класс, отвечающий за вывод информации о "Регенераторе" (Regenerator)
 * и динамическое формирование кнопок крафта (1,5,10,25,50,100).
 */
class RegeneratorCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Перечень доступных вариантов крафта (количественного).
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

        // Англ. название в базе: "Regenerator"
        $itemNameEng = 'Regenerator';
        $itemQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Заголовок
        $itemTitle = '🔋 Регенератор!';
        if ($itemQuantity > 0) {
            $itemTitle .= " (в инв. – {$itemQuantity} шт.)";
        }

        // Ресурсы на 1 шт.
        $requiredResources = [
            'Мясо диких животных' => 2,
            'Водные растения'     => 2,
            'Травы'               => 6,
            'Вода'                => 30,
        ];

        // Смотрим, сколько ресурсов есть у игрока — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Считаем, на сколько штук максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем текст
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта *1 шт.* нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];
            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк. {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _45_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _15 мин._\n\n"
            . "*Описание:* Адреналин в сердце, взрывает организм на новые подвиги: "
            . "+30 к здоровью, +20 к выносливости.\n\n";

        // Проверяем, хватает ли ресурсов хотя бы на 1
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',    'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',    'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('Regenerator'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим кнопки персонажа/инвентаря/купли-продажи
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/health_and_strength_regenerator.jpg');
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
     * Возвращает кол-во имеющихся "Регенераторов" (Regenerator).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $itemLog = $this->craftedItemsLogModel
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

        return $itemLog ? (int) $itemLog['quantity'] : 0;
    }

    /**
     * Считаем, на сколько шт. максимум хватает ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possibleByThis = (int) floor($have / $need);
                if ($possibleByThis < $maxCraftable) {
                    $maxCraftable = $possibleByThis;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100), если позволяют ресурсы.
     * Пример callback: "craftRegenerator_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_Regenerator_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Если используем calculateMaxCraftableItems(), этот метод (для 1 шт.) —
     * только для базовой проверки, можно убрать.
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            if ($res['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
