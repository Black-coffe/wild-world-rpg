<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BiomeModel;
use App\Models\WorldObjectModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class WorldObjectController extends BaseAdminController
{
    protected WorldObjectModel $worldObjectModel;
    protected BiomeModel $biomeModel;

    public function __construct()
    {
        $this->worldObjectModel = new WorldObjectModel();
        $this->biomeModel       = new BiomeModel();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareJsonData(array $data): array
    {
        if (isset($data['biome_id']) && is_array($data['biome_id'])) {
            $data['biome_id'] = json_encode($data['biome_id']);
        }

        if (isset($data['discovery_tools']) && is_string($data['discovery_tools'])) {
            $tools = json_decode($data['discovery_tools'], true);
            if (is_array($tools)) {
                $data['discovery_tools'] = json_encode($this->trimRecursive($tools));
            }
        }
        if (isset($data['contents']) && is_string($data['contents'])) {
            $contents = json_decode($data['contents'], true);
            if (is_array($contents)) {
                $data['contents'] = json_encode($this->trimRecursive($contents));
            }
        }

        return $data;
    }

    private function trimRecursive(mixed $input): mixed
    {
        if (!is_array($input)) {
            return trim((string) $input);
        }

        return array_map([$this, 'trimRecursive'], $input);
    }

    public function index(): string
    {
        $objects = $this->worldObjectModel->orderBy('created_at', 'DESC')->findAll();
        foreach ($objects as &$object) {
            if (!empty($object['biome_id'])) {
                $biome                = $this->biomeModel->find($object['biome_id']);
                $object['biome_name'] = $biome !== null ? (string) (((array) $biome)['name'] ?? 'Не указан') : 'Не указан';
            } else {
                $object['biome_name'] = 'Не привязан к биому';
            }
        }
        unset($object);

        return view('admin/world_object_index', [
            'objects' => $objects,
            'title'   => 'Список объектов в мире',
        ]);
    }

    public function createObjectForm(): string
    {
        $biomes = $this->biomeModel->findAll();

        return view('admin/world_object_create', [
            'title'  => 'Создание нового объекта',
            'biomes' => $biomes,
        ]);
    }

    public function storeObject(): ResponseInterface|RedirectResponse
    {
        $data = (array) $this->request->getPost();
        $data = $this->prepareJsonData($data);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $id = $this->worldObjectModel->insert($data);
        if ($id === false) {
            return $this->redirectBackWithErrors($this->worldObjectModel->errors());
        }

        $this->audit('WORLD_OBJECT_CREATE', 'world_object', (int) $id, [
            'name' => $this->request->getPost('name'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/world-objects'), 'Новый объект успешно добавлен.');
    }

    public function editObjectForm(int|string $objectId): string|ResponseInterface
    {
        $objectRaw = $this->worldObjectModel->find($objectId);
        $biomes    = $this->biomeModel->findAll();

        if ($objectRaw === null) {
            return $this->failNotFound('Object not found.');
        }

        $object = (array) $objectRaw;
        $object['discovery_tools'] = json_decode((string) ($object['discovery_tools'] ?? ''), true) ?? [];
        $object['contents']        = json_decode((string) ($object['contents'] ?? ''), true) ?? [];

        return view('admin/world_object_edit_form', [
            'object' => $object,
            'biomes' => $biomes,
            'title'  => 'Edit Object: ' . (string) ($object['name'] ?? ''),
        ]);
    }

    public function updateObject(int|string $objectId): ResponseInterface|RedirectResponse
    {
        $data = (array) $this->request->getPost();
        $data = $this->prepareJsonData($data);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            log_message('error', 'Validation errors: ' . print_r($this->validator?->getErrors() ?? [], true));
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        // v0.51.12: fixed duplicate $worldObjectModel->update() call (was calling update
        // twice — once for $result + once inside if() — wasteful 2× DB write per request).
        $result = $this->worldObjectModel->update($objectId, $data);

        if ($result === false) {
            log_message('error', 'Update failed with error: ' . print_r($this->worldObjectModel->errors(), true));
            return $this->failServerError('Не удалось обновить объект.');
        }

        $this->audit('WORLD_OBJECT_UPDATE', 'world_object', (int) $objectId, [
            'name' => $this->request->getPost('name'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/world-objects'), 'Объект успешно обновлен.');
    }

    public function deleteObject(int|string $objectId): RedirectResponse
    {
        if (!$this->worldObjectModel->delete($objectId)) {
            session()->setFlashdata('error', 'Не удалось удалить объект.');
            return redirect()->to(site_url('admin/world-objects'))->withInput();
        }

        $this->audit('WORLD_OBJECT_DELETE', 'world_object', (int) $objectId, []);

        return $this->redirectWithSuccess(site_url('admin/world-objects'), 'Объект успешно удален.');
    }
}
