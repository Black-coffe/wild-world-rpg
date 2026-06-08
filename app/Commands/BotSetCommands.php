<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Telegram\BotMenuService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * ADR-103 Часть A — регистрация `/`-меню бота (setMyCommands) под версионным контролем.
 *
 * Запуск: `php spark bot:setcommands` (после деплоя, разово при изменении меню).
 *
 * До ADR-103 список команд бота настраивался вручную через BotFather (в репо не было
 * `setMyCommands` вообще) и содержал лишь 3 пункта (/start, /tasks, /tips). Игрок,
 * потерявший reply-клавиатуру, не находил пути к базе/крафту. Теперь источник истины —
 * {@see BotMenuService::commandList()}, а это — деплой-шаг, переустанавливающий меню
 * из кода. `/`-меню (гамбургер у поля ввода) нельзя свернуть → вечный запасной путь.
 */
class BotSetCommands extends BaseCommand
{
    protected $group       = 'Telegram';
    protected $name        = 'bot:setcommands';
    protected $description = 'Регистрирует /-меню бота (setMyCommands) из BotMenuService::commandList().';
    protected $usage       = 'bot:setcommands [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Только показать список команд, без отправки в Telegram',
    ];

    public function run(array $params): void
    {
        $commands = BotMenuService::commandList();

        CLI::write('Команды для регистрации (' . count($commands) . '):', 'yellow');
        foreach ($commands as $cmd) {
            CLI::write("  /{$cmd['command']} — {$cmd['description']}");
        }

        if ((bool) CLI::getOption('dry-run')) {
            CLI::write('--dry-run: в Telegram ничего не отправлено.', 'cyan');

            return;
        }

        $apiKey      = (string) getenv('telegram.API_KEY');
        $botUsername = (string) getenv('telegram.BOT_USERNAME');
        if ($apiKey === '' || $botUsername === '') {
            CLI::error('Не заданы telegram.API_KEY / telegram.BOT_USERNAME в .env.');

            return;
        }

        try {
            // Инициализация статического Telegram-контекста для Request::*.
            new Telegram($apiKey, $botUsername);

            $response = Request::setMyCommands(['commands' => $commands]);

            if ($response->isOk()) {
                CLI::write('✓ /-меню бота успешно зарегистрировано.', 'green');
                log_message('info', '[bot:setcommands] setMyCommands OK (' . count($commands) . ' команд)');
            } else {
                CLI::error('Telegram вернул ошибку: ' . $response->getDescription());
            }
        } catch (TelegramException $e) {
            CLI::error('TelegramException: ' . $e->getMessage());
        }
    }
}
