<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Каталог ран, которые НЕ лечатся едой.
 *
 * Зачем. Аудит 18.08.2026 (повод — сигнал игрока Анжелы «непонятен смысл лекарств:
 * быстрее отжираться консервами») показал, что лекарства и еда конкурировали на ОДНОЙ
 * оси — восстановление здоровья и выносливости, — и еда выигрывала:
 *
 * | предмет                      | HP | выносл. |
 * |------------------------------|----|---------|
 * | 🍲 Сытное рагу (еда)         | 45 | 55      |
 * | 🐟 Рыбные консервы (еда)     | 45 | 45      |
 * | 🧰 Аптечка (лекарство)       | 40 | 20      |
 * | 💉 Регенератор (лекарство)   | 30 | 20      |
 * | 🧴 Антисептик (лекарство)    |  4 |  2      |
 * | 🩹 Бинт (лекарство)          |  2 |  1      |
 *
 * То есть лучшее лекарство было слабее обычного блюда, а бинт лечил 2 HP. Лекарствам
 * нужна была не прибавка к числам, а **своя ось**: состояния, которые едой не снимаются.
 *
 * Числа эффектов живут в админке (ADR-024, ключи `debuff.<key>.*`) — здесь только
 * структура: что это, как называется, чем лечится, куда бьёт.
 *
 * Инварианты:
 *  - 🔴 еда НИКОГДА не снимает состояние — только предмет из `cured_by`;
 *  - у каждого состояния есть хотя бы один достижимый предмет лечения
 *    (гейт `tests/unit/Config/DebuffsTest.php`);
 *  - тексты самодостаточны (media-off, ADR-020).
 */
class Debuffs extends BaseConfig
{
    public const POISON    = 'poison';
    public const BURN      = 'burn';
    public const FROSTBITE = 'frostbite';
    public const FRACTURE  = 'fracture';

    /**
     * Полный каталог. `effect` — тип воздействия, читаемый кодом:
     *  - `hp_drain`    — тик отнимает здоровье, пока состояние держится;
     *  - `heal_cap`    — потолок: лечиться выше доли от максимума нельзя;
     *  - `slowdown`    — все дела (добыча, крафт, стройка, переход) идут дольше.
     *
     * `cured_by` — `name_eng` предметов из `crafted_items`, любой из них снимает.
     *
     * @var array<string, array{
     *     name: string,
     *     emoji: string,
     *     effect: string,
     *     what: string,
     *     source_hint: string,
     *     cured_by: list<string>,
     *     cure_hint: string
     * }>
     */
    public const CATALOG = [
        self::POISON => [
            'name'        => 'Отравление',
            'emoji'       => '🤢',
            'effect'      => 'hp_drain',
            'what'        => 'Здоровье тает само по себе, пока яд в крови — еда не помогает, она лишь кормит.',
            'source_hint' => 'Укус ядовитой твари.',
            'cured_by'    => ['Antiseptic', 'FirstAidKit'],
            'cure_hint'   => 'Антисептик (или Аптечка) промывает рану и останавливает яд.',
        ],
        self::BURN => [
            'name'        => 'Ожог',
            'emoji'       => '🔥',
            'effect'      => 'heal_cap',
            'what'        => 'Обожжённое тело не берёт лечение выше определённого предела — сколько ни ешь, до полного не доберёшься.',
            'source_hint' => 'Огонь, лава, метеоритный дождь.',
            'cured_by'    => ['Bandage', 'FirstAidKit'],
            'cure_hint'   => 'Бинт (или Аптечка) закрывает ожог, и потолок снимается.',
        ],
        self::FROSTBITE => [
            'name'        => 'Обморожение',
            'emoji'       => '🥶',
            'effect'      => 'heal_cap',
            'what'        => 'Обмороженные руки и ноги не восстанавливаются полностью, пока их не отогреть и не перевязать.',
            'source_hint' => 'Долгая стоянка в холодных биомах.',
            'cured_by'    => ['Bandage', 'FirstAidKit'],
            'cure_hint'   => 'Бинт (или Аптечка) — перевязать и отогреть.',
        ],
        self::FRACTURE => [
            'name'        => 'Перелом',
            'emoji'       => '🦴',
            'effect'      => 'slowdown',
            'what'        => 'Со сломанной костью всё идёт дольше: и добыча, и сборка, и переход по карте.',
            'source_hint' => 'Падение в горах и пещерах.',
            'cured_by'    => ['Regenerator', 'FirstAidKit'],
            'cure_hint'   => 'Регенератор (или Аптечка) сращивает кость.',
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * @return array{name:string, emoji:string, effect:string, what:string, source_hint:string, cured_by:list<string>, cure_hint:string}|null
     */
    public static function get(string $key): ?array
    {
        return self::CATALOG[$key] ?? null;
    }

    /**
     * Какие состояния снимает предмет (по `name_eng`).
     *
     * @return list<string>
     */
    public static function curedByItem(string $itemNameEng): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $row) {
            if (in_array($itemNameEng, $row['cured_by'], true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
