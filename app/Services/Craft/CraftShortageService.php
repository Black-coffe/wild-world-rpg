<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\ButtonPacker;
use App\Services\World\BiomeCompassService;
use Config\CraftRecipes;

/**
 * ADR-158 — экран «чего не хватает» вместо глухого отказа.
 *
 * До этого старт крафта при нехватке отвечал строкой «Недостаточно ресурсов для
 * крафта N шт.» — без списка, без чисел и без единой кнопки, хотя точный список
 * недостающего сервер УЖЕ считал и молча выбрасывал в лог отказа.
 *
 * Замер прода (60 дней, 144 отказа у 26 игроков) задал приоритеты:
 *   • 137 отказов — не хватало СЫРЬЯ, и только 8 — крафтового компонента;
 *   • 127 отказов — попытка собрать ОДНУ штуку, то есть игрок упирался не в объём.
 * Поэтому главный ответ экрана — «вот чего не хватает и ГДЕ это добывается», а не
 * разворот дерева рецептов.
 *
 * Порядок вариантов принципиален и обратен привычному: сперва добыть, потом
 * собрать, и только потом купить. Обратный порядок молча сообщал бы новичку, что
 * платить — нормальный первый ход, при том что мир и добыча и есть игра.
 */
class CraftShortageService
{
    public const KEY_ENABLED = 'craft.shortage_hint.enabled';

    /**
     * Тот же ключ, что и `CraftShortfallBuyAction::KEY_MAX_UNITS` (story 06/07,
     * контракт `plan.md`): сделка режет докупку до этого лимита, и экран обязан
     * считать блок на том же количестве — иначе игрок видит сумму за N штук, а
     * спишется за меньшее (fix-02, крупная находка 6 ревью).
     */
    public const KEY_MAX_UNITS_PER_PURCHASE = 'craft.shortfall_buy.max_units_per_purchase';

    /** Сколько позиций показываем подробно — дальше caption распухает без пользы. */
    private const MAX_DETAILED = 6;

    /** Столько же для блока докупки — свой лимит, свой счётчик «и ещё N». */
    private const MAX_SHORTFALL_DETAILED = 6;

    private GameSettingsService $settings;
    private ResourceModel $resources;
    private BiomeModel $biomes;
    private MapModel $map;
    private CraftShortfallBuyService $shortfallBuy;

