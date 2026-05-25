<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Slugifier;
use App\Models\SiteCategoryModel;
use App\Models\SitePostCategoryModel;
use App\Models\SitePostModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\HTTP\CURLRequest;
use JsonException;
use Throwable;

/**
 * Импорт публичного сайта wildworld.fun (WordPress) в CMS-таблицы CI4 (ADR-050).
 *
 * Тянет категории + посты + featured-картинки через публичный WP REST API,
 * сохраняет slug'и (SEO-преемственность), идемпотентно апсертит по wp_post_id /
 * wp_term_id, заполняет M:N связь. content_html хранится как raw `content.rendered`
 * (источник доверенный: свой WP + админ-only рендер). `canon_reviewed=0` ставится
 * ТОЛЬКО при первом insert — повторный прогон НЕ затирает ручные канон-правки
 * (по умолчанию при canon_reviewed=1 тело не перезаписывается; --force-content меняет).
 *
 * Опции spark — через пробел, НЕ через `=`:
 *   php spark site:import-wp --dry-run            # ничего не пишет, только показывает
 *   php spark site:import-wp --limit 3            # первые N постов (для проверки)
 *   php spark site:import-wp --skip-images        # без скачивания картинок
 *   php spark site:import-wp --base-url https://wildworld.fun
 *   php spark site:import-wp --force-content      # перезаписать тело даже у canon_reviewed=1
 */
class ImportWordPress extends BaseCommand
{
    protected $group       = 'Site';
    protected $name        = 'site:import-wp';
    protected $description = 'Импорт постов/категорий/картинок с wildworld.fun (WordPress REST API) в CMS-таблицы (ADR-050).';
    protected $usage       = 'site:import-wp [--dry-run] [--limit N] [--skip-images] [--base-url <url>] [--force-content]';
    protected $options     = [
        '--dry-run'       => 'Не писать в БД и не качать картинки — только показать план.',
        '--limit'         => 'Ограничить число обрабатываемых постов (для smoke).',
        '--skip-images'   => 'Не скачивать featured-картинки.',
        '--base-url'      => 'База WordPress (по умолчанию https://wildworld.fun).',
        '--force-content' => 'Перезаписывать content_html/title/excerpt даже если canon_reviewed=1.',
    ];

    private const PER_PAGE = 20;

    private bool $dryRun       = false;
    private bool $skipImages   = false;
    private bool $forceContent = false;
    private int $limit         = 0;

    /** @var array{posts_created:int,posts_updated:int,posts_skipped_content:int,cats:int,images_ok:int,images_failed:int} */
    private array $stats = [
        'posts_created'         => 0,
        'posts_updated'         => 0,
        'posts_skipped_content' => 0,
        'cats'                  => 0,
        'images_ok'             => 0,
        'images_failed'         => 0,
    ];

