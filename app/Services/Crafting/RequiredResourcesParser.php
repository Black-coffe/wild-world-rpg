<?php

declare(strict_types=1);

namespace App\Services\Crafting;

use App\Models\ResourceModel;

/**
 * S2 (v0.51.184+) — единственная точка парсинга `crafted_items.required_resources`
 * и `buildings.required_resources`. До S2 эти поля хранились в 4 несовместимых
 * форматах исторически; парсер нормализует ВСЕ в каноническую форму
 * `array<string, int>` (`name => quantity`).
 *
 * Поддерживаемые input-форматы:
 *
 *  1. **Free-text v1a** (newline-separated, основной для лекарств/инструментов):
 *     `"Травы - 2 шт.\nКора деревьев - 3 шт.\nВода - 200 мл."`
 *
 *  2. **Free-text v1b** (comma-separated, для англоязычных рецептов оружия/брони):
 *     `"Wool - 4 шт., Leather - 2 шт."`
 *
 *  3. **PHP-array-like v2** (legacy quoted/arrow):
 *     `"'Кактус' => 4,\n'Грибы' => 6,\n'Вода' => 50,"`
 *
 *  4. **JSON object v3** (целевой формат, после миграции S2):
 *     `{"Травы": 2, "Кора деревьев": 3}`
 *
 *  5. **JSON array v3-bis** (legacy формат с resource_id, конвертируется по lookup'у):
 *     `[{"resource_id": 1, "quantity": 2}, {"resource_id": 3, "quantity": 1}]`
 *
 * Каноническая форма (output): `array<string, int>` где key = имя ресурса/крафта,
 * value = quantity. Пустой `[]` для пустого/null/невалидного входа.
 *
 * Используется:
 *  - {@see \App\Services\CraftTree\CraftTreeService} для admin visualization
 *  - migration `2026-05-18-100000_NormalizeCraftedItemsRequiredResourcesToJson` для
 *    разовой нормализации БД.
 */
final class RequiredResourcesParser
{
    /** @var array<int, string>|null Memoized resource_id → name lookup для v3-bis. */
    private ?array $resourceIdToName = null;

    public function __construct(private ?ResourceModel $resourceModel = null)
    {
    }

    /**
     * Распарсить любой из 5 форматов в каноническую форму.
     *
     * @return array<string, int>
     */
    public function parse(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '[]' || $raw === '{}') {
            return [];
        }

        // 1. JSON path (v3 или v3-bis)
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->normalizeJsonShape($decoded);
        }

        // 2. Text path: split by \n или comma-not-in-parens
        $lines = preg_split('/\r?\n|,\s*(?![^()]*\))/u', $raw) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // v2 PHP-array-like: 'Имя' => 4 или "Имя" => 4
            if (preg_match("/^['\"]?(.+?)['\"]?\s*=>\s*(\d+)/u", $line, $m)) {
                $name = trim($m[1]);
                $qty  = (int) $m[2];
                if ($name !== '' && $qty > 0) {
                    $out[$name] = $qty;
                }
                continue;
            }

            // v1 free-text: "Имя - N <unit>." или "Имя — N <unit>." или "Имя – N"
            if (preg_match('/^(.+?)\s*[-–—]\s*(\d+)\s*(\p{L}*)\.?\s*$/u', $line, $m)) {
                $name = trim($m[1]);
                $qty  = (int) $m[2];
                if ($name !== '' && $qty > 0) {
                    $out[$name] = $qty;
                }
                continue;
            }

            // v1d fallback: "Имя N" (без тире)
            if (preg_match('/^(.+?)\s+(\d+)\s*(\p{L}*)\.?\s*$/u', $line, $m)) {
                $name = trim($m[1]);
                $qty  = (int) $m[2];
                if ($name !== '' && $qty > 0) {
                    $out[$name] = $qty;
                }
            }
        }

        return $out;
    }

    /**
     * Закодировать каноническую форму в JSON-строку для сохранения в БД.
     *
     * @param array<string, int> $resources
     */
    public function encode(array $resources): string
    {
        if (empty($resources)) {
            return '{}';
        }
        $json = json_encode($resources, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }

    /**
     * Распознать форму JSON и привести к {"name": qty}.
     *
     * @param array<int|string, mixed> $decoded
     * @return array<string, int>
     */
    private function normalizeJsonShape(array $decoded): array
    {
        // v3 canonical: {"Имя": int}
        // v3-bis: [{"resource_id": int, "quantity": int}, ...]
        if ($this->isResourceIdQuantityList($decoded)) {
            return $this->convertResourceIdList($decoded);
        }

        // Canonical {"name": qty} — sanitize
        $out = [];
        foreach ($decoded as $name => $qty) {
            $nameStr = trim((string) $name);
            if ($nameStr === '') {
                continue;
            }
            if (is_numeric($qty)) {
                $qInt = (int) $qty;
                if ($qInt > 0) {
                    $out[$nameStr] = $qInt;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $decoded
     */
    private function isResourceIdQuantityList(array $decoded): bool
    {
        if (! isset($decoded[0]) || ! is_array($decoded[0])) {
            return false;
        }
        return array_key_exists('resource_id', $decoded[0])
            && array_key_exists('quantity', $decoded[0]);
    }

    /**
     * Конвертировать `[{"resource_id": 1, "quantity": 2}, ...]` → `{"Древесина": 2, ...}`
     * через lookup в `resources` table.
     *
     * @param array<int|string, mixed> $decoded
     * @return array<string, int>
     */
    private function convertResourceIdList(array $decoded): array
    {
        $this->primeResourceLookup();
        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rIdRaw = $item['resource_id'] ?? null;
            $qtyRaw = $item['quantity'] ?? null;
            if (! is_numeric($rIdRaw) || ! is_numeric($qtyRaw)) {
                continue;
            }
            $rId = (int) $rIdRaw;
            $qty = (int) $qtyRaw;
            if ($rId <= 0 || $qty <= 0) {
                continue;
            }
            $name = $this->resourceIdToName[$rId] ?? "resource_id={$rId}";
            $out[$name] = $qty;
        }
        return $out;
    }

    private function primeResourceLookup(): void
    {
        if ($this->resourceIdToName !== null) {
            return;
        }
        $this->resourceIdToName = [];
        $model = $this->resourceModel ?? new ResourceModel();
        // ResourceModel::findAll() возвращает list<ResourceEntity> (F1.4) → toArray() напрямую.
        foreach ($model->findAll() as $entity) {
            $arr     = $entity->toArray();
            $idRaw   = $arr['id'] ?? null;
            $nameRaw = $arr['name'] ?? null;
            if (is_numeric($idRaw) && is_string($nameRaw) && $nameRaw !== '') {
                $this->resourceIdToName[(int) $idRaw] = $nameRaw;
            }
        }
    }
}
