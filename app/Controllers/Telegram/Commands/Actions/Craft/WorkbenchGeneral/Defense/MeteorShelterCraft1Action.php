<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Defense;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Экран крафта «🛡 Метеоритное укрытие» (`MeteorShelter`).
 *
 * Это та самая дверь, которой не было: `info_callback => 'meteorShelter'` был
 * прописан в `Config\CraftRecipes` ещё в v0.51.127, но не зарегистрирован в
 * `Config\CallbackRoutes` и не выведен ни одной кнопкой — предмет невозможно
 * было скрафтить, хотя текст «Метеоритного удара» обещает его как способ
 * вдвое срезать потери. Подробности — в шапке {@see DefenseCraft1Select}.
 *
 * Экран честен насчёт двух вещей, о которых игроки спрашивали в чате:
 *  - укрытие не изнашивается и не тратится, достаточно одного в инвентаре;
 *  - оно нужно ровно для поля: дома от этого события укрывает сама база.
 */
class MeteorShelterCraft1Action extends BaseAction
{
    /** Ресурсы на 1 шт. — зеркалит `Config\CraftRecipes['MeteorShelter']['resources']`. */
    private const RECIPE_KEY = 'MeteorShelter';

    /**
     * Набор количеств для кнопок крафта. Укрытие не стакается пачками — потолок скромный.
     *
     * @var list<int>
     */
    private array $craftQuantities = [1, 2, 3];

    // `$resourceModel` НЕ объявляем: он уже есть в BaseAction (нетипизированным)
    // и там же инициализируется. Пере-объявление с типом — фатал «must not be defined».
    protected CharacterResourceModel $characterResourceModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не определён.',
            ]);
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse(); // Переезд идёт, сервис уже отписался игроку.
        }

        $characterId = (int) $character['id'];

        // Рецепт — единственный источник правды по составу и уровню, чтобы экран
        // и сделка не разъехались (урок `feedback_screen_price_must_come_from_transaction_service`:
        // числа на экране обязаны браться оттуда же, откуда их возьмёт списание).
        $recipe = config(CraftRecipes::class)->get(self::RECIPE_KEY);
        if (!is_array($recipe)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => '⚠️ Рецепт временно недоступен.',
            ]);
        }

        /** @var array<string,int> $requiredPerOne */
        $requiredPerOne = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        // Нарроуим mixed до int проверкой, а не слепым кастом (урок `feedback_phpstan_no_mixed_to_int_cast`).
        $rawLevel      = $recipe['required_level'] ?? 0;
        $levelRequired = is_numeric($rawLevel) ? (int) $rawLevel : 0;
        $rawCharLevel  = $character['level'] ?? 0;
        $charLevel     = is_numeric($rawCharLevel) ? (int) $rawCharLevel : 0;

        $haveQty = $this->getCraftedItemQuantity($characterId, self::RECIPE_KEY);

        $title = '🛡 Метеоритное укрытие!';
        if ($haveQty > 0) {
            $title .= " (в инв. – {$haveQty} шт.)";
        }

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredPerOne);
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredPerOne);

        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $need = $requiredPerOne[$res['name']] ?? 0;
            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$res['quantity']} ед., редк - {$res['rarity']})\n";
        }

        $text .= "\n*Нужен уровень:* _{$levelRequired}_ (у тебя _{$charLevel}_)\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~10–15 мин._\n\n"
            . "*Описание:* Переносное укрытие из камня и металла. Когда идёт "
            . "*Метеоритный удар*, оно срабатывает само и режет потерю ресурсов вдвое. "
            . "Надевать и включать ничего не надо — хватит одного укрытия в инвентаре, "
            . "оно не изнашивается и работает столько раз, сколько грянет удар.\n\n"
            . "_Дома укрытие не нужно: на своей базе метеорит не заденет ресурсы вовсе. "
            . "Укрытие — на тот случай, когда удар застанет тебя в поле._\n\n";

        $backRow = [['text' => '⬅️ Назад', 'callback_data' => 'defenseCraft']];

        if ($charLevel < $levelRequired) {
            // Уровень не добран — говорим прямо, что именно закрыто и чем открывается,
            // а не молчим и не пишем «недоступно» (правило UX-DISCOVERABILITY).
            $text .= "🔒 __Укрытие открывается с *{$levelRequired}* уровня. "
                . "Набирай опыт в вылазках и добыче — до открытия остался "
                . ($levelRequired - $charLevel) . " ур.__";
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🌟 Мировые события', 'callback_data' => 'events'],
                ],
                $backRow,
            ]];
        } elseif ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов, чтобы создать даже 1 шт.__";
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                ],
                $backRow,
            ]];
        } else {
            $rows = array_chunk($this->getAvailableQuantityButtons($maxCraftableItems), 3);
            $rows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $rows[] = $backRow;
            $keyboard = ['inline_keyboard' => $rows];
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $base = $this->navTarget() + [
            'chat_id'      => $chatId,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ];

        // Файл проверяем до encodeFile — иначе fopen отсутствующего пути убьёт экран.
        $imageRel  = is_string($recipe['image_completed'] ?? null)
            ? $recipe['image_completed']
            : 'uploads/telegram/camp/Construction-by-improvised.jpg';
        if (is_file(FCPATH . $imageRel)) {
            return \App\Services\Notifications\MediaSender::editOrSend($base + [
                'photo'   => Request::encodeFile(base_url($imageRel)),
                'caption' => $text,
            ]);
        }

        return \App\Services\Notifications\MediaSender::editTextOrSend($base + ['text' => $text]);
    }

    /** Сколько таких предметов уже лежит у персонажа. */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $itemRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

        return $itemRow ? (int) $itemRow['quantity'] : 0;
    }

    /**
     * @param array<string,int> $requiredPerOne
     * @return list<array{name:string,quantity:int,rarity:int|string}>
     */
    private function checkResourcesAvailability(int $characterId, array $requiredPerOne): array
    {
        $results = [];
        foreach (array_keys($requiredPerOne) as $resName) {
            $resource = $this->resourceModel->getResourceByName($resName);
            $qty      = 0;
            $rarity   = 0;

            if ($resource) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);

                $qty    = $charRes ? (int) $charRes['quantity'] : 0;
                $rarity = $resource['rarity'];
            }

            $results[] = ['name' => $resName, 'quantity' => $qty, 'rarity' => $rarity];
        }

        return $results;
    }

    /**
     * @param list<array{name:string,quantity:int,rarity:int|string}> $resourcesAvailable
     * @param array<string,int>                                       $requiredPerOne
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredPerOne): int
    {
        $maxCraftable = PHP_INT_MAX;
        foreach ($resourcesAvailable as $res) {
            $need = $requiredPerOne[$res['name']] ?? 0;
            if ($need > 0) {
                $possible = (int) floor($res['quantity'] / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /** @return list<array{text:string,callback_data:string}> */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => 'genericCraft_' . self::RECIPE_KEY . "_{$q}",
                ];
            }
        }

        return $buttons;
    }
}