    /** @var array<int,string>|null id биома → название (таблица маленькая, читаем раз) */
    private ?array $biomeNames = null;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?ResourceModel $resources = null,
        ?BiomeModel $biomes = null,
        ?MapModel $map = null,
        ?CraftShortfallBuyService $shortfallBuy = null
    ) {
        $this->settings     = $settings ?? new GameSettingsService();
        $this->resources    = $resources ?? new ResourceModel();
        $this->biomes       = $biomes ?? new BiomeModel();
        $this->map          = $map ?? new MapModel();
        $this->shortfallBuy = $shortfallBuy ?? new CraftShortfallBuyService();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::KEY_ENABLED, true);
    }

    /**
     * Текст и кнопки экрана недостачи.
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @param array<string,array{need:int|float,have:int|float|string,name:string}> $missingResources
     * @param array<string,array{need:int|float,have:int|float|string,name:string}> $missingItems
     * @param array<string,mixed> $recipe
     * @return array{text:string, keyboard:array{inline_keyboard:list<list<array<string,string>>>}}
     */
    public function describe(
        array|CharacterEntity $character,
        array $missingResources,
        array $missingItems,
        int $quantity,
        array $recipe = []
    ): array {
        $title = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) && $recipe['item_name_rus'] !== ''
            ? $this->safe($recipe['item_name_rus'])
            : 'этот предмет';

        $lines = [
            '🔨 *' . $title . '* — не хватает материалов'
                . ($quantity > 1 ? ' на *' . $quantity . ' шт.*' : '') . '.',
            '',
            '*Не хватает:*',
        ];

        $hints      = $this->compassHints($character);
        $buyButtons = [];
        $craftButtons = [];
        $shown      = 0;
        $hidden     = 0;

        foreach ($missingResources as $name => $row) {
            if ($shown >= self::MAX_DETAILED) {
                $hidden++;
                continue;
            }
            $shown++;
            $lines[] = $this->resourceLines($name, $row, $hints, $buyButtons);
        }

        foreach ($missingItems as $itemEng => $row) {
            if ($shown >= self::MAX_DETAILED) {
                $hidden++;
                continue;
            }
            $shown++;
            $lines[] = $this->componentLines($itemEng, $row, $craftButtons);
        }

        if ($hidden > 0) {
            $lines[] = '• _…и ещё ' . $hidden . ' позиц' . ($hidden === 1 ? 'ия' : 'ий') . '._';
        }

        $lines[] = '';
        $lines[] = '_Добыть почти всегда дешевле, чем купить._';

        $shortfall = $this->shortfallBuyBlock($character, $recipe, $quantity);
        if ($shortfall['lines'] !== []) {
            $lines[] = '';
            array_push($lines, ...$shortfall['lines']);
        }

        return [
            'text'     => implode("\n", $lines),
            'keyboard' => ['inline_keyboard' => $this->keyboard($craftButtons, $buyButtons, $recipe, $shortfall['button'])],
        ];
    }

    /**
     * Блок «🛒 Докупить у торговца» (story `craft-shortfall-buy-08`). Стоит ниже
     * «⛏ Добыть» и вариантов «собрать самому» по тексту — порядок экрана не меняем
     * (ADR-158). Ни одно число здесь не считается заново: всё приходит из
     * `CraftShortfallBuyService::quote()` — единственного источника чисел (contracts,
     * `plan.md`). Наценка называется «наценка торговца за срочность»; слово «опт»
     * запрещено (ADR-096) — оно занято оптовой продажей.
     *
     * Пустой возврат — обе ситуации, в которых блока быть не должно: killswitch
     * выключен (`REASON_KILLSWITCH_OFF`) или в рецепте нечего докупать (`$recipe`
     * не передан вызывающим кодом, либо все позиции уже в достатке).
     *
     * Количество, на которое считается блок, зажимается `max_units_per_purchase`
     * ДО вызова `quote()` — тем же лимитом, до которого `CraftShortfallBuyAction`
     * режет сделку. Иначе экран честно печатает сумму за запрошенные 5 шт., а
     * спишется она за 1 (fix-02, крупная находка 6 ревью).
     *
     * `button` — рабочая кнопка входа в докупку (`craftBuy_<RecipeKey>_<qty>`,
     * контракт `plan.md`), а не только текст: до этой правки нажать было нечего
     * (fix-02, критическая находка 1). `RecipeKey` берём из `craft_again_callback`
     * рецепта (`genericCraft_<Key>_<qty>`) — единственное поле, где он уже есть
     * во всех ~105 рецептах `CraftRecipes`. Если поля нет (легаси-старты брони/
     * маяков мимо `CraftRecipes`, story 13/14 — вне объёма этой правки) или сделка
     * недоступна — кнопки нет, это уже лечит существующий текст lock-состояния.
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @param array<string,mixed> $recipe
     * @return array{lines:list<string>, button:array{text:string,callback_data:string}|null}
     */
    private function shortfallBuyBlock(array|CharacterEntity $character, array $recipe, int $quantity): array
    {
        $maxUnits = $this->maxUnitsPerPurchase();
        $quoteQty = min($quantity, $maxUnits);

        $quote = $this->shortfallBuy->quote($character, $this->shortfallQuoteRecipe($recipe), $quoteQty);
        if ($quote->lines === []) {
            return ['lines' => [], 'button' => null];
        }

        $characterLevelRaw = $character['level'] ?? 0;
        $characterLevel     = is_numeric($characterLevelRaw) ? (int) $characterLevelRaw : 0;

        $out   = ['🛒 *Докупить у торговца* — сколько будет стоить закрыть остаток прямо сейчас:'];
        $shown  = 0;
        $hidden = 0;
        foreach ($quote->lines as $line) {
            if ($shown >= self::MAX_SHORTFALL_DETAILED) {
                $hidden++;
                continue;
            }
            $shown++;
            $out[] = $this->shortfallLineText($line, $characterLevel);
        }
        if ($hidden > 0) {
            $out[] = '• _…и ещё ' . $hidden . ' позиц' . ($hidden === 1 ? 'ия' : 'ий') . '._';
        }

        if ($quoteQty < $quantity) {
            $out[] = '_Докупка ограничена ' . $maxUnits . ' шт. за раз — цифры ниже посчитаны на ' . $quoteQty . '._';
        }

        $out[] = $this->shortfallSummaryText($quote);

        $recipeKey = $this->shortfallRecipeKey($recipe);
        $button    = ($quote->available && $recipeKey !== null)
            ? ['text' => '🛒 Докупить и собрать', 'callback_data' => 'craftBuy_' . $recipeKey . '_' . $quoteQty]
            : null;

        return ['lines' => $out, 'button' => $button];
    }

    /**
     * `craft_again_callback` живёт во всех рецептах `Config\CraftRecipes` в форме
     * `genericCraft_<RecipeKey>_<qty>` — единственное поле рецепта, откуда `RecipeKey`
     * можно взять честно, не изобретая новый контракт (`plan.md` §Contracts требует
     * готовый `craftBuy_<RecipeKey>_<qty>`, не собственный ключ).
     *
     * @param array<string,mixed> $recipe
     */
    private function shortfallRecipeKey(array $recipe): ?string
    {
        $callback = $recipe['craft_again_callback'] ?? null;
        if (! is_string($callback) || $callback === '') {
            return null;
        }

        return preg_match('/^genericCraft_(.+)_\d+$/', $callback, $m) === 1 ? $m[1] : null;
    }

    /** Тот же лимит, что режет сделку (`CraftShortfallBuyAction::KEY_MAX_UNITS`). */
    private function maxUnitsPerPurchase(): int
    {
        $raw = $this->settings->get(self::KEY_MAX_UNITS_PER_PURCHASE, 1);
        $val = is_numeric($raw) ? (int) $raw : 1;

        return max(1, $val);
    }

    /**
     * `CraftShortfallBuyService::quote()` объявляет узкий тип `$recipe` (contracts,
     * `plan.md`), а `describe()` принимает произвольный `array<string,mixed>` из
     * рецепта конфига — сужаем явным чтением полей, не кастом и не игнором phpstan.
     *
     * @param array<string,mixed> $recipe
     * @return array{resources:array<string,int>,crafted_items:array<string,int>,item_name_eng:string}
     */
    private function shortfallQuoteRecipe(array $recipe): array
    {
        return [
            'resources'     => $this->stringIntMap($recipe['resources'] ?? []),
            'crafted_items' => $this->stringIntMap($recipe['crafted_items'] ?? []),
            'item_name_eng' => isset($recipe['item_name_eng']) && is_string($recipe['item_name_eng']) ? $recipe['item_name_eng'] : '',
        ];
    }

    /** @return array<string,int> */
    private function stringIntMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $qty) {
            if (! is_string($name) || ! is_numeric($qty)) {
                continue;
            }
            $out[$name] = (int) $qty;
        }

        return $out;
    }

    /**
     * @param array{resourceId:int,name:string,need:int,have:int,gap:int,unitPrice:float,lineTotal:float,buyable:bool,blockReason:string|null} $line
     */
    private function shortfallLineText(array $line, int $characterLevel): string
    {
        $name = $this->safe($line['name']);
        $need = (int) $line['need'];
        $have = (int) $line['have'];
        $gap  = (int) $line['gap'];

        if ($line['buyable']) {
            $unit  = number_format((float) $line['unitPrice'], 2, '.', ' ');
            $total = number_format((float) $line['lineTotal'], 0, '.', ' ');

            return '• *' . $name . '* — не хватает *' . $gap . '* (нужно ' . $need . ', есть ' . $have
                . '), у торговца ' . $unit . ' 💰/шт → *' . $total . ' 💰* итого';
        }

        return '• *' . $name . '* — 🔒 ' . $this->shortfallBlockReasonText(
            (string) $line['blockReason'],
            $line['name'],
            $characterLevel
        );
    }

    /**
     * Причина lock-состояния конкретной позиции + путь вперёд — до тапа, не после.
     */
    private function shortfallBlockReasonText(string $reason, string $rawName, int $characterLevel): string
    {
        return match ($reason) {
            CraftShortfallBuyService::REASON_NOT_TRADEABLE
                => 'не продаётся — эту позицию можно только добыть',
            CraftShortfallBuyService::REASON_LEVEL_REQUIRED
                => $this->levelRequiredText($rawName, $characterLevel),
            CraftShortfallBuyService::REASON_NOT_ON_SALE
                => 'это крафтовый компонент — у торговца его нет, собери на верстаке',
            default => 'сейчас недоступно для докупки — добудь сам',
        };
    }

    private function levelRequiredText(string $rawName, int $characterLevel): string
    {
        $resource  = $this->findResource($rawName);
        $needRaw   = $resource['level_required'] ?? 0;
        $needLevel = is_numeric($needRaw) ? (int) $needRaw : 0;

        return $needLevel > 0
            ? 'докупка открывается с ' . $needLevel . ' уровня, у тебя ' . $characterLevel . ' — пока добудь сам'
            : 'докупка открывается с более высокого уровня, у тебя ' . $characterLevel . ' — пока добудь сам';
    }

    /**
     * Итог блока: при доступной сделке — наценка/сумма/остаток золота/доля рецепта
     * деньгами (что не докуплено — идёт из своего запаса и сборки). При отказе —
     * причина и путь вперёд, а не голое «недоступно».
     */
    private function shortfallSummaryText(CraftShortfallQuote $quote): string
    {
        if ($quote->available) {
            $sharePct = (int) round($quote->share * 100);

            return 'Наценка торговца за срочность: *+' . $quote->markupPct . '%*. Итого спишется *'
                . number_format($quote->total, 0, '.', ' ') . ' 💰*, после сделки останется *'
                . number_format($quote->goldAfter, 0, '.', ' ') . ' 💰*. Докупка закроет ' . $sharePct
                . '% рецепта деньгами — остальное идёт из своего запаса и сборки на верстаке.';
        }

        return match ($quote->refusal) {
            CraftShortfallBuyService::REASON_MIN_GOLD => 'Не хватает *'
                . number_format(-$quote->goldAfter, 0, '.', ' ') . ' 💰* на докупку (нужно '
                . number_format($quote->total, 0, '.', ' ') . ' 💰) — заработай золото или добудь сырьё сам.',
            CraftShortfallBuyService::REASON_PROFIT_GATE =>
                'Докупка сейчас невыгодна: обошлась бы дороже, чем ты выручишь за собранный предмет — выгоднее добыть сырьё самому.',
            default => 'Докупить всю сборку целиком нельзя — причина у позиции выше. Собери недостающее сам.',
        };
    }

    /**
     * ADR-171: `storage`/`pooled` приходят от `GenericCraftActionStart::checkResources()` —
     * сколько лежит на складе базы и засчитан ли он сейчас в `have`. Игрок мимо базы
     * не видит склад в остатке, но заслуживает знать, что он там есть — жалоба
     * Анжелы была ровно про это молчание.
     *
     * @param array{need:int|float,have:int|float|string,name:string,storage?:int|float,pooled?:bool} $row
     * @param array<int,string> $hints biome_id → подсказка компаса
     * @param list<array<string,string>> $buyButtons
     */
    private function resourceLines(string $name, array $row, array $hints, array &$buyButtons): string
    {
        $need = (int) $row['need'];
        $have = (int) $row['have'];
        $gap  = max(0, $need - $have);

        $text = '• *' . $this->safe($name) . '* — нужно ' . $need . ', есть ' . $have;

        $storage = isset($row['storage']) ? (int) $row['storage'] : 0;
        $pooled  = ($row['pooled'] ?? false) === true;
        if (!$pooled && $storage > 0) {
            $text .= "\n   🏠 на складе базы ждёт ещё *" . $storage . '* — вернись на базу, чтобы использовать';
        }

        $resource = $this->findResource($name);
        if ($resource === null) {
            return $text;
        }

        $where = $this->whereToGather($resource, $hints);
        if ($where !== null) {
            $text .= "\n   ⛏ " . $where;
        }

        // ResourceEntity кастует is_tradeable в boolean ($casts), а сырая строка БД
        // даёт 0/1 — принимаем оба вида, иначе ветка «докупить» молча не срабатывает.
        $tradeableRaw = $resource['is_tradeable'] ?? null;
        $tradeable    = $tradeableRaw === true || (is_numeric($tradeableRaw) && (int) $tradeableRaw === 1);
        $priceRaw     = $resource['buy_price'] ?? null;
        $buyPrice     = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
        if ($tradeable && $buyPrice > 0 && $gap > 0) {
            $cost = (int) ceil($gap * $buyPrice);
            $text .= "\n   🛒 докупить " . $gap . ' — около ' . number_format($cost, 0, '.', ' ') . ' 💰';

            $idRaw = $resource['id'] ?? null;
            if (is_numeric($idRaw) && count($buyButtons) < 3) {
                // Дефицит-ссылка вместо `buy_select`: экран уже посчитал, сколько не хватает,
                // — кнопка ведёт сразу на это количество, а не на пустой выбор qty.
                $buyButtons[] = [
                    'text'          => '🛒 ' . $this->safe($name) . ' ×' . (int) $gap,
                    'callback_data' => 'buy_need_' . (int) $idRaw . '_' . (int) $gap,
                ];
            }
        } elseif ($gap > 0) {
            $text .= "\n   🛒 у торговца не купить — только добыть";
        }

        return $text;
    }

    /**
     * @param array{need:int|float,have:int|float|string,name:string} $row
     * @param list<array<string,string>> $craftButtons
     */
    private function componentLines(string $itemEng, array $row, array &$craftButtons): string
    {
        $need = (int) $row['need'];
        $have = (int) $row['have'];
        $text = '• *' . $this->safe($row['name']) . '* — нужно ' . $need . ', есть ' . $have
            . "\n   🔨 это крафтовый компонент — собери его на верстаке";

        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');
        foreach ($cfg->recipes as $sub) {
            if (($sub['item_name_eng'] ?? null) !== $itemEng) {
                continue;
            }
            $callback = $sub['info_callback'] ?? null;
            if (is_string($callback) && $callback !== '' && count($craftButtons) < 2) {
                $craftButtons[] = [
                    'text'          => '🔨 ' . $this->safe($row['name']),
                    'callback_data' => $callback,
                ];
            }
            break;
        }

        return $text;
    }

    /**
     * «добыть: Лес, Роща · северо-восток, далеко» — биомы ресурса плюс компас
     * (ADR-152). Без данных о биомах строку не выдумываем.
     *
     * @param array<int,string> $hints
     */
    private function whereToGather(\App\Entities\ResourceEntity $resource, array $hints): ?string
    {
        $csv = $resource['biome_id'] ?? null;
        if (! is_string($csv) || trim($csv) === '') {
            return null;
        }

        $names = [];
        $hint  = null;
        foreach (explode(',', $csv) as $part) {
            $id = (int) trim($part);
            if ($id <= 0) {
                continue;
            }
            $name = $this->biomeName($id);
            if ($name !== null && count($names) < 3) {
                $names[] = $name;
            }
            if ($hint === null && isset($hints[$id])) {
                $hint = $hints[$id];
            }
        }

        if ($names === []) {
            return null;
        }

        $text = 'добыть: ' . implode(', ', $names);

        return $hint !== null ? $text . ' · ' . $hint : $text;
    }

    private function biomeName(int $id): ?string
    {
        if ($this->biomeNames === null) {
            $this->biomeNames = $this->loadBiomeNames();
        }

        return $this->biomeNames[$id] ?? null;
    }

    /**
     * Точки чтения БД собраны в три метода-seam'а: класс намеренно не final, чтобы
     * текст экрана можно было проверять на фикстурах, не наполняя тестовую базу
     * ресурсами, биомами и картой (в `wildworld_tests` таблицы `map` нет вовсе).
     *
     * ResourceModel::first() отдаёт ResourceEntity (F1.4.2), не массив; доступ по
     * ключам работает через ArrayAccess.
     */
    protected function findResource(string $name): ?\App\Entities\ResourceEntity
    {
        $row = $this->resources->where('name', $name)->first();

        return $row instanceof \App\Entities\ResourceEntity ? $row : null;
    }

    /**
     * BiomeModel::findAll() отдаёт BiomeEntity[] (F1.4.1); ключи через ArrayAccess.
     *
     * @return array<int,string> id биома → название
     */
    protected function loadBiomeNames(): array
    {
        $names = [];
        foreach ($this->biomes->findAll() as $row) {
            $idRaw   = $row['id'] ?? null;
            $nameRaw = $row['name'] ?? null;
            if (is_numeric($idRaw) && is_string($nameRaw) && $nameRaw !== '') {
                $names[(int) $idRaw] = $this->safe($nameRaw);
            }
        }

        return $names;
    }

    /**
     * Подсказки компаса относительно текущей клетки персонажа. Координаты берём
     * из `map` по `cell_number`; нет клетки или killswitch выключен — просто
     * назовём биомы без стороны света.
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @return array<int,string>
     */
    protected function compassHints(array|CharacterEntity $character): array
    {
        $compass = new BiomeCompassService();
        if (! $compass->enabled()) {
            return [];
        }

        $cellRaw = $character['cell_number'] ?? null;
        if (! is_numeric($cellRaw)) {
            return [];
        }

        $cell = $this->map->where('cell_number', (int) $cellRaw)->first();
        if (! is_array($cell) || ! isset($cell['coordinate_x'], $cell['coordinate_y'])) {
            return [];
        }
        if (! is_numeric($cell['coordinate_x']) || ! is_numeric($cell['coordinate_y'])) {
            return [];
        }

        return $compass->hintsFor((int) $cell['coordinate_x'], (int) $cell['coordinate_y']);
    }

    /**
     * Порядок рядов — это сообщение о ценностях: сперва собрать, потом добыть,
     * и только потом купить. Кнопка входа в докупку (если она есть — story
     * fix-02) идёт НИЖЕ «⛏ Добыть» и вариантов «собрать самому», в самом низу,
     * порядок экрана (ADR-158) не меняется.
     *
     * @param list<array<string,string>> $craftButtons
     * @param list<array<string,string>> $buyButtons
     * @param array<string,mixed> $recipe
     * @param array{text:string,callback_data:string}|null $shortfallButton
     * @return list<list<array<string,string>>>
     */
    private function keyboard(array $craftButtons, array $buyButtons, array $recipe, ?array $shortfallButton = null): array
    {
        $rows = [];

        if ($craftButtons !== []) {
            $rows[] = $craftButtons;
        }

        // ADR-168 — метка источника «не хватает сырья в крафте».
        $rows[] = [['text' => '⛏ Добыть', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('gather', \App\Services\Logging\ActionOrigin::FROM_CRAFT)]];

        if ($buyButtons !== []) {
            $rows[] = $buyButtons;
        }

        $back = isset($recipe['info_callback']) && is_string($recipe['info_callback']) && $recipe['info_callback'] !== ''
            ? $recipe['info_callback']
            : 'craft';

        // Ряды кнопок проходят через общий нормализатор (`.claude/rules/telegram-ux.md`):
        // одиночек в строке не остаётся. Кнопка докупки — та же строка, что и
        // «Инвентарь»/«Назад», а не отдельный ряд из одной кнопки.
        $trailing = [];
        if ($shortfallButton !== null) {
            $trailing[] = $shortfallButton;
        }
        $trailing[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];
        $trailing[] = ['text' => '⬅️ Назад', 'callback_data' => $back];

        foreach (ButtonPacker::pack($trailing) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Легаси parse_mode='Markdown' не экранирует служебные символы: одиночная `*`
     * или `_` в названии рвёт разметку, Telegram отвечает 400 и сообщение молча
     * не доходит. Поэтому вычищаем их из подставляемых имён.
     */
    private function safe(string $value): string
    {
        return trim(str_replace(['*', '_', '`', '[', ']'], '', $value));
    }
}
