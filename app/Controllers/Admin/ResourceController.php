<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BiomeModel;
use App\Models\ResourceModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class ResourceController extends BaseAdminController
{
    protected ResourceModel $resourceModel;
    protected BiomeModel $biomeModel;

    public function __construct()
    {
        $this->resourceModel = new ResourceModel();
        $this->biomeModel    = new BiomeModel();
    }

    public function index(): string
    {
        $resources = $this->resourceModel->orderBy('created_at', 'DESC')->findAll();

        foreach ($resources as &$resource) {
            $biomeIdRaw = $resource['biome_id'] ?? null;
            if (is_scalar($biomeIdRaw) && (string) $biomeIdRaw !== '') {
                $biomeIds = explode(',', (string) $biomeIdRaw);
                $resource['biome_names'] = $this->getBiomeNamesByIds($biomeIds);
            } else {
                $resource['biome_names'] = 'Не привязан к биому';
            }

            $typeRaw = $resource['type'] ?? null;
            $resource['translated_types'] = $this->translateResourceTypes(is_scalar($typeRaw) ? (string) $typeRaw : '');
        }
        unset($resource);

        return view('admin/resource_index', [
            'resources' => $resources,
            'title'     => 'Список всех Ресурсов в игре',
        ]);
    }

    public function createResourceForm(): string
    {
        $biomes = $this->biomeModel->findAll();

        return view('admin/resource_create', [
            'title'  => 'Создание нового игрового ресурса',
            'biomes' => $biomes,
        ]);
    }

    public function storeResource(): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        if (isset($data['biome_id']) && is_array($data['biome_id'])) {
            $data['biome_id'] = $this->joinScalarsCsv($data['biome_id']);
        }
        if (isset($data['type']) && is_array($data['type'])) {
            $data['type'] = $this->joinScalarsCsv($data['type']);
        }

        if (!$this->validate($this->resourceModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $id = $this->resourceModel->insert($data);

        if ($id === false) {
            return $this->redirectBackWithErrors($this->resourceModel->errors());
        }

        $this->audit('RESOURCE_CREATE', 'resource', (int) $id, [
            'name'     => $this->request->getPost('name'),
            'rarity'   => $this->request->getPost('rarity'),
            'type'     => $this->request->getPost('type'),
            'biome_id' => $this->request->getPost('biome_id'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/resources'), 'Новый ресурс успешно добавлен.');
    }

    public function editResourceForm(int|string $resourceId): string|ResponseInterface
    {
        $resource = $this->resourceModel->find($resourceId);
        $biomes   = $this->biomeModel->findAll();

        if ($resource === null) {
            return $this->failNotFound('Ресурс не найден.');
        }
        $resource = $this->entityToArray($resource);

        return view('admin/resource_edit_form', [
            'resource' => $resource,
            'biomes'   => $biomes,
            'title'    => 'Редактирование ресурса: ' . (string) ($resource['name'] ?? ''),
        ]);
    }

    public function updateResource(int|string $resourceId): RedirectResponse|ResponseInterface
    {
        $data = (array) $this->request->getPost();

        if (isset($data['biome_id']) && is_array($data['biome_id'])) {
            $data['biome_id'] = $this->joinScalarsCsv($data['biome_id']);
        }
        if (isset($data['type']) && is_array($data['type'])) {
            $data['type'] = $this->joinScalarsCsv($data['type']);
        }

        $resource = $this->resourceModel->find($resourceId);
        if ($resource === null) {
            return $this->failNotFound('Ресурс не найден.');
        }

        if (!$this->validate($this->resourceModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $updated = $this->resourceModel->update($resourceId, $data);

        if (!$updated) {
            return $this->failServerError('Не удалось обновить ресурс.');
        }

        $this->audit('RESOURCE_UPDATE', 'resource', (int) $resourceId, [
            'name'     => $this->request->getPost('name'),
            'rarity'   => $this->request->getPost('rarity'),
            'type'     => $this->request->getPost('type'),
            'biome_id' => $this->request->getPost('biome_id'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/resources'), 'Ресурс успешно обновлен.');
    }

    /**
     * Сериализует массив скалярных значений (id биомов / типов ресурса) в CSV-строку,
     * пропуская не-скалярные элементы. Поведенчески идентично оригинальному
     * `implode(',', $data['biome_id'])`, но safe-cast для phpstan L9.
     *
     * @param array<int|string, mixed> $values
     */
    private function joinScalarsCsv(array $values): string
    {
        $parts = [];
        foreach ($values as $v) {
            if (is_scalar($v)) {
                $parts[] = (string) $v;
            }
        }

        return implode(',', $parts);
    }

    /**
     * @param list<string|int> $biomeIds
     */
    protected function getBiomeNamesByIds(array $biomeIds): string
    {
        $biomes = $this->biomeModel->find($biomeIds);
        if ($biomes === null) {
            return '';
        }

        $biomeNames = [];
        foreach ((array) $biomes as $biome) {
            $biomeArr     = $this->entityToArray($biome);
            $biomeNames[] = (string) ($biomeArr['name'] ?? '');
        }

        return implode(', ', $biomeNames);
    }

    /**
     * Перевод comma-separated списка resource types на русские локали для UI.
     * TODO Phase C: вынести в lookup-таблицу `resource_types` (см. roadmap).
     */
    protected function translateResourceTypes(string $types): string
    {
        $typeList        = explode(',', $types);
        $translatedTypes = [];

        $map = [
            'crafting' => 'Крафтовые',
            'food'     => 'Еда',
            'water'    => 'Вода',
            'material' => 'Материалы',
        ];

        foreach ($typeList as $type) {
            $type = trim($type);
            if (isset($map[$type])) {
                $translatedTypes[] = $map[$type];
            }
        }

        return implode(', ', $translatedTypes);
    }
}
