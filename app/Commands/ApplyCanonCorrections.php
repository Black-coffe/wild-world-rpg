<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\SitePageModel;
use App\Models\SitePostModel;
use App\Models\SiteRedirectModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Ревизия импортированного контента под текущий ЛОР (ADR-052 + ADR-010).
 *
 * Версионируется в git и идемпотентна → прогоняется на каждом окружении ПОСЛЕ
 * `site:import-wp` (контент в БД, правки в коде → переносимы local→testbot→prod).
 *
 * Три механизма:
 *   1. File-override (АВТО): любой `app/Data/site_canon/<slug>.html` → полностью
 *      заменяет content_html поста с этим slug + canon_reviewed=1. Просто кладёшь
 *      файл — он применяется (масштабируемо для марафона ревизии всех постов).
 *   2. Replace-правки: точечные str_replace по slug (напр. фракция Разбойники→Партизаны),
 *      где полный rewrite не нужен.
 *   3. Seed: статические страницы (app/Data/site_canon/pages/) + 301-редиректы.
 *
 *   php spark site:apply-canon            # применить
 *   php spark site:apply-canon --dry-run  # показать план
 */
class ApplyCanonCorrections extends BaseCommand
{
    protected $group       = 'Site';
    protected $name        = 'site:apply-canon';
    protected $description = 'Ревизия контента сайта под канон: file-override постов + точечные правки + статические страницы/редиректы.';
    protected $usage       = 'site:apply-canon [--dry-run]';
    protected $options     = ['--dry-run' => 'Показать изменения без записи.'];

    /**
     * Падежи фракции: длинные формы ПЕРВЫМИ (str_replace по порядку).
     */
    private const FACTION_RENAME = [
        'Разбойниками' => 'Партизанами',
        'Разбойниках'  => 'Партизанах',
        'Разбойникам'  => 'Партизанам',
        'Разбойников'  => 'Партизан',
        'Разбойники'   => 'Партизаны',
        'Разбойника'   => 'Партизана',
        'Разбойнике'   => 'Партизане',
        'Разбойнику'   => 'Партизану',
        'Разбойник'    => 'Партизан',
    ];

    /**
     * Точечные str_replace-правки (где полный file-override не нужен).
     *
     * @var list<array{slug:string,replace:array<string,string>,note:string}>
     */
    private const REPLACE_CORRECTIONS = [
        [
            'slug'    => 'predstavljaem-chetyre-frakcii-v-nashej-igre-wild-world',
            'replace' => self::FACTION_RENAME,
            'note'    => 'Фракция «Разбойники» → канон «Партизаны» (ADR-010)',
        ],
        [
            // Genre-обзор без дрейфа, но без упоминания игры — добавляем Wild World CTA
            // (конвертация топ-SEO трафика «текстовые мморпг/игры»). + canon_reviewed.
            'slug'    => 'tekstovye-igry-proshloe-nastojashhee-i-budushhee-zhanra-v-2024-2025-godah',
            'replace' => [
                'чем наше воображение.</p>' => 'чем наше воображение.</p>' . "\n"
                    . '<hr />' . "\n"
                    . '<h2>Wild World — текстовая MMORPG, в которую можно играть прямо сейчас</h2>' . "\n"
                    . '<p>Захотелось попробовать жанр на практике? <strong>Wild World</strong> — масштабная постапокалиптическая текстовая MMORPG прямо в Telegram: огромный остров, 9 биомов, крафт, базы, фракции и PvP, без установки и с телефона. Запусти бота <a href="https://t.me/wildworldrpg_bot" target="_blank" rel="noopener"><strong>@wildworldrpg_bot</strong></a> и начни своё выживание.</p>',
            ],
            'note'    => 'Genre-пост: добавлен Wild World CTA',
        ],
        [
            // Genre-обзор text-RPG: уже точен и с CTA на бота → только пометка сверки.
            'slug'    => 'tekstovye-rpg-v-2024-godu-vozrozhdenie-klassiki-ili-novyj-vitok-razvitija',
            'replace' => [],
            'note'    => 'Genre-пост точен (CTA уже есть) — пометка canon_reviewed',
        ],
    ];

    /**
     * Статические страницы (контент — app/Data/site_canon/pages/). Insert-or-update по slug.
     *
     * @var list<array{slug:string,title:string,content_file:string,meta:string}>
     */
    private const PAGES = [
        ['slug' => 'about', 'title' => 'О проекте Wild World', 'content_file' => 'about.html', 'meta' => 'Wild World — постапокалиптическая текстовая MMORPG в Telegram: исследование, крафт, база, фракции и PvP.'],
        ['slug' => 'contacts', 'title' => 'Контакты', 'content_file' => 'contacts.html', 'meta' => 'Связь с командой Wild World и ссылка на игрового Telegram-бота.'],
    ];

    /**
     * 301-редиректы старых WP-URL (нормализованы: ведущий /, без хвостового /, UTF-8).
     *
     * @var list<array{from:string,to:string,code:int}>
     */
    private const REDIRECTS = [
        ['from' => '/главная', 'to' => '/', 'code' => 301],
        ['from' => '/блог', 'to' => '/devblog', 'code' => 301],
        ['from' => '/контакты', 'to' => '/contacts', 'code' => 301],
        ['from' => '/dashboard', 'to' => '/admin/dashboard', 'code' => 301],
        ['from' => '/login', 'to' => '/admin/login', 'code' => 301],
        ['from' => '/logout', 'to' => '/admin/logout', 'code' => 301],
    ];

