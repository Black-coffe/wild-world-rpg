<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Разбить базу» (title_en='Set up camp') → мульти-бэйс-формулировка.
 *
 * Живой совет отстал от ADR-095 Фазы 1a: он утверждал «можно построить *две* — первая
 * сразу, вторая на *101-м* уровне». Это снесённый хардкод `EntrenchAction`. С ADR-095
 * лимит считает `BaseLimitService::maxBasesForLevel` =
 * max(1, min(hard_cap, floor(level / levels_per_base))) — на проде levels_per_base=50,
 * hard_cap=19: вторая база на 100-м, третья на 150-м, дальше каждые 50 уровней, до 19.
 *
 * Формулировка держит точные пороги, но прямо отсылает за живым числом в экран
 * «Окопаться» (он печатает значения из GameSettings) — анти-дрейф на случай, если
 * `buildings.bases.*` перетюнят через админку.
 *
 * UPDATE по title_en (идемпотентно, второй строки не создаёт). Таблиц/player-колонок
 * не заводит → WipeManifest не трогаем (game_tips уже классифицирован).
 */
class UpdateSetUpCampTipMultiBase extends Migration
{
    private const NEW_CONTENT = '🏕 *База:* первая доступна сразу и ничего не стоит — встань на '
        . 'свободную клетку и жми «Окопаться». Следующие базы открывает уровень: вторая — на '
        . '*100-м*, третья — на *150-м*, дальше каждые *50* уровней ещё одна (всего до *19*). '
        . 'Точный порог для тебя Роби всегда назовёт прямо в экране «Окопаться». База даёт '
        . 'постройки, налог и точку телепорта, а налог считается по каждой базе отдельно. '
        . 'Выбирай место с умом — рядом с нужными биомами!';

    private const OLD_CONTENT = '🏕 *База:* можно построить две — первая доступна сразу, вторая '
        . 'на 101-м уровне. База даёт постройки, налог и точку телепорта. Выбирай место с умом '
        . '— рядом с нужными биомами!';

    public function up(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', 'Set up camp')
            ->update(['content' => self::NEW_CONTENT, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', 'Set up camp')
            ->update(['content' => self::OLD_CONTENT, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
