<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Quest\DailyTaskCatalog;
use App\Services\Quest\DailyTaskService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-109 (E8) Ф2 — экран «🗓 Задания дня».
 *
 * Точка входа в дейлики из карточки Персонажа (callback `dailyTasks`). Ленивая генерация
 * набора за сегодня (ensureAssigned — идемпотентно), затем рендер 3 заданий с прогрессом
 * (current − baseline, cap qty), наградами и бонусом за все 3. Экран самодостаточен в тексте
 * (media-off инвариант): несёт весь смысл словами, картинки нет.
 *
 * Прогресс СЧИТАЕТСЯ по монотонным счётчикам (DailyTaskService), завершение/выдача золота —
 * фоном в DailyTaskProgressHandler (everyMinute). Экран — read-only витрина статуса.
 */
final class DailyTasksAction extends BaseAction
{
    private DailyTaskService $daily;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->daily = new DailyTaskService();
    }

    public function handle(): ServerResponse
    {
        return $this->executeWithCharacter(function ($user, $character): ServerResponse {
            $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

            // Killswitch OFF → фича dormant. Кнопка обычно не показывается при OFF, но
            // защищаемся от прямого callback (устаревшее сообщение / ручной вызов).
            if (! $this->daily->enabled()) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $this->callbackQuery->getId(),
                    'text'              => 'Ежедневные задания сейчас недоступны.',
                    'show_alert'        => true,
                ]);
            }

            // Ленивое назначение набора за сегодня (идемпотентно). Дублирует webhook-хук —
            // подстраховка, если игрок попал на экран до фонового назначения.
            $this->daily->ensureAssigned($character);

            $charId = (int) ($character['id'] ?? 0);
            $tasks  = $this->daily->today($charId);

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return Request::sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => $this->buildText($tasks),
                'parse_mode'               => 'Markdown',
                'disable_web_page_preview' => true,
                'reply_markup'             => json_encode($this->buildKeyboard()),
            ]);
        });
    }

    /**
     * @param list<array{id:int, slot:int, task_key:string, title:string, verb:string, objective_qty:int, progress:int, is_completed:bool, reward_gold:int}> $tasks
     */
    private function buildText(array $tasks): string
    {
        $today = date('d.m.Y');
        $text  = "🗓 *Задания дня* — {$today}\n\n";

        if ($tasks === []) {
            return $text
                . "На сегодня заданий пока нет — загляни чуть позже.\n\n"
                . "_Каждый день пустошь подкидывает короткий список целей: разведка, крафт, "
                . "торговля, охота, бой. Награда — золото, а за полный набор ещё и бонус._";
        }

        $done = 0;
        foreach ($tasks as $t) {
            $mark = $t['is_completed'] ? '✅' : '▫️';
            $bar  = $this->progressBar($t['progress'], $t['objective_qty']);
            $text .= "{$mark} *{$t['title']}*\n";
            $text .= "    {$t['verb']}: `{$t['progress']}/{$t['objective_qty']}` {$bar}\n";
            $text .= "    🏆 Награда: *{$t['reward_gold']}* золота"
                . ($t['is_completed'] ? ' — _получено_' : '') . "\n\n";
            if ($t['is_completed']) {
                $done++;
            }
        }

        $total = count($tasks);
        $text .= "Прогресс набора: *{$done}/{$total}*\n";

        $bonus = $this->daily->allDoneBonusGold();
        if ($bonus > 0) {
            $text .= $done >= $total
                ? "🎉 Набор закрыт — бонус *+{$bonus}* золота начислен!\n"
                : "💎 За все {$total} заданий — бонус *+{$bonus}* золота.\n";
        }

        $text .= "\n_Прогресс засчитывается автоматически по ходу игры — разведывай, крафти, "
            . "торгуй и сражайся. Награда придёт отдельным сообщением._";

        return $text;
    }

    /** Текстовый прогресс-бар из 5 делений (media-off самодостаточен). */
    private function progressBar(int $progress, int $qty): string
    {
        if ($qty <= 0) {
            return '';
        }
        $slots  = 5;
        $filled = (int) round(min(1.0, $progress / $qty) * $slots);

        return str_repeat('▰', $filled) . str_repeat('▱', $slots - $filled);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}
     */
    private function buildKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Обновить', 'callback_data' => 'dailyTasks'],
                ],
                [
                    ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ],
            ],
        ];
    }
}
