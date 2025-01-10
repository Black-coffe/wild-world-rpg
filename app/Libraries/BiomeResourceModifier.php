<?php
namespace App\Libraries;

use App\Models\BiomeModel;
use App\Models\ResourceModel;

class BiomeResourceModifier
{
    protected $biomeModel;
    protected $resourceModel;

    public function __construct()
    {
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
    }

    public function modifyResourcesByBiome($biomeId, $resources)
    {
        // Получение данных о биоме
        $biome = $this->biomeModel->find($biomeId);
        // Проверка существования биома
        if (!$biome) {
            log_message('error', "Biome not found with ID: {$biomeId}");
            return $resources; // Возвращаем ресурсы без изменений
        }

        // Получение названия биома
        $biomeName = $biome['name'];

        // Логирование для отладки
        // log_message('debug', "modifyResourcesByBiome biomeId: {$biomeId}");
        // log_message('debug', "modifyResourcesByBiome biomeName: {$biomeName}");

        // Получаем имена ресурсов
        foreach ($resources as &$resource) {
            $resourceData = $this->resourceModel->find($resource['resource_id']);
            if ($resourceData) {
                $resource['name'] = $resourceData['name'];
            }
        }

        // Выбор метода модификации ресурсов в зависимости от названия биома
        return match ($biomeName) {
            'Пустыни' => $this->modifyForDesert($resources),
            'Леса' => $this->modifyForForest($resources),
            'Горы' => $this->modifyForMountains($resources),
            default => $resources,
        };
    }

    protected function modifyForMountains($resources)
    {
        // log_message('debug', "modifyForForest resources: " . print_r($resources, true));
        foreach ($resources as &$resource) {
            $resourceData = $this->resourceModel->getResourceById($resource['resource_id']);
            if ($resourceData) {
                if ($resourceData['name'] == 'Камни') {
                    $resource['amount'] = (int) round($resource['amount'] * 3);
                } elseif ($resourceData['name'] == 'Древесина') {
                    $resource['amount'] = (int) round($resource['amount'] / 2);
                }elseif ($resourceData['name'] == 'Мхи') {
                    $resource['amount'] = (int) round($resource['amount'] / 2);
                }elseif ($resourceData['name'] == 'Вода') {
                    $resource['amount'] = (int) round($resource['amount'] / 4);
                }
            } else {
                log_message('error', "Resource not found with ID: {$resource['resource_id']}");
            }
        }
        return $resources;
    }

    protected function modifyForForest($resources)
    {
        foreach ($resources as &$resource) {
            $resourceData = $this->resourceModel->getResourceById($resource['resource_id']);
            if ($resourceData) {
                // Проверяем имя ресурса и применяем соответствующую модификацию
                if ($resourceData['name'] == 'Древесина') {
                    $resource['amount'] = (int) round($resource['amount'] * 10);
                } elseif ($resourceData['name'] == 'Камни') {
                    $resource['amount'] = (int) round($resource['amount'] / 2);
                }
            } else {
                log_message('error', "Resource not found with ID: {$resource['resource_id']}");
            }
        }
        return $resources;
    }

    protected function modifyForDesert($resources)
    {
        foreach ($resources as &$resource) {
            $resourceData = $this->resourceModel->getResourceById($resource['resource_id']);
            if ($resourceData) {
                if ($resourceData['name'] == 'Песок') {
                    $resource['amount'] = (int) round($resource['amount'] * 10);
                } elseif ($resourceData['name'] == 'Вода') {
                    $resource['amount'] = (int) round($resource['amount'] / 10);
                }
            } else {
                log_message('error', "Resource not found with ID: {$resource['resource_id']}");
            }
        }
        return $resources;
    }
}
