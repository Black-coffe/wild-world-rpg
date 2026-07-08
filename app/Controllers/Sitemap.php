<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SiteCategoryModel;
use App\Models\SitePageModel;
use App\Models\SitePostModel;
use App\Services\WikiContentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Карты сайта в формате Yoast/WordPress (ADR-052) — чтобы старые сабмиты в Google
 * Search Console (`sitemap_index.xml`, `post-sitemap.xml`, `page-sitemap.xml`,
 * `category-sitemap.xml`, `post_tag-sitemap.xml`, `author-sitemap.xml`) снова
 * отдавались (раньше WP → теперь CI4). URL каноничны без хвостового слэша (SLASH-OFF).
 */
class Sitemap extends BaseController
{
    /** sitemap_index.xml — индекс под-карт. */
    public function index(): ResponseInterface
    {
        $maps = [
            ['loc' => base_url('post-sitemap.xml'), 'lastmod' => $this->latestPostDate()],
            ['loc' => base_url('page-sitemap.xml'), 'lastmod' => date('Y-m-d\TH:i:sP')],
            ['loc' => base_url('category-sitemap.xml'), 'lastmod' => date('Y-m-d\TH:i:sP')],
        ];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($maps as $m) {
            $xml .= '  <sitemap><loc>' . $this->x($m['loc']) . '</loc>'
                . '<lastmod>' . $m['lastmod'] . '</lastmod></sitemap>' . "\n";
        }
        $xml .= '</sitemapindex>' . "\n";

        return $this->xml($xml);
    }

    /** post-sitemap.xml — все опубликованные посты (root-level slug). */
    public function posts(): ResponseInterface
    {
        $urls = [];
        foreach ((new SitePostModel())->where('status', 'published')->orderBy('published_at', 'DESC')->findAll() as $p) {
            if (! is_array($p)) {
                continue;
            }
            $slug = is_string($p['slug'] ?? null) ? $p['slug'] : '';
            if ($slug === '') {
                continue;
            }
            $upd = is_string($p['updated_at'] ?? null) ? $p['updated_at'] : null;
            $urls[] = [
                'loc'     => rtrim(base_url($slug), '/'),
                'lastmod' => $upd !== null && strtotime($upd) !== false ? date('Y-m-d\TH:i:sP', (int) strtotime($upd)) : null,
            ];
        }

        return $this->xml($this->urlset($urls));
    }

    /** page-sitemap.xml — главная + статические страницы + вики. */
    public function pages(): ResponseInterface
    {
        $urls   = [];
        $urls[] = ['loc' => rtrim(base_url(), '/'), 'lastmod' => null];

        foreach ((new SitePageModel())->where('status', 'published')->findAll() as $pg) {
            $slug = is_array($pg) && is_string($pg['slug'] ?? null) ? $pg['slug'] : '';
            if ($slug !== '') {
                $urls[] = ['loc' => rtrim(base_url($slug), '/'), 'lastmod' => null];
            }
        }

        $urls[] = ['loc' => rtrim(base_url('wiki'), '/'), 'lastmod' => null];
        foreach ((new WikiContentService())->sections() as $s) {
            $urls[] = ['loc' => rtrim(base_url('wiki/' . $s['key']), '/'), 'lastmod' => null];
        }

        // ADR-149 — интерактивный инструмент «Калькулятор крафта» (code-route, не CMS-страница).
        $urls[] = ['loc' => rtrim(base_url('kalkulyator-krafta'), '/'), 'lastmod' => null];

        return $this->xml($this->urlset($urls));
    }

    /** category-sitemap.xml — рубрики блога. */
    public function categories(): ResponseInterface
    {
        $urls = [];
        foreach ((new SiteCategoryModel())->orderBy('sort')->findAll() as $c) {
            $slug = is_array($c) && is_string($c['slug'] ?? null) ? $c['slug'] : '';
            if ($slug !== '') {
                $urls[] = ['loc' => rtrim(base_url($slug), '/'), 'lastmod' => null];
            }
        }

        return $this->xml($this->urlset($urls));
    }

    /** post_tag-sitemap.xml — тегов нет; пустой валидный urlset (200, не 404) для старых сабмитов. */
    public function tags(): ResponseInterface
    {
        return $this->xml($this->urlset([]));
    }

    /** author-sitemap.xml — авторов нет; пустой валидный urlset (200). */
    public function authors(): ResponseInterface
    {
        return $this->xml($this->urlset([]));
    }

    /**
     * @param list<array{loc:string,lastmod:?string}> $urls
     */
    private function urlset(array $urls): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . $this->x($u['loc']) . '</loc>';
            if ($u['lastmod'] !== null) {
                $xml .= '<lastmod>' . $u['lastmod'] . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    private function latestPostDate(): string
    {
        $row = (new SitePostModel())->where('status', 'published')->orderBy('updated_at', 'DESC')->first();
        $upd = is_array($row) && is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null;

        return $upd !== null && strtotime($upd) !== false ? date('Y-m-d\TH:i:sP', (int) strtotime($upd)) : date('Y-m-d\TH:i:sP');
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1, 'UTF-8');
    }

    private function xml(string $body): ResponseInterface
    {
        return $this->response->setContentType('application/xml')->setBody($body);
    }
}
