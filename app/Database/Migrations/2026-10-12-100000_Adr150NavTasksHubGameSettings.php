<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 Слайс 3 — killswitch группы «📋 Дела» (пере-архитектура навигации).
 *
 * Сводит в один дом четыре источника целей, живших порознь: активные таймеры
 * (`character_tasks` in_work — до сих пор ТОЛЬКО slash `/tasks`, ни одной кнопки),
 * полярную звезду (ADR-139, строчка-приписка на Персе/Карте), квесты (`questAndTask`,
 * ADR-126) и задания дня (ADR-109, вход был только с карточки Перса).
 *
 * Диагноз (firehose ADR-148, срез 2026-07-09): экраны целей открывали 25 из 83 активных
 * игроков (30%); все 326 обращений к `/tasks` — командой, ни одного по кнопке.
 *
 * false (default) — DORMANT: byte-identical (`/tasks` = легаси-список, нижнее меню без
 * «📋 Дела», хаб «Действия» ведёт прямо в `questAndTask`). true — «📋 Дела» становится домом
 * целей. Активация после Tier-3 + решение владельца. Своп полного 6-грида — на финале ADR-150.
 *
 * game_settings = KEEP (WipeManifest не трогаем — таблица уже классифицирована).
 * Idempotent по setting_key. Категория world (рядом с navigation.world_hub / me_hub).
 */
class Adr150NavTasksHubGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.tasks_hub.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 Слайс 3: killswitch группы «📋 Дела». Аудит firehose (2026-07-09): экраны целей находят лишь 25 из 83 активных игроков (30%), а все 326 обращений к активным задачам пришли командой /tasks — кнопки на них не существовало. Полярная звезда, квесты, задания дня и таймеры жили на четырёх разных экранах. false (default) — DORMANT, byte-identical. true — единый экран «Дела» + нижняя кнопка + вход из хаба «Действия». Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'Появляется экран TasksSurfaceService (callback tasksHub): активные таймеры с остатком времени, полярная звезда (ADR-139), счётчики квестов и заданий дня. Нижнее меню получает кнопку «📋 Дела»; /tasks открывает этот же экран; кнопка в хабе «Действия» ведёт в «Дела» вместо прямого прыжка в questAndTask. Кнопка прерывания задачи переименована в честное «⛔️ Прервать» (легаси-текст обещал «моментально снять задачу», хотя награда теряется).',
                'above_effect_text'  => 'true — игрок видит «что идёт сейчас» и «что дальше» в одном месте, из любого экрана в один тап; квесты и дейлики остаются в одном тапе оттуда. Ожидаемый эффект — рост доли игроков, доходящих до целей, выше нынешних 30%.',
                'below_effect_text'  => 'false — текущее поведение: таймеры доступны только через /tasks (кнопки нет), квесты вложены в «Действия», задания дня — с карточки Перса, полярная звезда — приписка на чужих экранах.',
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
        $this->db->table('game_settings')->where('setting_key', 'navigation.tasks_hub.enabled')->delete();
    }
}
