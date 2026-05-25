<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SiteCategoryModel;
use App\Models\SitePageModel;
use App\Models\SitePostModel;
use App\Services\WikiContentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Динамический sitemap.xml (ADR-052): главная + категории + посты + страницы + вики.
 * URL'ы каноничны (без хвостового слэша — SLASH-OFF, .htaccess срезает `/`).
 */
class Sitemap extends BaseController
{
    public function index(): ResponseInterface
    {
        /** @var list<array{loc:string,lastmod:?string}> $urls */
        $urls = [];

        $urls[] = ['loc' => rtrim(base_url(), '/'), 'lastmod' => null];
        $urls[] = ['loc' => rtrim(base_url('wiki'), '/'), 'lastmod' => null];

        foreach ((new WikiContentService())->sections() as $s) {
            $urls[] = ['loc' => rtrim(base_url('wiki/' . $s['key']), '/'), 'lastmod' => null];
        }

        foreach ((new SiteCategoryModel())->orderBy('sort')->findAll() as $cat) {
            $slug = is_array($cat) && is_string($cat['slug'] ?? null) ? $cat['slug'] : '';
            if ($slug !== '') {
                $urls[] = ['loc' => rtrim(base_url($slug), '/'), 'lastmod' => null];
            }
        }

        foreach ((new SitePostModel())->where('status', 'published')->orderBy('published_at', 'DESC')->findAll() as $post) {
            if (! is_array($post)) {
                continue;
            }
            $slug = is_string($post['slug'] ?? null) ? $post['slug'] : '';
            if ($slug === '') {
                continue;
            }
            $upd = is_string($post['updated_at'] ?? null) ? $post['updated_at'] : null;
            $urls[] = [
                'loc'     => rtrim(base_url($slug), '/'),
                'lastmod' => $upd !== null && strtotime($upd) !== false ? date('Y-m-d', (int) strtotime($upd)) : null,
            ];
        }

        foreach ((new SitePageModel())->where('status', 'published')->findAll() as $page) {
            $slug = is_array($page) && is_string($page['slug'] ?? null) ? $page['slug'] : '';
            if ($slug !== '') {
                $urls[] = ['loc' => rtrim(base_url($slug), '/'), 'lastmod' => null];
            }
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . '</loc>';
            if ($u['lastmod'] !== null) {
                $xml .= '<lastmod>' . $u['lastmod'] . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return $this->response
            ->setContentType('application/xml')
            ->setBody($xml);
    }
}
