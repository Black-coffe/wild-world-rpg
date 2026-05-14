<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\EventModel;
use App\Models\BiomeModel;
use App\Services\Events\EventEffectInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Config\WorldEvents;

class EventController extends BaseAdminController
{
    protected EventModel $eventModel;
    protected BiomeModel $biomeModel;

    public function __construct()
    {
        $this->eventModel = new EventModel();
        $this->biomeModel = new BiomeModel();
    }

    /**
     * Phase B5 (ADR-023) — registered effect-handler options для form info-панели.
     *
     * @return list<array{key: string, displayName: string, description: string}>
     */
    protected function effectHandlerOptions(): array
    {
        return Services::handlerRegistry()->optionsFor(EventEffectInterface::class);
    }

    /**
     * Phase B6 (ADR-023) — нормалізує input.handler_key:
     *  - empty/missing → null (legacy fallback)
     *  - non-empty + valid registry key (для EventEffectInterface) → string
     *  - non-empty + invalid → null + помилка валідації у $errors output param
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
        if ($entry === null || !in_array(EventEffectInterface::class, $entry->interfaces, true)) {
            $errors[] = "handler_key '{$key}' не зарегистрирован для EventEffectInterface — оставлено NULL (legacy fallback).";
            return null;
        }
        return $key;
    }

    public function index(): string
    {
        $events = $this->eventModel->findAll();

        return view('admin/event_index', [
            'events' => $events,
            'title'  => 'Список всех Событий в игре',
        ]);
    }

    public function editEventForm(int|string $eventId): string|ResponseInterface
    {
        $eventRaw = $this->eventModel->find($eventId);

        if ($eventRaw === null) {
            return $this->failNotFound('Событие не найдено.');
        }
        $event = (array) $eventRaw;

        $biomes = $this->biomeModel->findAll();

        // F7.11: WorldEvents config для отображения effect_kind / tick_chance / etc. (read-only).
        $worldConfig = config(WorldEvents::class)->get((string) ($event['name_english'] ?? ''));

        return view('admin/event_edit_form', [
            'event'          => $event,
            'biomes'         => $biomes,
            'worldConfig'    => $worldConfig,
            'effectHandlers' => $this->effectHandlerOptions(),
            'title'          => 'Редактирование события: ' . (string) ($event['name'] ?? ''),
        ]);
    }

    public function updateEvent(int|string $eventId): RedirectResponse|ResponseInterface
    {
        $data = $this->jsonEncodeArrayFields((array) $this->request->getPost(), ['biome_ids']);

        $data['random_coverage'] = isset($data['random_coverage']) && $data['random_coverage'] === 'on' ? 1 : 0;

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->eventModel->getValidationRules(), $this->eventModel->getValidationMessages())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
        }

        $existingEvent = $this->eventModel->find($eventId);
        if ($existingEvent === null) {
            return $this->failNotFound('Событие не найдено.');
        }

        $updated = $this->eventModel->update($eventId, $data);

        if (!$updated) {
            return $this->failServerError('Не удалось обновить событие.');
        }

        $this->audit('EVENT_UPDATE', 'event', (int) $eventId, [
            'name'               => $data['name'] ?? null,
            'effect_type'        => $data['effect_type'] ?? null,
            'effect_value'       => $data['effect_value'] ?? null,
            'duration'           => $data['duration'] ?? null,
            'frequency_per_week' => $data['frequency_per_week'] ?? null,
        ]);

        return $this->redirectWithSuccess(site_url('admin/events'), 'Событие успешно обновлено.');
    }

    public function createEventForm(): string
    {
        $biomes = $this->biomeModel->findAll();

        return view('admin/event_create_form', [
            'title'          => 'Создание нового события',
            'biomes'         => $biomes,
            'effectHandlers' => $this->effectHandlerOptions(),
        ]);
    }

    public function storeEvent(): RedirectResponse|ResponseInterface
    {
        $data = $this->jsonEncodeArrayFields((array) $this->request->getPost(), ['biome_ids']);

        $data['random_coverage'] = isset($data['random_coverage']) ? 1 : 0;

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->eventModel->getValidationRules(), $this->eventModel->getValidationMessages())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
        }

        $saved = $this->eventModel->save($data);

        if (!$saved) {
            return $this->failServerError('Не удалось создать событие.');
        }

        $this->audit('EVENT_CREATE', 'event', (int) $this->eventModel->getInsertID(), [
            'name'               => $data['name'] ?? null,
            'effect_type'        => $data['effect_type'] ?? null,
            'effect_value'       => $data['effect_value'] ?? null,
            'duration'           => $data['duration'] ?? null,
            'frequency_per_week' => $data['frequency_per_week'] ?? null,
        ]);

        return $this->redirectWithSuccess(site_url('admin/events'), 'Новое событие успешно создано.');
    }
}
