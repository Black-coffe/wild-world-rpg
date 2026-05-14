<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BiomeModel;
use App\Models\WorldObjectModel;
use App\TaskHandlers\Objects\ObjectHandlerInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

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
     * Phase B5 (ADR-023) — registered world-object handler options для form info-панели.
     *
     * @return list<array{key: string, displayName: string, description: string}>
     */
    protected function objectHandlerOptions(): array
    {
        return Services::handlerRegistry()->optionsFor(ObjectHandlerInterface::class);
    }

    /**
     * Phase B6 (ADR-023) — нормалізує input.handler_key для world_objects.
     *
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    protected function normalizeHandlerKey(mixed $raw, array &$errors): ?string
    {
        $errors = [];
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $key = trim($raw);
        $entry = Services::handlerRegistry()->getByKey($key);
        if ($entry === null || !in_array(ObjectHandlerInterface::class, $entry->interfaces, true)) {
            $errors[] = "handler_key '{$key}' не зарегистрирован для ObjectHandlerInterface — оставлено NULL (legacy fallback).";
            return null;
        }
        return $key;
    }

    /**
     * `biome_id` — multi-select от админ-формы (массив id'ов биомов) → JSON.
     * `discovery_tools` и `contents` — админ вводит JSON-строку руками в textarea,
     * мы её декодируем, обрезаем пробелы рекурсивно (защита от случайных отступов
     * в скопированном JSON) и кодируем обратно — чтобы в БД попал «чистый» JSON.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function prepareJsonData(array $data): array
    {
        $data = $this->jsonEncodeArrayFields($data, ['biome_id']);

        foreach (['discovery_tools', 'contents'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                if (is_array($decoded)) {
                    $data[$field] = json_encode($this->trimRecursive($decoded));
                }
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
                $object['biome_name'] = $biome !== null ? (string) ($this->entityToArray($biome)['name'] ?? 'Не указан') : 'Не указан';
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
            'title'          => 'Создание нового объекта',
            'biomes'         => $biomes,
            'objectHandlers' => $this->objectHandlerOptions(),
        ]);
    }

    public function storeObject(): ResponseInterface|RedirectResponse
    {
        $data = (array) $this->request->getPost();
        $data = $this->prepareJsonData($data);

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
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
            'object'         => $object,
            'biomes'         => $biomes,
            'objectHandlers' => $this->objectHandlerOptions(),
            'title'          => 'Edit Object: ' . (string) ($object['name'] ?? ''),
        ]);
    }

    public function updateObject(int|string $objectId): ResponseInterface|RedirectResponse
    {
        $data = (array) $this->request->getPost();
        $data = $this->prepareJsonData($data);

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->worldObjectModel->getValidationRules())) {
            log_message('error', 'Validation errors: ' . print_r($this->validator?->getErrors() ?? [], true));
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
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
