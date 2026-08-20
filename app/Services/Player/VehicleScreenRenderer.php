<?php

declare(strict_types=1);

namespace App\Services\Player;

/**
 * transport-10 (ADR-174, `docs/specs/transport-system/`) — чистая render-функция
 * экрана «🚚 Мой транспорт». Без БД и Telegram: принимает уже собранное состояние,
 * отдаёт текст + ряды кнопок. Весь тест (`VehicleScreenRenderTest`) идёт по ней —
 * сборка состояния (запросы к `VehicleActivationService`/`VehicleEffectsService`/
 * `CraftRecipes`/моделям) живёт в `VehicleAction`, эта функция её не касается.
 *
 * Media-off (ADR-020): весь смысл — в тексте. Картинки экран не отправляет вовсе
 * (простой текстовый экран, MediaSender не нужен).
 */
final class VehicleScreenRenderer
{
    /** Порог предупреждения «скоро сломается» — доля от полного запаса. */
    private const WARN_SHARE = 0.15;

    /**
     * @param array{
     *   active: array{name:string, icon:string, cells_left:int, charges_full:int, wear_per_cell:int, savings_minutes:int}|null,
     *   garage: list<array{log_id:int, name:string, icon:string}>,
     *   showcase: list<array{faction_id:int, faction_name:string, faction_icon:string, vehicle_name:string, vehicle_icon:string, required_level:int, unlocked:bool}>,
     *   character_level: int,
     * } $state
     * @return array{text:string, buttons: list<list<array<string,string>>>}
     */
    public static function renderScreen(array $state): array
    {
        $active         = $state['active'];
        $garage         = $state['garage'];
        $showcase       = $state['showcase'];
        $characterLevel = $state['character_level'];

        $text = "🚚 *Мой транспорт*\n\n" . self::activeBlock($active) . "\n" . self::garageBlock($garage, $active);
        $text .= "\n" . self::showcaseBlock($showcase, $characterLevel);

        $buttons = [];
        if ($active !== null) {
            $buttons[] = ['text' => '🅾️ Снять', 'callback_data' => 'vehicleDeactivate'];
        }
        foreach ($garage as $item) {
            $logId = $item['log_id'];
            $icon  = $item['icon'];
            $name  = $item['name'];
            $buttons[] = ['text' => trim("▶️ {$icon} {$name}"), 'callback_data' => "vehicleActivate_{$logId}"];
        }
        $buttons[] = ['text' => '👤 Персонаж', 'callback_data' => 'character'];

        return ['text' => $text, 'buttons' => self::packRows($buttons)];
    }

    /**
     * Отдельный вид витрины (маршрут `vehicleShowcase`, нужен story 12) — тот же
     * список карточек, без блоков активной машины/гаража.
     *
     * @param array{showcase: list<array{faction_id:int, faction_name:string, faction_icon:string, vehicle_name:string, vehicle_icon:string, required_level:int, unlocked:bool}>, character_level:int} $state
     * @return array{text:string, buttons: list<list<array<string,string>>>}
     */
    public static function renderShowcase(array $state): array
    {
        $text = "🏭 *Витрина фракционных машин*\n\n"
            . self::showcaseBlock($state['showcase'], $state['character_level']);

        $buttons = [
            ['text' => '🚚 Мой транспорт', 'callback_data' => 'vehicleScreen'],
            ['text' => '👤 Персонаж', 'callback_data' => 'character'],
        ];

        return ['text' => $text, 'buttons' => self::packRows($buttons)];
    }

    /**
     * @param array{name:string, icon:string, cells_left:int, charges_full:int, wear_per_cell:int, savings_minutes:int}|null $active
     */
    private static function activeBlock(?array $active): string
    {
        if ($active === null) {
            return "Сейчас активного транспорта нет — двигайся пешком или активируй машину из гаража ниже.\n";
        }

        $icon = $active['icon'];
        $name = $active['name'];
        $cellsLeft = $active['cells_left'];
        $chargesFull = max(1, $active['charges_full']);
        $savings = $active['savings_minutes'];

        $out = "▶️ *Активна:* {$icon} {$name}\n";

        if ($cellsLeft <= 0) {
            $out .= "💥 *Разбита* — нужен ремонт.\n";
        } else {
            $out .= "🔧 Износ: хватит ещё на ~{$cellsLeft} клеток.\n";
            if ($cellsLeft <= max(1, (int) round($chargesFull * self::WARN_SHARE))) {
                $out .= "⚠️ Скоро сломается — подумай о ремонте или замене.\n";
            }
        }

        if ($savings > 0) {
            $out .= "💰 Эта повозка сэкономила тебе ~{$savings} мин.\n";
        }

        return $out;
    }

    /**
     * @param list<array{log_id:int, name:string, icon:string}> $garage
     * @param array{name:string, icon:string, cells_left:int, charges_full:int, wear_per_cell:int, savings_minutes:int}|null $active
     */
    private static function garageBlock(array $garage, ?array $active): string
    {
        if ($garage === []) {
            return $active === null
                ? "🏚 Гараж пуст — собери машину через крафт (🚚 Транспорт).\n"
                : '';
        }

        $lines = ["🏚 *Гараж* (нажми, чтобы активировать):"];
        foreach ($garage as $item) {
            $lines[] = "· " . trim("{$item['icon']} {$item['name']}");
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{faction_id:int, faction_name:string, faction_icon:string, vehicle_name:string, vehicle_icon:string, required_level:int, unlocked:bool}> $showcase
     */
    private static function showcaseBlock(array $showcase, int $characterLevel): string
    {
        $lines = [
            "Выбор фракции на 10 уровне решает, какая из этих машин станет твоей. Навсегда.",
        ];

        foreach ($showcase as $card) {
            $factionIcon  = $card['faction_icon'];
            $factionName  = $card['faction_name'];
            $vehicleIcon  = $card['vehicle_icon'];
            $vehicleName  = $card['vehicle_name'];
            $requiredLvl  = $card['required_level'];
            $unlocked     = $card['unlocked'];

            $head = trim("{$factionIcon} {$factionName}") . " — " . trim("{$vehicleIcon} {$vehicleName}");

            if ($unlocked) {
                $lines[] = "✅ {$head} (с {$requiredLvl} ур.)";
            } else {
                $lines[] = "🔒 {$head}. Только {$factionName}. Фракция выбирается на 10 уровне, "
                    . "один раз и навсегда. С {$requiredLvl} уровня — у тебя {$characterLevel}.";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Ноль одиночек в ряду: 2–3 кнопки на ряд. Копия правила
     * `CharacterService::packButtonRows()` — тот приватный и static на другом
     * классе, дублировать вызов нельзя, логика короткая и стабильная.
     *
     * @param  list<array<string,string>>       $flat
     * @return list<list<array<string,string>>>
     */
    private static function packRows(array $flat): array
    {
        $count = count($flat);
        if ($count === 0) {
            return [];
        }
        if ($count === 1) {
            return [[$flat[0]]];
        }

        $rows = [];
        $i = 0;
        while ($i < $count) {
            $remaining = $count - $i;
            $take = ($remaining === 3 || $remaining === 1) ? min(3, $remaining) : 2;
            if ($remaining === 1 && $i > 0) {
                // Одиночка в хвосте — приклеить к предыдущему ряду, если там есть место (<3).
                $lastIndex = count($rows) - 1;
                if (count($rows[$lastIndex]) < 3) {
                    $rows[$lastIndex][] = $flat[$i];
                    $i++;
                    continue;
                }
                $take = 1;
            }
            $rows[] = array_slice($flat, $i, $take);
            $i += $take;
        }

        return $rows;
    }
}
