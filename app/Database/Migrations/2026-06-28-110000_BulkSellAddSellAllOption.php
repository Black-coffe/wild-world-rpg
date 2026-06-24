<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E28 (ADR-096) — «Продать всё»: добавляет 100% в варианты оптовой продажи.
 *
 * Коррекция default'а admin-tunable ключа `economy.bulk_sell.percent_options`
 * (ADR-024 «правка default → новая seed-миграция»): `10,25,50` → `10,25,50,100`.
 * 100% рендерится отдельной кнопкой «🧺 Всё» (BulkSellAction::buttonsRow) и проходит
 * тот же обязательный confirm-шаг (preview → «✅ Да, продать всё»).
 *
 * Idempotent + НЕ клобберит ручную настройку: `value_string` обновляется только если
 * он всё ещё равен старому дефолту `10,25,50`; `default_value_text` (для reset-to-default)
 * обновляется всегда. Если ключа нет (свежая БД до seed) — no-op (seed вставит, затем эта
 * миграция дополнит по порядку timestamp).
 *
 * WipeManifest: только UPDATE в game_settings (KEEP) — новых таблиц/player-колонок нет.
 */
class BulkSellAddSellAllOption extends Migration
{
    private const KEY     = 'economy.bulk_sell.percent_options';
    private const OLD_CSV = '10,25,50';
    private const NEW_CSV = '10,25,50,100';

    public function up(): void
    {
        $existing = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if (empty($existing)) {
            return; // ключа ещё нет — seed вставит дефолт, тут нечего корректировать
        }

        $update = [
            'default_value_text' => self::NEW_CSV,
            'effect_text'        => 'BulkSellAction строит кнопки процентов из этого списка (целые 1..100 через запятую; сортируются, дубли убираются) на экранах SellAction (scope=все) и SellResourceAction (scope=редкость). 100 рендерится как отдельная кнопка «🧺 Всё» (продать весь объём после подтверждения). Значение вне списка в callback отклоняется.',
            'above_effect_text'  => 'Список уже включает 100 («🧺 Всё» — продать весь объём scope одним подтверждением). Выше 100 невозможно (parsePercents режет >100).',
            'below_effect_text'  => 'Убрать 100 → исчезнет кнопка «🧺 Всё» (останутся только частичные доли). Только мелкие доли (например «5,10») → оптовая продажа ощущается несущественной, игроки продолжат кликать поштучно.',
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        // value_string трогаем ТОЛЬКО если он всё ещё старый дефолт (не затираем ручную настройку админа).
        if (($existing['value_string'] ?? null) === self::OLD_CSV) {
            $update['value_string'] = self::NEW_CSV;
        }

        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->update($update);
    }

    public function down(): void
    {
        // Откат: вернуть старый дефолт, если значение — наш новый дефолт.
        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->where('value_string', self::NEW_CSV)
            ->update([
                'value_string'       => self::OLD_CSV,
                'default_value_text' => self::OLD_CSV,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
    }
}
