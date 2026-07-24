<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SiteCategoryModel;
use App\Models\SitePageModel;
use App\Models\SitePostCategoryModel;
use App\Models\SitePostModel;
use App\Models\SiteRedirectModel;
use App\Services\WikiContentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Публичный сайт wildworld.fun в CI4-экосистеме (ADR-052): главная, лента/категории,
 * одиночный пост, статические страницы. Catch-all `Front::resolve` разводит
 * корневой slug → пост / страница / 404 (404 → Errors::notFound → site_redirects).
 */
class Front extends BaseController
{
    public function home(): string|ResponseInterface
    {
        // Старые кириллические WP-URL (/блог, /контакты, /главная) CI4 ошибочно сводит к
        // home: percent-encoded не-ASCII одиночный сегмент теряется при разборе URI (nginx).
        // Ловим по СЫРОМУ $_SERVER['REQUEST_URI'] (CI4-getPath его теряет) + хардкод-карта.
        $rawUri   = is_string($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : '';
        $pathPart = parse_url($rawUri, PHP_URL_PATH);
        $decoded  = '/' . trim(rawurldecode(is_string($pathPart) ? $pathPart : ''), '/');
        $hard     = ['/главная' => '/', '/блог' => '/devblog', '/контакты' => '/contacts'];
        if ($decoded !== '/' && isset($hard[$decoded])) {
            return redirect()->to($hard[$decoded], 301);
        }
        if ($decoded !== '/') {
            $redirectModel = new SiteRedirectModel();
            $redirect      = $redirectModel->matchPath($decoded);
            if ($redirect !== null) {
                $redirectModel->recordHit($redirect['id']);

                return redirect()->to($redirect['to_path'], $redirect['code'] === 302 ? 302 : 301);
            }
        }

        $posts = new SitePostModel();
        $wiki  = new WikiContentService();

        return view('site/home', [
            'latest'       => $posts->publishedFeed(6),
            'wikiSections' => $wiki->sections(),
            'factions'     => $wiki->factions(),
            'meta'         => $this->meta(
                'Wild World — постапокалиптическая текстовая MMORPG в Telegram',
                'Выживай в огромном открытом мире: исследуй земли, добывай ресурсы, крафти снаряжение, строй базу, вступай во фракции и сражайся — прямо в Telegram.',
                rtrim(base_url(), '/'),
                null,
                'index,follow',
                'website',
                [
                    'keywords' => 'Wild World, текстовая MMORPG, игра в Telegram, постапокалипсис, выживание, крафт, ролевая игра, RPG, песочница, PvP',
                    'jsonld'   => [$this->videoGameSchema()],
                ],
            ),
        ]);
    }

    /**
     * Catch-all корневого slug: опубликованный пост → страница → 404.
     */
    public function resolve(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));

        $post = (new SitePostModel())->where('slug', $slug)->where('status', 'published')->first();
        if (is_array($post)) {
            return $this->renderPost($post);
        }

        $page = (new SitePageModel())->where('slug', $slug)->where('status', 'published')->first();
        if (is_array($page)) {
            return $this->renderPage($page);
        }

