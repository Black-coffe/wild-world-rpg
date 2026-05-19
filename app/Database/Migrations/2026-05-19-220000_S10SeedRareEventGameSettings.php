<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S10 (v0.51.192) — seed GameSettings tuning keys для 8 rare-drop events
 * (4 existing F7.2 + 4 нових S10).
 *
 * Pattern: `rare_event.<name_english>.{chance_per_tick|amount_min|amount_max}`.
 * RareResourceGrantEffect::compute() читає GameSettings override fallback на
 * effect_params з Config\WorldEvents.php (S10 commit).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): кожен tunable має
 * rationale_text / effect_text / above_effect_text / below_effect_text.
 * Не дотримання — invariant violation, запис не створюється.
 *
 * Idempotent: пропуск існуючих setting_key.
 */
class S10SeedRareEventGameSettings extends Migration
{
    /**
     * @var array<int, array{event:string,ru:string,chance:float,amount_min:int,amount_max:int,resource_label:string,biome_label:string}>
     */
    private const EVENTS = [
        // Existing F7.2 (4) — переводимо параметри з Config-only на DB-tunable.
        [
            'event'          => 'BerryBoom',
            'ru'             => 'Ягодный бум',
            'chance'         => 0.20,
            'amount_min'     => 2,
            'amount_max'     => 5,
            'resource_label' => 'ягоды (keyword=berry)',
            'biome_label'    => 'Леса (id=1)',
        ],
        [
            'event'          => 'FishStock',
            'ru'             => 'Рыбный запас',
            'chance'         => 0.15,
            'amount_min'     => 1,
            'amount_max'     => 3,
            'resource_label' => 'рыба (keyword=fish)',
            'biome_label'    => 'Реки (id=4)',
        ],
        [
            'event'          => 'ExoticFlowering',
            'ru'             => 'Экзотическое цветение',
            'chance'         => 0.10,
            'amount_min'     => 1,
            'amount_max'     => 2,
            'resource_label' => 'rare_flower (rarity=1)',
            'biome_label'    => 'Тропические джунгли (id=5)',
        ],
        [
            'event'          => 'RevealingHiddenCameras',
            'ru'             => 'Открытие скрытых подземелий',
            'chance'         => 0.10,
            'amount_min'     => 1,
            'amount_max'     => 3,
            'resource_label' => 'Сердце Дракона/Древняя Книга/Корона (keyword=RevealingHiddenCameras)',
            'biome_label'    => 'Пещеры (id=7)',
        ],
        // S10 new (4) — biome-specific T3-крафт ресурси.
        [
            'event'          => 'VolcanicFuelCache',
            'ru'             => 'Вулканический выброс',
            'chance'         => 0.08,
            'amount_min'     => 1,
            'amount_max'     => 2,
            'resource_label' => 'Отработанные ТВЭЛы (keyword=fuel_rods)',
            'biome_label'    => 'Вулканические территории (id=8)',
        ],
        [
            'event'          => 'PreCollapseVaultOpening',
            'ru'             => 'Открытие доколлапсного хранилища',
            'chance'         => 0.08,
            'amount_min'     => 1,
            'amount_max'     => 2,
            'resource_label' => 'Доколлапсная электроника (keyword=pre_collapse_electronics)',
            'biome_label'    => 'Пещеры (id=7)',
        ],
        [
            'event'          => 'IndustrialDumpFind',
            'ru'             => 'Заброшенная промзона',
            'chance'         => 0.12,
            'amount_min'     => 1,
            'amount_max'     => 3,
            'resource_label' => 'Промышленный пластик (keyword=industrial_plastic)',
            'biome_label'    => 'Тропические джунгли (id=5)',
        ],
        [
            'event'          => 'MountainArmyDepot',
            'ru'             => 'Армейский склад',
            'chance'         => 0.10,
            'amount_min'     => 1,
            'amount_max'     => 2,
            'resource_label' => 'Медицинское сырьё (keyword=medical_compound)',
            'biome_label'    => 'Горы (id=2)',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::EVENTS as $e) {
            $this->seedChance($e, $now);
            $this->seedAmountMin($e, $now);
            $this->seedAmountMax($e, $now);
        }
    }

    public function down(): void
    {
        foreach (self::EVENTS as $e) {
            $this->db->table('game_settings')
                ->whereIn('setting_key', [
                    "rare_event.{$e['event']}.chance_per_tick",
                    "rare_event.{$e['event']}.amount_min",
                    "rare_event.{$e['event']}.amount_max",
                ])
                ->delete();
        }
    }

