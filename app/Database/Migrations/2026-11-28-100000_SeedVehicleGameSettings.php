<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Транспортная система (`docs/specs/transport-system/`, story transport-02) — числа пяти машин
 * в GameSettings под `world.vehicle.*` (категория `world`). Источник значений —
 * `concept-final.md §1` (панель гейм-дизайнер + лор-надзиратель + 2 игрока + архитектурный
 * консилиум, ADR-174) и таблица `plan.md → ## Contracts`.
 *
 * Killswitch `world.vehicle.enabled=false` — DORMANT: `VehicleEffectsService::profileFor()`
 * всегда отдаёт нейтраль, пока никто не запрашивает профиль (story 02 не трогает MarchAction/
 * MarchingTaskHandler — врезка story 04). `world.vehicle.cargo.max_share=0.33` — общий потолок
 * доли груза для всех машин; на выкате шага 1 поднимается через admin UI до 0 (см. plan.md
 * → «Порядок выката»), в этой миграции сеется финальным значением 0.33 (Шаг 2).
 *
 * Пять машин: 🛒 Лёгкая повозка (`cart`, всем, ур.6) · 🚲 Горный велосипед (`mtb`, Партизаны,
 * ур.12) · 🛻 Снегоход (`snowmobile`, Милитари, ур.14) · 🐎 Тягловая повозка (`draft_cart`,
 * Фермеры, ур.14) · 🛸 Автономный дрон (`drone_auto`, Инженеры, ур.16). У каждой — честный минус
 * (лор-требование: «нигде не может быть 0», бонус либо есть, либо равен пешему).
 *
 * Idempotent (как Adr131/Adr138): по `setting_key`. `game_settings` = KEEP (WipeManifest не
 * трогаем — новых таблиц/колонок нет).
 */
