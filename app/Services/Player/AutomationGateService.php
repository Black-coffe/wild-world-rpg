<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-086 Фаза 1b — Солнечная станция как пререквизит запуска автоматизации.
 *
 * Владение Станцией становится условием для ЗАПУСКА новых роботов и телепортаций
 * («Станция питает автоматику и телепорт»). Гейтит только НОВЫЕ запуски (точка =
 * создание задачи / выполнение телепорта); уже идущие задачи в character_tasks
 * не перепроверяются → **grandfather автоматический** (никогда не рвём чужой прогресс).
 *
 * Dormant: killswitch `building.solarstation.gate.enabled` (default false) → `blocksLaunch`
 * всегда false → 0 эффекта (байт-идентичное поведение). Активация — флип в admin-UI
 * после Tier-3 + balance-pass overlap (сколько robot/teleport-владельцев уже имеют Станцию).
 *
 * DI: BuildingModel/CharacterBuildingModel + callable $settingReader (GameSettingsService
 * final → callable-инъекция, как BuildingEffectsService) для тестов.
 */
final class AutomationGateService
{
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $charBuildingModel;
    /** @var (callable(string,mixed):mixed)|null */
    private $settingReader;

    /**
     * @param (callable(string,mixed):mixed)|null $settingReader  Tests injection.
     *   Production: lazy `new GameSettingsService()->get($key, $default)`.
     */
    public function __construct(
        ?BuildingModel $buildingModel = null,
        ?CharacterBuildingModel $charBuildingModel = null,
        ?callable $settingReader = null,
    ) {
        $this->buildingModel     = $buildingModel ?? new BuildingModel();
        $this->charBuildingModel = $charBuildingModel ?? new CharacterBuildingModel();
        $this->settingReader     = $settingReader;
    }

    /**
     * Killswitch гейта. default false (dormant). Coerce mixed → bool без cast (phpstan L9).
     */
    public function gateEnabled(): bool
    {
        $reader = $this->settingReader ?? static fn (string $k, mixed $d): mixed
            => (new GameSettingsService())->get($k, $d);
        $v = $reader('building.solarstation.gate.enabled', false);

        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * Владеет ли персонаж Солнечной станцией (любого уровня).
     */
    public function ownsSolarStation(int $charId): bool
    {
        $b = $this->buildingModel->where('name_en', 'SolarStation')->first();
        if (! is_array($b)) {
            return false;
        }
        $idRaw = $b['id'] ?? null;
        if (! is_numeric($idRaw) || (int) $idRaw === 0) {
            return false;
        }
        $row = $this->charBuildingModel
            ->where('character_id', $charId)
            ->where('building_id', (int) $idRaw)
            ->first();
        return is_array($row);
    }

    /**
     * Нужно ли ЗАБЛОКИРОВАТЬ запуск (gate ON И нет Станции).
     * При killswitch OFF (dormant) — всегда false (0 эффекта).
     */
    public function blocksLaunch(int $charId): bool
    {
        if (! $this->gateEnabled()) {
            return false;
        }
        return ! $this->ownsSolarStation($charId);
    }

    /**
     * Текст lock-сообщения: объясняет пререквизит + путь его выполнения
     * (UX-discoverability). Plain-text + эмодзи (без markdown-спецсимволов) —
     * рендерится одинаково во всех send-путях (часть с parse_mode, часть без).
     */
    public function lockMessage(): string
    {
        return "🔒 Нет питания. Для запуска автоматики (роботы) и телепортации нужна "
            . "☀️ Солнечная станция — она снабжает энергией роботов и телепорт.\n\n"
            . "Построй её на базе: 🏚️ База → Строить → ☀️ Солнечная станция. "
            . "Уже запущенные задачи продолжатся без помех.";
    }
}