        throw PageNotFoundException::forPageNotFound('Нет такой страницы: ' . $slug);
    }

    public function category(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));
        $cat  = (new SiteCategoryModel())->where('slug', $slug)->first();
        if (! is_array($cat)) {
            throw PageNotFoundException::forPageNotFound('Нет такой категории: ' . $slug);
        }

        $catId = is_numeric($cat['id'] ?? null) ? (int) $cat['id'] : 0;
        $name  = is_string($cat['name'] ?? null) ? $cat['name'] : $slug;
        $desc  = is_string($cat['description'] ?? null) ? $cat['description'] : '';

        $canonical = rtrim(base_url($slug), '/');
        $metaDesc  = $desc !== ''
            ? $this->metaDescription($desc)
            : ('Материалы рубрики «' . $name . '» — Wild World, постапокалиптическая текстовая MMORPG в Telegram.');

        return view('site/posts', [
            'heading'     => $name,
            'lead'        => $desc,
            'breadcrumbs' => [$this->homeCrumb(), ['name' => $name, 'url' => $canonical]],
            'posts'       => (new SitePostModel())->publishedInCategory($catId),
            'meta'        => $this->meta(
                $name . ' — Wild World',
                $metaDesc,
                $canonical,
                null,
                'index,follow',
                'website',
                [
                    'keywords'    => $name . ', Wild World, вики, гайд, текстовая MMORPG, Telegram',
                    'breadcrumbs' => [$this->homeCrumb(), ['name' => $name, 'url' => $canonical]],
                    'jsonld'      => [[
                        '@type'       => 'CollectionPage',
                        'name'        => $name,
                        'url'         => $canonical,
                        'description' => $metaDesc,
                        'inLanguage'  => 'ru',
                        'isPartOf'    => ['@id' => rtrim(base_url(), '/') . '/#website'],
                    ]],
                ],
            ),
        ]);
    }

    public function page(string $slug): string
    {
        $page = (new SitePageModel())->where('slug', mb_strtolower($slug))->where('status', 'published')->first();
        if (! is_array($page)) {
            throw PageNotFoundException::forPageNotFound('Нет такой страницы: ' . $slug);
        }

        return $this->renderPage($page);
    }

    /**
     * Admin-only превью поста ЛЮБОГО статуса (включая draft) для UI/UX-ревью
     * редколлегии перед публикацией. За login-фильтром (admin-группа в Routes),
     * noindex,nofollow — превью не для поисковиков. Рендерит тем же site/post-view.
     */
    public function preview(int $id): string|ResponseInterface
    {
        $post = (new SitePostModel())->find($id);
        if (! is_array($post)) {
            throw PageNotFoundException::forPageNotFound('Нет такого поста для превью: ' . $id);
        }

        return $this->renderPost($post, 'noindex,nofollow');
    }

    /**
     * @param array<array-key,mixed> $post
     */
    private function renderPost(array $post, string $robots = 'index,follow'): string
    {
        $id       = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
        $slug     = is_string($post['slug'] ?? null) ? $post['slug'] : '';
        $title    = is_string($post['title'] ?? null) ? $post['title'] : '';
        $metaDesc = is_string($post['meta_description'] ?? null) ? $post['meta_description'] : '';
        $image    = is_string($post['featured_image'] ?? null) && $post['featured_image'] !== '' ? $post['featured_image'] : null;

        $catIds     = (new SitePostCategoryModel())->categoryIdsForPost($id);
        $categories = $catIds !== [] ? (new SiteCategoryModel())->whereIn('id', $catIds)->orderBy('sort')->findAll() : [];

        $canonical = rtrim(base_url($slug), '/');
        $imageUrl  = $image !== null ? rtrim(base_url($image), '/') : null;
        $descr     = $metaDesc !== ''
            ? $this->metaDescription($metaDesc)
            : ($title . ' — Wild World, постапокалиптическая текстовая MMORPG в Telegram.');

        // ISO-даты для article-меты и BlogPosting.
        $pubRaw = is_string($post['published_at'] ?? null) ? $post['published_at'] : '';
        $modRaw = is_string($post['updated_at'] ?? null) && $post['updated_at'] !== '' ? $post['updated_at'] : $pubRaw;
        $pubISO = $pubRaw !== '' ? date('c', (int) strtotime($pubRaw)) : '';
        $modISO = $modRaw !== '' ? date('c', (int) strtotime($modRaw)) : $pubISO;

        // Первая категория → раздел + средняя крошка (иначе «Девблог»).
        $firstCat = null;
        foreach ($categories as $c) {
            if (is_array($c) && is_string($c['name'] ?? null) && is_string($c['slug'] ?? null)) {
                $firstCat = ['name' => $c['name'], 'url' => rtrim(base_url($c['slug']), '/')];
                break;
            }
        }
        $midCrumb = $firstCat ?? ['name' => 'Девблог', 'url' => rtrim(base_url('devblog'), '/')];
        $section  = $firstCat['name'] ?? 'Девблог';

        $breadcrumbs = [$this->homeCrumb(), $midCrumb, ['name' => $title, 'url' => $canonical]];

        $site = rtrim(base_url(), '/');

        return view('site/post', [
            'post'       => $post,
            'categories' => $categories,
            'breadcrumbs' => $breadcrumbs,
            'meta'       => $this->meta(
                $title . ' — Wild World',
                $descr,
                $canonical,
                $imageUrl,
                $robots,
                'article',
                [
                    'keywords'      => $title . ', ' . $section . ', Wild World, текстовая MMORPG, Telegram',
                    'publishedTime' => $pubISO,
                    'modifiedTime'  => $modISO,
                    'section'       => $section,
                    'breadcrumbs'   => $breadcrumbs,
                    'jsonld'        => [[
                        '@type'            => 'BlogPosting',
                        'headline'         => mb_substr($title, 0, 110),
                        'datePublished'    => $pubISO,
                        'dateModified'     => $modISO,
                        'image'            => [$imageUrl ?? base_url('og-default.jpg')],
                        'mainEntityOfPage' => $canonical,
                        'url'              => $canonical,
                        'inLanguage'       => 'ru',
                        'description'      => $descr,
                        'articleSection'   => $section,
                        'author'           => ['@type' => 'Organization', 'name' => 'Wild World', 'url' => $site . '/'],
                        'publisher'        => ['@id' => $site . '/#organization'],
                    ]],
                ],
            ),
        ]);
    }

    /**
     * @param array<array-key,mixed> $page
     */
    private function renderPage(array $page): string
    {
        $slug     = is_string($page['slug'] ?? null) ? $page['slug'] : '';
        $title    = is_string($page['title'] ?? null) ? $page['title'] : '';
        $metaDesc = is_string($page['meta_description'] ?? null) ? $page['meta_description'] : '';

        $canonical   = rtrim(base_url($slug), '/');
        $breadcrumbs = [$this->homeCrumb(), ['name' => $title, 'url' => $canonical]];

        return view('site/page', [
            'page'        => $page,
            'breadcrumbs' => $breadcrumbs,
            'meta'        => $this->meta(
                $title . ' — Wild World',
                $metaDesc !== '' ? $this->metaDescription($metaDesc) : $title . ' — Wild World.',
                $canonical,
                null,
                'index,follow',
                'website',
                ['breadcrumbs' => $breadcrumbs],
            ),
        ]);
    }

    /**
     * Описание для сниппета выдачи: обрезка ПО ГРАНИЦЕ СЛОВА с многоточием.
     *
     * SEO-аудит 2026-07-24 нашёл, что раньше здесь стоял голый `mb_substr(…, 0, 160)`, и он
     * резал ровно на 160-м символе — то есть посреди слова. В выдаче это выглядело так:
     * «…платные эталоны жанра и проекты без покупок вовсе. Как за» — фраза обрывается на
     * предлоге. Google показывает ~155–160 символов, поэтому смысл не в том, чтобы уместиться
     * любой ценой, а в том, чтобы обрыв читался как сокращение, а не как баг.
     *
     * Короче лимита — отдаём как есть (многоточие не дорисовываем: это была бы ложь о том,
     * что текст продолжается).
     */
    private function metaDescription(string $raw, int $limit = 160): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', strip_tags($raw)) ?? '');

        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        // Режем с запасом под многоточие, затем откатываемся к последнему пробелу.
        $cut   = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        // Хвостовая пунктуация перед многоточием читается как опечатка («слово, …»).
        $cut = rtrim($cut, " \t\n\r\0\x0B.,;:!?—–-");

        return $cut . '…';
    }

    /**
     * @param array<string,mixed> $extra доп. SEO-поля: keywords, breadcrumbs, jsonld, publishedTime, modifiedTime, section
     *
     * @return array<string,mixed>
     */
    private function meta(
        string $title,
        string $description,
        string $canonical,
        ?string $ogImage = null,
        string $robots = 'index,follow',
        string $ogType = 'website',
        array $extra = []
    ): array {
        return array_merge([
            'title'       => $title,
            'description' => $description,
            'canonical'   => $canonical,
            'ogImage'     => $ogImage,
            'robots'      => $robots,
            'ogType'      => $ogType,
        ], $extra);
    }

    /**
     * Хлебная крошка «Главная».
     *
     * @return array{name:string,url:string}
     */
    private function homeCrumb(): array
    {
        return ['name' => 'Главная', 'url' => rtrim(base_url(), '/') . '/'];
    }

    /**
     * Schema.org VideoGame для главной — игра как сущность (rich-результаты).
     *
     * @return array<string,mixed>
     */
    private function videoGameSchema(): array
    {
        $site = rtrim(base_url(), '/');

        return [
            '@type'               => 'VideoGame',
            'name'                => 'Wild World',
            'url'                 => $site . '/',
            'description'         => 'Постапокалиптическая текстовая MMORPG в Telegram: исследование открытого мира, добыча, крафт, строительство баз, фракции и PvP.',
            'image'               => base_url('og-default.jpg'),
            'inLanguage'          => 'ru',
            'genre'               => ['MMORPG', 'Текстовая RPG', 'Выживание', 'Песочница'],
            'gamePlatform'        => ['Telegram', 'Web'],
            'applicationCategory' => 'GameApplication',
            'operatingSystem'     => 'Telegram',
            'author'              => ['@type' => 'Organization', 'name' => 'Wild World'],
            'publisher'           => ['@id' => $site . '/#organization'],
            'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
        ];
    }
}