class SeedVehicleGameSettings extends Migration
{
    /**
     * @var array<string, array{name: string, level: int, faction: string, explored: int, unexplored: int, cold: int, tired: float, order: int, cargo: float, charges: int, wear: int, minus: string, above: string, below: string}>
     */
    private array $vehicles = [
        'cart' => [
            'name' => '🛒 Лёгкая повозка', 'level' => 6, 'faction' => '',
            'explored' => 4, 'unexplored' => 4, 'cold' => 4, 'tired' => 0.90, 'order' => 90,
            'cargo' => 0.20, 'charges' => 300, 'wear' => 1,
            'minus' => 'самый слабый бонус усталости среди пяти машин',
            'above' => 'ускоряет ход больше повозки без веса — размывает нишу «стартовая, доступна всем, дешёвая» относительно фракционных машин.',
            'below' => 'усталость почти как у пешего — древесина+ткань потрачены впустую, машина не ощущается покупкой.',
        ],
        'mtb' => [
            'name' => '🚲 Горный велосипед', 'level' => 12, 'faction' => 'partisans',
            'explored' => 4, 'unexplored' => 5, 'cold' => 5, 'tired' => 0.80, 'order' => 90,
            'cargo' => 0.00, 'charges' => 350, 'wear' => 1,
            'minus' => 'ноль груза, хрупкий (списывается на общих правах с остальными)',
            'above' => 'бонус на разведанной земле догоняет целину — ломает разворот «максимум на бездорожье, а не на знакомой земле» (канон Партизан).',
            'below' => 'на целине не отличим от повозки — партизанская мобильность на фронтире перестаёт читаться.',
        ],
        'snowmobile' => [
            'name' => '🛻 Снегоход', 'level' => 14, 'faction' => 'military',
            'explored' => 4, 'unexplored' => 4, 'cold' => 5, 'tired' => 1.10, 'order' => 120,
            'cargo' => 0.25, 'charges' => 400, 'wear' => 2,
            'minus' => 'усталость ХУЖЕ пешего (единственное исключение поимённо) + износ ×2',
            'above' => 'при tired_factor ниже 1.0 снегоход перестаёт быть «зависимым от логистики» (канон Милитари) — исчезает честный минус.',
            'below' => 'выше 1.10 снегоход дороже пешего хода настолько, что теряет смысл — только специализация в холодных биомах должна компенсировать.',
        ],
        'draft_cart' => [
            'name' => '🐎 Тягловая повозка', 'level' => 14, 'faction' => 'farmers',
            'explored' => 4, 'unexplored' => 4, 'cold' => 4, 'tired' => 0.75, 'order' => 120,
            'cargo' => 0.33, 'charges' => 400, 'wear' => 1,
            'minus' => 'скорость не растёт вовсе (cells_per_tick = база везде)',
            'above' => 'пол усталости 0.75 — ниже уже 0.5× базы (0.375 клеток): единственный регулятор темпа исследования обесценивается, остров открывается за сутки.',
            'below' => 'выше 0.75 тягловая повозка теряет смысл на фоне лёгкой повозки при той же скорости.',
        ],
        'drone_auto' => [
            'name' => '🛸 Автономный дрон', 'level' => 16, 'faction' => 'engineers',
            'explored' => 3, 'unexplored' => 3, 'cold' => 3, 'tired' => 0.75, 'order' => 90,
            'cargo' => 0.00, 'charges' => 350, 'wear' => 1,
            'minus' => 'не ускоряет вовсе (cells_per_tick = база 3 на всех местностях)',
            'above' => 'если cells_per_tick поднять выше базы — дрон перестаёт быть «зависимостью от энергии» без скорости (канон Инженеров), дублирует велосипед.',
            'below' => 'пол усталости 0.75 — тот же нижний предел, что у тягловой повозки; ниже обесценивает регулятор темпа.',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.vehicle.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Мастер-killswitch транспортной системы (story transport-02). false (default) — DORMANT: VehicleEffectsService::profileFor() всегда отдаёт нейтраль (пеший профиль), поход байт-идентичен до-транспорта. true включает профили пяти машин по их GameSettings-ключам.',
                'effect_text'        => 'VehicleEffectsService::isEnabled() гейтит profileFor(). true — активная машина у персонажа (story 03/04) начинает влиять на темп/усталость/груз/износ похода.',
                'above_effect_text'  => 'true — транспорт живой; включать только после Tier-3 смока на preprod-testbot (plan.md → «Порядок выката»).',
                'below_effect_text'  => 'false — транспорт невидим и не влияет на игру, даже если рецепты и предметы уже выкачены.',
            ],
            [
                'setting_key'        => 'world.vehicle.cargo.max_share',
                'value_type'         => 'float',
                'value_float'        => 0.33,
                'default_value_text' => '0.33',
                'rationale_text'     => 'Общий потолок доли груза, которую переносит любая машина (клип для всех per-vehicle cargo_share). 0.33 — Шаг 2 выката; на Шаге 1 поднимается временно до 0 через admin UI, пока карго-механика не выехала (plan.md → «Порядок выката», story 08).',
                'effect_text'        => 'VehicleEffectsService::profileFor() зажимает cargo_share сверху этим значением независимо от per-vehicle настройки.',
                'above_effect_text'  => 'Выше 0.33 — тягловая повозка (cargo_share=0.33) перестаёт быть потолком карго-роли, любая машина может стать грузовой без честного минуса.',
                'below_effect_text'  => 'На 0 — карго-бонус выключен у всех машин разом (безопасный старт Шага 1, story 08 ещё не врезана).',
                'recommended_min'    => '0.00',
                'recommended_max'    => '0.33',
                'hard_min'           => '0.00',
                'hard_max'           => '0.50',
            ],
        ];

