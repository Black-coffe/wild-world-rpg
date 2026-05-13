<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function auth($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('auth');
        }

        return new \App\Libraries\Authentication;
    }

    /**
     * F2.6 — singleton-репозиторий персонажа.
     *
     * Использовать так:
     *   $repo = service('characterRepository');
     *   $character = $repo->findByTelegramUserId($userId);
     *
     * В тестах подменяется через `Services::injectMock()`, что позволяет
     * домен-сервисам работать с in-memory implementation вместо БД.
     *
     * @return \App\Repositories\Contracts\CharacterRepositoryInterface
     */
    public static function characterRepository($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('characterRepository');
        }
        return new \App\Repositories\CI4CharacterRepository();
    }

    /**
     * Phase B2 (ADR-023) — plugin-реестр handler-классов с auto-discovery.
     *
     * Использовать так:
     *   $registry = service('handlerRegistry');
     *   $entry    = $registry->getByKey('damage_health'); // ?HandlerEntry
     *
     * Дефолтные scan-пути покрывают Phase B2-B4:
     *   - App\Services\Events\Effects\  (B2 — event-effects, активно)
     *   - App\TaskHandlers\             (B3 — task-handlers, пока без атрибутов)
     *
     * В тестах подменяется через `Services::injectMock('handlerRegistry', $mock)`.
     */
    public static function handlerRegistry(bool $getShared = true): \App\Services\Handlers\HandlerRegistry
    {
        if ($getShared) {
            $shared = static::getSharedInstance('handlerRegistry');
            if ($shared instanceof \App\Services\Handlers\HandlerRegistry) {
                return $shared;
            }
        }
        return new \App\Services\Handlers\HandlerRegistry(
            namespacePrefixes: [
                'App\\Services\\Events\\Effects\\',
                'App\\TaskHandlers\\',
            ],
            scanner: new \App\Services\Handlers\HandlerDiscoveryScanner(),
        );
    }

}
