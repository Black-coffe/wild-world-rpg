<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Libraries\Slugifier;
use App\Models\SiteCategoryModel;
use App\Models\SitePostCategoryModel;
use App\Models\SitePostModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Админ-CRUD постов публичного сайта (ADR-052) + workflow ревизии канона:
 * список сортирует несверённые (canon_reviewed=0) сверху, фильтр ?review=pending,
 * бейдж и одно-кликовый markReviewed.
 */
class SitePostController extends BaseAdminController
{
    protected SitePostModel $posts;
    protected SiteCategoryModel $categories;
    protected SitePostCategoryModel $pivot;

    public function __construct()
    {
        $this->posts      = new SitePostModel();
        $this->categories = new SiteCategoryModel();
        $this->pivot      = new SitePostCategoryModel();
    }

    public function index(): string
    {
        $review  = $this->request->getGet('review') === 'pending' ? 'pending' : 'all';
        $builder = (new SitePostModel())
            ->orderBy('canon_reviewed', 'ASC')
            ->orderBy('published_at', 'DESC');
        if ($review === 'pending') {
            $builder->where('canon_reviewed', 0);
        }

        return view('admin/site/posts_index', [
            'posts'        => $builder->findAll(),
            'review'       => $review,
            'pendingCount' => (new SitePostModel())->where('canon_reviewed', 0)->countAllResults(),
            'totalCount'   => (new SitePostModel())->countAllResults(),
        ]);
    }

    public function createForm(): string
    {
        return view('admin/site/post_form', [
            'mode'          => 'create',
            'post'          => null,
            'allCategories' => $this->categories->orderBy('sort')->findAll(),
            'selectedCats'  => [],
        ]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->buildData(null);

        $id = $this->posts->insert($data);
        if ($id === false) {
            return $this->redirectBackWithErrors($this->posts->errors());
        }
        $localId = (int) $id;

        $this->pivot->syncForPost($localId, $this->postedCategoryIds());
        $this->audit('SITE_POST_CREATE', 'site_post', $localId, ['slug' => $data['slug']]);

        return $this->redirectWithSuccess(site_url('admin/site/posts'), 'Пост создан.');
    }

    public function editForm(int|string $id): string|ResponseInterface
    {
        $post = $this->posts->find($id);
        if ($post === null) {
            return $this->failNotFound('Пост не найден.');
        }

        return view('admin/site/post_form', [
            'mode'          => 'edit',
            'post'          => (array) $post,
            'allCategories' => $this->categories->orderBy('sort')->findAll(),
            'selectedCats'  => $this->pivot->categoryIdsForPost((int) $id),
        ]);
    }

    public function update(int|string $id): RedirectResponse|ResponseInterface
    {
        $post = $this->posts->find($id);
        if ($post === null) {
            return $this->failNotFound('Пост не найден.');
        }

        $data       = $this->buildData((int) $id);
        $data['id'] = (int) $id; // для is_unique[...,{id}]

        if (! $this->posts->update($id, $data)) {
            return $this->redirectBackWithErrors($this->posts->errors());
        }

        $this->pivot->syncForPost((int) $id, $this->postedCategoryIds());
        $this->audit('SITE_POST_UPDATE', 'site_post', (int) $id, ['slug' => $data['slug']]);

        return $this->redirectWithSuccess(site_url('admin/site/posts'), 'Пост обновлён.');
    }

    public function delete(int|string $id): RedirectResponse
    {
        $post = $this->posts->find($id);
        if ($post === null) {
            session()->setFlashdata('error', 'Пост не найден.');

            return redirect()->to(site_url('admin/site/posts'));
        }

        $this->pivot->syncForPost((int) $id, []); // очистить M:N
        $this->posts->delete($id);
        $this->audit('SITE_POST_DELETE', 'site_post', (int) $id, []);

        return $this->redirectWithSuccess(site_url('admin/site/posts'), 'Пост удалён.');
    }

    /**
     * Одно-кликовая отметка «сверено с каноном» (ADR-010).
     */
    public function markReviewed(int|string $id): RedirectResponse|ResponseInterface
    {
        $post = $this->posts->find($id);
        if ($post === null) {
            return $this->failNotFound('Пост не найден.');
        }

        $this->posts->update($id, ['id' => (int) $id, 'canon_reviewed' => 1]);
        $this->audit('SITE_POST_CANON_REVIEWED', 'site_post', (int) $id, []);

        return $this->redirectWithSuccess(site_url('admin/site/posts'), 'Пост отмечен как сверённый с каноном.');
    }

    /**
     * @return array<string,string|int|null>
     */
    private function buildData(?int $id): array
    {
        $title = trim($this->str($this->request->getPost('title')));
        $slug  = trim($this->str($this->request->getPost('slug')));
        if ($slug === '' && $title !== '') {
            $slug = (new Slugifier())->transliterate($title);
        }

        // Короткий заголовок только для тега <title> (SEO-аудит 2026-07-24). Без него
        // форма молча теряла бы поле, и каждая новая статья снова получала бы
        // обрезаемый в выдаче заголовок — ровно то, что чинили по всем 120 постам.
        $seoTitle = trim($this->str($this->request->getPost('seo_title')));
        $excerpt  = trim($this->str($this->request->getPost('excerpt')));
        $metaDesc = trim($this->str($this->request->getPost('meta_description')));
        $featured = trim($this->str($this->request->getPost('featured_image')));
        $pub      = trim($this->str($this->request->getPost('published_at')));

        return [
            'title'            => $title,
            'seo_title'        => $seoTitle !== '' ? $seoTitle : null,
            'slug'             => $slug,
            'excerpt'          => $excerpt !== '' ? $excerpt : null,
            'content_html'     => $this->str($this->request->getPost('content_html')),
            'meta_description' => $metaDesc !== '' ? $metaDesc : null,
            'featured_image'   => $featured !== '' ? $featured : null,
            'status'           => $this->request->getPost('status') === 'published' ? 'published' : 'draft',
            'canon_reviewed'   => $this->request->getPost('canon_reviewed') !== null ? 1 : 0,
            'published_at'     => $pub !== '' ? $pub : date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<int>
     */
    private function postedCategoryIds(): array
    {
        $raw = $this->request->getPost('categories');
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_numeric($v)) {
                    $out[] = (int) $v;
                }
            }
        }

        return $out;
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }
}
