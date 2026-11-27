<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H6.3 (spec `docs/specs/h6-relics-1127/`) — фэнтези-реликвии становятся постапоком, без изъятия у держателей.
 *
 * Реликвии (resources 69/70/71) переименовываются на месте: строка ресурса та же, её id тот же, поэтому
 * `character_resources` (связь по `id_resources`), лут боссов (`loot_table_items.item_id`) и пожертвования в
 * коллекции (`character_collection_slots` по id слота) не трогаются. Prod SELECT 2026-11-27: 69 — 12 держателей,
 * 70 — 12, 71 — 10.
 *
 * `collection_slots.resource_name` связан с ресурсом строкой имени, а не id, и в другой коллации (general_ci против
 * unicode_ci у `resources.name`), поэтому слоты обновляются той же миграцией по буквальному старому значению, без
 * join. Иначе слоты «Сокровищ глубин» перестали бы видеть запас держателя.
 *
 * Пост сайта о пещерах: канон-файл `app/Data/site_canon/<slug>.html` применяется только ручной командой
 * `php spark site:apply-canon`, поэтому миграция правит `site_posts.content_html` этого slug теми же заменами,
 * что внесены в файл, — сайт меняется с деплоем.
 *
 * Идемпотентно: ресурс ищется по старому или новому имени (UNIQUE name) и получает новые имя и описание; слоты и
 * пост меняются заменой старой строки, которой после первого прогона нет. down() возвращает старые имена и
 * описания (копия локальной базы со снимка testbot 2026-11-27: классы миграций не автозагружаются). Схема не
 * меняется — WipeManifest не затронут.
 */
class H6RenameRelicsAndBracketWeapons extends Migration
{
    /**
     * [старое имя, новое имя, старое описание, новое описание]. Новые описания не обещают эффектов: у реликвий их
     * нет — продажа банку, коллекция «Сокровища глубин» и лут боссов.
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    public const RELICS = [
        [
            'Сердце Дракона',
            'Изотопное ядро „Дракон-4“',
            'Огненный камень, пульсирующий энергией. Говорят, что он обладает огромной силой. Завладеть таким считается настоящим событием и достижением.',
            'Уцелевший микрореактор бункерного комплекса, заводской индекс «Дракон-4». Корпус до сих пор тёплый, а счётчик рядом с ним не умолкает. Найти такой — событие для любого ветерана.',
        ],
        [
            'Древняя Книга',
            'Архивный носитель Предтеч',
            'Потрепанная книга, написанная на неизвестном языке. Говорят, что она содержит ценные знания.',
            'Защищённый полевой журнал Предтеч: кассета микроплёнки в герметичном футляре, записанная до Коллапса. Прочесть её на острове нечем, но цену ей знает каждый скупщик.',
        ],
        [
            'Корона подземного короля',
            'Релейный венец Глубины',
            'Корона, принадлежавшая легендарному подземному королю. Дает +10 ко всем характеристикам персонажа раз в 10 дней!',
            'Командный обруч-транспондер начальника подземного узла «Глубина». Реле давно молчат, но этот трофей — знак того, что ты спустился на самое дно.',
        ],
    ];

    public const SITE_SLUG = 'syre-biom-peshhery-i-podzemelja-vot-spisok-syrja-kotoroe-mozhet-byt-najdeno-i-ispolzovano';

    /**
     * Замены в посте сайта: старый фрагмент → новый. Описания идут до имён (в них имён нет, но порядок держит
     * down() симметричным). Те же замены внесены в канон-файл поста.
     *
     * @var array<string, string>
     */
    public const SITE_REPLACE = [
        '<p>Легендарная находка глубочайших залов, открывающаяся только героям высокого уровня.</p>'
            => '<p>Уцелевший микрореактор бункерного комплекса с заводским индексом «Дракон-4» — находка глубочайших залов для выживших высокого уровня.</p>',
        '<p>Редчайший артефакт знаний для эндгейм-контента.</p>'
            => '<p>Защищённый полевой журнал Предтеч на микроплёнке, записанный до Коллапса, — редчайшая находка для эндгейма.</p>',
        '<p>Венец подземелий — самая престижная и труднодоступная находка на острове.</p>'
            => '<p>Командный обруч-транспондер начальника подземного узла — самая престижная и труднодоступная находка на острове.</p>',
        'Сердце Дракона'           => 'Изотопное ядро „Дракон-4“',
        'Древняя Книга'            => 'Архивный носитель Предтеч',
        'Корона подземного короля' => 'Релейный венец Глубины',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::RELICS as [$oldName, $newName, , $newDescription]) {
            $this->db->table('resources')
                ->whereIn('name', [$oldName, $newName])
                ->update(['name' => $newName, 'description' => $newDescription, 'updated_at' => $now]);

            $this->db->table('collection_slots')
                ->where('resource_name', $oldName)
                ->update(['resource_name' => $newName]);
        }

        $this->replaceInSitePost(self::SITE_REPLACE, $now);
    }

    public function down(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::RELICS as [$oldName, $newName, $oldDescription]) {
            $this->db->table('resources')
                ->where('name', $newName)
                ->update(['name' => $oldName, 'description' => $oldDescription, 'updated_at' => $now]);

            $this->db->table('collection_slots')
                ->where('resource_name', $newName)
                ->update(['resource_name' => $oldName]);
        }

        $this->replaceInSitePost(array_flip(self::SITE_REPLACE), $now);
    }

    /**
     * @param array<string, string> $replace
     */
    private function replaceInSitePost(array $replace, string $now): void
    {
        if (! $this->db->tableExists('site_posts')) {
            return;
        }

        $post = $this->db->table('site_posts')
            ->select('id, content_html')
            ->where('slug', self::SITE_SLUG)
            ->get()
            ->getRowArray();
        if (! is_array($post) || ! is_string($post['content_html'] ?? null)) {
            return;
        }

        $content = str_replace(array_keys($replace), array_values($replace), $post['content_html']);
        if ($content === $post['content_html']) {
            return;
        }

        $this->db->table('site_posts')
            ->where('id', $post['id'])
            ->update(['content_html' => $content, 'updated_at' => $now]);
    }
}
