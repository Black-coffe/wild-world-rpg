<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\SitePageModel;
use App\Models\SitePostModel;
use App\Models\SiteRedirectModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Систематические правки импортированного контента под текущий ЛОР (ADR-052 + ADR-010).
 *
 * Версионируется в git и идемпотентна → прогоняется на каждом окружении ПОСЛЕ
 * `site:import-wp` (контент в БД, правки в коде → переносимы local→testbot→prod).
 * Каждая правка scoped по slug (не глобальный replace — «Разбойники»-фракция ≠
 * «шайка разбойников»-NPC). Повторный прогон безопасен (str_replace отсутствующего = no-op).
 *
 *   php spark site:apply-canon            # применить
 *   php spark site:apply-canon --dry-run  # показать что изменится
 */
class ApplyCanonCorrections extends BaseCommand
{
    protected $group       = 'Site';
    protected $name        = 'site:apply-canon';
    protected $description = 'Систематические правки контента сайта под канон (ADR-010): фракция Разбойники→Партизаны и др.';
    protected $usage       = 'site:apply-canon [--dry-run]';
    protected $options     = ['--dry-run' => 'Показать изменения без записи.'];

    /**
     * Падежи фракции: длинные формы ПЕРВЫМИ (str_replace по порядку), иначе
     * «Разбойники» превратится в «Партизани» при раннем «Разбойник»→«Партизан».
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
     * @var list<array{slug:string,replace:array<string,string>,note:string,content_file?:string}>
     */
    private const CORRECTIONS = [
        [
            'slug'          => 'predstavljaem-chetyre-frakcii-v-nashej-igre-wild-world',
            'replace'       => self::FACTION_RENAME,
            'note'          => 'Фракция «Разбойники» → канон «Партизаны» (ADR-010)',
        ],
        [
            // Полный ревью: «концепт-док» переписан в игрок-обзор под канон — фракции
            // Милитари/Партизаны/Инженеры/Фермеры (были Остин Тех/Наследники Военных/Мутанты),
            // PvP полевые дуэли + 150 раундов + death −0.5%, стратегобъекты, 4 финала.
            'slug'          => 'wild-world-masshtabnaya-tekstovaya-mmorpg-s-elementami-pesochnitsy-i-vyzhivaniya',
            'replace'       => [],
            'content_file'  => 'wild-world-masshtabnaya-tekstovaya-mmorpg-s-elementami-pesochnitsy-i-vyzhivaniya.html',
            'note'          => 'Флагманский обзор переписан под канон (фракции/PvP/финалы)',
        ],
        [
            'slug'          => 'wild-world-novaja-tekstovaja-rpg-v-postapokalipticheskom-settinge',
            'replace'       => [],
            'content_file'  => 'wild-world-novaja-tekstovaja-rpg-v-postapokalipticheskom-settinge.html',
            'note'          => 'Обзорный пост: точные имена фракций + стратегобъекты + https-ссылки',
        ],
        [
            // Полный переписанный пост: формула боя приведена к коду (PvpDamageCalculator/
            // GameBalance) — урон от оружия (не strength×0.5), уклонение ≤75%, Lucky Strike/
            // One-Shot, 150 раундов; смерть −0.5% (не 10%), победитель ~+0.1% (не +2%).
            'slug'          => 'pvp-rezhim-wild-world-mehanika-srazheniy-strahovanie-strategii',
            'replace'       => [],
            'content_file'  => 'pvp-rezhim-wild-world-mehanika-srazheniy-strahovanie-strategii.html',
            'note'          => 'PvP-формула и числа смерти/победы приведены к актуальному коду (ADR-010)',
        ],
        [
            // Концепт-пост: фракции «Остин Тех»/«Наследники Военных» → канон (Милитари/
            // Партизаны/Инженеры/Фермеры); стратегобъекты и 4 финала теперь реальны
            // (V12-V13 + эндгейм), финалы привязаны к фракциям.
            'slug'          => 'wild-world-koncepcija-i-razvitie-igry',
            'replace'       => [],
            'content_file'  => 'wild-world-koncepcija-i-razvitie-igry.html',
            'note'          => 'Названия фракций → канон + привязка финалов к фракциям',
        ],
    ];

