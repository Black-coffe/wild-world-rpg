<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Buildings as BuildingsConfig;

/**
 * ADR-155, слайс 3 контент-паса L1→L10 — «Постройки говорят правду».
 *
 * Аудит 2026-07-26 нашёл ДВА независимых расхождения между тем, что игроку показывают, и тем,
 * что происходит на самом деле.
 *
 * 1️⃣ **Требуемый уровень жил в двух местах и разошёлся у 9 построек из 16.**
 *    Гейтит стройку `Config\Buildings[...]['level_required']` (GenericBuildingAction шаг 5),
 *    а показывается колонка `buildings.min_character_level` (публичная вики, админский
 *    craft-tree, строка «что откроется на уровне N»). Расхождения:
 *      Мастерская 4→1, Доменная печь 5→1, Склад 6→1, Теплица 8→1, Солнечная станция 10→1,
 *      Вышка связи 10→1, Лаборатория 10→5, Робототехника 6→10, **Спортзал 0→5**.
 *    Рантайм уже переведён на конфиг ({@see \App\Services\Buildings\BuildingGateService}),
 *    поэтому колонка больше ни на что не влияет — но её приводим в соответствие, чтобы админка
 *    и будущий код не наступили на те же грабли (на них наступил ADR-153: строка «что откроется»
 *    считала постройки по этой колонке, и Спортзал с её точки зрения не открывался никогда).
 *
 * 2️⃣ **Описание Спортзала завышало отдачу в 6 раз.** В БД: «Каждые 5ть минут добавляет
 *    персонажу 0,01 единицу силы». В коде интервал — 30 минут (`gymTickIntervalMinutes` = 30,
 *    live-значение `building.gym.tick_interval_minutes` = 30 на проде). Обещано 2.88 силы в
 *    сутки, реально 0.48. Хуже всего место: этот текст печатает экран УЖЕ ПОСТРОЕННОГО зала
 *    (GymHandler), то есть враньё читает игрок, который только что вложил Древесину 1400,
 *    Гальку 1600 и Воду 1000 и проверяет, окупилось ли.
 *    Заодно уточнено описание Теплицы: «2500 фруктов и 600 ягод в сутки» против фактических
 *    ~2880 и ~1440 при полном запасе воды (занижало, но тоже неправда).
 *
 * Идемпотентно: обновляем только строки, где значение отличается; повторный прогон — no-op.
 * `buildings` = KEEP (WipeManifest не трогаем), новых таблиц и player-колонок нет.
 */
class Adr155SyncBuildingLevelGatesAndDescriptions extends Migration
{
    public function up(): void
    {
        $this->syncLevelGates();
        $this->fixDescriptions();
    }

    /**
     * Приводит `buildings.min_character_level` к тому же значению, которое реально проверяется
     * при стройке. Источник — `Config\Buildings`, матч по `name_en` (ключ конфига).
     */
    private function syncLevelGates(): void
    {
        /** @var array<string, mixed> $recipes */
        $recipes = (new BuildingsConfig())->recipes;

        foreach ($recipes as $nameEn => $recipe) {
            if (! is_array($recipe) || ! isset($recipe['level_required']) || ! is_numeric($recipe['level_required'])) {
                continue;
            }
            $level = max(1, (int) $recipe['level_required']);

            $this->db->table('buildings')
                ->where('name_en', $nameEn)
                ->where('min_character_level !=', $level)
                ->update(['min_character_level' => $level]);
        }
    }

    /**
     * Числовые описания приводятся к тому, что делает код. Матч по `name_en`, значение задаётся
     * целиком (повторный прогон не портит).
     */
    private function fixDescriptions(): void
    {
        $fixed = [
            'Gym' => 'Тренировочный зал на базе. Каждые 30 минут добавляет силу: на 1 уровне '
                . '0,01 за раз, с уровнем зала прибавка растёт. Сила — одна из четырёх '
                . 'характеристик, из которых складывается уровень персонажа. Работает, пока '
                . 'уплачен налог за постройку.',
            'Greenhouse' => 'Теплица кормит базу: пока хватает воды, каждую минуту приносит '
                . 'урожай (на 1 уровне 2 фрукта и 1 ягоду, с уровнем растёт и добавляются грибы '
                . 'и злаки). Вода расходуется из твоих запасов — на 1 уровне 1 единица за минуту.',
        ];

        foreach ($fixed as $nameEn => $description) {
            $this->db->table('buildings')
                ->where('name_en', $nameEn)
                ->where('description !=', $description)
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // Обратно к состоянию до правки (значения зафиксированы аудитом 2026-07-26).
        $legacyLevels = [
            'Workshop' => 4, 'BlastFurnace' => 5, 'Warehouse' => 6, 'Greenhouse' => 8,
            'SolarStation' => 10, 'CommunicationTower' => 10, 'Laboratory' => 10,
            'RoboticsWorkshop' => 6, 'Gym' => 0,
        ];
        foreach ($legacyLevels as $nameEn => $level) {
            $this->db->table('buildings')->where('name_en', $nameEn)->update(['min_character_level' => $level]);
        }

        $legacyDescriptions = [
            'Gym'        => 'Каждые 5ть минут добавляет персонажу 0,01 единицу силы',
            'Greenhouse' => 'Каждые сутки приносит урожай: Фрукты: 2500 единиц, Ягоды: 600 единиц',
        ];
        foreach ($legacyDescriptions as $nameEn => $description) {
            $this->db->table('buildings')->where('name_en', $nameEn)->update(['description' => $description]);
        }
    }
}
