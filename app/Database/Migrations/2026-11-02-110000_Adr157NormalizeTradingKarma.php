<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-157 — нормализация накопленной Кармы торговли к новым границам.
 *
 * До фикса карма росла на «цена продажи × 0.0002» без верхнего предела, а цена
 * продажи зависела от кармы — петля разгоняла сама себя. На проде это дало
 * 15 592 при нейтрали 100 (и 1 195 у второго персонажа); снизу встречались
 * отрицательные значения (−100.89).
 *
 * `TradePricingService::normalizeKarma` прижимает значение к границам уже при
 * чтении, поэтому преимущество снято и без этой миграции. Здесь чистятся сами
 * данные, чтобы карточка персонажа и админка не показывали числа, которые ни на
 * что не влияют.
 *
 * Границы берутся из `game_settings` (economy.trade.karma_min/max), при их
 * отсутствии — дефолты сервиса 0..200.
 *
 * Золото НЕ трогается: решение по накопленным балансам принимается отдельно,
 * это не задача миграции схемы.
 *
 * WipeManifest: `characters` уже классифицирована (CHARACTER_RESET), новых
 * таблиц и колонок нет.
 */
class Adr157NormalizeTradingKarma extends Migration
{
    public function up(): void
    {
        $min = $this->settingFloat('economy.trade.karma_min', 0.0);
        $max = $this->settingFloat('economy.trade.karma_max', 200.0);
        if ($max < $min) {
            $max = $min;
        }

        $this->db->query(
            'UPDATE characters SET trading_karma = ? WHERE trading_karma > ?',
            [$max, $max]
        );
        $this->db->query(
            'UPDATE characters SET trading_karma = ? WHERE trading_karma < ?',
            [$min, $min]
        );
    }

    public function down(): void
    {
        // Исходные значения не сохранялись — восстановить нельзя, и не нужно:
        // они были продуктом незакрытой петли, а не игровым достижением.
    }

    private function settingFloat(string $key, float $default): float
    {
        $row = $this->db->table('game_settings')
            ->select('value_float')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();

        return $row !== null && is_numeric($row['value_float'] ?? null)
            ? (float) $row['value_float']
            : $default;
    }
}
