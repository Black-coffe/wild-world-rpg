<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterFactionModel;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestOverviewService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E27 (ADR-126) — единый экран «📜 Задания»: дашборд-обзор ВСЕХ источников целей.
 *
 * Раньше был статичным меню без данных, а ежедневные задания висели ОТДЕЛЬНО (вход только
 * с карточки Перса) → игрок не видел картину целиком. Теперь хаб сводит счётчики через
 * {@see QuestOverviewService}: активные / доступные (+заблокированные цепочкой) / задания
 * дня / развилки W11 / завершённые + намёк на NPC-поручения, и добавляет недостающую
 * кнопку «🗓 Задания дня». Caption самодостаточен (media-off инвариант).
 */
class QuestAndTaskAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        return $this->executeWithCharacter(function ($user, $character): ServerResponse {
            $charId    = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
            $level     = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
            $factionId = (new CharacterFactionModel())->getFactionId($charId);

            $summary = (new QuestOverviewService())->summary($level, $charId, $factionId);

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            $imagePath = base_url('uploads/telegram/quests/Quests-and-Missions.jpg');

            // #12 edit-in-place (ADR-018): навигационный экран → редактируем сообщение
            // (fallback на новое при ошибке / клике с text-экрана).
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => $this->buildText($summary),
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($this->buildKeyboard($summary)),
            ]);
        });
    }

    /**
     * @param array{active:int, available:int, locked:int, completed:int, daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int}, branches:int, npc_hint:bool} $s
     */
    private function buildText(array $s): string
    {
        $text = "*📜 Задания* — все твои цели в одном месте.\n\n";

        // Развилка цепочки — необратимый выбор, показываем первой.
        if ($s['branches'] > 0) {
            $text .= "🔀 *Развилка цепочки ждёт выбор!* Открой «Доступные» — решение необратимо.\n\n";
        }

        $text .= "🚀 *Активные:* {$s['active']}\n";

        $avail = "📅 *Доступные:* {$s['available']}";
        if ($s['locked'] > 0) {
            $avail .= "  _(🔒 ещё {$s['locked']} по цепочке)_";
        }
        $text .= $avail . "\n";

        // Ежедневные задания — раньше прятались на карточке Перса.
        if ($s['daily']['enabled']) {
            if ($s['daily']['assigned'] && $s['daily']['total'] > 0) {
                $line = "🗓 *Задания дня:* {$s['daily']['done']}/{$s['daily']['total']}";
                $line .= $s['daily']['done'] >= $s['daily']['total'] ? ' ✅' : '';
                $text .= $line . "\n";
            } else {
                $text .= "🗓 *Задания дня:* открой — набор на сегодня уже ждёт\n";
            }
        }

        $text .= "🏅 *Завершено:* {$s['completed']}\n";

        if ($s['npc_hint']) {
            $text .= "\n📻 _У некоторых выживших в поселениях и в походе есть свои поручения — "
                . "заговори с ними (значок 📜)._";
        }

        $text .= "\n\nВыбери раздел 👇";

        return $text;
    }

    /**
     * @param array{active:int, available:int, locked:int, completed:int, daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int}, branches:int, npc_hint:bool} $s
     * @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}
     */
    private function buildKeyboard(array $s): array
    {
        $rows = [];

        $rows[] = [
            ['text' => "🚀 Активные ({$s['active']})", 'callback_data' => 'activeQuests'],
            ['text' => "📅 Доступные ({$s['available']})", 'callback_data' => 'availableQuests'],
        ];

        if ($s['daily']['enabled']) {
            $label = '🗓 Задания дня';
            if ($s['daily']['assigned'] && $s['daily']['total'] > 0) {
                $label .= " ({$s['daily']['done']}/{$s['daily']['total']})";
            }
            $rows[] = [['text' => $label, 'callback_data' => 'dailyTasks']];
        }

        $rows[] = [
            ['text' => '🏅 Завершённые', 'callback_data' => 'completedQuests'],
            ['text' => '🌐 Квестомания', 'callback_data' => 'questInfo'],
        ];

        $rows[] = [
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ];

        return ['inline_keyboard' => $rows];
    }
}
