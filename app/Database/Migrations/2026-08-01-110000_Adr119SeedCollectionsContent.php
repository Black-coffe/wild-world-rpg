<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-119 (E19) — контент Ф1: 2 коллекции + слоты + 2 титула-награды.
 *
 * Сквозная нить ADR-113: реликвии элиток (E15) + ресурсы севера (E16) + редкие event-находки (S10).
 * Слоты ссылаются на resources.name (списание при сдаче — CharacterResourceModel::deductResourceByName).
 * Титулы-награды source_type='collection' (НЕ авто-выдаются TitleCheckCron; выдаёт CollectionService
 * при завершении). Idempotent: collection_key/title_key UNIQUE; слоты — только если их ещё нет.
 * collections/collection_slots = KEEP, titles = KEEP.
 */
class Adr119SeedCollectionsContent extends Migration
{
    public function up(): void
    {
        // 1) Титулы-награды (source_type='collection', source_ref=collection_key).
        $titles = [
            ['title_key' => 'keeper_of_relics', 'name' => 'Хранитель реликвий', 'description' => 'Собрал полный Реликварий Предтеч — реликвии исчезнувшей цивилизации.', 'source_type' => 'collection', 'source_ref' => 'forerunner_reliquary', 'icon' => '🏺', 'sort_order' => 60, 'enabled' => 1],
            ['title_key' => 'treasure_hunter', 'name' => 'Кладоискатель', 'description' => 'Собрал коллекцию «Сокровища глубин» — редчайшие находки пустоши.', 'source_type' => 'collection', 'source_ref' => 'deep_treasures', 'icon' => '💎', 'sort_order' => 61, 'enabled' => 1],
        ];
        foreach ($titles as $t) {
            if ($this->db->table('titles')->where('title_key', $t['title_key'])->countAllResults() === 0) {
                $this->db->table('titles')->insert($t);
            }
        }

        // 2) Коллекции.
        $collections = [
            [
                'collection_key'   => 'forerunner_reliquary',
                'name'             => 'Реликварий Предтеч',
                'description'      => 'Артефакты цивилизации, что правила пустошью до Тишины. Музей вспомнит их за тебя.',
                'theme'            => 'Предтечи',
                'min_level'        => 50,
                'reward_title_key' => 'keeper_of_relics',
                'icon'             => '🏺',
                'sort_order'       => 10,
                'enabled'          => 1,
                'slots'            => [
                    ['resource_name' => 'Древние реликвии', 'required_qty' => 3, 'sort_order' => 1],
                    ['resource_name' => 'Пепел Предтеч',     'required_qty' => 5, 'sort_order' => 2],
                    ['resource_name' => 'Кристалл Разлома',  'required_qty' => 3, 'sort_order' => 3],
                ],
            ],
            [
                'collection_key'   => 'deep_treasures',
                'name'             => 'Сокровища глубин',
                'description'      => 'То, что находят раз в жизни — и обычно теряют. Здесь они останутся навсегда.',
                'theme'            => 'Легендарные находки',
                'min_level'        => 50,
                'reward_title_key' => 'treasure_hunter',
                'icon'             => '💎',
                'sort_order'       => 20,
                'enabled'          => 1,
                'slots'            => [
                    ['resource_name' => 'Сердце Дракона',           'required_qty' => 1, 'sort_order' => 1],
                    ['resource_name' => 'Древняя Книга',            'required_qty' => 1, 'sort_order' => 2],
                    ['resource_name' => 'Корона подземного короля', 'required_qty' => 1, 'sort_order' => 3],
                ],
            ],
        ];

        foreach ($collections as $c) {
            $slots = $c['slots'];
            unset($c['slots']);

            $existing = $this->db->table('collections')->select('id')->where('collection_key', $c['collection_key'])->get()->getRowArray();
            if ($existing === null) {
                $this->db->table('collections')->insert($c);
                $collectionId = (int) $this->db->insertID();
            } else {
                $collectionId = (int) $existing['id'];
            }

            // Слоты — только если у коллекции их ещё нет (стабильность slot_id для FK character_collection_slots).
            $slotCount = $this->db->table('collection_slots')->where('collection_id', $collectionId)->countAllResults();
            if ($slotCount === 0) {
                foreach ($slots as $s) {
                    $s['collection_id'] = $collectionId;
                    $this->db->table('collection_slots')->insert($s);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = ['forerunner_reliquary', 'deep_treasures'];
        $ids  = [];
        foreach ($this->db->table('collections')->select('id')->whereIn('collection_key', $keys)->get()->getResultArray() as $r) {
            $ids[] = (int) $r['id'];
        }
        if ($ids !== []) {
            $this->db->table('collection_slots')->whereIn('collection_id', $ids)->delete();
        }
        $this->db->table('collections')->whereIn('collection_key', $keys)->delete();
        $this->db->table('titles')->whereIn('title_key', ['keeper_of_relics', 'treasure_hunter'])->delete();
    }
}
