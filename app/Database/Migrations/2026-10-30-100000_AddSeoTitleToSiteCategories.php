<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SEO-аудит 2026-07-24: описательный <title> для страниц рубрик.
 *
 * Рубрики отдавали заголовки вида «Крафт — Wild World» (18 символов) и «NPC — Wild World»
 * (16). Обрезки нет, но в выдаче пустует половина отведённого места, а сам термин не несёт
 * слов, по которым страницу можно найти: по данным Search Console показы у этих страниц
 * есть (`/kraft`, `/npc`, `/syre`), а кликов — ноль.
 *
 * Тот же приём, что у постов: отдельное поле `seo_title` только для тега <title>; заголовок
 * H1 на странице остаётся прежним. Пусто → прежнее поведение.
 *
 * WipeManifest: `site_categories` уже классифицирована (KEEP) — контент сайта, не данные
 * игрока; новая колонка player-связи не создаёт.
 */
class AddSeoTitleToSiteCategories extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('seo_title', 'site_categories')) {
            $this->forge->addColumn('site_categories', [
                'seo_title' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 190,
                    'null'       => true,
                    'after'      => 'name',
                ],
            ]);
        }

        $titles = [
            'devblog'      => 'Дев-блог Wild World: как делается текстовая MMORPG',
            'npc'          => 'NPC в Wild World: кто встречается на пустоши',
            'informacija'  => 'Об игре Wild World: правила, механики, обновления',
            'kraft'        => 'Крафт в Wild World: рецепты, верстаки и материалы',
            'letopis-mira' => 'Летопись мира Wild World: история острова',
            'mestnost'     => 'Местность в Wild World: биомы и типы территорий',
            'syre'         => 'Сырьё в Wild World: что добывают в каждом биоме',
        ];

        foreach ($titles as $slug => $title) {
            $this->db->table('site_categories')
                ->where('slug', $slug)
                ->groupStart()
                    ->where('seo_title', null)
                    ->orWhere('seo_title', '')
                ->groupEnd()
                ->update(['seo_title' => $title]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('seo_title', 'site_categories')) {
            $this->forge->dropColumn('site_categories', 'seo_title');
        }
    }
}
