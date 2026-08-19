<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Services\GameSettings\GameSettingsService;

class RewardService
{
    /**
     * Дефолты на случай отсутствия ключа в `game_settings` (свежее окружение,
     * тесты). Прод-значение живёт в БД и правится в админке (ADR-173) —
     * менять эти константы означает менять только точку старта, не живую
     * настройку. Значения обязаны совпадать буква в букву с seed-миграцией
     * story `-01` (docs/specs/pve-reward-pool-whitelist/plan.md → «Контракт ключей»).
     */
    private const DEFAULT_CRAFT_TYPES         = 'drug,food,component,tool,weapon,clothing';
    private const DEFAULT_PRICE_CAP           = 10000;
    private const DEFAULT_EXPENSIVE_THRESHOLD = 5000;
    private const DEFAULT_TYPE_FILTER_ENABLED = true;

    private CharacterResourceModel $characterResourceModel;
    private ResourceModel $resourceModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private GameSettingsService $settings;

    /**
     * Наградные статы применяются атомарно через CharacterStatsService (ADR-151),
     * поэтому ни CharacterRepository, ни CharacterModel, ни logger здесь больше
     * не нужны — конструктор поднимает только модели ресурсов/предметов.
     */
    public function __construct(?GameSettingsService $settings = null)
    {
        $this->characterResourceModel  = new CharacterResourceModel();
        $this->resourceModel           = new ResourceModel();
        $this->craftedItemsLogModel    = new CraftedItemsLogModel();
        $this->settings                = $settings ?? new GameSettingsService();
    }