    /**
     * @param array{event:string,ru:string,chance:float,amount_min:int,amount_max:int,resource_label:string,biome_label:string} $e
     */
    private function seedChance(array $e, string $now): void
    {
        $key = "rare_event.{$e['event']}.chance_per_tick";
        if ($this->keyExists($key)) {
            return;
        }

        $chancePct = (int) round($e['chance'] * 100);

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => $e['chance'],
            'default_value_text' => (string) $e['chance'],
            'rationale_text'     => "{$chancePct}% за tick — компромисс между «редкое событие, ощутимо когда срабатывает» (П1/П3 game-stories) и «не настолько частое, чтобы T3-ресурсы стали тривиальными» (П8 economy, П10 onboarding). Событие «{$e['ru']}» спавнит {$e['resource_label']} в биоме {$e['biome_label']}.",
            'effect_text'        => 'Вероятность сработать на каждый tick события (RareResourceGrantEffect::compute). 0.10 = 10% chance / tick. Игрок должен быть в gather-цикле (requires_state=\'gather\') и в соответствующем биоме.',
            'above_effect_text'  => "При 0.30+ — T3 ресурс становится common drop (≈3 из 10 ticks за час события); инфляция T3-крафта. При 0.50 — ломает scarcity-контракт «найденная плёнка/выживание».",
            'below_effect_text'  => "При 0.01 — игроки месяцами не видят ни одного drop'а; событие воспринимается как декорация. П3 long-horizon ОК, но П1/П5 разочарованы.",
            'recommended_min'    => '0.05',
            'recommended_max'    => '0.30',
            'hard_min'           => '0.001',
            'hard_max'           => '1.0',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    /**
     * @param array{event:string,ru:string,chance:float,amount_min:int,amount_max:int,resource_label:string,biome_label:string} $e
     */
    private function seedAmountMin(array $e, string $now): void
    {
        $key = "rare_event.{$e['event']}.amount_min";
        if ($this->keyExists($key)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => $e['amount_min'],
            'default_value_text' => (string) $e['amount_min'],
            'rationale_text'     => "Минимум {$e['amount_min']} — чтобы успешный drop был ощутимым «нашёл ценное» (а не «крошка на 1 единицу»). Событие «{$e['ru']}» в биоме {$e['biome_label']}.",
            'effect_text'        => 'Нижняя граница mt_rand(min, max) для количества выдаваемого ресурса при успешном drop. Применяется вместе с amount_max.',
            'above_effect_text'  => "Если приблизить к amount_max — drop становится детерминированным (всегда max units). Менее «лотерея», больше «гарантия».",
            'below_effect_text'  => "При 1 — половина успешных drop'ов даёт 1 единицу (минимум, но всё ещё ощутимо для T3-рецептов).",
            'recommended_min'    => '1',
            'recommended_max'    => '5',
            'hard_min'           => '1',
            'hard_max'           => '20',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    /**
     * @param array{event:string,ru:string,chance:float,amount_min:int,amount_max:int,resource_label:string,biome_label:string} $e
     */
    private function seedAmountMax(array $e, string $now): void
    {
        $key = "rare_event.{$e['event']}.amount_max";
        if ($this->keyExists($key)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => $e['amount_max'],
            'default_value_text' => (string) $e['amount_max'],
            'rationale_text'     => "Максимум {$e['amount_max']} — даёт ощущение «лотерея с upper-bound», стимулирует продолжать gather в активном событии. Событие «{$e['ru']}» в биоме {$e['biome_label']}.",
            'effect_text'        => 'Верхняя граница mt_rand(min, max) для количества выдаваемого ресурса при успешном drop. Если max < min — RareResourceGrantEffect авто-выравнивает max до min.',
            'above_effect_text'  => "При 10+ — один успешный drop закрывает целый T3-рецепт; событие становится «одной удачи достаточно». Снижает retention к серии gather'ов.",
            'below_effect_text'  => "При 1 (= amount_min) — drop детерминирован (всегда 1 unit), пропадает «лотерейный» элемент.",
            'recommended_min'    => '2',
            'recommended_max'    => '10',
            'hard_min'           => '1',
            'hard_max'           => '50',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    private function keyExists(string $key): bool
    {
        $row = $this->db->table('game_settings')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();

        return ! empty($row);
    }
}
