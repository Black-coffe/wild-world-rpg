<?php

declare(strict_types=1);

namespace App\Services\Player\TeleportBeacon;

use App\Services\GameSettings\GameSettingsService;

/**
 * Баланс телепорт-маяков в одной точке (ADR-024: параметр, влияющий на баланс,
 * живёт в админке, а не магическим числом в коде).
 *
 * До этого запас телепортов (100) и суточный налог (180$) были константами
 * `BeaconInstaller`, а экраны дублировали их литералами в тексте — «🔋 Запас
 * прочности: 100 использований» было написано руками и разъехалось бы с кодом
 * при первом же ребалансе. Теперь и запись в БД, и текст экранов читают одно
 * значение.
 *
 * Что берут значения:
 *  - `world.teleport.beacon_max_uses` — сколько телепортов держит новый маяк
 *    (пишется в `teleport_beacons.remaining_uses` при установке);
 *  - `world.teleport.beacon_tax_per_day` — налог за маяк в сутки (пишется в
 *    `teleport_beacons.tax_cost`, дальше его списывает `TaxCollectionHandler`).
 *
 * ⚠️ Уже установленные маяки хранят СВОИ значения в строке — смена настройки
 * влияет на новые маяки, старые доживают со своим запасом и своим налогом.
 */
final class BeaconSettings
{
    public const DEFAULT_MAX_USES    = 100;
    public const DEFAULT_TAX_PER_DAY = 180;

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Запас телепортов у нового маяка. */
    public function maxUses(): int
    {
        $v = $this->settings->get('world.teleport.beacon_max_uses', self::DEFAULT_MAX_USES);

        return is_numeric($v) && (int) $v >= 1 ? (int) $v : self::DEFAULT_MAX_USES;
    }

    /** Суточный налог за один стоящий маяк. */
    public function taxPerDay(): int
    {
        $v = $this->settings->get('world.teleport.beacon_tax_per_day', self::DEFAULT_TAX_PER_DAY);

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : self::DEFAULT_TAX_PER_DAY;
    }
}