    /**
     * Килсвитч белого списка по типу и потолка цены (ADR-173). `false` →
     * пул наград фильтруется только порогом цены, как до фикса.
     */
    private function typeFilterEnabled(): bool
    {
        $v = $this->settings->get('pve.reward.type_filter_enabled', self::DEFAULT_TYPE_FILTER_ENABLED);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<string> Допустимые crafted_items.type для розыгрыша PvE-наград
     */
    private function eligibleCraftTypes(): array
    {
        $v   = $this->settings->get('pve.reward.craft_types', self::DEFAULT_CRAFT_TYPES);
        $raw = is_string($v) ? $v : self::DEFAULT_CRAFT_TYPES;
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $t = trim($part);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out === [] ? explode(',', self::DEFAULT_CRAFT_TYPES) : $out;
    }

    /** Потолок цены предмета в пуле. `0` = без потолка. */
    private function priceCap(): int
    {
        $v = $this->settings->get('pve.reward.price_cap', self::DEFAULT_PRICE_CAP);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : self::DEFAULT_PRICE_CAP;
    }

    /** Порог «дорогой/дешёвый» пул — заменяет бывший литерал 5000. */
    private function expensiveThreshold(): int
    {
        $v = $this->settings->get('pve.reward.expensive_threshold', self::DEFAULT_EXPENSIVE_THRESHOLD);
        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::DEFAULT_EXPENSIVE_THRESHOLD;
    }

    /**
     * Выдаёт награды с учётом новых правил:
     * - сравниваем уровень winner и loser
     * - в зависимости от этого выдаём разные статы, золото, ресурсы и крафтовые предметы
     */
    public function grantRewards(BattleCharacter $winner, BattleCharacter $loser): array
    {
        // Проверяем, кто слабее по уровню:
        $npcStrongerOrEqual = ($loser->level >= $winner->level);

        // 1. Генерируем прибавку к статам и золоту
        if ($npcStrongerOrEqual) {
            // NPC сильнее или равен игроку
            // Опыт +0.01..0.10, Ловкость +0.01..0.10, Интеллект +0.01..0.10, Сила +0.01..0.15
            // Золото 1000..10000
            $expGain       = $this->randomFloat(0.01, 0.10);
            $agiGain       = $this->randomFloat(0.01, 0.10);
            $intGain       = $this->randomFloat(0.01, 0.10);
            $strGain       = $this->randomFloat(0.01, 0.15);
            $goldGained    = mt_rand(1000, 10000);

        } else {
            // NPC слабее
            // Только Сила: +0.01..0.05, Золото: 100..1000
            $expGain       = 0;  // Опыт не даём (по вашему описанию «больше ничего»)
            $agiGain       = 0;
            $intGain       = 0;
            $strGain       = $this->randomFloat(0.01, 0.05);
            $goldGained    = mt_rand(100, 1000);
        }

        // 2. Обновляем статы игрока — АТОМАРНО (ADR-151, класс lost-update).
        //    Все 5 наградных статов (gold/exp/str/agi/int) применяются ОДНОЙ
        //    дельтой к СВЕЖИМ значениям под row-lock (SELECT … FOR UPDATE),
        //    а НЕ абсолютом из снапшота $winner (BattleCharacter, прочитан ДО
        //    боя). Раньше exp/agi/int/str писались абсолютом из снапшота, а gold
        //    хоть и шёл через atomic adjustGold — тут же затирался абсолютным
        //    update'ом gold в PvEService. Окно широкое: AutoPveHandler крутит
        //    бой каждую минуту, параллельно игрок может купить в магазине,
        //    собрать tribute, выиграть другой бой → те начисления терялись.
        (new \App\Services\Player\CharacterStatsService())->adjust((int) $winner->id, [
            'gold'       => $goldGained,
            'experience' => $expGain,
            'strength'   => $strGain,
            'agility'    => $agiGain,
            'intellect'  => $intGain,
        ]);

        // 3. Выдаём ресурсы
        //    Если NPC был сильнее/равен => от 1..5 видов, rarity 2..6, по 1..5 штук
        //    Иначе => 1..2 видов, rarity 6..10, 1..2 штуки
        $resourcesGiven = [];
        if ($npcStrongerOrEqual) {
            $countResourceTypes = mt_rand(1, 5); // от 1 до 5 видов
            $resourcesGiven = $this->getRandomResources($countResourceTypes, 2, 6, 1, 5);
        } else {
            $countResourceTypes = mt_rand(1, 2); // от 1 до 2 видов
            $resourcesGiven = $this->getRandomResources($countResourceTypes, 6, 10, 1, 2);
        }

        // Сохраняем в character_resources
        foreach ($resourcesGiven as $res) {
            $this->characterResourceModel->addOrIncreaseResource(
                $winner->id,
                $res['id'],
                $res['quantity']
            );
        }

        // 4. Крафтовые предметы
        //    Если NPC сильнее/равен => даём 1..5 разных дорогих предметов, каждый по 1..5 шт.
        //    Если слабее => 50% шанс дать 1 «недорогой» предмет, 1 шт
        $craftedItemsGiven = [];
        if ($npcStrongerOrEqual) {
            $countItems = mt_rand(1, 5);
            $craftedItemsGiven = $this->getRandomCraftedItems($countItems, true); // «дорогие»
        } else {
            // 50% шанс
            if (mt_rand(1, 100) <= 50) {
                // Дадим 1 предмет
                $oneItem = $this->getRandomCraftedItems(1, false); // «недорогой»
                $craftedItemsGiven = $oneItem; // возможно массив на 1 элемент
            }
        }

        // Сохраняем предметы в crafted_items_log
        // v0.51.31 fix (Bug #9): UPSERT замість blind INSERT — інакше PvE reward
        // створює дубль stack для items які вже є у inventory. Reported у
        // Bugs-info: "появилось отдельное успокоительное" (346 ед. + 1 ед. окремо).
        foreach ($craftedItemsGiven as $ci) {
            $existing = $this->craftedItemsLogModel
                ->where('character_id', $winner->id)
                ->where('crafted_item_id', $ci['id'])
                ->first();
            if ($existing) {
                $this->craftedItemsLogModel->update($existing['id'], [
                    'quantity' => (int) $existing['quantity'] + (int) $ci['quantity'],
                ]);
            } else {
                // Прочность — из шаблона `crafted_items`, как это делает крафт
                // (GenericCraftCompletionHandler). Захардкоженная 100 выдавала
                // предмет уже изношенным: робот приезжал со 100 из 1200, сапёрная
                // лопата со 100 из 120 — «награда, которую сразу надо чинить».
                //
                // 🔴 2026-08-09: fallback тоже был 100 — и для предметов с пустым
                // шаблоном (вся еда, база 0) печатал 100 доз в одной единице.
                // Безопасный минимум — 1 доза, как в трофеях PvP (LootProcessor).
                $tplDur = CraftedItemsLogModel::baseCharges($ci['durability_count'] ?? null);
                $this->craftedItemsLogModel->insert([
                    'character_id'      => $winner->id,
                    'task_id'           => 1,
                    'crafted_item_id'   => $ci['id'],
                    'type'              => $ci['type'],
                    'direction_craft'   => $ci['direction_craft'],
                    'crafting_location' => 'all',
                    'durability_count'  => $tplDur,
                    'quantity'          => $ci['quantity'],
                ]);
            }
        }

        // 5. Формируем итог для вывода
        //    (учтём, что опыт возвращаем как число, золото и т.д.)
        $firstResourceName  = (!empty($resourcesGiven)) ? $resourcesGiven[0]['name'] : null;
        $firstCraftItemName = (!empty($craftedItemsGiven)) ? $craftedItemsGiven[0]['name_rus'] : null;

        return [
            // Новые показатели (сколько фактически добавилось)
            'exp'         => $expGain,
            'gold'        => $goldGained,
            'strength'    => $strGain,
            'agility'     => $agiGain,
            'intellect'   => $intGain,

            // Для простоты вернём только «первый» ресурс/предмет в награде,
            // а при желании можно вернуть массив
            'resource'    => $firstResourceName,
            'craftedItem' => $firstCraftItemName,
        ];
    }

    /**
     * Генерирует float число в диапазоне [min, max], округлённое до 2 знаков.
     */
    private function randomFloat(float $min, float $max): float
    {
        $val = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($val, 2);
    }

    /**
     * Получает N случайных ресурсов из БД с учётом заданной редкости (минимум..максимум),
     * и случайным количеством (quantityMin..quantityMax).
     */
    private function getRandomResources(
        int $count,
        int $rarityMin,
        int $rarityMax,
        int $quantityMin,
        int $quantityMax
    ): array {
        $results = [];

        // Получаем список ресурсов, подходящих по редкости
        $all = $this->resourceModel
            ->where('rarity >=', $rarityMin)
            ->where('rarity <=', $rarityMax)
            ->findAll();

        // Если нет подходящих, возвращаем пустой массив
        if (empty($all)) {
            return [];
        }

        // Перемешиваем
        shuffle($all);

        // Берём первые $count (или меньше, если нет столько)
        $selected = array_slice($all, 0, $count);

        // Добавляем рандомное количество к каждому
        foreach ($selected as $res) {
            $res['quantity'] = mt_rand($quantityMin, $quantityMax);
            $results[] = $res;
        }

        return $results;
    }

    /**
     * Получает N случайных крафтовых предметов — «дорогих» или «дешёвых».
     * По-простому считаем, что в таблице `crafted_items` есть поле `price`.
     * - expensive=TRUE  => where('price >= expensiveThreshold()')
     * - otherwise       => where('price <  expensiveThreshold()')
     *
     * ADR-173: пул дополнительно курируется белым списком `type` и потолком
     * цены (пока не выключены `pve.reward.type_filter_enabled`) — до фикса
     * сюда попадал весь каталог, включая постройки/роботов/дронов/верстаки.
     *
     * Свежий инстанс модели на каждый вызов: `$this->craftedItemModel` —
     * общее состояние сервиса, а `where()` CI4-моделей навешивается на этот
     * же инстанс. `AutoPveHandler` крутит `grantRewards()` в кроне на одном
     * процессе — накопленные `where()` со второго вызова аукнулись бы
     * молчаливо пустым пулом.
     */
    private function getRandomCraftedItems(int $count, bool $expensive): array
    {
        $query = new CraftedItemsModel();
        $threshold = $this->expensiveThreshold();
        if ($expensive) {
            // «Максимально дорогие»
            $query = $query->where('price >=', $threshold);
        } else {
            // «Не очень дорогие»
            $query = $query->where('price <', $threshold);
        }

        if ($this->typeFilterEnabled()) {
            $query = $query->whereIn('type', $this->eligibleCraftTypes());

            $cap = $this->priceCap();
            if ($cap > 0) {
                $query = $query->where('price <=', $cap);
            }
        }

        $all = $query->findAll();
        if (empty($all)) {
            return [];
        }

        shuffle($all);

        // Возьмём до $count штук
        $selected = array_slice($all, 0, $count);

        // Для сильного NPC сказано: каждый предмет 1..5 шт.
        // Для слабого NPC: ровно 1 шт (но можно при желании варьировать).
        $items = [];
        foreach ($selected as $item) {
            // Если «expensive» (сильный NPC) => quantity 1..5
            // Иначе «дешёвый» => quantity = 1
            $quantity = $expensive ? mt_rand(1, 5) : 1;

            $item['quantity'] = $quantity;
            $items[] = $item;
        }

        return $items;
    }

}
