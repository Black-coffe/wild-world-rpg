<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * craft-batch-price-confirm (ADR-024) — пороги подтверждения крупной партии крафта.
 *
 * Жалоба 05.10.2026: «Крафт 50шт» одним нажатием списал 300 000 золота и занял 59 ч без единого
 * вопроса. Ядро `CraftOrderService::start()` теперь просит подтверждение, если партия ≥ `min_qty` штук
 * ИЛИ стоит ≥ `min_gold` золота; 0 выключает своё условие. Читает `App\Services\Craft\CraftBatchConfirmPolicy`
 * — одинаково для бота и `/play`.
 *
 * Идемпотентна: существующий ключ не перезаписывается (значение, выставленное в админке, живёт).
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц и колонок нет.
 */
class SeedCraftConfirmThresholdSettings extends Migration
{
    private const KEYS = ['craft.confirm.min_qty', 'craft.confirm.min_gold'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'craft.confirm.min_qty',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'С какой партии (в штуках) крафт просит подтвердить запуск экраном итога: золото, ресурсы, компоненты и время всей партии. 25 — ступень кнопок количества (1/5/10/25/50/100), с которой время партии уходит в десятки часов даже на дешёвых рецептах; 1, 5 и 10 штук стартуют одним нажатием, как раньше.',
                'effect_text'        => 'CraftOrderService::start(): партия ≥ N штук без подтверждения возвращает экран итога и ничего не списывает; «✅ Запустить» стартует ровно её. Бот и /play одинаково. Условие ИЛИ с craft.confirm.min_gold. 0 — условие по штукам выключено.',
                'above_effect_text'  => 'Выше 25 — большие партии по штукам снова уходят одним нажатием; остаётся только защита по золоту, дешёвые рецепты на 50–100 шт. запускаются без итога и времени.',
                'below_effect_text'  => 'Ниже 25 — подтверждение появляется на частых партиях (5–10 шт.), лишний тап на каждом крафте раздражает и приучает жать «Запустить» не глядя.',
                'recommended_min'    => '10',
                'recommended_max'    => '50',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'craft.confirm.min_gold',
                'value_int'          => 50000,
                'default_value_text' => '50000',
                'rationale_text'     => 'С какой стоимости партии (золото за все штуки) крафт просит подтверждение, даже если штук мало. 50 000 — порядок цены 4–8 штук T3-инструментов (5 000–12 000 за штуку): дорогая партия всегда показывает итог до списания, дешёвые одиночные крафты — нет.',
                'effect_text'        => 'CraftOrderService::start(): партия, чьё золото (gold_required × количество) ≥ N, без подтверждения возвращает экран итога и ничего не списывает. Бот и /play одинаково. Условие ИЛИ с craft.confirm.min_qty. 0 — условие по золоту выключено.',
                'above_effect_text'  => 'Выше 50 000 — дорогие партии T3 (десятки тысяч золота) уходят одним нажатием, повторяется случай «куда ушли деньги» на 5–9 штуках.',
                'below_effect_text'  => 'Ниже 50 000 — подтверждение на одиночных T3-крафтах и на средних партиях; на 10 000 и ниже почти каждый профессиональный крафт требует лишний тап.',
                'recommended_min'    => '20000',
                'recommended_max'    => '200000',
                'hard_min'           => '0',
                'hard_max'           => '10000000',
            ],
        ];

        $defaults = [
            'category'     => 'craft',
            'value_type'   => 'int',
            'value_float'  => null,
            'value_bool'   => null,
            'value_string' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', self::KEYS)->delete();
    }
}
