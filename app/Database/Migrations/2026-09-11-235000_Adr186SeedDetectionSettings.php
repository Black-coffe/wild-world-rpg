<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 (`docs/specs/pvp-detection-clarity/`, story `-07`) — три ручки экрана
 * «Обнаружение игроков»: сколько соседей показать текстом, с какого возраста бездействия
 * персонаж считается брошенным, печатать ли строку-остаток «и ещё N поблизости».
 * Категория `world` (см. `## Contracts` плана — это единственные три ключа спеки не в `combat`).
 *
 * Триггер — прод-инцидент: одно сообщение обнаружения донесло 123 строки, до 369 кнопок
 * и оборвалось посреди слова на лимите Telegram (`recon-prod.md`). Дефолты ниже — не
 * произвольные числа: `max_listed=12` подобран так, чтобы даже при длинных именах жёсткая
 * граница длины текста (enforced в `PlayerDetectionService::renderDetectionMessage()`,
 * не полагаясь на одну эту ручку) срабатывала редко; `inactive_days=14` — тот же порог
 * бездействия, что уже используется в аудитах «брошенных» персонажей проекта.
 *
 * Idempotent по `setting_key` (паттерн `Adr186SeedPvpRestrictionSettings`). `game_settings`
 * = KEEP (WipeManifest не трогаем — новых таблиц/player-колонок эта миграция не создаёт).
 */
class Adr186SeedDetectionSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.detection.max_listed',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Сколько ближайших соседей показываются построчно в сообщении обнаружения. Прод-инцидент — 123 строки и до 369 кнопок в одном сообщении, оборвавшемся на полуслове об лимит Telegram. 12 — достаточно, чтобы игрок увидел реальную угрозу рядом, но не настолько много, чтобы список стал нечитаемой простынёй даже при коротких именах.',
                'effect_text'        => 'PlayerDetectionService::renderDetectionMessage() выводит построчно первых `max_listed` соседей (после сортировки: активные недавно → ближе → по id), остальные сворачиваются в одну строку «и ещё N поблизости».',
                'above_effect_text'  => 'Выше 12 — список длиннее, полезен при плотной охоте на несколько целей сразу, но растёт риск упереться в жёсткую границу длины текста раньше и свернуть больше строк, чем ожидает игрок по значению ручки.',
                'below_effect_text'  => 'Ниже 12 — сообщение короче и быстрее читается, но у игрока в плотной локации может остаться неверное впечатление «рядом мало кто есть», хотя свёрнутая строка-остаток это компенсирует текстом.',
                'recommended_min'    => '5',
                'recommended_max'    => '25',
                'hard_min'           => '1',
                'hard_max'           => '50',
            ],
            [
                'setting_key'        => 'world.detection.inactive_days',
                'value_type'         => 'int',
                'value_int'          => 14,
                'default_value_text' => '14',
                'rationale_text'     => 'После скольки дней без действий (`action_log.created_at`, НЕ `characters.last_update_time` — она пуста у всех 709 строк на проде) сосед в списке обнаружения помечается как брошенный. 14 дней — персонаж ещё может вернуться после отпуска/паузы, но уже заметно неактивен для живого противостояния.',
                'effect_text'        => 'PlayerDetectionService::renderDetectionMessage() помечает строку соседа как брошенную, если максимум `action_log.created_at` этого персонажа старше `inactive_days` дней (или записей нет вовсе).',
                'above_effect_text'  => 'Выше 14 — метка «брошен» появляется реже, часть давно неактивных персонажей продолжает выглядеть как живая цель.',
                'below_effect_text'  => 'Ниже 14 — метка «брошен» появляется у персонажей, которые просто взяли короткую паузу, создавая ложное впечатление лёгкой добычи.',
                'recommended_min'    => '7',
                'recommended_max'    => '60',
                'hard_min'           => '1',
                'hard_max'           => '365',
            ],
            [
                'setting_key'        => 'world.detection.show_inactive_summary',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Печатать ли свёрнутую строку-остаток «и ещё N поблизости», когда соседей больше, чем `max_listed`. true — игрок должен знать, что список неполон, а не решить, что рядом больше никого нет.',
                'effect_text'        => 'PlayerDetectionService::renderDetectionMessage() при false не добавляет строку-остаток вовсе, даже если часть соседей свёрнута.',
                'above_effect_text'  => 'Булев ключ — «above» не применимо: true это печать строки-остатка.',
                'below_effect_text'  => 'Булев ключ — «below» не применимо: false скрывает сам факт, что список был длиннее, чем показано.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
        ];

        $defaults = [
            'category'     => 'world',
            'value_float'  => null,
            'value_string' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $merged = array_merge($defaults, $row);
            // Каждая строка задаёт ровно один из value_int/value_bool явно, остальные — null
            // из $defaults; но не все ключи объявляют все три value_* поля, поэтому добираем.
            $merged += ['value_int' => null, 'value_bool' => null];
            $this->db->table('game_settings')->insert($merged);
        }
    }

    public function down(): void
    {
        $keys = [
            'world.detection.max_listed',
            'world.detection.inactive_days',
            'world.detection.show_inactive_summary',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