    /** @param array<int|string,string|null> $params */
    public function run(array $params): int
    {
        $dryRun = (bool) CLI::getOption('dry-run');

        $files    = $this->applyFileOverrides($dryRun);
        $replaced = $this->applyReplaceCorrections($dryRun);
        $pagesN   = $this->seedPages($dryRun);
        $redirN   = $this->seedRedirects($dryRun);

        CLI::write('');
        CLI::write(sprintf(
            'Готово%s. Постов (file): %d, постов (replace): %d, страниц: %d, редиректов: %d.',
            $dryRun ? ' (dry-run)' : '',
            $files,
            $replaced,
            $pagesN,
            $redirN
        ), 'yellow');

        return EXIT_SUCCESS;
    }

    /**
     * АВТО file-override: app/Data/site_canon/*.html → пост с slug=basename + canon_reviewed=1.
     */
    private function applyFileOverrides(bool $dryRun): int
    {
        $dir   = APPPATH . 'Data/site_canon/';
        $found = glob($dir . '*.html');
        if ($found === false) {
            return 0;
        }

        $model = new SitePostModel();
        $n     = 0;
        foreach ($found as $path) {
            $slug    = basename($path, '.html');
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $post = $model->where('slug', $slug)->first();
            if (! is_array($post)) {
                CLI::write('  ⚠ file-override: нет поста для ' . $slug, 'red');
                continue;
            }
            $id      = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
            $current = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';
            $reviewed = (int) (is_numeric($post['canon_reviewed'] ?? null) ? $post['canon_reviewed'] : 0) === 1;

            if ($content === $current && $reviewed) {
                continue; // уже применено
            }

            CLI::write('  • file: ' . $slug, 'green');
            if ($dryRun) {
                $n++;
                continue;
            }
            if ($model->update($id, ['id' => $id, 'content_html' => $content, 'canon_reviewed' => 1])) {
                $n++;
            } else {
                CLI::error('  ✗ update failed (' . $slug . '): ' . implode('; ', $model->errors()));
            }
        }

        return $n;
    }

    /** Точечные str_replace-правки. */
    private function applyReplaceCorrections(bool $dryRun): int
    {
        $model = new SitePostModel();
        $n     = 0;
        foreach (self::REPLACE_CORRECTIONS as $c) {
            $post = $model->where('slug', $c['slug'])->first();
            if (! is_array($post)) {
                continue;
            }
            $id      = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
            $content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';
            $excerpt = is_string($post['excerpt'] ?? null) ? $post['excerpt'] : '';
            $reviewed = (int) (is_numeric($post['canon_reviewed'] ?? null) ? $post['canon_reviewed'] : 0) === 1;

            $newContent = str_replace(array_keys($c['replace']), array_values($c['replace']), $content);
            $newExcerpt = str_replace(array_keys($c['replace']), array_values($c['replace']), $excerpt);

            if ($newContent === $content && $newExcerpt === $excerpt && $reviewed) {
                continue;
            }

            CLI::write('  • replace: ' . $c['slug'] . ' — ' . $c['note'], 'green');
            if ($dryRun) {
                $n++;
                continue;
            }
            if ($model->update($id, ['id' => $id, 'content_html' => $newContent, 'excerpt' => $newExcerpt !== '' ? $newExcerpt : null, 'canon_reviewed' => 1])) {
                $n++;
            }
        }

        return $n;
    }

    /** Insert-or-update статических страниц. */
    private function seedPages(bool $dryRun): int
    {
        $model = new SitePageModel();
        $n     = 0;
        foreach (self::PAGES as $p) {
            $path   = APPPATH . 'Data/site_canon/pages/' . $p['content_file'];
            $loaded = is_file($path) ? file_get_contents($path) : false;
            if ($loaded === false) {
                CLI::write('  ⚠ страница: файл не найден ' . $p['content_file'], 'red');
                continue;
            }
            if ($dryRun) {
                $n++;
                continue;
            }
            $existing = $model->where('slug', $p['slug'])->first();
            $data     = [
                'slug'             => $p['slug'],
                'title'            => $p['title'],
                'content_html'     => $loaded,
                'meta_description' => $p['meta'],
                'status'           => 'published',
            ];
            if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
                $data['id'] = (int) $existing['id'];
                $model->update((int) $existing['id'], $data);
            } else {
                $model->insert($data);
            }
            $n++;
        }

        return $n;
    }

    /** Insert редиректов (по from_path; существующие не трогаем). */
    private function seedRedirects(bool $dryRun): int
    {
        $model = new SiteRedirectModel();
        $n     = 0;
        foreach (self::REDIRECTS as $r) {
            if ($model->where('from_path', $r['from'])->first() !== null) {
                continue;
            }
            if ($dryRun) {
                $n++;
                continue;
            }
            $model->insert(['from_path' => $r['from'], 'to_path' => $r['to'], 'code' => $r['code']]);
            $n++;
        }

        return $n;
    }
}
