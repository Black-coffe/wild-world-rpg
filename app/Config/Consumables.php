<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Единственный источник правды «лекарство это или провизия» на экранах аптечки.
 *
 * Зачем отдельно от `crafted_items.type`. Тип `drug` в БД завязан на шесть механик
 * (срок годности ADR-094, поиск аптечки боссфайтом, страховка, крафт-дерево, ярлыки
 * рынка, деградация эффекта) — смена типа у 28 провизионных строк тихо изменила бы их
 * все, а нужна только другая полка на экране. Здесь — чисто презентационная
 * классификация (docs/specs/pharmacy-split/brief.md).
 *
 * 🔴 Незнакомое `name_eng` → провизия. Кулинарного контента прибавляется в разы
 * быстрее медицинского, и забытый в списке новый отвар должен попасть на полку с едой,
 * а не снова засорить аптечку. Забытое новое лекарство при этом остаётся применимым —
 * оно просто стоит не на своей полке, тупика не создаёт.
 */
class Consumables extends BaseConfig
{
    public const SHELF_MEDICINE  = 'medicine';
    public const SHELF_PROVISION = 'provision';

    /** @var list<string> `crafted_items.name_eng` — снимают раны (Config\Debuffs::cured_by). */
    public const MEDICINE = [
        'headache_tablets',
        'FirstAidKit',
        'Common cold tincture',
        'TonicElixir',
        'Bandage',
        'AnalgesicPowder',
        'Stimulator',
        'Antiseptic',
        'Sedative',
        'Regenerator',
        'SyntheticMedicine',
        'EmergencyTransfusion',
        'SurgicalKit',
        'WinterWarmingBalm',
        'SummerAloeBalm',
    ];

    /**
     * @var list<string> `crafted_items.name_eng` — еда и питьё, состояний не снимают.
     * Три пограничных сезонных отвара (SpringPrimroseInfusion, SpringShootsDecoction,
     * SummerMintTea) варятся на костре рядом с квасом и морсом — не лечат, поэтому здесь.
     */
    public const PROVISION = [
        'WinterHerbalBrew',
        'WinterHoneyMead',
        'WinterCampStew',
        'WinterPreserves',
        'SpringFirstHerbTea',
        'SpringBirchSap',
        'SpringPrimroseInfusion',
        'SpringWildGreens',
        'SpringShootsDecoction',
        'SummerColdKvass',
        'SummerBerryMors',
        'SummerFruitWater',
        'SummerMintTea',
        'AutumnBerryJam',
        'AutumnMushroomStew',
        'AutumnNutMix',
        'AutumnCider',
        'AutumnVegPreserves',
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
        'StewPreserve',
        'DryRation',
        'FishSoup',
        'GrilledFish',
        'FishPreserve',
    ];

    /**
     * На какую полку кладём предмет. Незнакомое имя → провизия (см. класс-докблок).
     */
    public static function shelfOf(string $nameEng): string
    {
        return in_array($nameEng, self::MEDICINE, true) ? self::SHELF_MEDICINE : self::SHELF_PROVISION;
    }
}