    /** @param array<int|string,string|null> $params */
    public function run(array $params): int
    {
        $this->dryRun       = (bool) CLI::getOption('dry-run');
        $this->skipImages   = (bool) CLI::getOption('skip-images');
        $this->forceContent = (bool) CLI::getOption('force-content');

        $limitOpt    = CLI::getOption('limit');
        $this->limit = is_numeric($limitOpt) ? (int) $limitOpt : 0;

        $baseOpt = CLI::getOption('base-url');
        $base    = rtrim(is_string($baseOpt) && $baseOpt !== '' ? $baseOpt : 'https://wildworld.fun', '/') . '/';

        CLI::write('Импорт WordPress → CMS' . ($this->dryRun ? ' (dry-run)' : ''), 'yellow');
        CLI::write('База: ' . $base . ($this->limit > 0 ? '  | limit=' . $this->limit : ''), 'dark_gray');

        $client = \Config\Services::curlrequest([
            'baseURI'     => $base,
            'timeout'     => 60,
            'http_errors' => false,
        ]);

        try {
            $catMap = $this->importCategories($client);
            $this->importPosts($client, $catMap);
        } catch (Throwable $e) {
            CLI::error('Фатальная ошибка импорта: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write(sprintf(
            'Готово. Категорий: %d | Постов: +%d создано, ~%d обновлено, %d тел сохранено (canon). Картинки: %d ок, %d ошибок.',
            $this->stats['cats'],
            $this->stats['posts_created'],
            $this->stats['posts_updated'],
            $this->stats['posts_skipped_content'],
            $this->stats['images_ok'],
            $this->stats['images_failed'],
        ), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * Импорт категорий. Возвращает карту wp_term_id => local category_id.
     *
     * @return array<int,int>
     */
    private function importCategories(CURLRequest $client): array
    {
        $json = $this->fetchJson($client, 'wp-json/wp/v2/categories', ['per_page' => 100]);
        $map  = [];

        if (! is_array($json)) {
            CLI::write('  ⚠ Категории не получены — продолжаю без маппинга.', 'red');

            return $map;
        }

        $model = new SiteCategoryModel();
        $sort  = 0;
        foreach ($json as $raw) {
            $cat     = self::asArray($raw);
            $termId  = self::asInt($cat['id'] ?? null);
            $slug    = rawurldecode(self::asString($cat['slug'] ?? '')); // WP кодирует кириллические slug'и
            $name    = html_entity_decode(self::asString($cat['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $count   = self::asInt($cat['count'] ?? null);
            $descRaw = self::asString($cat['description'] ?? '');

            // Пустые категории (в т.ч. системная WP «Без рубрики») — пропускаем.
            if ($termId === 0 || $slug === '' || $count === 0) {
                continue;
            }

            $localId = $this->upsertCategory($model, $termId, $slug, $name, $descRaw, $sort);
            if ($localId > 0) {
                $map[$termId] = $localId;
                $this->stats['cats']++;
            }
            $sort++;
        }

        CLI::write('  Категорий смаплено: ' . count($map), 'dark_gray');

        return $map;
    }

    private function upsertCategory(SiteCategoryModel $model, int $termId, string $slug, string $name, string $description, int $sort): int
    {
        $existing = $model->where('wp_term_id', $termId)->first();
        $data     = [
            'wp_term_id'  => $termId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description !== '' ? $description : null,
            'sort'        => $sort,
        ];

        if ($this->dryRun) {
            CLI::write('  [dry] категория ' . $slug . ' (' . $name . ')');

            return is_array($existing) && is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
        }

        if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
            $id         = (int) $existing['id'];
            $data['id'] = $id; // для is_unique[...,{id}] на апдейте (id фильтруется из SET)
            $model->update($id, $data);

            return $id;
        }

        $newId = $model->insert($data);

        return is_numeric($newId) ? (int) $newId : 0;
    }

    /**
     * @param array<int,int> $catMap
     */
    private function importPosts(CURLRequest $client, array $catMap): void
    {
        $postModel  = new SitePostModel();
        $pivotModel = new SitePostCategoryModel();
        $processed  = 0;

        for ($page = 1; ; $page++) {
            $json = $this->fetchJson($client, 'wp-json/wp/v2/posts', [
                'per_page' => self::PER_PAGE,
                'page'     => $page,
                '_embed'   => 1,
            ]);

            if (! is_array($json) || $json === []) {
                break; // конец пагинации (или HTTP 400 за последней страницей)
            }

            foreach ($json as $raw) {
                if ($this->limit > 0 && $processed >= $this->limit) {
                    return;
                }
                $processed++;

                try {
                    $this->upsertPost($client, $postModel, $pivotModel, $catMap, self::asArray($raw));
                } catch (Throwable $e) {
                    CLI::error('  ✗ пост пропущен: ' . $e->getMessage());
                }
            }

            if (count($json) < self::PER_PAGE) {
                break;
            }
        }
    }

    /**
     * @param array<int,int>      $catMap
     * @param array<string,mixed> $post
     */
    private function upsertPost(
        CURLRequest $client,
        SitePostModel $postModel,
        SitePostCategoryModel $pivotModel,
        array $catMap,
        array $post
    ): void {
        $wpId = self::asInt($post['id'] ?? null);
        if ($wpId === 0) {
            return;
        }

        $titleArr = self::asArray($post['title'] ?? null);
        $title    = html_entity_decode(self::asString($titleArr['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title    = trim($title) !== '' ? trim($title) : ('Без названия #' . $wpId);

        $contentArr = self::asArray($post['content'] ?? null);
        $contentRaw = self::asString($contentArr['rendered'] ?? '');

        $excerptArr  = self::asArray($post['excerpt'] ?? null);
        $excerptText = trim(strip_tags(self::asString($excerptArr['rendered'] ?? '')));
        $metaDesc    = $excerptText !== '' ? mb_substr($excerptText, 0, 300) : null;

        $slug = rawurldecode(self::asString($post['slug'] ?? ''));
        if ($slug === '' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
            $slug = (new Slugifier())->transliterate($title);
        }
        $slug = $this->uniqueSlug($postModel, $slug, $wpId);

        $publishedAt = $this->parseDate(self::asString($post['date'] ?? ''));
        $catIds      = $this->mapCategories(self::asIntList($post['categories'] ?? null), $catMap);

        $existing   = $postModel->where('wp_post_id', $wpId)->first();
        $isExisting = is_array($existing) && is_numeric($existing['id'] ?? null);
        $reviewed   = $isExisting && (int) self::asInt($existing['canon_reviewed'] ?? null) === 1;

        if ($this->dryRun) {
            CLI::write(sprintf('  [dry] %s  «%s»  cats=[%s]%s', $slug, mb_substr($title, 0, 50), implode(',', $catIds), $reviewed ? ' (canon✓ тело сохранится)' : ''));

            return;
        }

        // Картинка (только при создании или если её ещё нет).
        $featured = $isExisting ? self::asStringOrNull($existing['featured_image'] ?? null) : null;
        if (! $this->skipImages && $featured === null) {
            $featured = $this->downloadFeaturedImage($client, $post, $slug);
        }

        $data = [
            'wp_post_id'       => $wpId,
            'slug'             => $slug,
            'title'            => $title,
            'excerpt'          => $excerptText !== '' ? $excerptText : null,
            'content_html'     => $contentRaw !== '' ? $contentRaw : '<p></p>',
            'featured_image'   => $featured,
            'meta_description' => $metaDesc,
            'status'           => 'published',
            'published_at'     => $publishedAt,
        ];

        if (! $isExisting) {
            $data['canon_reviewed'] = 0;
            $newId                  = $postModel->insert($data);
            if ($newId === false) {
                throw new \RuntimeException('insert failed: ' . implode('; ', $postModel->errors()));
            }
            $localId = (int) $newId;
            $this->stats['posts_created']++;
        } else {
            /** @var array<string,mixed> $existing */
            $localId = (int) self::asInt($existing['id'] ?? null);

            // canon-защита: не затирать тело/заголовок ручной ревизии.
            if ($reviewed && ! $this->forceContent) {
                unset($data['content_html'], $data['title'], $data['excerpt'], $data['meta_description']);
                $this->stats['posts_skipped_content']++;
            }

            $data['id'] = $localId; // для is_unique[...,{id}] на апдейте (id фильтруется из SET)

            if (! $postModel->update($localId, $data)) {
                throw new \RuntimeException('update failed: ' . implode('; ', $postModel->errors()));
            }
            $this->stats['posts_updated']++;
        }

        $pivotModel->syncForPost($localId, $catIds);
        CLI::write('  ✓ ' . $slug, 'green');
    }

    /**
     * Скачать featured-картинку (из _embed) в public/uploads/site/<slug>.<ext>.
     *
     * @param array<string,mixed> $post
     */
    private function downloadFeaturedImage(CURLRequest $client, array $post, string $slug): ?string
    {
        $url = $this->featuredUrl($post);
        if ($url === null) {
            return null;
        }

        $ext  = $this->extFromUrl($url);
        $rel  = 'uploads/site/' . $slug . '.' . $ext;
        $dir  = FCPATH . 'uploads/site';
        $path = FCPATH . $rel;

        try {
            $resp = $client->get($url);
            if ($resp->getStatusCode() !== 200) {
                $this->stats['images_failed']++;
                CLI::write('    ⚠ картинка HTTP ' . $resp->getStatusCode() . ' ' . $url, 'red');

                return null;
            }
            $bytes = (string) $resp->getBody();
            if ($bytes === '') {
                $this->stats['images_failed']++;

                return null;
            }

            @mkdir($dir, 0775, true);
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $bytes) === false || ! @rename($tmp, $path)) {
                @unlink($tmp);
                $this->stats['images_failed']++;

                return null;
            }

            $this->stats['images_ok']++;

            return $rel;
        } catch (Throwable $e) {
            $this->stats['images_failed']++;
            CLI::write('    ⚠ ошибка скачивания: ' . $e->getMessage(), 'red');

            return null;
        }
    }

    /**
     * Достать source_url featured-картинки из `_embedded.wp:featuredmedia[0]`.
     *
     * @param array<string,mixed> $post
     */
    private function featuredUrl(array $post): ?string
    {
        $embedded = self::asArray($post['_embedded'] ?? null);
        $media    = $embedded['wp:featuredmedia'] ?? null;
        if (! is_array($media) || ! isset($media[0])) {
            return null;
        }
        $first = self::asArray($media[0]);
        $url   = self::asString($first['source_url'] ?? '');

        return $url !== '' && str_starts_with($url, 'http') ? $url : null;
    }

    private function extFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg';
    }

    /**
     * Уникальный slug в site_posts (исключая текущий wp_post_id).
     */
    private function uniqueSlug(SitePostModel $model, string $slug, int $wpId): string
    {
        $slug = $slug !== '' ? $slug : ('post-' . $wpId);
        $base = $slug;
        $i    = 2;
        while (true) {
            $row = $model->where('slug', $slug)->first();
            if (! is_array($row)) {
                return $slug;
            }
            if (self::asInt($row['wp_post_id'] ?? null) === $wpId) {
                return $slug; // это тот же пост — slug свободен для него
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    /**
     * @param list<int>      $wpTermIds
     * @param array<int,int> $catMap
     * @return list<int>
     */
    private function mapCategories(array $wpTermIds, array $catMap): array
    {
        $out = [];
        foreach ($wpTermIds as $termId) {
            if (isset($catMap[$termId])) {
                $out[] = $catMap[$termId];
            }
        }

        return $out;
    }

    private function parseDate(string $wpDate): ?string
    {
        if ($wpDate === '') {
            return null;
        }
        $ts = strtotime($wpDate);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * GET + JSON-decode. Возвращает decoded mixed (array при успехе) или null.
     *
     * @param array<string,int|string> $query
     */
    private function fetchJson(CURLRequest $client, string $path, array $query): mixed
    {
        $response = $client->get($path, ['query' => $query]);
        $status   = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return null; // 400 за последней страницей пагинации = норма
        }

        try {
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    // ---- mixed-нарроверы (идиомы CraftTreeService, держат phpstan L9 зелёным) ----

    private static function asString(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }

    private static function asStringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function asInt(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * @return array<array-key,mixed>
     */
    private static function asArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /**
     * @return list<int>
     */
    private static function asIntList(mixed $v): array
    {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $x) {
                if (is_numeric($x)) {
                    $out[] = (int) $x;
                }
            }
        }

        return $out;
    }
}
