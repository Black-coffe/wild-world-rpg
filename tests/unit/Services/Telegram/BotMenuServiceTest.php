<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\BotMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\Keyboard;

/**
 * ADR-103 Часть A — контракт навигационного меню бота.
 *
 * Гарантирует, что `/`-меню (setMyCommands) валидно по правилам Telegram и что КАЖДАЯ
 * заявленная команда реально существует классом-обработчиком (анти-дрифт: setMyCommands
 * не должен рекламировать команду-пустышку).
 *
 * @internal
 */
final class BotMenuServiceTest extends CIUnitTestCase
{
    /** Telegram: имя команды — строчная латиница/цифры/подчёркивание, 1..32 символа. */
    public function testCommandNamesAreTelegramValid(): void
    {
        foreach (BotMenuService::commandList() as $cmd) {
            $name = $cmd['command'];
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_]{1,32}$/',
                $name,
                "Команда '{$name}' нарушает формат Telegram (a-z0-9_, ≤32).",
            );
            $this->assertNotSame('', trim($cmd['description']), "У команды '{$name}' пустое описание.");
            $this->assertLessThanOrEqual(256, mb_strlen($cmd['description']), "Описание '{$name}' длиннее 256.");
        }
    }

    /** Имена команд уникальны (Telegram отклонит дубликаты). */
    public function testCommandNamesAreUnique(): void
    {
        $names = array_column(BotMenuService::commandList(), 'command');
        $this->assertSame(array_values(array_unique($names)), $names, 'Дубликаты имён команд в меню.');
    }

    /** Критичные навигационные команды (боль m8k2b) обязаны быть в меню. */
    public function testCriticalNavigationCommandsPresent(): void
    {
        $names = array_column(BotMenuService::commandList(), 'command');
        foreach (['start', 'menu', 'me', 'base', 'craft', 'map'] as $required) {
            $this->assertContains($required, $names, "В /-меню отсутствует критичная команда /{$required}.");
        }
    }

    /**
     * Анти-дрифт: каждая команда меню имеет класс-обработчик с таким же $name.
     * Иначе setMyCommands рекламирует несуществующую команду → тупик у игрока.
     */
    public function testEveryMenuCommandHasHandlerClass(): void
    {
        $registered = $this->registeredCommandNames();

        foreach (BotMenuService::commandList() as $cmd) {
            $this->assertContains(
                $cmd['command'],
                $registered,
                "Команда /{$cmd['command']} есть в меню, но нет класса-обработчика с \$name='{$cmd['command']}'.",
            );
        }
    }

    public function testMainReplyKeyboardStructure(): void
    {
        $kb = BotMenuService::mainReplyKeyboard();
        $this->assertInstanceOf(Keyboard::class, $kb);

        $raw = json_decode((string) json_encode($kb->jsonSerialize()), true);
        $this->assertIsArray($raw);
        $this->assertArrayHasKey('keyboard', $raw);

        // Плоский список подписей кнопок.
        $labels = [];
        foreach ($raw['keyboard'] as $row) {
            foreach ($row as $btn) {
                $labels[] = is_array($btn) ? ($btn['text'] ?? '') : $btn;
            }
        }
        foreach (['Перс', 'База', 'Крафт', 'Карта', 'Настройки'] as $expected) {
            $this->assertContains($expected, $labels, "В нижнем меню нет кнопки «{$expected}».");
        }
    }

    /**
     * Сканирует классы команд бота, собирая объявленные `protected $name = '...'`.
     *
     * @return list<string>
     */
    private function registeredCommandNames(): array
    {
        $dir   = APPPATH . 'Controllers/Telegram/Commands';
        $names = [];

        foreach ([$dir, $dir . '/SystemCommands'] as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $src = (string) file_get_contents($file);
                if (preg_match('/protected\s+\$name\s*=\s*\'([^\']+)\'/', $src, $m) === 1) {
                    $names[] = $m[1];
                }
            }
        }

        return $names;
    }
}
