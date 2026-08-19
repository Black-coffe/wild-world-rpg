<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-173, дельта плана 1-2 (docs/specs/pve-reward-pool-whitelist/plan.md).
 * Первая seed-миграция (`2026-08-19-210000_Adr173SeedPveRewardPoolGameSettings`)
 * задала `pve.reward.expensive_threshold=5000` / `pve.reward.price_cap=10000` как
 * «поведение не меняется», не сверив с живым каталогом. Замер: самый дорогой
 * разрешённый белым списком предмет стоит 500 — при пороге 5000 запрос
 * `price >= 5000 AND type IN (белый список)` возвращает 0 строк, ветка «сильный
 * NPC» мертва. Разбивка по порогам внутри белого списка: 100 → 13 дорогих /
 * 31 дешёвый, 200 → 6/38, 500 → 1/43. 100 выбран как порог с обеими живыми
 * ветками; потолок 1000 — выше максимума каталога (500), но ловит будущие
 * дорогие добавления внутри разрешённых типов.
 *
 * Условный UPDATE: только если в БД всё ещё лежат дофиксовые значения
 * (5000 / 10000) — иначе ручная правка админа через /admin/game-settings
 * молча затирается. Идемпотентно: повторный прогон не меняет уже
 * ретюненные строки (условие `value_int = <старое>` больше не совпадёт).
 */
class Adr173RetunePveRewardThresholds extends Migration
{
    private const OLD_THRESHOLD = 5000;
    private const NEW_THRESHOLD = 100;
    private const OLD_PRICE_CAP = 10000;
    private const NEW_PRICE_CAP = 1000;

    private const OLD_KILLSWITCH_RATIONALE = 'Killswitch фильтрации пула PvE-наград по типу и потолку цены. true — RewardService применяет pve.reward.craft_types и pve.reward.price_cap. false — мгновенный откат к поведению до фикса (весь каталог по одной только цене), на случай если куратор пула сломает выдачу наград.';
    private const NEW_KILLSWITCH_RATIONALE = 'Killswitch фильтрации пула PvE-наград по типу и потолку цены. true — RewardService применяет pve.reward.craft_types и pve.reward.price_cap поверх настраиваемого expensive_threshold. false — откат к поведению до фикса (коммит 4f575bf7): пул делится ИСКЛЮЧИТЕЛЬНО историческим порогом 5000 (RewardService::LEGACY_EXPENSIVE_THRESHOLD), без whereIn по типу и без потолка. Этот порог фиксирован и не читает expensive_threshold — иначе после снижения expensive_threshold до 100 (story -04) выключенный killswitch раздавал бы почти весь каталог, включая robots 95000-100000 и workbench 120000, — то есть аварийный рычаг стал бы строго хуже аварии, от которой должен спасать.';
    private const OLD_KILLSWITCH_EFFECT    = 'RewardService::getRandomCraftedItems() — при false игнорирует craft_types и price_cap, фильтрует только по expensive_threshold (как до ADR-173).';
    private const NEW_KILLSWITCH_EFFECT    = 'RewardService::getRandomCraftedItems() — при false игнорирует craft_types и price_cap, делит пул фиксированным историческим порогом LEGACY_EXPENSIVE_THRESHOLD=5000 (не настраиваемым expensive_threshold).';
    private const OLD_KILLSWITCH_BELOW     = 'false возвращает старое поведение: в пул снова попадает весь каталог crafted_items без ограничения по типу и цене, включая роботов/верстаки/постройки.';
    private const NEW_KILLSWITCH_BELOW     = 'false возвращает дофиксовое поведение: пул делится историческим порогом 5000, без ограничения по типу и цене — включая роботов/верстаки/постройки. Это откат, а не эскалация: настраиваемый expensive_threshold (по умолчанию 100) при выключенном фильтре намеренно не используется.';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.expensive_threshold')
            ->where('value_int', self::OLD_THRESHOLD)
            ->where('default_value_text', (string) self::OLD_THRESHOLD)
            ->update([
                'value_int'          => self::NEW_THRESHOLD,
                'default_value_text' => (string) self::NEW_THRESHOLD,
                'recommended_min'    => '50',
                'recommended_max'    => '500',
                'hard_min'           => '10',
                'hard_max'           => '200000',
                'rationale_text'     => 'Порог цены, по которому RewardService делит NPC на «сильного» (дорогой пул) и «слабого» (дешёвый пул). Замер на живом каталоге (docs/specs/pve-reward-pool-whitelist/pve-reward-pool-whitelist-04.md): максимальная цена среди типов из pve.reward.craft_types — 500, поэтому дофиксовый дефолт 5000 давал 0 строк в «дорогом» пуле — ветка «победил сильного NPC» была мертва. Разбивка внутри белого списка: 100 → 13 дорогих/31 дешёвый, 200 → 6/38, 500 → 1/43. 100 выбран как порог, на котором обе ветки живые, а «дорогой» тир состоит из реально ценных позиций (кирки, компоненты, термокомбинезон).',
                'above_effect_text'  => 'При 500 «дорогой» пул сжимается до 1 позиции — победа над сильным NPC станет однообразной. При исходных 5000 пул пуст: ветка не работает вовсе.',
                'below_effect_text'  => 'Ниже 50 «дешёвый» пул почти исчезает (в белом списке минимальная цена 5) — слабый NPC перестанет давать что-либо в половине случаев.',
                'updated_at'         => $now,
            ]);

        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.price_cap')
            ->where('value_int', self::OLD_PRICE_CAP)
            ->where('default_value_text', (string) self::OLD_PRICE_CAP)
            ->update([
                'value_int'          => self::NEW_PRICE_CAP,
                'default_value_text' => (string) self::NEW_PRICE_CAP,
                'recommended_min'    => '200',
                'recommended_max'    => '5000',
                'hard_min'           => '0',
                'hard_max'           => '200000',
                'rationale_text'     => 'Потолок цены предмета, допущенного в пул наград PvE. Замер на живом каталоге: максимальная цена среди разрешённых типов — 500, дофиксовый дефолт 10000 не отсекал ничего и никогда. 1000 выбран с запасом вдвое над нынешним максимумом — ловит будущие дорогие добавления внутри разрешённых типов, не отсекая текущий каталог.',
                'above_effect_text'  => 'При 20000 потолок снова ничего не отсекает среди сегодняшних типов — перестаёт быть рубежом на случай будущих дорогих добавлений.',
                'below_effect_text'  => 'Ниже 500 потолок начинает резать сегодняшний каталог (максимум 500) — из наград исчезнут самые ценные допустимые позиции.',
                'updated_at'         => $now,
            ]);

