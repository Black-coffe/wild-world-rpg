<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse;

use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Farming\FarmingService;

/**
 * V6 (ADR-033) — общие DB-helpers для активного земледелия (SeedSelect / Preview /
 * PlantStart). Используется классами, наследующими BaseAction (доступ к
 * $this->characterTaskModel / $this->taskModel / $this->resourceModel).
 *
 * FarmingService остаётся чистым GameSettings-reader'ом; DB-доступ — здесь.
 * V7: greenhouse-level множители (урожай/время роста) — через BuildingEffectsService.
 */
trait FarmingActionTrait
{
    /** V7 — BuildingEffectsService для greenhouse-level множителей. */
    protected function buildingEffects(): BuildingEffectsService
    {
        return new BuildingEffectsService();
    }

    /** Есть ли у персонажа теплица (building name_en='Greenhouse'). */
    protected function hasGreenhouse(int $characterId): bool
    {
        $db    = \Config\Database::connect();
        $count = $db->table('character_buildings cb')
            ->join('buildings b', 'b.id = cb.building_id')
            ->where('cb.character_id', $characterId)
            ->where('b.name_en', 'Greenhouse')
            ->countAllResults();
        return $count > 0;
    }

    /**
     * Сколько семян данной культуры у персонажа (character_resources по name_en).
     */
    protected function ownedSeedQty(int $characterId, string $seedNameEn): int
    {
        $db  = \Config\Database::connect();
        $row = $db->table('character_resources cr')
            ->select('cr.quantity')
            ->join('resources r', 'r.id = cr.id_resources')
            ->where('cr.id_characters', $characterId)
            ->where('r.name_en', $seedNameEn)
            ->get();
        $arr = $row !== false ? $row->getRowArray() : null;
        return is_array($arr) && isset($arr['quantity']) && is_numeric($arr['quantity']) ? (int) $arr['quantity'] : 0;
    }

    /**
     * Карта name_en → qty для всех 4 семян (один SQL).
     *
     * @param list<string> $seedNamesEn
     * @return array<string,int>
     */
    protected function ownedSeedMap(int $characterId, array $seedNamesEn): array
    {
        if ($seedNamesEn === []) {
            return [];
        }
        $db   = \Config\Database::connect();
        $rows = $db->table('character_resources cr')
            ->select('r.name_en AS name_en, cr.quantity AS quantity')
            ->join('resources r', 'r.id = cr.id_resources')
            ->where('cr.id_characters', $characterId)
            ->whereIn('r.name_en', $seedNamesEn)
            ->get();
        $out = [];
        if ($rows !== false) {
            foreach ($rows->getResultArray() as $r) {
                $name = isset($r['name_en']) && is_string($r['name_en']) ? $r['name_en'] : '';
                $qty  = isset($r['quantity']) && is_numeric($r['quantity']) ? (int) $r['quantity'] : 0;
                if ($name !== '') {
                    $out[$name] = $qty;
                }
            }
        }
        return $out;
    }

    /**
     * tasks-row для 'plantCrop' (нужен task_id). null если не найдена.
     *
     * @return array<array-key,mixed>|null
     */
    protected function plantTaskRow(): ?array
    {
        $row = $this->taskModel->where('name', 'plantCrop')->first();
        return is_array($row) ? $row : null;
    }

    /**
     * Активные посадки (status=in_work) персонажа.
     *
     * @return list<array<string,mixed>>
     */
    protected function activePlantings(int $characterId, int $plantTaskId): array
    {
        $rows = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $plantTaskId)
            ->where('status', 'in_work')
            ->orderBy('end_time', 'ASC')
            ->findAll();
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Текстовый блок «что растёт сейчас» (ETA) + занятые слоты — для caption
     * (media-off self-sufficient). Пустой если посадок нет.
     *
     * @param list<array<string,mixed>> $plantings
     */
    protected function activePlantingsText(array $plantings, int $maxSlots, FarmingService $farming): string
    {
        $used = count($plantings);
        if ($used === 0) {
            return "📭 Сейчас ничего не растёт. Слотов: 0/{$maxSlots}.\n";
        }
        $lines = ["🌿 *Растёт сейчас* ({$used}/{$maxSlots}):"];
        $now   = time();
        foreach ($plantings as $p) {
            $cropRaw = '';
            $tsRaw   = $p['task_settings'] ?? null;
            if (is_string($tsRaw) && $tsRaw !== '') {
                $decoded = json_decode($tsRaw, true);
                if (is_array($decoded) && isset($decoded['crop']) && is_string($decoded['crop'])) {
                    $cropRaw = $decoded['crop'];
                }
            }
            $meta    = $farming->cropMeta($cropRaw);
            $cropRu  = $meta !== null ? $meta['crop_ru'] : 'Культура';
            $icon    = $meta !== null ? $meta['icon'] : '🌱';
            $endRaw  = isset($p['end_time']) && is_string($p['end_time']) ? $p['end_time'] : '';
            $etaTxt  = '—';
            if ($endRaw !== '') {
                $endTs = strtotime($endRaw);
                if ($endTs !== false) {
                    $left = $endTs - $now;
                    $etaTxt = $left <= 0 ? 'готово' : ('~' . max(1, (int) ceil($left / 60)) . ' мин');
                }
            }
            $lines[] = "  {$icon} {$cropRu} — {$etaTxt}";
        }
        return implode("\n", $lines) . "\n";
    }
}
