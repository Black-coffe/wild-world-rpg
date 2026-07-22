<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Компас биомов (2026-07-22) — GameSettings по конституционному правилу ADMIN-TUNABLE BALANCE.
 *
 * Флаг фичи: подсказки «где искать биом» в экране «❓ Легенда». Гасится без редеплоя —
 * легенда возвращается к прежнему виду (значок → название).
 *
 * Идемпотентность по `setting_key`. `game_settings` = KEEP в WipeManifest, новых таблиц нет.
 */
final class SeedBiomeCompassGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'setting_key'        => 'world.biome_compass.enabled',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'Killswitch компаса биомов в легенде карты. Построен по живому вопросу игрока в чате 22.07.2026 («а где найти вулканы?»), на который в игре не было ответа: легенда объясняла, что значит значок 🌋, гайд и советы говорили, что в вулканах есть нефть, но где эти вулканы находятся — не сообщалось нигде, и единственным каналом ответа был чат с владельцем. География при этом объективна: вулканических клеток ~0.4% карты и 92% из них в северо-восточной четверти. Включён по умолчанию: это устранение информационного разрыва, а не выдача преимущества.',
            'effect_text'        => 'BiomeCompassService::enabled. true → в экране «❓ Легенда» каждый биом получает сторону света до ближайшего скопления, порядок расстояния словами и пометку редкости. false → легенда рендерится как раньше (значок → название), подсказки исчезают. Компас намеренно крупнозернистый (блоки по 100 клеток): даёт сторону света, а не координаты; туман войны не раскрывается, ходить и открывать карту всё равно игроку.',
            'above_effect_text'  => 'n/a (bool).',
            'below_effect_text'  => 'false → возвращается исходная проблема: игрок видит значок биома, но не знает, в какой стороне его искать, и идёт спрашивать в чат.',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->countAllResults();

        if ($exists === 0) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'world.biome_compass.enabled')
            ->delete();
    }
}
