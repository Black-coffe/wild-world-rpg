<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Events\EventsModelService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Экран «🎉 События» (callback `events`).
 *
 * Показывает игроку ДВА блока (задача «видимость событий», 2026-06-20 — репорт
 * SarCasM: «событие нет, а урон есть» + Ivan «думал у всех, а оно по локациям»):
 *
 *   1) 🌟 Что происходит ПРЯМО СЕЙЧАС — активные события (`status='active'`) с тем,
 *      где они идут, какой эффект, сколько осталось, и коснулось ли они ИГРОКА.
 *   2) 📜 ИСТОРИЯ — последние 3 завершённых события (`status='completed'`) с началом,
 *      концом и длительностью. История даёт игроку контекст: «Метеоритный дождь шёл
 *      17:50–18:13, тебя задело» — и снимает путаницу, когда событие закончилось
 *      минуту назад, а урон ещё сказывается на здоровье.
 *
 * w2-n5-deeds-02 (ADR-190): данные — {@see EventsModelService::model()} (ядро, общее с вебом);
 * здесь только рендер Markdown с прежним текстом.
 *
 * 🖼 MEDIA-OFF (ADR-020): экран чисто текстовый (фото не шлёт) — disable_media не влияет.
 */
class EventAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $charId = isset($character['id']) && is_numeric($character['id']) ? (int) $character['id'] : 0;
        $model  = (new EventsModelService())->model($charId);

        $text  = $this->buildCurrentSection($model['active']);
        $text .= $this->buildHistorySection($model['past']);

        $keyboard = $this->getKeyboard();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Блок «что сейчас». Либо список активных событий, либо «активных нет».
     *
     * @param list<array{name: string, description: string, where_ru: string, effect_ru: string, biomes: list<string>, time_left: string, touched: bool}> $activeEvents
     */
    private function buildCurrentSection(array $activeEvents): string
    {
        if (empty($activeEvents)) {
            return "🎉 *Сейчас активных событий нет.*\n"
                . "Исследуй мир и готовься к новым приключениям.\n\n";
        }

        $text = "🌟 *Сейчас в мире происходят события:* 🌟\n\n";
        $num  = 0;
        foreach ($activeEvents as $event) {
            $num++;
            $text .= "№{$num} *{$event['name']}*\n"
                . "📜 _{$event['description']}_\n"
                . "🌍 *Где:* _{$event['where_ru']}_\n"
                . $this->biomesLine($event['biomes'])
                . "🚀 *Эффект:* _{$event['effect_ru']}_\n"
                . "⏳ *Закончится через:* _{$event['time_left']}_\n"
                . ($event['touched'] ? "🎯 _Тебя уже коснулось._\n" : "")
                . "\n";
        }

        return $text;
    }

    /**
     * Блок «история» — последние завершённые события с началом/концом/длительностью.
     *
     * @param list<array{name: string, start_ru: string, end_ru: string, duration: string, biomes: list<string>, touched: bool}> $pastEvents
     */
    private function buildHistorySection(array $pastEvents): string
    {
        if (empty($pastEvents)) {
            return '';
        }

        $text = "━━━━━━━━━━━━\n"
            . "📜 *Последние прошедшие события:*\n\n";

        foreach ($pastEvents as $event) {
            $text .= "▫️ *{$event['name']}*\n"
                . "🕘 Начало: _{$event['start_ru']}_\n"
                . "🏁 Конец: _{$event['end_ru']}_\n"
                . "⏱ Длилось: _{$event['duration']}_\n"
                . $this->biomesLine($event['biomes'])
                . ($event['touched'] ? "🎯 _Тебя задело._\n" : "🟢 _Тебя не коснулось._\n")
                . "\n";
        }

        return $text;
    }

    /**
     * Строка «🌱 Биомы: …». Пусто, если биомов нет.
     *
     * @param list<string> $names
     */
    private function biomesLine(array $names): string
    {
        return empty($names) ? '' : "🌱 *Биомы:* " . implode(', ', $names) . "\n";
    }

    /**
     * ADR-150 (чистка дублей). 🔴 Здесь была кнопка «🎉 События» → callback `events`, то есть
     * ссылка экрана на самого себя: игрок «переходил» туда, где уже стоит. Плюс суп из чужих
     * групп. Теперь — честный выход: идти в мир или действовать на клетке.
     * Канонический вход на этот экран — кнопка «🎉 События» на экране «🌍 Мир».
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    protected function getKeyboard(): array
    {
        if (\App\Services\Telegram\NavKeyboards::simplified()) {
            return \App\Services\Telegram\NavKeyboards::whatNextWith();
        }

        return [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
    }

}
