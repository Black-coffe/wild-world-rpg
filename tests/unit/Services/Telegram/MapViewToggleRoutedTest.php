<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Вид карты мира (2026-07-22) — анти-мёртвая-кнопка + анти-возврат-к-слепой-команде.
 *
 * Живой сигнал из чата 2026-07-21: «режим карты хрен поменять уже, да?». Переключение
 * `preferred_map_type` жило ТОЛЬКО текстовыми командами `accurate_map`/`beautiful_map`,
 * о которых игрок узнавал единственный раз — на экране «вид не выбран», а тот после
 * первого выбора не показывается никогда. Классика BUILT-BUT-INVISIBLE (UX-DISCOVERABILITY).
 *
 * Тест держит три вещи, которые молча ломаются: callback зарегистрирован в роутере,
 * кнопка реально есть на экране настроек в КАЖДОМ из трёх состояний, и экран «вид не
 * выбран» не откатился к просьбе набирать команду руками.
 *
 * @internal
 */
final class MapViewToggleRoutedTest extends CIUnitTestCase
{
    private const CALLBACKS = ['mapAccurate', 'mapBeautiful'];

    private function settingsSource(): string
    {
        return (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/SettingsAction.php'
        );
    }

    /** Callback без записи в CallbackRoutes = кнопка, которая молча ничего не делает. */
    public function testCallbacksAreRoutedToSettingsAction(): void
    {
        $routes = (new \Config\CallbackRoutes())->exactRoutes;

        foreach (self::CALLBACKS as $cb) {
            $this->assertArrayHasKey(
                $cb,
                $routes,
                "Callback «{$cb}» не зарегистрирован в CallbackRoutes — кнопка смены вида карты мертва."
            );
            $this->assertSame(
                SettingsAction::class,
                $routes[$cb],
                "Callback «{$cb}» ведёт не в SettingsAction."
            );
        }
    }

    /** Обработчик обязан существовать — иначе роут есть, а ветки нет и тумблер молчит. */
    public function testSettingsActionHandlesBothCallbacks(): void
    {
        $src = $this->settingsSource();

        foreach (self::CALLBACKS as $cb) {
            $this->assertStringContainsString(
                "'" . $cb . "'",
                $src,
                "SettingsAction не упоминает «{$cb}» — ветка обработки потеряна."
            );
        }

        $this->assertStringContainsString(
            "'preferred_map_type'",
            $src,
            'SettingsAction больше не пишет preferred_map_type — тумблер перестал что-либо менять.'
        );
    }

    /**
     * Кнопка обязана быть видна в КАЖДОМ состоянии: не выбран (оба варианта),
     * точная (кнопка на художественную) и наоборот. Иначе вход снова исчезнет —
     * ровно тот сценарий, из-за которого игрок и не смог сменить вид.
     */
    public function testBothVariantsArePresentAsButtons(): void
    {
        $src = $this->settingsSource();

        foreach (self::CALLBACKS as $cb) {
            $this->assertStringContainsString(
                "'callback_data' => '" . $cb . "'",
                $src,
                "На экране настроек нет кнопки с callback_data «{$cb}»."
            );
        }

        $this->assertStringContainsString(
            'Вид карты мира',
            $src,
            'С экрана настроек пропал раздел «Вид карты мира».'
        );
    }

    /**
     * Экран «вид не выбран» (MapService) обязан давать КНОПКИ. Раньше он просил
     * скопировать и отправить текстовую команду — единственный источник знания о них.
     */
    public function testNotChosenScreenOffersButtonsNotTypedCommands(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Services/World/MapService.php');

        foreach (self::CALLBACKS as $cb) {
            $this->assertStringContainsString(
                "'callback_data' => '" . $cb . "'",
                $src,
                "Экран «вид карты не выбран» потерял кнопку «{$cb}» и снова требует ввода команды."
            );
        }

        $this->assertStringNotContainsString(
            'Скопируйте нужную команду',
            $src,
            'Экран «вид карты не выбран» вернулся к просьбе набирать команду руками.'
        );
    }

    /** Чистый парсер состояния — без БД. Мусор и NULL обязаны читаться как «не выбран». */
    public function testMapTypeOfNormalisesStoredValue(): void
    {
        $this->assertSame('accurate', SettingsAction::mapTypeOf(['preferred_map_type' => 'accurate']));
        $this->assertSame('beautiful', SettingsAction::mapTypeOf(['preferred_map_type' => 'BEAUTIFUL']));
        $this->assertSame('accurate', SettingsAction::mapTypeOf(['preferred_map_type' => ' accurate ']));
        $this->assertNull(SettingsAction::mapTypeOf(['preferred_map_type' => null]));
        $this->assertNull(SettingsAction::mapTypeOf(['preferred_map_type' => 'pixel']));
        $this->assertNull(SettingsAction::mapTypeOf([]));
        $this->assertNull(SettingsAction::mapTypeOf(null));
    }
}
