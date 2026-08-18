<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;
use Config\Debuffs;

/**
 * Откуда берутся раны, которые не лечатся едой.
 *
 * Первый (и пока единственный) источник — переход по опасному биому: где ходишь, то и
 * получаешь. Лор игрока, из которого выросла механика: «после укуса ядовитых тварей»,
 * «всякие пожары, лава, вулканы», «при долгом отсутствии действий в холодных биомах»,
 * «падение в горах и пещерах».
 *
 * Сопоставление биом → рана держится на `biome_type` (а не на id: id зависит от
 * сидов конкретной базы, и завязка на него — классическая мина «имя из БД ≠ ключ
 * конфига»). Тип биома читается из строки `biomes`.
 *
 * Все шансы — в админке (ADR-024), ключ `debuff.chance.<key>_percent`; ноль отключает
 * источник, не трогая остальную систему. Общий killswitch — `debuff.enabled`.
 */
final class DebuffSourceService
{
    /**
     * Тип биома → какая рана там водится. Значения сверены с живой таблицей `biomes`
     * (типы: wet / cold / plain / cave / volcanic / dry) — выдуманные ключи вроде
     * «mountains» не сматчились бы никогда и источник молча не работал бы.
     *
     * ⚠️ «Горы» и «Тундра» делят один тип `cold`, поэтому в горах ловится обморожение,
     * а перелом — в пещерах. Разделить их можно, только если у биома появится
     * собственный признак риска; на типах это неразличимо.
     *
     * @var array<string, string>
     */
    private const BIOME_RISK = [
        'cave'     => Debuffs::FRACTURE,
        'volcanic' => Debuffs::BURN,
        'cold'     => Debuffs::FROSTBITE,
        'wet'      => Debuffs::POISON,
    ];

    private DebuffService $debuffs;
    private GameSettingsService $settings;

    public function __construct(?DebuffService $debuffs = null, ?GameSettingsService $settings = null)
    {
        $this->debuffs  = $debuffs  ?? new DebuffService();
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Бросок при входе на клетку. Возвращает ключ полученной раны или null.
     *
     * 🔴 Принимает и массив, и Entity: `BiomeModel` объявляет `$returnType = BiomeEntity`,
     * и первая версия хука отсеивала Entity через `is_array()` — источник молча не
     * работал вовсе (поймано Tier-3 на testbot: шанс 100%, переход в «Горы», ноль ран).
     * Нормализуем здесь, чтобы вызывающему не приходилось помнить про тип модели.
     *
     * @param array<array-key, mixed>|\CodeIgniter\Entity\Entity|null $biomeRow строка `biomes` целевой клетки
     */
    public function rollOnMove(int $characterId, array|\CodeIgniter\Entity\Entity|null $biomeRow): ?string
    {
        if ($characterId <= 0 || $biomeRow === null || ! $this->debuffs->enabled()) {
            return null;
        }

        $biomeRow = $biomeRow instanceof \CodeIgniter\Entity\Entity ? $biomeRow->toArray() : $biomeRow;

        $key = $this->riskFor($biomeRow);
        if ($key === null) {
            return null;
        }

        // Уже болеешь этим — второй бросок не нужен: повторная выдача только копит
        // тяжесть, а получать «ещё один перелом» на каждом шаге по горам — наказание
        // без выхода.
        if ($this->debuffs->has($characterId, $key)) {
            return null;
        }

        $chance = $this->chancePercent($key);
        if ($chance <= 0) {
            return null;
        }

        // Опасность биома усиливает риск: danger_level 10 удваивает базовый шанс.
        $danger = is_numeric($biomeRow['danger_level'] ?? null) ? (int) $biomeRow['danger_level'] : 5;
        $scaled = (int) round($chance * (1 + max(0, $danger - 5) / 5));

        if (random_int(1, 100) > max(1, $scaled)) {
            return null;
        }

        return $this->debuffs->apply($characterId, $key, 'biome') ? $key : null;
    }

    /**
     * Какая рана водится в этом биоме (null — безопасно).
     *
     * @param array<array-key, mixed> $biomeRow
     */
    public function riskFor(array $biomeRow): ?string
    {
        $type = $biomeRow['biome_type'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }

        return self::BIOME_RISK[strtolower($type)] ?? null;
    }

    private function chancePercent(string $key): int
    {
        $v = $this->settings->get("debuff.chance.{$key}_percent", 0);

        return is_numeric($v) ? max(0, min(100, (int) $v)) : 0;
    }
}
