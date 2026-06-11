<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-111 (E10) — контент-плотность L15-19: «квест на каждый гейт».
 *
 * Audit квестов по уровням вскрыл «провисание целей» между фракцией (L10) и T3 (L20):
 *   1-9: 30 · 10-14: 17 · **15-19: 6 (все на 15-16) · L17/L18/L19: 0** · 20-25: 13 · 26+: 3.
 * Заполняем пустыню L15-19 СТАРТАБЕЛЬНЫМИ корневыми квестами (objective_type из whitelist
 * QuestChainService::isExtendedStartableRoot: collect_resource/building_level/npc_kills/
 * level_milestone — видны в «Доступных квестах», без prerequisite, faction_id=NULL=всем).
 * Тема — подготовка к T3 (материалы/исследования/боевая готовность/рывок к L20). Гейтятся
 * уже активным `quests.extended_enabled` (ON на проде) — контент, не новый механизм.
 *
 * quests = KEEP (контент-определения) → WipeManifest не затрагивается. Idempotent (по title_en).
 */
class Adr111QuestDensityL15to19 extends Migration
{
    public function up(): void
    {
        $quests = [
            // L15 — материалы T3 (заполняет тонкий L15, учит добыче промышленного сырья)
            $this->q('DensityIndustrialStock', 'Промышленный запас', 15, 'collect_resource', 'IndustrialPlastic', 25, 6000,
                'Эпоха T3 начинается с материалов. Собери 25 единиц промышленного пластика — без него не собрать продвинутую экипировку и модули верстаков севера.'),

            // L17 — исследования (пустой уровень; учит апгрейду Лаборатории к технологиям)
            $this->q('DensityResearchModule', 'Исследовательский модуль', 17, 'building_level', 'Laboratory', 2, 6500,
                'Знание — оружие выжившего. Прокачай Лабораторию до 2 уровня: продвинутые исследования открывают путь к технологиям Технопарка.'),

            // L18 — боевая готовность (пустой уровень; мост npc_kills 60→200)
            $this->q('DensityWastesCleaner', 'Чистильщик окраин', 18, 'npc_kills', null, 120, 7000,
                'Окраины кишат тварями, и L20 новичком не пережить. Уничтожь 120 целей — закали руку перед рывком к мастерству.'),

            // L18 — выживание/медицина (пустой уровень; учит добыче медицинского сырья)
            $this->q('DensityFieldHospital', 'Полевой госпиталь', 18, 'collect_resource', 'MedicalCompound', 15, 6800,
                'Глубокий север не прощает ран. Накопи 15 единиц медицинского сырья — полевой госпиталь спасёт, когда аптечки кончатся.'),

            // L19 — капстоун к T3 (пустой уровень; рывок к гейту L20)
            $this->q('DensityMasteryThreshold', 'На пороге мастерства', 19, 'level_milestone', null, 20, 7500,
                'Ты на пороге T3. Достигни 20 уровня — за ним профессиональные верстаки, лаборатории и оружие, которого пустошь ещё не видела.'),
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
            'faction_id'         => null,
        ];
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'DensityIndustrialStock', 'DensityResearchModule', 'DensityWastesCleaner',
            'DensityFieldHospital', 'DensityMasteryThreshold',
        ])->delete();
    }
}
