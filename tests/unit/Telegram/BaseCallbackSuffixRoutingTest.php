<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Controllers\Telegram\Commands\SystemCommands\CallbackqueryCommand;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Navigation\NavigationMapService;
use App\Services\Telegram\CallbackRouter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * story multibase-picker-01 — суффикс базы `_b<claimed_cells.id>` в `callback_data`.
 *
 * Кодек: `append()/split()` по контракту plan.md, лимит 64 байта.
 * Маршрутизация: суффиксный callback проходит ровно через те же слои
 * `CallbackqueryCommand` (wildcard → prefix-dispatcher → exact/prefix по первому
 * сегменту), что и без суффикса, и приходит в тот же класс-обработчик. Слои
 * опрашиваются реальными функциями роутинга (`CallbackRouter::resolve`,
 * префиксы диспетчера из синхронизированного зеркала, `CallbackqueryCommand::actionOf`,
 * `CallbackRoutes::resolve`), без копии их логики в тесте. Полный `callback_data`
 * гарантирован тем, что каждый слой передаёт обработчику исходный `CallbackQuery`.
 *
 * @internal
 */
final class BaseCallbackSuffixRoutingTest extends CIUnitTestCase
{
    public function testAppendAddsSuffix(): void
    {
        $this->assertSame('building_12_Warehouse_b345', BaseCallbackSuffix::append('building_12_Warehouse', 345));
        $this->assertSame('hangar_b345', BaseCallbackSuffix::append('hangar', 345));
    }

    public function testSplitRoundTrip(): void
    {
        $this->assertSame(['building_12_Warehouse', 345], BaseCallbackSuffix::split('building_12_Warehouse_b345'));
        $this->assertSame(['upgrade_building_12', 7], BaseCallbackSuffix::split('upgrade_building_12_b7'));
        $this->assertSame(['Base', 1], BaseCallbackSuffix::split('Base_b1'));
    }

    public function testSplitWithoutSuffixReturnsNull(): void
    {
        $this->assertSame(['building_12_Warehouse', null], BaseCallbackSuffix::split('building_12_Warehouse'));
        $this->assertSame(['hangar', null], BaseCallbackSuffix::split('hangar'));
        // `_b` без цифр в хвосте и `b` без подчёркивания — не суффикс.
        $this->assertSame(['hangar_b', null], BaseCallbackSuffix::split('hangar_b'));
        $this->assertSame(['building_12_Lab5', null], BaseCallbackSuffix::split('building_12_Lab5'));
    }

    public function testAppendAtExactly64BytesIsAllowed(): void
    {
        $base = str_repeat('x', 64 - strlen('_b123'));
        $this->assertSame(64, strlen(BaseCallbackSuffix::append($base, 123)));
    }

    public function testAppendOver64BytesThrows(): void
    {
        $this->expectException(\LengthException::class);
        BaseCallbackSuffix::append(str_repeat('x', 64 - strlen('_b123') + 1), 123);
    }

    /**
     * Суффиксные формы из `## Contracts` plan.md — каждая рядом со своей формой без суффикса.
     *
     * @return array<string, array{0: string}>
     */
    public static function suffixedCallbacks(): array
    {
        return [
            'building card'      => ['building_12_Warehouse'],
            'upgrade ask'        => ['upgrade_building_12'],
            'upgrade confirm'    => ['confirm_upgrade_building_12'],
            'hangar'             => ['hangar'],
            'base screen'        => ['Base'],
            'construction'       => ['construction'],
            'campDecor'          => ['campDecor'],
            'baseDevelopment'    => ['baseDevelopment'],
        ];
    }

    /**
     * @dataProvider suffixedCallbacks
     */
    public function testSuffixedCallbackRoutesToSameHandler(string $plain): void
    {
        $suffixed = BaseCallbackSuffix::append($plain, 345);

        $plainRoute    = $this->route($plain);
        $suffixedRoute = $this->route($suffixed);

        $this->assertNotNull($plainRoute, "Без суффикса '{$plain}' должен маршрутизироваться (предпосылка теста).");
        $this->assertSame($plainRoute, $suffixedRoute, "'{$suffixed}' должен дойти до того же обработчика, что '{$plain}'.");
    }

    public function testNoExistingRouteEndsWithBaseSuffix(): void
    {
        $routes = new CallbackRoutes();
        $keys   = array_merge(
            array_keys($routes->exactRoutes),
            array_keys($routes->prefixRoutes),
            array_keys($routes->wildcardRoutes),
            $this->dispatcherPrefixes()
        );

        foreach ($keys as $key) {
            $this->assertDoesNotMatchRegularExpression('/_b\d+$/', (string) $key, "Маршрут '{$key}' кончается на _b<цифры> — конфликт с суффиксом базы.");
        }
    }

    /**
     * Какой слой и какой обработчик `CallbackqueryCommand::execute()` выберет для данных —
     * в том же порядке слоёв (слой 1, `character`, суффиксом базы не пользуется).
     */
    private function route(string $callbackData): ?string
    {
        $routes = new CallbackRoutes();

        $wildcard = (new CallbackRouter())->registerMany($routes->wildcardRoutes)->resolve($callbackData);
        if ($wildcard !== null) {
            return 'wildcard:' . $wildcard;
        }

        foreach ($this->dispatcherPrefixes() as $prefix) {
            if (str_starts_with($callbackData, $prefix)) {
                return 'dispatcher:' . $prefix;
            }
        }

        $exact = $routes->resolve(CallbackqueryCommand::actionOf($callbackData));

        return $exact === null ? null : 'exact:' . $exact;
    }

    /**
     * Префиксы `CallbackPrefixDispatcher::tryDispatch()` в порядке проверки — из зеркала
     * `NavigationMapService::PREFIX_DISPATCHER`, которое `PrefixDispatcherMirrorSyncTest`
     * держит 1:1 с исходником диспетчера.
     *
     * @return list<string>
     */
    private function dispatcherPrefixes(): array
    {
        $mirror = (new \ReflectionClass(NavigationMapService::class))->getConstant('PREFIX_DISPATCHER');
        $this->assertIsArray($mirror);

        return array_values(array_map('strval', array_keys($mirror)));
    }
}
