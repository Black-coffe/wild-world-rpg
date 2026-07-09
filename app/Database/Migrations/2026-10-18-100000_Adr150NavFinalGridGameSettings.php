<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 ФИНАЛ — killswitch полного свопа нижнего меню на целевую сетку 2×3.
 *
 * Каркас `[🌍 Мир · 🧑 Я · 🔨 Крафт] / [🏠 База · 📋 Дела · ⚙️ Ещё]` — шесть стабильных,
 * взаимоисключающих групп «место→действие» (решение грилл-дебата двух советов). До финала
 * каркас был переходным: `[Перс · База · Крафт · 🌍 Мир] / [📋 Дела · ⚙️ Ещё]` — слайсы
 * поочерёдно добавляли дома групп, но старая ось («Перс» рядом с «Картой») оставалась.
 *
 * 🔴 Гейт-конъюнкция в коде ({@see \App\Services\Telegram\BotMenuService::finalGridEnabled}):
 * своп НЕ произойдёт, пока не подняты world_hub + tasks_hub + more_hub. Кнопка не может вести
 * в дом, который ещё не построен.
 *
 * false (default) — DORMANT: каркас переходный, карточка Перса byte-identical.
 * true — сетка 2×3 + с карточки Перса уходят кросс-групповые кнопки (📡 Маяки → «🏠 База»,
 * 🛒 Магазин / 🎮 Развлечения / 👥 Реферал → «⚙️ Ещё»).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Idempotent по setting_key. Категория world.
 */
class Adr150NavFinalGridGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.final_grid.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 ФИНАЛ: killswitch полного свопа нижнего меню на целевую сетку 2x3 «Мир / Я / Крафт // База / Дела / Ещё». Слайсы 1-5 построили дома всех шести групп, но каркас оставался переходным: старая ось смешивала статус (Перс), место (База) и вид-тупик (Карта). Требует world_hub + tasks_hub + more_hub включёнными (гейт-конъюнкция в коде: кнопка не может вести в непостроенный дом). false (default) — DORMANT, byte-identical. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'Нижняя reply-клавиатура становится 2x3: «🌍 Мир · 🧑 Я · 🔨 Крафт» / «🏠 База · 📋 Дела · ⚙️ Ещё». С карточки Перса уходят кросс-групповые кнопки (📡 Маяки — они на экране Базы; 🛒 Магазин, 🎮 Развлечения, 👥 Позови выжившего — они в «Ещё»). Пере-аттач каркаса живым игрокам идёт своим маркером (NavMenuRefreshService), т.е. новое меню доедет один раз при первом же сообщении с подписью reply-кнопки.',
                'above_effect_text'  => 'true — у игрока шесть выученных мест вместо пяти смешанных ярлыков; каждая частая цель достижима в один тап с любого экрана; карточка Перса перестаёт быть свалкой чужих кнопок (13 → 8). Риск: у игрока меняется моторика (кнопки переезжают), поэтому пере-аттач приходит с пояснением, что где лежит.',
                'below_effect_text'  => 'false — каркас остаётся переходным: «Перс» и «Карта» на старых местах, кросс-групповые кнопки продолжают дублироваться на карточке Перса. Все дома групп при этом уже работают (слайсы 1-5), просто до них дальше идти.',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
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
        $this->db->table('game_settings')->where('setting_key', 'navigation.final_grid.enabled')->delete();
    }
}
