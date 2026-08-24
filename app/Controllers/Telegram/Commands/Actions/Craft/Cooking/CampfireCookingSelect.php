<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Cooking;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Craft\CraftCardHelper;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Notifications\MediaSender;
use App\Services\Telegram\ButtonPacker;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V8 (vNext, Фаза 2) — меню готовки на костре. Farm-to-table payoff для V6/V7:
 * блюда из урожая земледелия.
 *
 * 2026-08-16 — экран разбит НАДВОЕ: `cook` = 🍲 горячее (perishable),
 * `cookPreserves` = 🥫 консервы (preserved). Причина — замер подписи: одним
 * списком при живом `cooking.fish_dishes.enabled` выходило 1124–1206 символов
 * при лимите 1024, из-за чего MediaSender штатно уводил экран в текст и
 * картинку не видел НИКТО. Плюс деление честное по смыслу: горячее портится и
 * едят у костра, консервы не портятся и берут в дорогу.
 *
 * Дополнительно подпись «садится» под лимит сама: {@see fitCaption()} по одной
 * отбрасывает необязательные строки шапки, НИКОГДА не трогая строки рецептов —
 * состав блюда игрок обязан видеть (media-off, ADR-020).
 *
 * Caption самодостаточен (media-off): состав + что восстанавливает.
 */
class CampfireCookingSelect extends BaseAction
{
    /** Лимит подписи фото в Telegram (символы после парсинга разметки). */
    public const CAPTION_LIMIT = 1024;

    /** callback_data экрана консервов (горячее — исторический `cook`). */
    public const CB_PRESERVES = 'cookPreserves';

    /**
     * Средний сегмент callback_data шага выбора количества:
     * `cook_qty_<Key>` / `cookPreserves_qty_<Key>`. Роутинг проходит по ПЕРВОМУ
     * сегменту (`explode('_', $data)[0]` в `CallbackqueryCommand`), поэтому
     * оба варианта продолжают резолвиться в этот же класс существующими
     * exact-роутами `cook`/`cookPreserves` — CallbackRoutes.php трогать не нужно.
     */
    private const QTY_SEGMENT = 'qty';

    /**
     * Средний сегмент callback_data кнопки «📝 Своё число»:
     * `cook_qtyCustom_<Key>` / `cookPreserves_qtyCustom_<Key>`. Отдельный от
     * `QTY_SEGMENT` маркер (не просто третий сегмент `..._custom`) — иначе
     * `explode('_', $data, 3)` в `parseCallback()` захватил бы `_custom` как
     * часть ключа рецепта.
     */
    private const QTY_CUSTOM_SEGMENT = 'qtyCustom';

    /**
     * Ступени количества — ТЕ ЖЕ, что у обычного крафта (см. `craftQuantities`
     * в WoodMaterialsCraft1Action и десятках сиблингов WorkbenchGeneral/Standard):
     * два соседних экрана крафта не должны предлагать разные наборы количества.
     *
     * @var list<int>
     */
    public const QUANTITY_STEPS = [1, 5, 10, 25, 50, 100];

    /** @var list<string> 🍲 горячее — портится (`perishable`), порядок меню. */
    public const HOT_RECIPES = [
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
    ];

    /** @var list<string> 🥫 консервы — shelf-stable (`preserved`), V10. */
    public const PRESERVE_RECIPES = [
        'StewPreserve',
        'DryRation',
    ];

    /**
     * Все блюда готовки (горячее + консервы). Оставлен как единый перечень для
     * anti-drift проверок «ключ меню ↔ рецепт»; на экраны идут HOT/PRESERVE.
     *
     * @var list<string>
     */
    public const COOKING_RECIPES = [
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
        'StewPreserve',
        'DryRation',
    ];

    /** @var list<string> W23 — рыбное горячее (killswitch `cooking.fish_dishes.enabled`). */
    public const FISH_HOT_RECIPES = [
        'FishSoup',
        'GrilledFish',
    ];

    /** @var list<string> W23 — рыбная консерва (тот же killswitch). */
    public const FISH_PRESERVE_RECIPES = [
        'FishPreserve',
    ];

