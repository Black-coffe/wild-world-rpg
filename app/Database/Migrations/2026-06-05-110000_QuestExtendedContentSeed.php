<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 — контент расширенной системы квестов (Фаза 1, dormant).
 *
 * STANDALONE objective-«корни» (без prerequisite) по категориям Asana, на уровневых
 * бэндах старт→эндгейм. Невидимы/не прогрессят пока `quests.extended_enabled`=OFF.
 *   • Собери всё  → collect_resource (resources.name_en, qty)
 *   • Быстрый рост → level_milestone (достичь уровня)
 *   • Защита базы → building_level (оборонит. здание ≥ ур.)
 *   • Фермерство  → building_level (Greenhouse) + collect_resource (урожай)
 * Категории «драки с NPC» (npc_kills) и faction-гейтинг — Фаза 2. Idempotent.
 */
class QuestExtendedContentSeed extends Migration
{
    public function up(): void
    {
        $quests = [
            // ─────────── (a) Собери всё — collect_resource ───────────
            $this->q('CollectWoodStockpile', 'Запас древесины', 2, 'collect_resource', 'Wood', 100, 800,
                'Зима не ждёт. Собери 100 единиц древесины — на костёр, на стены, на крафт. Запасливый выживает.'),
            $this->q('CollectStonesPile', 'Каменная кладка', 4, 'collect_resource', 'Pebble', 60, 1000,
                'Камень — основа всего прочного. Набери 60 камней (гальки): пригодятся в стройке и укреплениях.'),
            $this->q('CollectIronstoneCache', 'Железный схрон', 6, 'collect_resource', 'Ironstone', 80, 1500,
                'Без железа нет ни инструментов, ни оружия. Накопи 80 железной руды — кузнечный задел выживальщика.'),
            $this->q('CollectCoalbedTrove', 'Угольный запас', 10, 'collect_resource', 'Coalbed', 50, 2500,
                'Уголь питает доменную печь. Собери 50 угольной породы — топливо для серьёзного производства.'),

            // ─────────── (b) Самое быстрое развитие — level_milestone ───────────
            $this->q('GrowthMilestone5', 'Первые шаги', 1, 'level_milestone', null, 5, 1000,
                'Остров проверяет новичков на прочность. Достигни 5 уровня — докажи, что ты не корм для рейдеров.'),
            $this->q('GrowthMilestone10', 'Закалённый пустошью', 1, 'level_milestone', null, 10, 2500,
                'Десятый уровень — рубеж. Здесь открываются фракции и серьёзный крафт. Дойди до него.'),
            $this->q('GrowthMilestone20', 'Ветеран пустоши', 1, 'level_milestone', null, 20, 6000,
                'Двадцатый уровень переживают единицы. Стань одним из них — и эндгейм-контент откроет двери.'),

            // ─────────── (d) Защита базы — building_level (оборонительные здания) ───────────
            $this->q('DefenseFirstWall', 'Возведи стену', 5, 'building_level', 'WoodenWall', 1, 1200,
                'Открытый лагерь — приглашение для мародёров. Построй деревянную стену: первый рубеж обороны базы.'),
            $this->q('DefenseWatchtower', 'Дозорная вышка', 12, 'building_level', 'WatchTower', 1, 3000,
                'Кто видит врага первым — побеждает. Построй дозорную вышку и держи периметр базы под наблюдением.'),
            $this->q('DefenseFortifiedArsenal', 'Укреплённый арсенал', 20, 'building_level', 'Arsenal', 3, 8000,
                'Оборона держится на снабжении. Прокачай Арсенал до 3 уровня — сердце укреплённой базы.'),

            // ─────────── (e) Фермерство — building_level (Greenhouse) + collect_resource (урожай) ───────────
            $this->q('FarmBuildGreenhouse', 'Построй теплицу', 8, 'building_level', 'Greenhouse', 1, 2000,
                'Голод убивает вернее пули. Построй теплицу — и еда перестанет быть лотереей вылазок.'),
            $this->q('FarmGreenhouseMaster', 'Мастер теплицы', 14, 'building_level', 'Greenhouse', 3, 5000,
                'Прокачай теплицу до 3 уровня — стабильный урожай прокормит и тебя, и союзников по фракции.'),
            $this->q('FarmHarvestGrain', 'Большой урожай зерна', 8, 'collect_resource', 'Crops', 200, 3000,
                'Зерно — валюта выживания. Накопи 200 зерновых культур: запас на чёрный день и сырьё для кухни.'),
        ];

        foreach ($quests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function q(
        string $titleEn,
        string $titleRu,
        int $minLevel,
        string $objectiveType,
        ?string $objectiveTarget,
        int $objectiveQty,
        int $reward,
        string $description
    ): array {
        return [
            'title_ru'           => $titleRu,
            'title_en'           => $titleEn,
            'description'        => $description,
            'status'             => 'active',
            'min_level'          => $minLevel,
            'reward_type'        => 'gold',
            'reward'             => $reward,
            'prerequisite_quest' => null,
            'objective_type'     => $objectiveType,
            'objective_target'   => $objectiveTarget,
            'objective_qty'      => $objectiveQty,
            'branch_group'       => null,
            'branch_label'       => null,
        ];
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'CollectWoodStockpile', 'CollectStonesPile', 'CollectIronstoneCache', 'CollectCoalbedTrove',
            'GrowthMilestone5', 'GrowthMilestone10', 'GrowthMilestone20',
            'DefenseFirstWall', 'DefenseWatchtower', 'DefenseFortifiedArsenal',
            'FarmBuildGreenhouse', 'FarmGreenhouseMaster', 'FarmHarvestGrain',
        ])->delete();
    }
}
