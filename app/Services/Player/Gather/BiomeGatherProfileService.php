<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use Config\GatherBiomeProfiles;
use App\Services\GameSettings\GameSettingsService;

/**
 * V22 (ADR-054) — «Биомы решают»: GameSettings-driven модификатор добычи по биому.
 *
 * Заменяет хардкод-либу `App\Libraries\BiomeResourceModifier` (×3/×10/÷2/÷4/÷10 на 3 биома,
 * N+1 SQL, нарушение ADR-024). Покрывает ВСЕ 9 биомов через data-shape `Config\GatherBiomeProfiles`
 * (signature/scarce-списки) + live-tunable множители GameSettings (category=resources).
 *
 * Pure non-hostile: signature ×signature_multiplier (буст), scarce ×offbiome_multiplier (дефицит).
 * Legacy сохранён точно (forest 10.0 / mountains 3.0 / deserts 10.0 + offbiome 0.5 = прежние ÷2).
 * Детерминированно (0 RNG → фикстуры стабильны). Killswitch off / неизвестный биом → unchanged.
 *
 * Зеркало FarmingService: stateless reader GameSettings, имена ресурсов приходят готовой
 * batch-map от вызывающего (НЕ N+1).
 */
final class BiomeGatherProfileService
{
    /**
     * Default signature-множитель по slug'у биома (если ключ GameSettings отсутствует).
     * forest/deserts = 10.0 (legacy ×10), остальные = 3.0.
     *
     * @var array<string, float>
     */
    private const DEFAULT_SIGNATURE = [
        'forest'    => 10.0,
        'mountains' => 3.0,
        'tundra'    => 3.0,
        'rivers'    => 3.0,
        'tropics'   => 3.0,
        'plains'    => 3.0,
        'caves'     => 3.0,
        'volcanic'  => 3.0,
        'deserts'   => 10.0,
    ];

    private const DEFAULT_OFFBIOME = 0.5;

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    // ── killswitch & knobs ───────────────────────────────────────────────

    /** Killswitch слоя. false → modifyResourcesByBiome возвращает добычу без изменений. */
    public function isEnabled(): bool
    {
        return $this->boolSetting('gather.biome.rebalance_enabled', true);
    }

    /** Множитель signature-ресурсов биома (по slug'у). >0. */
    public function signatureMultiplier(string $slug): float
    {
        $default = self::DEFAULT_SIGNATURE[$slug] ?? 1.0;
        $v = $this->floatSetting("gather.biome.{$slug}.signature_multiplier", $default);
        return $v > 0 ? $v : $default;
    }

    /** Множитель scarce-ресурсов (off-theme дефицит, общий для всех биомов). >0. */
    public function offbiomeMultiplier(): float
    {
        $v = $this->floatSetting('gather.biome.offbiome_multiplier', self::DEFAULT_OFFBIOME);
        return $v > 0 ? $v : self::DEFAULT_OFFBIOME;
    }

    // ── core ──────────────────────────────────────────────────────────────

    /**
     * Применяет biome-профиль к добыче: signature ×signature_mult, scarce ×offbiome_mult.
     *
     * Killswitch off / биом не в карте / пустой результат → возврат без изменений (0 регрессии).
     * Имена ресурсов берутся из готовой map (batch у вызывающего — НЕ N+1). Ресурс без имени
     * в map → не модифицируется.
     *
     * @param list<array<string,mixed>> $foundResources элементы [resource_id, amount, type]
     * @param array<int,string>         $nameById       resource_id → RU-имя (resources.name)
     * @return list<array<string,mixed>>
     */
    public function modifyResourcesByBiome(?int $biomeId, array $foundResources, array $nameById): array
    {
        if ($foundResources === [] || ! $this->isEnabled()) {
            return $foundResources;
        }

        $slug = GatherBiomeProfiles::slugFor($biomeId);
        if ($slug === null) {
            return $foundResources;
        }

        $signature = GatherBiomeProfiles::signatureFor($biomeId);
        $scarce    = GatherBiomeProfiles::scarceFor($biomeId);
        if ($signature === [] && $scarce === []) {
            return $foundResources;
        }

        $sigMult = $this->signatureMultiplier($slug);
        $offMult = $this->offbiomeMultiplier();

        foreach ($foundResources as $i => $res) {
            $ridRaw = $res['resource_id'] ?? null;
            $rid    = is_numeric($ridRaw) ? (int) $ridRaw : 0;
            $amtRaw = $res['amount'] ?? null;
            if ($rid === 0 || ! is_numeric($amtRaw)) {
                continue;
            }
            $name = $nameById[$rid] ?? null;
            if ($name === null) {
                continue;
            }

            $mult = null;
            if (in_array($name, $signature, true)) {
                $mult = $sigMult;
            } elseif (in_array($name, $scarce, true)) {
                $mult = $offMult;
            }
            if ($mult === null) {
                continue;
            }

            $foundResources[$i]['amount'] = (int) round(((int) $amtRaw) * $mult);
        }

        return $foundResources;
    }

    /**
     * Signature-ресурсы биома (RU-имена) для UI-хинта «биом богат: …».
     * Пусто если killswitch off или биом не в карте.
     *
     * @return list<string>
     */
    public function signatureNamesFor(?int $biomeId): array
    {
        if (! $this->isEnabled()) {
            return [];
        }
        return GatherBiomeProfiles::signatureFor($biomeId);
    }

    // ── internals (зеркало FarmingService) ────────────────────────────────

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
