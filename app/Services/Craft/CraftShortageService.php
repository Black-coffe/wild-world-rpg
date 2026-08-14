<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;
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

    /** Сколько позиций показываем подробно — дальше caption распухает без пользы. */
    private const MAX_DETAILED = 6;

    private GameSettingsService $settings;
    private ResourceModel $resources;
    private BiomeModel $biomes;
    private MapModel $map;

    /** @var array<int,string>|null id биома → название (таблица маленькая, читаем раз) */
    private ?array $biomeNames = null;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?ResourceModel $resources = null,
        ?BiomeModel $biomes = null,
        ?MapModel $map = null
    ) {
        $this->settings  = $settings ?? new GameSettingsService();
        $this->resources = $resources ?? new ResourceModel();
        $this->biomes    = $biomes ?? new BiomeModel();
        $this->map       = $map ?? new MapModel();
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

        return [
            'text'     => implode("\n", $lines),
            'keyboard' => ['inline_keyboard' => $this->keyboard($craftButtons, $buyButtons, $recipe)],
        ];
    }

    /**
     * @param array{need:int|float,have:int|float|string,name:string} $row
     * @param array<int,string> $hints biome_id → подсказка компаса
     * @param list<array<string,string>> $buyButtons
     */
    private function resourceLines(string $name, array $row, array $hints, array &$buyButtons): string
    {
        $need = (int) $row['need'];
        $have = (int) $row['have'];
        $gap  = max(0, $need - $have);

        $text = '• *' . $this->safe($name) . '* — нужно ' . $need . ', есть ' . $have;

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
                $buyButtons[] = [
                    'text'          => '🛒 ' . $this->safe($name),
                    'callback_data' => 'buy_select_' . (int) $idRaw,
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
     * и только потом купить.
     *
     * @param list<array<string,string>> $craftButtons
     * @param list<array<string,string>> $buyButtons
     * @param array<string,mixed> $recipe
     * @return list<list<array<string,string>>>
     */
    private function keyboard(array $craftButtons, array $buyButtons, array $recipe): array
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
        $rows[] = [
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ['text' => '⬅️ Назад', 'callback_data' => $back],
        ];

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