    /**
     * Статические страницы (контент — файлы app/Data/site_canon/pages/). Insert-or-update по slug.
     *
     * @var list<array{slug:string,title:string,content_file:string,meta:string}>
     */
    private const PAGES = [
        [
            'slug'         => 'about',
            'title'        => 'О проекте Wild World',
            'content_file' => 'about.html',
            'meta'         => 'Wild World — постапокалиптическая текстовая MMORPG в Telegram: исследование, крафт, база, фракции и PvP.',
        ],
        [
            'slug'         => 'contacts',
            'title'        => 'Контакты',
            'content_file' => 'contacts.html',
            'meta'         => 'Связь с командой Wild World и ссылка на игрового Telegram-бота.',
        ],
    ];

    /**
     * 301-редиректы старых WP-URL (нормализованы: ведущий /, без хвостового /, UTF-8).
     *
     * @var list<array{from:string,to:string,code:int}>
     */
    private const REDIRECTS = [
        ['from' => '/главная', 'to' => '/', 'code' => 301],          // WP homepage → новый корень
        ['from' => '/блог', 'to' => '/devblog', 'code' => 301],       // WP «Блог» → рубрика DevBlog
        ['from' => '/контакты', 'to' => '/contacts', 'code' => 301],  // кириллический slug → латиница
        ['from' => '/dashboard', 'to' => '/admin/dashboard', 'code' => 301],
        ['from' => '/login', 'to' => '/admin/login', 'code' => 301],
        ['from' => '/logout', 'to' => '/admin/logout', 'code' => 301],
    ];

    /** @param array<int|string,string|null> $params */
    public function run(array $params): int
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $model  = new SitePostModel();
        $applied = 0;
        $marked  = 0;

        foreach (self::CORRECTIONS as $c) {
            $post = $model->where('slug', $c['slug'])->first();
            if (! is_array($post)) {
                CLI::write('  ⚠ пост не найден: ' . $c['slug'], 'red');
                continue;
            }
            $id      = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
            $content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';
            $excerpt = is_string($post['excerpt'] ?? null) ? $post['excerpt'] : '';

            $file = $c['content_file'] ?? '';
            if ($file !== '') {
                $path   = APPPATH . 'Data/site_canon/' . $file;
                $loaded = is_file($path) ? file_get_contents($path) : false;
                if ($loaded === false) {
                    CLI::write('  ⚠ файл-оверрайд не найден: ' . $file, 'red');
                    continue;
                }
                $newContent = $loaded;
                $newExcerpt = $excerpt;
            } else {
                $from       = array_keys($c['replace']);
                $to         = array_values($c['replace']);
                $newContent = str_replace($from, $to, $content);
                $newExcerpt = str_replace($from, $to, $excerpt);
            }
            $changed = $newContent !== $content || $newExcerpt !== $excerpt;

            $alreadyReviewed = (int) (is_numeric($post['canon_reviewed'] ?? null) ? $post['canon_reviewed'] : 0) === 1;
            $willMark        = ! $alreadyReviewed; // запись в CORRECTIONS = сверённый пост

            if (! $changed && ! $willMark) {
                CLI::write('  = без изменений: ' . $c['slug'], 'dark_gray');
                continue;
            }

            CLI::write('  • ' . $c['slug'] . ' — ' . $c['note']
                . ($changed ? ' [текст]' : '') . ($willMark ? ' [canon✓]' : ''), 'green');

            if ($dryRun) {
                continue;
            }

            $data = ['id' => $id, 'content_html' => $newContent, 'excerpt' => $newExcerpt !== '' ? $newExcerpt : null, 'canon_reviewed' => 1];
            if ($model->update($id, $data)) {
                if ($changed) {
                    $applied++;
                }
                if ($willMark) {
                    $marked++;
                }
            } else {
                CLI::error('  ✗ update failed: ' . implode('; ', $model->errors()));
            }
        }

        $pagesN = $this->seedPages($dryRun);
        $redirN = $this->seedRedirects($dryRun);

        CLI::write('');
        CLI::write(sprintf(
            'Готово%s. Тексты: %d, сверено: %d, страниц: %d, редиректов: %d.',
            $dryRun ? ' (dry-run)' : '',
            $applied,
            $marked,
            $pagesN,
            $redirN
        ), 'yellow');

        return EXIT_SUCCESS;
    }

    /** Insert-or-update статических страниц из app/Data/site_canon/pages/. */
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
            CLI::write('  • страница /' . $p['slug'], 'green');
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
            $exists = $model->where('from_path', $r['from'])->first() !== null;
            if ($exists) {
                continue;
            }
            CLI::write('  • редирект ' . $r['from'] . ' → ' . $r['to'] . ' (' . $r['code'] . ')', 'green');
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