    /**
     * W23 (ADR-078) — рыбные блюда (дают «Рыбе» применение). Показываются только
     * при killswitch cooking.fish_dishes.enabled (dormant до активации).
     *
     * @var list<string>
     */
    public const FISH_RECIPES = [
        'FishSoup',
        'GrilledFish',
        'FishPreserve',
    ];

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $data = $this->callbackQuery->getData();

        // Кнопка «📝 Своё число»: `cook_qtyCustom_<Key>` / `cookPreserves_qtyCustom_<Key>`.
        $custom = self::parseCustomQuantityPrompt($data);
        if ($custom !== null) {
            return $this->handlePromptCustomQuantity($chatId, $custom['preserves'], $custom['key']);
        }

        $parsed = self::parseCallback($data);

        // Шаг выбора количества: `cook_qty_<Key>` / `cookPreserves_qty_<Key>`.
        if ($parsed['qtyKey'] !== null) {
            return $this->handleQuantityStep($chatId, $parsed['preserves'], $parsed['qtyKey']);
        }

        return $this->handleDishList($chatId, $parsed['preserves']);
    }

    /**
     * Парсит callback_data кнопки «Своё число» — ОТДЕЛЬНО от `parseCallback()`,
     * чтобы не менять её контракт (story `chat-requests-batch-04` уже держит
     * его тестом).
     *
     * @return array{preserves:bool,key:string}|null null — не эта кнопка
     */
    public static function parseCustomQuantityPrompt(string $data): ?array
    {
        $segments = explode('_', $data, 3);
        if (($segments[1] ?? null) !== self::QTY_CUSTOM_SEGMENT || ($segments[2] ?? '') === '') {
            return null;
        }

        return ['preserves' => $segments[0] === self::CB_PRESERVES, 'key' => $segments[2]];
    }

    /** callback_data кнопки «📝 Своё число» для одного блюда. */
    public static function customQuantityCallback(string $key, bool $preserves): string
    {
        $prefix = $preserves ? self::CB_PRESERVES : 'cook';

        return "{$prefix}_" . self::QTY_CUSTOM_SEGMENT . "_{$key}";
    }

    /**
     * Ключ блюда действительно готовится сейчас (учитывая killswitch рыбы) —
     * та же проверка, что резолвит список кнопок; используется извне
     * (`GenericmessageCommand`) перед тем, как доверять маркеру из ответа
     * игрока на ForceReply-промпт.
     */
    public static function isKnownCookingRecipe(string $key): bool
    {
        $gs      = new GameSettingsService();
        $fishRaw = $gs->get('cooking.fish_dishes.enabled', false);
        $fishOn  = is_bool($fishRaw) ? $fishRaw : (is_numeric($fishRaw) && (int) $fishRaw === 1);

        $keys = self::COOKING_RECIPES;
        if ($fishOn) {
            $keys = array_merge($keys, self::FISH_RECIPES);
        }

        return in_array($key, $keys, true);
    }

    /**
     * Разбирает callback_data экрана — чистая функция без побочных эффектов
     * (без Telegram/БД), поэтому парсинг тестируется напрямую. `qtyKey` не
     * `null` только для шага выбора количества.
     *
     * @return array{preserves:bool,qtyKey:?string}
     */
    public static function parseCallback(string $data): array
    {
        $segments  = explode('_', $data, 3);
        $preserves = $segments[0] === self::CB_PRESERVES;
        $qtyKey    = (($segments[1] ?? null) === self::QTY_SEGMENT && ($segments[2] ?? '') !== '')
            ? $segments[2]
            : null;

        return ['preserves' => $preserves, 'qtyKey' => $qtyKey];
    }

    /**
     * callback_data кнопки блюда в списке — ведёт на шаг выбора количества,
     * НЕ на прямой старт крафта одной штуки.
     */
    public static function dishStepCallback(string $key, bool $preserves): string
    {
        $prefix = $preserves ? self::CB_PRESERVES : 'cook';

        return "{$prefix}_" . self::QTY_SEGMENT . "_{$key}";
    }

    /**
     * Кнопки количества для одного блюда — ведут на `genericCraft_<Key>_<qty>`,
     * тот же механизм, что у обычного крафта (см. класс-докблок `handleQuantityStep()`).
     *
     * `$steps` — по умолчанию все `QUANTITY_STEPS`; вызывающий код гейтит их по
     * доступным ресурсам через {@see affordableSteps()} до вызова (story 10 —
     * ступени готовки обязаны вести себя как у обычного крафта, где кнопка на
     * недоступное количество вообще не показывается).
     *
     * @param  list<int>|null                          $steps
     * @return list<array{text:string,callback_data:string}>
     */
    public static function quantityButtons(string $recipeKey, ?array $steps = null): array
    {
        $buttons = [];
        foreach ($steps ?? self::QUANTITY_STEPS as $qty) {
            $buttons[] = ['text' => "{$qty} шт.", 'callback_data' => "genericCraft_{$recipeKey}_{$qty}"];
        }

        return $buttons;
    }

    /**
     * Итоговые РЯДЫ шага количества — ровно то, что `handleQuantityStep()`
     * кладёт в `reply_markup`. Вынесено отдельным методом, чтобы тест мерил
     * ФАКТИЧЕСКУЮ раскладку, уходящую в Telegram, а не промежуточный список
     * кнопок до упаковки (ревью-урок: «мерить нужно выход нормализатора, а не
     * файлы»). «Своё число» — в ОБЩЕМ пуле кнопок перед `ButtonPacker::pack()`,
     * не отдельным хвостовым рядом: `pack()` гарантирует ряд без одиночки
     * только когда в пуле ≥2 кнопки, а хвостовой ряд из одной «Своё число» был
     * именно вырожденным случаем — вдвойне заметно в ветке нехватки сырья, где
     * `$buttons` уже состоял из одной `fallbackButton()` (правило проекта «ни
     * одной кнопки-одиночки в ряду»).
     *
     * @param  list<int> $steps ступени, реально доступные по ресурсам ({@see affordableSteps()})
     * @return list<list<array{text:string,callback_data:string}>>
     */
    public static function quantityStepRows(string $recipeKey, bool $preserves, array $steps): array
    {
        $buttons = self::quantityButtons($recipeKey, $steps);
        if ($buttons === []) {
            // Тот же выход из тупика «не хватает даже на 1 шт.», что у обычного
            // крафта — ведёт на genericCraft_<Key>_1, который сам покажет
            // CraftShortageService («чего не хватает»), а не пустой список кнопок.
            $buttons[] = (new CraftCardHelper())->fallbackButton($recipeKey);
        }
        $buttons[] = ['text' => '📝 Своё число', 'callback_data' => self::customQuantityCallback($recipeKey, $preserves)];

        // По 2-3 в ряд (ButtonPacker) — ни одной кнопки-одиночки в строке.
        $rows = ButtonPacker::pack($buttons);
        $rows[] = [
            ['text' => '⬅️ К списку блюд', 'callback_data' => $preserves ? self::CB_PRESERVES : 'cook'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        return $rows;
    }

    /**
     * Максимум штук, на которые хватает ресурсов — та же формула, что у
     * обычного крафта (`calculateMaxCraftableItems()` в `LumberjackAxeCraft1Action`
     * и сиблингах: минимум по каждому ресурсу `floor(есть / нужно_на_1)`).
     * Продублирована здесь по тому же принципу, что и в остальных ~27 карточках
     * крафта (см. докблок `CraftCardHelper`) — общего предка у формулы нет.
     *
     * @param  array<string,int> $availableByName имя ресурса → сколько есть у персонажа
     * @param  array<string,mixed> $requiredResources имя ресурса → нужно на 1 шт.
     */
    public static function maxCraftableItems(array $availableByName, array $requiredResources): int
    {
        $max = PHP_INT_MAX;
        foreach ($requiredResources as $name => $need) {
            if (! is_numeric($need) || (int) $need <= 0) {
                continue;
            }
            $have     = is_numeric($availableByName[$name] ?? null) ? (int) $availableByName[$name] : 0;
            $possible = (int) floor($have / (int) $need);
            $max      = min($max, $possible);
        }

        return $max === PHP_INT_MAX ? 0 : $max;
    }

    /**
     * `QUANTITY_STEPS`, отфильтрованные по реально доступным ресурсам —
     * зеркало `getAvailableQuantityButtons()` обычного крафта: показываем
     * только ступени, которые персонаж реально может себе позволить.
     *
     * @param  array<string,int>   $availableByName
     * @param  array<string,mixed> $requiredResources
     * @return list<int>
     */
    public static function affordableSteps(array $availableByName, array $requiredResources): array
    {
        $max = self::maxCraftableItems($availableByName, $requiredResources);

        return array_values(array_filter(
            self::QUANTITY_STEPS,
            static fn (int $q): bool => $q <= $max,
        ));
    }

    /**
     * Список блюд экрана (горячее или консервы) — прежнее поведение `handle()`
     * до появления промежуточного шага количества.
     */
    private function handleDishList(int $chatId, bool $preserves): ServerResponse
    {
        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');
        $gs  = new GameSettingsService();

        // W23: рыбные добавляются только при включённом killswitch (dormant-safe).
        $fishRaw = $gs->get('cooking.fish_dishes.enabled', false);
        $fishOn  = is_bool($fishRaw) ? $fishRaw : (is_numeric($fishRaw) && (int) $fishRaw === 1);

        $recipeKeys = $preserves ? self::PRESERVE_RECIPES : self::HOT_RECIPES;
        if ($fishOn) {
            $recipeKeys = array_merge(
                $recipeKeys,
                $preserves ? self::FISH_PRESERVE_RECIPES : self::FISH_HOT_RECIPES,
            );
        }

        $scope = new \App\Services\Tasks\ActionScopeService();
        $fb    = new \App\Services\Food\FoodBuffService();

        [, $character] = $this->getUserAndCharacter();
        $wfu = $character !== null ? ($character['well_fed_until'] ?? null) : null;

        $wellFedMinutes = null;
        if ($fb->isWellFed($wfu)) {
            $tsEnd          = is_string($wfu) ? strtotime($wfu) : false;
            $wellFedMinutes = $tsEnd !== false ? max(1, (int) ceil(($tsEnd - time()) / 60)) : 0;
        }

        $dishes      = [];
        $dishButtons = [];
        foreach ($recipeKeys as $key) {
            $recipe = $cfg->get($key);
            if ($recipe === null) {
                continue;
            }
            $icon = isset($recipe['icon_emoji']) && is_string($recipe['icon_emoji']) ? $recipe['icon_emoji'] : '🍲';
            $name = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) ? $recipe['item_name_rus'] : $key;

            // Heal-эффект (data-driven, как UsePharmacyAction резолвит).
            $snake = self::toSnakeCase($key);
            $hpRaw = $gs->get("medical.{$snake}.heal_health", 0);
            $tdRaw = $gs->get("medical.{$snake}.heal_tired", 0);

            $dishes[] = [
                'icon'  => $icon,
                'name'  => $name,
                'cost'  => self::costOf($recipe),
                'hp'    => is_numeric($hpRaw) ? (int) $hpRaw : 0,
                'tired' => is_numeric($tdRaw) ? (int) $tdRaw : 0,
            ];
            // Ведёт на шаг выбора количества (см. `handleQuantityStep()`), а не сразу
            // на старт крафта одной штуки — Анжела 18.08.2026: «выбрать количество».
            $dishButtons[] = ['text' => "{$icon} {$name}", 'callback_data' => self::dishStepCallback($key, $preserves)];
        }

        // Блюда — по 2-3 в ряд (ButtonPacker), а не колонкой: семь кнопок в столбик
        // на телефоне превращают меню в простыню прокрутки.
        $rows = ButtonPacker::pack($dishButtons);

        $text = self::renderText(
            $preserves,
            $dishes,
            $scope->occupancyWarning($scope->isRecipeBackground('MushroomSoup')),
            (! $preserves && $fb->freshnessEnabled()) ? $fb->freshDays() : null,
            $wellFedMinutes,
            $fb->combatEnabled(),
        );

        // Хвост: перекрёстная ссылка на вторую половину готовки + навигация.
        // Пары, а не одиночки в ряду — правило упаковки рядов.
        $rows[] = [
            $preserves
                ? ['text' => '🍲 Горячее', 'callback_data' => 'cook']
                : ['text' => '🥫 Консервы', 'callback_data' => self::CB_PRESERVES],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        $rows[] = [
            ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
            ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
        ];

        $imagePath = base_url(self::imageRel($preserves));

        return MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Шаг выбора количества одного блюда. Кнопки ведут на
     * `genericCraft_<Key>_<qty>` — тот же механизм, что и обычный крафт
     * (`GenericCraftActionStart` сам умножает ресурсы/золото/время на qty и
     * сам же гейтит 🔒-занятость, ADR-167). Этот шаг — чистый рендер меню, он
     * ничего не списывает и не создаёт задачу, поэтому гейт занятости
     * продолжает отрабатывать ровно там же, где отрабатывал раньше — на клике
     * по кнопке количества, при старте крафта.
     *
     * Неизвестный/устаревший ключ блюда (протухшая кнопка) — назад в список,
     * а не тупик.
     */
    private function handleQuantityStep(int $chatId, bool $preserves, string $recipeKey): ServerResponse
    {
        $recipe = self::resolveMenuRecipe($recipeKey, $preserves);
        if ($recipe === null) {
            return $this->handleDishList($chatId, $preserves);
        }

        $gs = new GameSettingsService();

        $icon = isset($recipe['icon_emoji']) && is_string($recipe['icon_emoji']) ? $recipe['icon_emoji'] : '🍲';
        $name = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) ? $recipe['item_name_rus'] : $recipeKey;

        $snake = self::toSnakeCase($recipeKey);
        $hpRaw = $gs->get("medical.{$snake}.heal_health", 0);
        $tdRaw = $gs->get("medical.{$snake}.heal_tired", 0);

        $text = self::renderQuantityText(
            $preserves,
            $icon,
            $name,
            self::costOf($recipe),
            is_numeric($hpRaw) ? (int) $hpRaw : 0,
            is_numeric($tdRaw) ? (int) $tdRaw : 0,
        );

        // Ступени гейтятся по реально доступным ресурсам — как у обычного
        // крафта (story 10): кнопка на недоступное количество не показывается.
        $rawResources = isset($recipe['resources']) && is_array($recipe['resources']) ? $recipe['resources'] : [];
        /** @var array<string,int> $requiredResources */
        $requiredResources = [];
        foreach ($rawResources as $resName => $resQty) {
            if (is_string($resName) && is_numeric($resQty)) {
                $requiredResources[$resName] = (int) $resQty;
            }
        }

        [, $character] = $this->getUserAndCharacter();
        $characterId   = $character !== null && is_numeric($character['id'] ?? null) ? (int) $character['id'] : null;

        $availableByName = [];
        if ($characterId !== null) {
            foreach ((new CraftCardHelper())->available($characterId, $requiredResources) as $res) {
                $availableByName[$res['name']] = $res['quantity'];
            }
        }
        $steps = self::affordableSteps($availableByName, $requiredResources);
        $rows  = self::quantityStepRows($recipeKey, $preserves, $steps);

        $imagePath = base_url(self::imageRel($preserves));

        return MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Резолвит ключ блюда в конфиг рецепта, ТОЛЬКО если он реально стоит в
     * меню запрошенной половины экрана (горячее/консервы, с учётом killswitch
     * рыбы) — общий guard для `handleQuantityStep()` и `handlePromptCustomQuantity()`.
     *
     * @return array<string,mixed>|null
     */
    private static function resolveMenuRecipe(string $recipeKey, bool $preserves): ?array
    {
        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');
        $gs  = new GameSettingsService();

        $fishRaw = $gs->get('cooking.fish_dishes.enabled', false);
        $fishOn  = is_bool($fishRaw) ? $fishRaw : (is_numeric($fishRaw) && (int) $fishRaw === 1);

        $allowedKeys = $preserves ? self::PRESERVE_RECIPES : self::HOT_RECIPES;
        if ($fishOn) {
            $allowedKeys = array_merge(
                $allowedKeys,
                $preserves ? self::FISH_PRESERVE_RECIPES : self::FISH_HOT_RECIPES,
            );
        }

        if (! in_array($recipeKey, $allowedKeys, true)) {
            return null;
        }

        return $cfg->get($recipeKey);
    }

    /**
     * ForceReply-промпт «своё число» — образец `SellResourceAction::promptCustomQuantity()`.
     * Маркер `COOK:<Key>` в тексте промпта — `GenericmessageCommand::execute()`
     * матчит его на ответе игрока и дальше идёт ТЕМ ЖЕ путём, что и кнопка
     * количества: `genericCraft_<Key>_<qty>` → `GenericCraftActionStart`
     * (ресурсы/золото/время, 🔒-гейт ADR-167 — без дублирования).
     *
     * Неизвестный/устаревший ключ блюда — назад в список, а не тупик (как и
     * `handleQuantityStep()`).
     */
    private function handlePromptCustomQuantity(int $chatId, bool $preserves, string $recipeKey): ServerResponse
    {
        $recipe = self::resolveMenuRecipe($recipeKey, $preserves);
        if ($recipe === null) {
            return $this->handleDishList($chatId, $preserves);
        }

        $name = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) ? $recipe['item_name_rus'] : $recipeKey;
        $cost = self::costOf($recipe);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "📝 Введите количество для готовки *{$name}* (состав на 1 шт.: {$cost}).\n\n"
                . "Ответьте на это сообщение целым числом.\n_(код заявки: COOK:{$recipeKey})_",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['force_reply' => true, 'selective' => true]),
        ]);
    }

    /**
     * Подпись шага выбора количества — самодостаточна (media-off, ADR-020):
     * название блюда, эффект, состав на ОДНУ штуку и явное предупреждение,
     * что выбранное число умножит ресурсы/золото/время.
     */
    public static function renderQuantityText(
        bool $preserves,
        string $icon,
        string $name,
        string $cost,
        int $hp,
        int $tired,
    ): string {
        return ($preserves ? "🥫 *Костёр — консервы*\n" : "🔥 *Костёр — горячее*\n")
            . "{$icon} *{$name}* — ❤️+{$hp} / ⚡+{$tired}\n"
            . "_{$cost}_ (на 1 шт.)\n\n"
            . "Сколько приготовить? Ресурсы, золото и время крафта умножатся на выбранное число.\n";
    }

    /**
     * Картинка экрана: у горячего и у консервов она СВОЯ.
     *
     * До 2026-08-16 обе половины рендерились общим `general_crafting_img.png` —
     * фото верстака с ножами и молотком, то есть экран про готовку показывал
     * мастерскую. Теперь: огонь с котлом против полки с банками — половины
     * различаются даже на миниатюре.
     *
     * Если файл не доехал (не выкатили uploads) — откатываемся на общий крафт:
     * `Request::encodeFile` на несуществующем пути бросает исключение и убивает
     * весь экран, а экран важнее картинки.
     */
    private static function imageRel(bool $preserves): string
    {
        $rel = $preserves
            ? 'uploads/telegram/craft/cooking/campfire_preserves.jpg'
            : 'uploads/telegram/craft/cooking/campfire_hot.jpg';

        if (defined('FCPATH') && ! is_file(FCPATH . $rel)) {
            return 'uploads/telegram/craft/general_crafting_img.png';
        }

        return $rel;
    }

    /**
     * Собирает текст экрана готовки. Чистая функция от данных — поэтому длину
     * подписи можно гейтить тестом, а не ловить на проде по пропавшей картинке.
     *
     * @param  bool                                                                    $preserves      экран консервов (иначе горячее)
     * @param  list<array{icon:string,name:string,cost:string,hp:int,tired:int}>        $dishes         строки блюд, в порядке показа
     * @param  string                                                                  $occupancy      предупреждение о занятости (⏳/🔒)
     * @param  int|null                                                                $freshDays      срок свежести, null — строку не показывать
     * @param  int|null                                                                $wellFedMinutes остаток «Сытости», null — не сыт
     * @param  bool                                                                    $combatEnabled  сытость влияет на бой (ADR-121)
     */
    public static function renderText(
        bool $preserves,
        array $dishes,
        string $occupancy,
        ?int $freshDays,
        ?int $wellFedMinutes,
        bool $combatEnabled,
    ): string {
        // Шапка: заголовок + предупреждение о занятости — остаются ВСЕГДА.
        $header = ($preserves ? "🥫 *Костёр — консервы*\n" : "🔥 *Костёр — горячее*\n")
            . $occupancy . "\n";

        // Необязательные строки шапки — в порядке ПОКАЗА (ключ → строка).
        $optional          = [];
        $optional['intro'] = $preserves
            ? "_Заготовки в дорогу: не портятся и держат сытость дольше горячего, но дороже по сырью._\n"
            : "_Блюда из урожая и улова: восстанавливают здоровье и особенно выносливость._\n";

        // Свежесть касается только горячего — консервы по определению всегда свежи.
        if (! $preserves && $freshDays !== null) {
            $optional['fresh'] = "_Свежее блюдо ({$freshDays} дн.) даёт полную сытость, залежалое — меньше. Лечит всегда._\n";
        }

        if ($wellFedMinutes !== null) {
            // E21 Ф1 (ADR-121): боевое измерение — сытость даёт бонус в PvE (охота/север).
            $combatNote          = $combatEnabled ? ', в бою сильнее' : '';
            $optional['wellfed'] = "🍖 _Сытость активна (ещё ~{$wellFedMinutes} мин): крафт быстрее, добыча щедрее{$combatNote}._\n";
        } elseif ($combatEnabled) {
            // Мотивация нужна только тем, у кого сытости СЕЙЧАС нет: сытому про бой
            // уже сказано строкой выше, дубль сжирал бы лимит подписи впустую.
            $optional['combat'] = "_⚔️ Поешь перед охотой на элиток и походом на север._\n";
        }

        // Строки блюд — неприкосновенны: это и есть содержание экрана.
        $body = "\n";
        foreach ($dishes as $d) {
            $body .= "{$d['icon']} *{$d['name']}* — ❤️+{$d['hp']} / ⚡+{$d['tired']}\n"
                . "   _{$d['cost']}_\n";
        }

        return self::fitCaption($header, $optional, $body, ['combat', 'fresh', 'intro', 'wellfed']);
    }

    /**
     * Состав рецепта строкой «Ресурс×N, Ресурс×N» — ровно то, что видит игрок.
     *
     * @param array<string,mixed> $recipe
     */
    public static function costOf(array $recipe): string
    {
        $parts = [];
        if (isset($recipe['resources']) && is_array($recipe['resources'])) {
            foreach ($recipe['resources'] as $resName => $qty) {
                $parts[] = (is_string($resName) ? $resName : '') . '×' . (is_numeric($qty) ? (int) $qty : 0);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Собирает подпись, укладывая её в лимит фото: `$header` и `$body` остаются
     * всегда, необязательные строки шапки отбрасываются ПО ОДНОЙ в порядке
     * `$dropOrder`, пока подпись не сядет под лимит.
     *
     * Почему так, а не «обрезать хвост»: обрезка съела бы состав блюд, а он —
     * весь смысл экрана в media-off режиме (ADR-020). Гарнир из мотивирующих
     * строк потерять не жалко, данные — нельзя. Если после отбрасывания всего
     * необязательного подпись всё ещё длинная, дальше сработает штатная
     * деградация MediaSender (то же содержимое уходит текстом, лимит 4096).
     *
     * @param  array<string,string> $optional  ключ → строка, в порядке ПОКАЗА
     * @param  list<string>         $dropOrder ключи в порядке ОТБРАСЫВАНИЯ
     */
    public static function fitCaption(string $header, array $optional, string $body, array $dropOrder): string
    {
        $build = static fn (array $opt): string => $header . implode('', $opt) . $body;

        $caption = $build($optional);
        foreach ($dropOrder as $key) {
            if (! MediaSender::captionExceedsPhotoLimit($caption)) {
                return $caption;
            }
            unset($optional[$key]);
            $caption = $build($optional);
        }

        return $caption;
    }

    /** name_eng → setting-key segment (зеркало UsePharmacyAction::toSnakeCase). */
    private static function toSnakeCase(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $s = str_replace([' ', '-'], '_', $s);
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return strtolower(trim($s, '_'));
    }
}
