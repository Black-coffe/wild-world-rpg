<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * transport-09 (ADR-174, поправка владельца «разбивается, но не пропадает») —
 * следствие решения владельца: смерть больше НЕ изымает транспорт, поэтому полис
 * `craft_insurance` на `type='transport'` продавал бы защиту от несуществующего
 * риска — та же ошибка, которую вскрыл ADR-172 на предметах с `quantity=1`.
 *
 * Убирает `transport` из CSV `craft_insurance.eligible_types` (V24, ADR-056),
 * не трогая остальные типы и их порядок. Идемпотентно — парсит текущее значение,
 * а не перезаписывает жёсткой строкой (значение на проде могло измениться через
 * admin UI после V24-миграции). Проверено на проде 2026-08-19: застрахованных
 * `type='transport'` строк — ноль (при 8 верстаках и 10 роботах под полисами),
 * компенсировать нечего.
 */
class Adr174TransportOutOfInsurance extends Migration
{
    private const KEY = 'craft_insurance.eligible_types';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if ($row === null) {
            // V24 ещё не сеяла ключ (порядок миграций) — сеем безопасный дефолт
            // уже без transport, чтобы вторая история не завела зависимость.
            $this->db->table('game_settings')->insert([
                'setting_key'        => self::KEY,
                'category'           => 'resources',
                'value_type'         => 'string',
                'value_string'       => 'robots,workbench',
                'default_value_text' => 'robots,workbench',
                'rationale_text'     => $this->rationale(),
                'effect_text'        => 'CraftInsuranceService::eligibleTypes(): array<string>. Используется в CraftInsuranceListAction (фильтр SELECT) и CraftInsureItemAction (валидация перед insure).',
                'above_effect_text'  => 'Расширение списка сверх дорогих активов размывает gold-sink и создаёт UX-шум в списке страхуемых предметов.',
                'below_effect_text'  => $this->belowEffect(),
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            return;
        }

        $current = array_values(array_filter(
            array_map('trim', explode(',', (string) $row['value_string'])),
            static fn (string $type): bool => $type !== ''
        ));

        if (! in_array('transport', $current, true)) {
            // Уже применено (или транспорт никогда не сеялся в этой строке) — идемпотентность.
            return;
        }

        $updated = array_values(array_filter(
            $current,
            static fn (string $type): bool => $type !== 'transport'
        ));

        $newValue = implode(',', $updated);

        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->update([
                'value_string'       => $newValue,
                'default_value_text' => $newValue,
                'rationale_text'     => $this->rationale(),
                'below_effect_text'  => $this->belowEffect(),
                'updated_at'         => $now,
            ]);
    }

    public function down(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return;
        }

        $current = array_values(array_filter(
            array_map('trim', explode(',', (string) $row['value_string'])),
            static fn (string $type): bool => $type !== ''
        ));

        if (in_array('transport', $current, true)) {
            return;
        }

        $current[]  = 'transport';
        $restored   = implode(',', $current);

        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->update([
                'value_string'       => $restored,
                'default_value_text' => $restored,
                'updated_at'         => $now,
            ]);
    }

    private function rationale(): string
    {
        return 'transport-09 (ADR-174, поправка владельца): смерть больше НЕ изымает транспорт '
            . '(обнуляет износ активной машины, строка crafted_items_log остаётся), поэтому полис '
            . 'на type=transport продавал бы защиту от несуществующего риска — та же ошибка, '
            . 'что вскрыл ADR-172 на quantity=1. Прод 2026-08-19: 0 застрахованных transport-строк, '
            . 'компенсировать нечего.';
    }

    private function belowEffect(): string
    {
        return 'Сужение до "robots" только: workbench теряется на смерти без полиса — недовольство '
            . 'владельцев верстаков. transport НАЗАД добавлять не нужно — машина больше не теряется '
            . 'смертью, страховка на неё бессмысленна по определению.';
    }
}
