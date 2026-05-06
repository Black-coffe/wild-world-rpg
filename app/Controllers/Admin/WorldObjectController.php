<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\WorldObjectModel;
use App\Models\BiomeModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

class WorldObjectController extends BaseController
{
    use ResponseTrait;

    protected WorldObjectModel $worldObjectModel;
    protected BiomeModel $biomeModel;

    public function __construct()
    {
        $this->worldObjectModel = new WorldObjectModel();
        $this->biomeModel = new BiomeModel();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareJsonData(array $data): array {
        if (isset($data['biome_id']) && is_array($data['biome_id'])) {
            // Преобразование массива biome_id в строку JSON
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

    private function trimRecursive(mixed $input): mixed {
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
                $biome = $this->biomeModel->find($object['biome_id']);
                $object['biome_name'] = $biome ? $biome['name'] : 'Не указан';
            } else {
                $object['biome_name'] = 'Не привязан к биому';
            }
        }
        unset($object);

        return view('admin/world_object_index', [
            'objects' => $objects,
            'title' => 'Список объектов в мире'
        ]);
    }

    public function createObjectForm(): string
    {
        $biomes = $this->biomeModel->findAll();
        return view('admin/world_object_create', [
            'title' => 'Создание нового объекта',
            'biomes' => $biomes,
        ]);
    }

    public function storeObject(): ResponseInterface {
        $data = $this->request->getPost();

        // Подготовка данных JSON для 'discovery_tools' и 'contents'
        $data = $this->prepareJsonData($data);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        $id = $this->worldObjectModel->insert($data);
        if ($id === false) {
            return redirect()->back()->withInput()->with('errors', $this->worldObjectModel->errors());
        }

        $this->auditAdminAction('WORLD_OBJECT_CREATE', 'world_object', (int) $id, [
            'name' => $this->request->getPost('name'),
        ]);

        session()->setFlashdata('success', 'Новый объект успешно добавлен.');
        return redirect()->to(site_url('admin/world-objects'));
    }

    public function editObjectForm(int|string $objectId): string|ResponseInterface
    {
        $object = $this->worldObjectModel->find($objectId);
        $biomes = $this->biomeModel->findAll();

        if ($object === null) {
            return $this->failNotFound('Object not found.');
        }

        // Проверка и декодирование JSON
        $object['discovery_tools'] = json_decode($object['discovery_tools'], true) ?? []; // Используйте null coalescing operator для предотвращения ошибок
        $object['contents'] = json_decode($object['contents'], true) ?? [];

        return view('admin/world_object_edit_form', [
            'object' => $object,
            'biomes' => $biomes,
            'title' => 'Edit Object: ' . $object['name']
        ]);
    }

    public function updateObject(int|string $objectId): ResponseInterface
    {
        $data = $this->request->getPost();

        // Подготовка данных JSON для 'discovery_tools' и 'contents'
        $data = $this->prepareJsonData($data);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            log_message('error', 'Validation errors: ' . print_r($this->validator?->getErrors() ?? [], true));
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $result = $this->worldObjectModel->update($objectId, $data);
        log_message('debug', 'Update result: ' . $result);

        if ($result === false) {
            log_message('error', 'Update failed with error: ' . print_r($this->worldObjectModel->errors(), true));
            return $this->failServerError('Не удалось обновить объект.');
        }

        if ($this->worldObjectModel->update($objectId, $data)) {
            $this->auditAdminAction('WORLD_OBJECT_UPDATE', 'world_object', (int) $objectId, [
                'name' => $this->request->getPost('name'),
            ]);
            session()->setFlashdata('success', 'Объект успешно обновлен.');
            return redirect()->to(site_url('admin/world-objects'));
        } else {
            return $this->failServerError('Не удалось обновить объект.');
        }
    }

    public function deleteObject(int|string $objectId): ResponseInterface
    {
        if (!$this->worldObjectModel->delete($objectId)) {
            session()->setFlashdata('error', 'Не удалось удалить объект.');
            return redirect()->to(site_url('admin/world-objects'))->withInput();
        }

        $this->auditAdminAction('WORLD_OBJECT_DELETE', 'world_object', (int) $objectId, []);

        session()->setFlashdata('success', 'Объект успешно удален.');
        return redirect()->to(site_url('admin/world-objects'));
    }

    /**
     * F1.9 expansion (v0.51.11): єдина точка запису destructive admin actions.
     *
     * @param array<string, mixed> $payload
     */
    private function auditAdminAction(string $action, ?string $targetType, ?int $targetId, array $payload = []): void
    {
        $auth = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record($adminUserId, $action, $targetType, $targetId, $payload);
    }
}