        // Находка ревью: killswitch обещал «откат к дофиксовому поведению»,
        // что верно только пока формулировка называет исторический порог,
        // а не настраиваемый expensive_threshold (см. RewardService::LEGACY_EXPENSIVE_THRESHOLD).
        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.type_filter_enabled')
            ->where('rationale_text', self::OLD_KILLSWITCH_RATIONALE)
            ->update([
                'rationale_text'    => self::NEW_KILLSWITCH_RATIONALE,
                'effect_text'       => self::NEW_KILLSWITCH_EFFECT,
                'below_effect_text' => self::NEW_KILLSWITCH_BELOW,
                'updated_at'        => $now,
            ]);
    }

    public function down(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.expensive_threshold')
            ->where('value_int', self::NEW_THRESHOLD)
            ->where('default_value_text', (string) self::NEW_THRESHOLD)
            ->update([
                'value_int'          => self::OLD_THRESHOLD,
                'default_value_text' => (string) self::OLD_THRESHOLD,
                'recommended_min'    => '2000',
                'recommended_max'    => '20000',
                'hard_min'           => '100',
                'hard_max'           => '200000',
                'updated_at'         => $now,
            ]);

        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.price_cap')
            ->where('value_int', self::NEW_PRICE_CAP)
            ->where('default_value_text', (string) self::NEW_PRICE_CAP)
            ->update([
                'value_int'          => self::OLD_PRICE_CAP,
                'default_value_text' => (string) self::OLD_PRICE_CAP,
                'recommended_min'    => '2000',
                'recommended_max'    => '20000',
                'hard_min'           => '0',
                'hard_max'           => '200000',
                'updated_at'         => $now,
            ]);

        $this->db->table('game_settings')
            ->where('setting_key', 'pve.reward.type_filter_enabled')
            ->where('rationale_text', self::NEW_KILLSWITCH_RATIONALE)
            ->update([
                'rationale_text'    => self::OLD_KILLSWITCH_RATIONALE,
                'effect_text'       => self::OLD_KILLSWITCH_EFFECT,
                'below_effect_text' => self::OLD_KILLSWITCH_BELOW,
                'updated_at'        => $now,
            ]);
    }
}
