<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BiomeModel;
use App\Models\ResourceModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class BiomeController extends BaseAdminController
{
    protected BiomeModel $biomeModel;
    protected ResourceModel $resourceModel;

    public function __construct()
    {
        $this->biomeModel    = new BiomeModel();
        $this->resourceModel = new ResourceModel();
    }

    public function index(): string
    {
        $biomes = $this->biomeModel->findAll();

        return view('admin/biome_index', [
            'biomes' => $biomes,
            'title'  => 'Список всех Биомов в игре',
        ]);
    }

    public function editBiomeForm(int|string $biomeId): string|ResponseInterface
    {
        $biome = $this->biomeModel->find($biomeId);

        if ($biome === null) {
            return $this->failNotFound('Биом не найден.');
        }
        $biome = $this->entityToArray($biome);

        return view('admin/biome_edit_form', [
            'biome' => $biome,
            'title' => 'Редактирование биома: ' . (string) ($biome['name'] ?? ''),
        ]);
    }

    public function updateBiome(int|string $biomeId): RedirectResponse|ResponseInterface
    {
        $data = (array) $this->request->getPost();

        $biome = $this->biomeModel->find($biomeId);
        if ($biome === null) {
            return $this->failNotFound('Биом не найден.');
        }

        if (!$this->validate($this->biomeModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $updated = $this->biomeModel->update($biomeId, $data);

        if (!$updated) {
            return $this->failServerError('Не удалось обновить биом.');
        }

        $this->audit('BIOME_UPDATE', 'biome', (int) $biomeId, [
            'name'                => $this->request->getPost('name'),
            'gather_rate'         => $this->request->getPost('gather_rate'),
            'survival_difficulty' => $this->request->getPost('survival_difficulty'),
            'danger_level'        => $this->request->getPost('danger_level'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/biomes'), 'Биом успешно обновлен.');
    }

    /**
     * S7 reverse-view: какие ресурсы добываются в данном биоме.
     * Резолв через FIND_IN_SET по `resources.biome_id` CSV (та же логика,
     * что и runtime `GatherCellResourceQuery`). Read-only.
     */
    public function showResources(int|string $biomeId): string|ResponseInterface
    {
        $biome = $this->biomeModel->find($biomeId);
        if ($biome === null) {
            return $this->failNotFound('Биом не найден.');
        }
        $biome = $this->entityToArray($biome);

        $id        = (int) $biomeId;
        $resources = $this->resourceModel
            ->where("FIND_IN_SET({$id}, biome_id) > 0", null, false)
            ->orderBy('rarity', 'DESC')
            ->orderBy('name')
            ->findAll();

        return view('admin/biome_resources', [
            'biome'     => $biome,
            'resources' => array_map(fn ($r) => $this->entityToArray($r), $resources),
            'title'     => 'Ресурсы биома: ' . (string) ($biome['name'] ?? ''),
        ]);
    }
}
