<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W2.N3-01 (ADR-024, ADR-190) — лимиты очереди крафта переезжают из `Config\GameBalance`
 * (`craftMaxQueuePerRecipe`, `craftMaxConcurrentSlots`) в GameSettings. Значения прежние (10 и 3):
 * поведение прода не меняется, теперь их можно крутить из админки. Читает
 * `App\Services\Craft\CraftOrderService` — и для бота, и для веба.
 *
 * Идемпотентна: существующий ключ не перезаписывается (значение, выставленное в админке, живёт).
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц и колонок нет.
 */
class SeedCraftQueueLimitSettings extends Migration
{
    private const KEYS = ['craft.queue.max_per_recipe', 'craft.queue.max_slots'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'craft.queue.max_per_recipe',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Сколько заказов одного рецепта (идущий + ожидающие) персонаж держит одновременно. 10 — прежнее значение Config\GameBalance с v0.51.129 (идея сообщества #1): хватает, чтобы поставить партию на ночь, но не превращает очередь в бесконечный склад заказов, сырьё которых уже списано.',
                'effect_text'        => 'CraftOrderService: старт крафта при уже N заказах того же рецепта отвечает «Очередь крафта заполнена (N макс.)». Проверка идёт и в гейте, и повторно под блокировкой строки персонажа в транзакции старта. Действует на бот и веб одинаково.',
                'above_effect_text'  => 'Выше 10 — игрок вешает на ночь десятки заказов одного рецепта: сырьё списывается сразу, очередь растёт, а отмена возвращает его пачками — больше строк character_tasks и ожидания без игры.',
                'below_effect_text'  => 'Ниже 10 — очередь заканчивается быстро, игроку чаще приходится возвращаться, чтобы поставить следующую партию; на 1 очередь фактически выключена (только идущий заказ).',
                'recommended_min'    => '3',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '50',
            ],
            [
                'setting_key'        => 'craft.queue.max_slots',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько РАЗНЫХ рецептов крафта (tasks.type=craft, идущие или в очереди) персонаж ведёт одновременно. 3 — прежнее значение Config\GameBalance с v0.51.129: параллельная работа трёх верстаков без ощущения, что крафт идёт сам собой бесконечно.',
                'effect_text'        => 'CraftOrderService: старт НОВОГО рецепта при N занятых слотах отвечает «Все N слота крафта заняты». Свой рецепт в очередь ставится без учёта слотов. Проверка идёт и в гейте, и повторно под блокировкой строки персонажа. Бот и веб одинаково.',
                'above_effect_text'  => 'Выше 3 — крафт распараллеливается шире, скорость выпуска предметов растёт кратно числу слотов, экономика получает больше готовых вещей за то же время.',
                'below_effect_text'  => 'Ниже 3 — одновременно идёт меньше разных рецептов; на 1 персонаж крафтит строго один рецепт за раз, и все остальные ждут.',
                'recommended_min'    => '1',
                'recommended_max'    => '6',
                'hard_min'           => '1',
                'hard_max'           => '10',
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
