<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SEO-аудит 2026-07-24, финальный проход: последние 4 слишком коротких <title>.
 *
 * После правок постов, рубрик и вики перепрогон 139 страниц показал 0 заголовков длиннее
 * лимита и ровно 4 короче 30 символов: «О проекте Wild World» (20), «Контакты — Wild World»
 * (21) — статические страницы, плюс два поста, где авто-разрез отработал слишком жадно.
 * Заголовки правдивые, но в выдаче занимают треть отведённого места и не несут слов, по
 * которым страницу можно найти.
 *
 * Статическим страницам заводим то же поле `seo_title`, что у постов и рубрик (H1 из `title`
 * не меняется), постам — просто дописываем значение. Идемпотентно: пишем только в пустое
 * поле, а посты правим адресно по текущему значению.
 *
 * WipeManifest: `site_pages` уже классифицирована (KEEP) — контент сайта, не данные игрока;
 * новая колонка player-связи не создаёт.
 */
class AddSeoTitleToSitePagesAndFixShortTitles extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('seo_title', 'site_pages')) {
            $this->forge->addColumn('site_pages', [
                'seo_title' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 190,
                    'null'       => true,
                    'after'      => 'title',
                ],
            ]);
        }

        $pages = [
            'about'    => 'О проекте Wild World: что это за игра и как в неё играть',
            'contacts' => 'Контакты Wild World: как связаться с разработчиком игры',
        ];

        foreach ($pages as $slug => $title) {
            $this->db->table('site_pages')
                ->where('slug', $slug)
                ->groupStart()
                    ->where('seo_title', null)
                    ->orWhere('seo_title', '')
                ->groupEnd()
                ->update(['seo_title' => $title]);
        }

        // Два поста: авто-разрез обрубил заголовок слишком коротко.
        $posts = [
            'obnovlenye-wild-world-nov-e-vozmozhnosty-dlya-baz-y-laherey-devblog-14' => 'Обновление Wild World: новые возможности баз и лагерей',
            'wild-world-oruzhie-tier-list'                                           => 'Оружие в Wild World: тир-лист от биты до легендарного',
        ];

        foreach ($posts as $slug => $title) {
            $this->db->table('site_posts')
                ->where('slug', $slug)
                ->update(['seo_title' => $title]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('seo_title', 'site_pages')) {
            $this->forge->dropColumn('site_pages', 'seo_title');
        }
    }
}
