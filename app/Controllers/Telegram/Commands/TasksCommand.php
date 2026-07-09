<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * `/tasks` — «📋 Дела»: что идёт прямо сейчас + полярная звезда + квесты + задания дня.
 *
 * ADR-150 ФИНАЛ. Раньше здесь жил собственный легаси-рендер списка активных задач — третья
 * копия одной и той же поверхности. Он умер вместе с активацией `navigation.tasks_hub`
 * и удалён: единственный рендер — {@see \App\Services\Tasks\TasksSurfaceService}, общий с
 * нижней кнопкой «📋 Дела» и callback `tasksHub`.
 *
 * 🔴 Вместе с ним удалена и его ложь: легаси-текст обещал «*Нажмите на цифру, чтобы моментально
 * снять задачу!*», хотя {@see Actions\FinishTaskAction} ставит `status='interrupted'`, отнимает
 * характеристики и ТЕРЯЕТ награду. Новая поверхность честно называет кнопку «⛔️ Прервать».
 */
class TasksCommand extends UserCommand
{
    protected $name        = 'tasks';
    protected $description = 'Displays a list of all active tasks for your character.';
    protected $usage       = '/tasks';
    protected $version     = '2.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openTasks(
            (int) $message->getChat()->getId(),
            (int) $message->getFrom()->getId()
        );
    }
}
