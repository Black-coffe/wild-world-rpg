<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S5 (ROADMAP-RETENTION-10, ADR-142) — «Навес» (LeanTo): дешёвое one-shot первое укрытие.
 *
 * Закрывает горлышко OnbStepBuild (Build-конверсия 9% — #1 утечка онбординг-воронки):
 * обычные здания требуют crafted_items + сырьё + 60-мин таймер, недоступные L1-новичку.
 * «Навес» — ТОЛЬКО сырьё (25 дерева + 10 воды), без крафта/зависимостей, ~3 мин, налог 0.
 *
 * Сидит два справочника (паттерн S26b WatchTower, idempotent INSERT с проверкой):
 *   - `buildings` row (name_en='LeanTo') — completion-handler резолвит здание по name_en
 *     → building_id для character_buildings; building_type='residential', tax=0 (новичка
 *     не нагружаем налогом — это поощряемое действие), hp низкий.
 *   - `tasks` row (buildLeanTo → handler_key='generic_building') — длительность из
 *     min/max_duration (минуты); новичок с низкими статами получает max_duration=3.
 *
 * Рецепт (стоимость/польза/тексты) — Config\Buildings::$recipes['LeanTo'] (единый паттерн
 * со всеми 15 зданиями). Видимость кнопки гейтит FirstShelterService (killswitch
 * onboarding.first_build.enabled, default OFF → dormant). WipeManifest: `buildings`/`tasks`
 * уже классифицированы KEEP (контент-определения) — новой таблицы нет, манифест не трогаем.
 */
class S5FirstShelterLeanTo extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. buildings row — справочник-определение «Навеса».
        $building = [
            'name_ru'                  => 'Навес',
            'name_en'                  => 'LeanTo',
            'description'              => 'Простое первое укрытие из жердей и шкур. Ставится быстро и почти даром — твой первый дом на пустоши и шаг к настоящему лагерю. Налогом не облагается.',
            'building_type'            => 'residential',
            'hp'                       => 50,
            'construction_time'        => 3,
            'tax'                      => 0,
            'level'                    => 1,
            'usage'                    => 'personal',
            'required_resources'       => json_encode(['Древесина' => 25, 'Вода' => 10], JSON_UNESCAPED_UNICODE),
            'min_character_level'      => 1,
            'effects'                  => json_encode(['effect' => 'starter_shelter'], JSON_UNESCAPED_UNICODE),
            'days_until_disappearance' => 0,
        ];
        $exists = $this->db->table('buildings')->where('name_en', $building['name_en'])->get()->getRowArray();
        if (empty($exists)) {
            $this->db->table('buildings')->insert($building);
        }

        // 2. tasks row — buildLeanTo через generic_building handler (как WatchTower S26b).
        // min=2 / max=3 мин: новичок (низкий score) получает max_duration=3; формула
        // GenericBuildingAction::calculateDuration капит вниз до min для прокачанных.
        $taskExists = $this->db->table('tasks')->where('name', 'buildLeanTo')->get()->getRowArray();
        if (empty($taskExists)) {
            $this->db->table('tasks')->insert([
                'name'                       => 'buildLeanTo',
                'handler_key'                => 'generic_building',
                'name_rus'                   => 'Строительство навеса',
                'description'                => 'Возведение простого первого укрытия на базе.',
                'min_duration'               => 2,
                'max_duration'               => 3,
                'type'                       => 'building',
                'difficulty_level'           => 1,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')->where('name', 'buildLeanTo')->delete();
        $this->db->table('buildings')->where('name_en', 'LeanTo')->delete();
    }
}
