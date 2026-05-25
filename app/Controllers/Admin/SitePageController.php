<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Libraries\Slugifier;
use App\Models\SitePageModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Админ-CRUD статических страниц публичного сайта (ADR-052): О проекте, Контакты и т.п.
 */
class SitePageController extends BaseAdminController
{
    protected SitePageModel $pages;

    public function __construct()
    {
        $this->pages = new SitePageModel();
    }

    public function index(): string
    {
        return view('admin/site/pages_index', [
            'pages' => (new SitePageModel())->orderBy('slug', 'ASC')->findAll(),
        ]);
    }

    public function createForm(): string
    {
        return view('admin/site/page_form', ['mode' => 'create', 'page' => null]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->buildData();

        $id = $this->pages->insert($data);
        if ($id === false) {
            return $this->redirectBackWithErrors($this->pages->errors());
        }

        $this->audit('SITE_PAGE_CREATE', 'site_page', (int) $id, ['slug' => $data['slug']]);

        return $this->redirectWithSuccess(site_url('admin/site/pages'), 'Страница создана.');
    }

    public function editForm(int|string $id): string|ResponseInterface
    {
        $page = $this->pages->find($id);
        if ($page === null) {
            return $this->failNotFound('Страница не найдена.');
        }

        return view('admin/site/page_form', ['mode' => 'edit', 'page' => (array) $page]);
    }

    public function update(int|string $id): RedirectResponse|ResponseInterface
    {
        $page = $this->pages->find($id);
        if ($page === null) {
            return $this->failNotFound('Страница не найдена.');
        }

        $data       = $this->buildData();
        $data['id'] = (int) $id;

        if (! $this->pages->update($id, $data)) {
            return $this->redirectBackWithErrors($this->pages->errors());
        }

        $this->audit('SITE_PAGE_UPDATE', 'site_page', (int) $id, ['slug' => $data['slug']]);

        return $this->redirectWithSuccess(site_url('admin/site/pages'), 'Страница обновлена.');
    }

    public function delete(int|string $id): RedirectResponse
    {
        $page = $this->pages->find($id);
        if ($page === null) {
            session()->setFlashdata('error', 'Страница не найдена.');

            return redirect()->to(site_url('admin/site/pages'));
        }

        $this->pages->delete($id);
        $this->audit('SITE_PAGE_DELETE', 'site_page', (int) $id, []);

        return $this->redirectWithSuccess(site_url('admin/site/pages'), 'Страница удалена.');
    }

    /**
     * @return array<string,string|int|null>
     */
    private function buildData(): array
    {
        $title = trim($this->str($this->request->getPost('title')));
        $slug  = trim($this->str($this->request->getPost('slug')));
        if ($slug === '' && $title !== '') {
            $slug = (new Slugifier())->transliterate($title);
        }
        $metaDesc = trim($this->str($this->request->getPost('meta_description')));

        return [
            'title'            => $title,
            'slug'             => $slug,
            'content_html'     => $this->str($this->request->getPost('content_html')),
            'meta_description' => $metaDesc !== '' ? $metaDesc : null,
            'status'           => $this->request->getPost('status') === 'published' ? 'published' : 'draft',
        ];
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }
}
