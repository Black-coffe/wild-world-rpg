<?php

declare(strict_types=1);

namespace App\Services\Quest;

/**
 * ADR-109 (E8) — каталог шаблонов ежедневных заданий (единый источник, как
 * {@see \App\Services\Onboarding\OnboardingChainCatalog}).
 *
 * Каждый шаблон привязан к МОНОТОННОМУ счётчику (objective_type) — дейлик считается
 * по baseline-дельте (current − baseline ≥ qty, см. ADR-109). qty и золото масштабируются
 * tier'ом уровня; min_level гейтит механику (новичок без боёв не получит combat-дейлик).
 *
 * objective_type здесь — ДЕЙЛИК-специфичные ключи (префикс d_), маппятся на счётчики в
 * {@see DailyTaskService::counter()}. НЕ путать с objective_type квестов ADR-088 (cumulative).
 */
final class DailyTaskCatalog
{
    /**
     * Пул шаблонов. qty/gold — по индексам tier'а [0=<L10, 1=L10-24, 2=L25+].
     *
     * @return list<array{
     *   key:string, category:string, objective_type:string, min_level:int,
     *   title:string, verb:string, qty:array{int,int,int}, gold:array{int,int,int}
     * }>
     */
    public static function pool(): array
    {
        return [
            [
                'key' => 'd_explore', 'category' => 'explore', 'objective_type' => 'd_explore',
                'min_level' => 1, 'title' => '🧭 Разведчик', 'verb' => 'Разведай новые клетки',
                'qty' => [5, 8, 12], 'gold' => [80, 150, 250],
            ],
            [
                'key' => 'd_craft', 'category' => 'craft', 'objective_type' => 'd_craft',
                'min_level' => 1, 'title' => '🛠 Мастеровой', 'verb' => 'Скрафти предметы',
                'qty' => [1, 2, 3], 'gold' => [80, 150, 250],
            ],
            [
                'key' => 'd_sell', 'category' => 'sell', 'objective_type' => 'd_sell',
                'min_level' => 1, 'title' => '💰 Торговец', 'verb' => 'Соверши сделки на рынке',
                'qty' => [1, 2, 3], 'gold' => [60, 120, 200],
            ],
            [
                'key' => 'd_hunt', 'category' => 'hunt', 'objective_type' => 'd_npc_kill',
                'min_level' => 3, 'title' => '🎯 Охотник', 'verb' => 'Одолей тварей пустоши',
                'qty' => [2, 3, 5], 'gold' => [100, 180, 300],
            ],
            [
                'key' => 'd_battle', 'category' => 'combat', 'objective_type' => 'd_battle_win',
                'min_level' => 5, 'title' => '⚔️ Боец', 'verb' => 'Победи в боях',
                'qty' => [1, 2, 3], 'gold' => [120, 200, 320],
            ],
        ];
    }

    /** Индекс tier'а по уровню: 0 = <L10, 1 = L10-24, 2 = L25+. Чистая. */
    public static function tierIndex(int $level): int
    {
        if ($level >= 25) {
            return 2;
        }
        if ($level >= 10) {
            return 1;
        }
        return 0;
    }

    /**
     * Шаблоны, доступные игроку по уровню (min_level ≤ level).
     *
     * @return list<array{key:string, category:string, objective_type:string, min_level:int, title:string, verb:string, qty:array{int,int,int}, gold:array{int,int,int}}>
     */
    public static function availableFor(int $level): array
    {
        return array_values(array_filter(
            self::pool(),
            static fn (array $t): bool => $level >= $t['min_level']
        ));
    }

    /**
     * Шаблон по key (или null).
     *
     * @return array{key:string, category:string, objective_type:string, min_level:int, title:string, verb:string, qty:array{int,int,int}, gold:array{int,int,int}}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::pool() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    /** qty шаблона для уровня (по tier'у). */
    public static function qtyFor(string $key, int $level): int
    {
        $t = self::find($key);
        return $t === null ? 0 : $t['qty'][self::tierIndex($level)];
    }

    /** Базовое золото шаблона для уровня (по tier'у). */
    public static function goldFor(string $key, int $level): int
    {
        $t = self::find($key);
        return $t === null ? 0 : $t['gold'][self::tierIndex($level)];
    }
}
