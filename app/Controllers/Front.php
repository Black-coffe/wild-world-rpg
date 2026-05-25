<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SiteCategoryModel;
use App\Models\SitePageModel;
use App\Models\SitePostCategoryModel;
use App\Models\SitePostModel;
use App\Services\WikiContentService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Публичный сайт wildworld.fun в CI4-экосистеме (ADR-050): главная, лента/категории,
 * одиночный пост, статические страницы. Catch-all `Front::resolve` разводит
 * корневой slug → пост / страница / 404 (404 → Errors::notFound → site_redirects).
 */
class Front extends BaseController
{
    public function home(): string
    {
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

        return view('site/posts', [
            'heading' => $name,
            'lead'    => $desc,
            'posts'   => (new SitePostModel())->publishedInCategory($catId),
            'meta'    => $this->meta(
                $name . ' — Wild World',
                $desc !== '' ? mb_substr(strip_tags($desc), 0, 300) : ('Материалы рубрики «' . $name . '» — Wild World.'),
                rtrim(base_url($slug), '/'),
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
     * @param array<array-key,mixed> $post
     */
    private function renderPost(array $post): string
    {
        $id       = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
        $slug     = is_string($post['slug'] ?? null) ? $post['slug'] : '';
        $title    = is_string($post['title'] ?? null) ? $post['title'] : '';
        $metaDesc = is_string($post['meta_description'] ?? null) ? $post['meta_description'] : '';
        $image    = is_string($post['featured_image'] ?? null) && $post['featured_image'] !== '' ? $post['featured_image'] : null;

        $catIds     = (new SitePostCategoryModel())->categoryIdsForPost($id);
        $categories = $catIds !== [] ? (new SiteCategoryModel())->whereIn('id', $catIds)->orderBy('sort')->findAll() : [];

        return view('site/post', [
            'post'       => $post,
            'categories' => $categories,
            'meta'       => $this->meta(
                $title . ' — Wild World',
                $metaDesc !== '' ? $metaDesc : ($title . ' — Wild World, постапокалиптическая MMORPG в Telegram.'),
                rtrim(base_url($slug), '/'),
                $image !== null ? rtrim(base_url($image), '/') : null,
                'index,follow',
                'article',
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

        return view('site/page', [
            'page' => $page,
            'meta' => $this->meta(
                $title . ' — Wild World',
                $metaDesc !== '' ? $metaDesc : $title,
                rtrim(base_url($slug), '/'),
            ),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function meta(
        string $title,
        string $description,
        string $canonical,
        ?string $ogImage = null,
        string $robots = 'index,follow',
        string $ogType = 'website'
    ): array {
        return [
            'title'       => $title,
            'description' => $description,
            'canonical'   => $canonical,
            'ogImage'     => $ogImage,
            'robots'      => $robots,
            'ogType'      => $ogType,
        ];
    }
}
