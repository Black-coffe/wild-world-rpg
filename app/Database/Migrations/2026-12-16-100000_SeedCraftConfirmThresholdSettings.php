<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * craft-batch-price-confirm (ADR-024) — пороги подтверждения крупной партии крафта.
 *
 * Жалоба 05.10.2026: «Крафт 50шт» одним нажатием списал 300 000 золота и занял 59 ч без единого
 * вопроса. Ядро `CraftOrderService::start()` теперь просит подтверждение, если партия ≥ `min_qty` штук
 * И стоит ≥ `min_gold` золота (слово владельца: «от 25 штук и дороже определённой суммы»); 0 выключает
 * своё условие, оба 0 — подтверждения нет. Читает `App\Services\Craft\CraftBatchConfirmPolicy`
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
                'rationale_text'     => 'С какой партии (в штуках) крафт просит подтвердить запуск экраном итога — вместе с порогом по золоту craft.confirm.min_gold: спрашиваем только партию, которая одновременно крупная И дорогая. 25 — ступень кнопок количества (1/5/10/25/50/100), с которой время партии уходит в десятки часов; 1, 5 и 10 штук стартуют одним нажатием, как раньше.',
                'effect_text'        => 'CraftOrderService::start(): партия ≥ N штук, которая к тому же стоит ≥ craft.confirm.min_gold, без подтверждения возвращает экран итога и ничего не списывает; «✅ Запустить» стартует ровно её. Бот и /play одинаково. 0 — условие по штукам выключено (спрашивает любая партия дороже порога золота).',
                'above_effect_text'  => 'Выше 25 — дорогие партии на 25–49 штук (например, T3-инструменты на сотни тысяч золота) снова уходят одним нажатием без итога.',
                'below_effect_text'  => 'Ниже 25 — подтверждение появляется и на небольших дорогих партиях (5–10 шт.); на 0 спрашивает любая партия дороже порога золота, даже одна штука.',
                'recommended_min'    => '10',
                'recommended_max'    => '50',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'craft.confirm.min_gold',
                'value_int'          => 50000,
                'default_value_text' => '50000',
                'rationale_text'     => 'С какой стоимости партии (золото за все штуки) крафт просит подтверждение — вместе с порогом по штукам craft.confirm.min_qty. 50 000 — порядок цены 4–8 штук T3-инструментов (5 000–12 000 за штуку): крупная дорогая партия показывает итог до списания, а дешёвые партии (бинты, еда) стартуют сразу, сколько бы их ни было.',
                'effect_text'        => 'CraftOrderService::start(): партия, чьё золото (gold_required × количество) ≥ N и в которой не меньше craft.confirm.min_qty штук, без подтверждения возвращает экран итога и ничего не списывает. Бот и /play одинаково. 0 — условие по золоту выключено (спрашивает любая партия от порога штук, даже бесплатная).',
                'above_effect_text'  => 'Выше 50 000 — крупные партии средней цены (десятки тысяч золота) уходят одним нажатием, повторяется случай «куда ушли деньги».',
                'below_effect_text'  => 'Ниже 50 000 — подтверждение и на крупных партиях недорогих рецептов; на 0 спрашивает каждая партия от порога штук, включая бинты и еду.',
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
