<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 — анти-сговор хардненинг «Трофейной подати» (Фаза 2-hardening, admin-tunable ADR-024).
 *
 * Добавляет поведенческие гейты (IP/device для Telegram-бота неприменимы — вебхук приходит с
 * серверов Telegram, игрок-IP недоступен; telegram_users IP не хранит). Все ключи category='combat',
 * под общим killswitch tribute.enabled. Idempotent (по setting_key).
 *
 * Ключевой принцип (отражён в rationale): false-positive детекции сговора — БЕЗОПАСНОЕ направление
 * отказа (подать просто не создаётся, никто зря не закабалён), а false-negative = утечка ресурсов
 * коллудеру. Поэтому пороги склоняются к блокировке.
 */
class Adr135TributeAntiCollusionSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── Берст-гейт: временной разброс квалифицирующих побед ──────────
            [
                'setting_key'        => 'tribute.min_win_span_hours',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'Минимальный разброс во времени (часов между первой и последней) для квалифицирующих побед доминирования. Анти-сговор: альт, «сливающий» серию из 5 боёв за минуты, не создаёт подать. Реальное превосходство копится часами/днями. 0 = гейт выключен.',
                'effect_text'        => 'При создании подати: если (max(created_at) − min(created_at)) побед хозяина над жертвой < N часов → отказ (reason=wins_too_bursty). Считается только когда порог побед уже достигнут.',
                'above_effect_text'  => 'При 48+ даже честная вечерняя серия может не пройти → ложные отказы (но FP здесь безопасен — подать просто не создаётся).',
                'below_effect_text'  => 'При 0 берст-гейт выключен → тривиальный сговор «5 атак подряд по стоящему альту за минуту» создаёт подать.',
                'recommended_min'    => '1',
                'recommended_max'    => '12',
                'hard_min'           => '0',
                'hard_max'           => '720',
            ],

            // ── Корреляция активности (сигнатура одного оператора) ───────────
            [
                'setting_key'        => 'tribute.collusion_min_samples',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимум действий (action_log за окно доминирования) у КАЖДОГО из двух аккаунтов, чтобы вообще судить о сговоре по корреляции активности. Скудная выборка → не судим (анти-false-positive). 0 = корреляционный гейт выключен.',
                'effect_text'        => 'Если у обоих аккаунтов ≥ N действий за окно И при этом НИ РАЗУ не было одновременной активности (см. collusion_concurrency_seconds) → подать блокируется (reason=collusion_suspected): один человек физически не действует двумя аккаунтами одновременно. Запрос дорогой → выполняется только когда подать иначе создалась бы.',
                'above_effect_text'  => 'При 1000+ почти никто не набирает выборку → корреляционный гейт фактически спит, остаётся только берст+реципрокность.',
                'below_effect_text'  => 'При 1-5 судим по мизерной выборке → растёт риск ложного срабатывания на малоактивных честных парах (но FP безопасен — отказ в создании подати).',
                'recommended_min'    => '30',
                'recommended_max'    => '200',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'tribute.collusion_concurrency_seconds',
                'value_type'         => 'int',
                'value_int'          => 120,
                'default_value_text' => '120',
                'rationale_text'     => 'Окно (секунд), в пределах которого действия двух аккаунтов считаются «одновременными». Хотя бы одна такая пара за весь период = доказательство двух разных людей (один оператор не может действовать двумя аккаунтами в одну минуту).',
                'effect_text'        => 'Используется при подсчёте concurrentActions в корреляционном гейте: для каждого действия аккаунта A ищется действие B в пределах ±N секунд. Если за всё окно совпадений 0 (при достаточной выборке) → сговор.',
                'above_effect_text'  => 'При 3600 (час) почти любые две сессии «пересекаются» → корреляционный гейт почти никогда не срабатывает (много false-negative — сговор проходит).',
                'below_effect_text'  => 'При 1-5 сек даже два реальных игрока почти никогда не попадут в окно → рост ложных срабатываний (FP безопасен, но честные доминаторы теряют подать).',
                'recommended_min'    => '60',
                'recommended_max'    => '300',
                'hard_min'           => '1',
                'hard_max'           => '86400',
            ],
        ];

        $defaults = [
            'category'        => 'combat',
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'tribute.min_win_span_hours',
            'tribute.collusion_min_samples',
            'tribute.collusion_concurrency_seconds',
        ])->delete();
    }
}