        foreach ($this->vehicles as $key => $v) {
            $prefix = "world.vehicle.{$key}.";
            $rows[] = [
                'setting_key'        => $prefix . 'cells_per_tick_explored',
                'value_type'         => 'int',
                'value_int'          => $v['explored'],
                'default_value_text' => (string) $v['explored'],
                'rationale_text'     => "{$v['name']} — клеток/тик по разведанной земле. Значение из concept-final.md §1: {$v['above']}",
                'effect_text'        => 'VehicleEffectsService::profileFor() читает этот ключ при terrain=explored, зажимает [base..5].',
                'above_effect_text'  => "Выше базы похода машина заметно ускоряет знакомую землю: {$v['above']}",
                'below_effect_text'  => 'Ниже базы клемпится к базе (cells_per_tick никогда не хуже пешего) — настройка станет мёртвой.',
                'recommended_min'    => '3', 'recommended_max' => '5', 'hard_min' => '1', 'hard_max' => '5',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'cells_per_tick_unexplored',
                'value_type'         => 'int',
                'value_int'          => $v['unexplored'],
                'default_value_text' => (string) $v['unexplored'],
                'rationale_text'     => "{$v['name']} — клеток/тик по целине (фронтиру). Бонус транспорта смотрит именно сюда (concept-final.md §0.2): {$v['minus']}.",
                'effect_text'        => 'VehicleEffectsService::profileFor() читает этот ключ при terrain=unexplored, зажимает [base..5].',
                'above_effect_text'  => "Выше указанного — машина ускоряет фронтир сильнее замысла: {$v['above']}",
                'below_effect_text'  => 'Ниже базы клемпится к базе — фронтирный бонус исчезает, машина ощущается сломанной.',
                'recommended_min'    => '3', 'recommended_max' => '5', 'hard_min' => '1', 'hard_max' => '5',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'cells_per_tick_cold',
                'value_type'         => 'int',
                'value_int'          => $v['cold'],
                'default_value_text' => (string) $v['cold'],
                'rationale_text'     => "{$v['name']} — клеток/тик в холодных биомах (Горы+Тундра, ~16% карты). {$v['above']}",
                'effect_text'        => 'VehicleEffectsService::profileFor() читает этот ключ при terrain=cold, зажимает [base..5].',
                'above_effect_text'  => "Выше указанного холодная специализация перестаёт быть честным различием между машинами.",
                'below_effect_text'  => 'Ниже базы клемпится к базе — холодная специализация (снегоход) теряет смысл.',
                'recommended_min'    => '3', 'recommended_max' => '5', 'hard_min' => '1', 'hard_max' => '5',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'tired_factor',
                'value_type'         => 'float',
                'value_float'        => $v['tired'],
                'default_value_text' => (string) $v['tired'],
                'rationale_text'     => "{$v['name']} — множитель усталости за клетку (база 0.5, пол 0.375={$v['tired']} снегохода-исключения не считая). Честный минус: {$v['minus']}.",
                'effect_text'        => 'VehicleEffectsService::profileFor() отдаёт tired_factor как есть — врезка в march-стоимость (story 04/05).',
                'above_effect_text'  => $v['above'],
                'below_effect_text'  => $v['below'],
                'recommended_min'    => '0.75', 'recommended_max' => '1.10', 'hard_min' => '0.75', 'hard_max' => '1.10',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'max_steps_per_order',
                'value_type'         => 'int',
                'value_int'          => $v['order'],
                'default_value_text' => (string) $v['order'],
                'rationale_text'     => "{$v['name']} — потолок клеток в одном приказе Похода (легаси-база 60). Длина приказа — ведущая величина транспорта наравне с усталостью (concept-final.md §0.3).",
                'effect_text'        => 'VehicleEffectsService::profileFor() отдаёт max_steps_per_order как есть — MarchAction/MarchingTaskHandler используют его вместо базового потолка (story 04).',
                'above_effect_text'  => 'Выше — дальний выход перестаёт требовать дробления на несколько приказов, обесценивая ручное планирование маршрута.',
                'below_effect_text'  => 'Ниже базы 60 — машина ХУЖЕ пешего по длине приказа, читается как поломка.',
                'recommended_min'    => '60', 'recommended_max' => '150', 'hard_min' => '60', 'hard_max' => '200',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'cargo_share',
                'value_type'         => 'float',
                'value_float'        => $v['cargo'],
                'default_value_text' => (string) $v['cargo'],
                'rationale_text'     => "{$v['name']} — доля дополнительного груза (0=нет карго-роли, зажато сверху world.vehicle.cargo.max_share). {$v['minus']}.",
                'effect_text'        => 'VehicleEffectsService::profileFor() зажимает cargo_share в [0..world.vehicle.cargo.max_share] — врезка в перенос груза story 08.',
                'above_effect_text'  => 'Выше max_share клемпится — настройка станет мёртвой сверх общего потолка.',
                'below_effect_text'  => 'Ниже 0 клемпится к 0 — карго-роль исчезает у машины, для которой она задумана (тягловая повозка).',
                'recommended_min'    => '0.00', 'recommended_max' => '0.33', 'hard_min' => '0.00', 'hard_max' => '0.33',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'charges_full',
                'value_type'         => 'int',
                'value_int'          => $v['charges'],
                'default_value_text' => (string) $v['charges'],
                'rationale_text'     => "{$v['name']} — полный запас зарядов (клеток износа до поломки) у свежесобранной/отремонтированной машины. Читает VehicleActivationService::effectiveCharges() (story 03) с зажимом min(текущий, эта база).",
                'effect_text'        => 'Верхняя граница зарядов активной машины — story 03 зажимает текущий заряд к этому значению при каждом чтении.',
                'above_effect_text'  => 'Выше — машина служит дольше между ремонтами/заменами, sink ресурсов реже.',
                'below_effect_text'  => 'Ниже — машина ломается быстрее, чаще требует крафта заново (экономика ремонта/замены).',
                'recommended_min'    => '200', 'recommended_max' => '500', 'hard_min' => '50', 'hard_max' => '1000',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'wear_per_cell',
                'value_type'         => 'int',
                'value_int'          => $v['wear'],
                'default_value_text' => (string) $v['wear'],
                'rationale_text'     => "{$v['name']} — списание зарядов за клетку похода (снегоход ×2 — честная цена за силу в холодных биомах). Врезка в списание — VehicleActivationService::spendCharges() (story 03).",
                'effect_text'        => 'Каждая пройденная клетка активной машины списывает это число зарядов с charges_full.',
                'above_effect_text'  => 'Выше — машина изнашивается быстрее, чем задумано её карточкой, ломает баланс sink/ценность.',
                'below_effect_text'  => 'Ниже 1 — машина не изнашивается вовсе, теряет sink-механику (кроме дрона на базовой ставке 1, задуманного как долгоживущий).',
                'recommended_min'    => '1', 'recommended_max' => '3', 'hard_min' => '1', 'hard_max' => '5',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'required_level',
                'value_type'         => 'int',
                'value_int'          => $v['level'],
                'default_value_text' => (string) $v['level'],
                'rationale_text'     => "{$v['name']} — минимальный уровень доступа к крафту/использованию (concept-final.md §1, сдвинуто к точке выбора фракции 10). Гейт закрывает фракционные рецепты сам, без отдельного флага показа.",
                'effect_text'        => 'Читается краftов гейтом (story 06/07) и экраном «Мой транспорт» (story 10) для 🔒-состояния.',
                'above_effect_text'  => 'Выше — машина недоступна дольше, откладывает пользу от вложенного крафта.',
                'below_effect_text'  => 'Ниже — машина доступна раньше точки выбора фракции, размывает связь «фракция = транспорт».',
                'recommended_min'    => '1', 'recommended_max' => '20', 'hard_min' => '1', 'hard_max' => '60',
            ];
            $rows[] = [
                'setting_key'        => $prefix . 'required_faction',
                'value_type'         => 'string',
                'value_string'       => $v['faction'],
                'default_value_text' => $v['faction'] === '' ? '' : $v['faction'],
                'rationale_text'     => "{$v['name']} — фракционный гейт (''=доступна всем, иначе id фракции: partisans/military/farmers/engineers). Фракционные машины — часть выбора фракции на 10 уровне (concept-final.md, «развороты по лору»).",
                'effect_text'        => 'Читается краftов гейтом (story 06/07) и экраном выбора фракции (story 11) для строки «что бывает у фракций».',
                'above_effect_text'  => 'Не применимо (строковый enum, не диапазон).',
                'below_effect_text'  => 'Не применимо (строковый enum, не диапазон).',
            ];
        }

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $keys = ['world.vehicle.enabled', 'world.vehicle.cargo.max_share'];
        foreach (array_keys($this->vehicles) as $key) {
            $prefix = "world.vehicle.{$key}.";
            $keys   = array_merge($keys, [
                $prefix . 'cells_per_tick_explored',
                $prefix . 'cells_per_tick_unexplored',
                $prefix . 'cells_per_tick_cold',
                $prefix . 'tired_factor',
                $prefix . 'max_steps_per_order',
                $prefix . 'cargo_share',
                $prefix . 'charges_full',
                $prefix . 'wear_per_cell',
                $prefix . 'required_level',
                $prefix . 'required_faction',
            ]);
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
