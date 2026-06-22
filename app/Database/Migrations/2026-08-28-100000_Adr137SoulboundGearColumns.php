<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB2 (ADR-137 «Узлы») — soulbound-флаг на экипировке (фундамент трофеев «Метка пустоши»).
 *
 * Добавляет `is_soulbound` в `characters_weapons` и `characters_outfits`. Трофеи узлов
 * (WB9) = soulbound-оружие/броня: «не продаётся / не теряется». В текущем коде экипировка
 * и так не продаётся (нет sell-пути для оружия/брони) и не теряется при смерти (LootProcessor
 * списывает только ресурсы + crafted_items) → колонка пока «спящий» маркер для WB9-выдачи,
 * raid-only бонуса и badge. Если когда-либо появится sell/loss-путь для экипировки — он
 * ОБЯЗАН уважать is_soulbound.
 *
 * ⚠ Урок disable_media/last_planted_crop: ОБА флага добавлены в `$allowedFields` моделей
 * (CharactersWeaponsModel / CharactersOutfitsModel) — иначе CI4 молча отфильтрует при update.
 *
 * WipeManifest (ADR-087): обе таблицы уже классифицированы PLAYER_DATA → новая колонка
 * покрыта классификацией таблицы; createTable не вызывается → coverage-тест зелёный.
 * Идемпотентно (fieldExists).
 */
class Adr137SoulboundGearColumns extends Migration
{
    /** @var list<string> */
    private array $tables = ['characters_weapons', 'characters_outfits'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if ($this->db->fieldExists('is_soulbound', $table)) {
                continue;
            }
            $this->forge->addColumn($table, [
                'is_soulbound' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'equipped',
                ],
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if ($this->db->fieldExists('is_soulbound', $table)) {
                $this->forge->dropColumn($table, 'is_soulbound');
            }
        }
    }
}
