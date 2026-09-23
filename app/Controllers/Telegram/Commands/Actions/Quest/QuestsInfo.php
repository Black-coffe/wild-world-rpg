<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\QuestModel;
use App\Models\CharacterModel;
use App\Services\Display\MarkdownSafe;

class QuestsInfo extends BaseAction
{
    private const PREFIX = 'questInfo_';

    /** Подписи типов награды — те же, что в ActiveQuests/AvailableQuests. */
    private const REWARD_TYPES = [
        'gold'       => 'золото',
        'experience' => 'опыт',
        'items'      => 'предметы',
    ];

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Создаем модели квестов и персонажей
        $questModel = new QuestModel();
        $characterModel = new CharacterModel();

        // Получаем информацию о персонаже по его chat_id
        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // bugs-info-0923-03: карточка ЛЮБОГО квеста строится из строки `quests`.
        // Кнопка `questInfo_id<id>` (новая) или `questInfo_<title_en>` (старые сообщения).
        $tail = self::cardTail((string) $this->callbackQuery->getData());
        if ($tail !== null) {
            $card = self::buildCard($tail, $questModel);

            return $this->sendTelegramMessage($chatId, $card['text'], $card['keyboard']);
        }

        // Получаем список всех квестов
        $quests = $questModel->findAll();

        if (empty($quests)) {
            $text = "В игре отсутствуют квесты. Проверьте позже!";
        } else {
            // Формируем текст приветствия и краткого описания квестов
            $text = "*📜 Список всех квестов:*\n\n";
            foreach ($quests as $quest) {
                $text .= "🔹 {$quest['title_ru']}\n";
            }
            $text .= "\n_Выберите квест 👇, чтобы получить подробную информацию_";
        }
        $keyboard = self::generateQuestKeyboard($quests);
        // Отправляем сообщение с информацией о квестах
        return $this->sendTelegramMessage($chatId, $text, $keyboard);
    }

    /**
     * @param array<mixed> $quests
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function generateQuestKeyboard(array $quests): array
    {
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($quests as $quest) {
            if (! is_array($quest)) {
                continue;
            }
            $row[] = [
                'text'          => self::str($quest['title_ru'] ?? ''),
                'callback_data' => self::PREFIX . 'id' . self::int($quest['id'] ?? 0),
            ];
            if (count($row) == 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }

        // Add standard buttons
        $keyboard['inline_keyboard'][] = [
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        return $keyboard;
    }

    // Универсальный метод отправки сообщения в чат.
    // #12 edit-in-place (ADR-018): список квестов / карточка квеста — навигация → редактируем
    // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
    // $chatId сохранён в сигнатуре для совместимости с вызовами, но chat_id берётся из navTarget().
    private function sendTelegramMessage($chatId, $text, $keyboard = null)
    {
        if (!$keyboard) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                        ['text' => '◀️ Я', 'callback_data' => 'character']
                    ],
                ]
            ];
        }

        // Остановка анимации загрузки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Хвост callback'а после ПЕРВОГО `questInfo_` целиком (title_en может содержать `_`).
     * null — это не кнопка карточки (голый `questInfo` → список).
     */
    public static function cardTail(string $callbackData): ?string
    {
        if (! str_starts_with($callbackData, self::PREFIX)) {
            return null;
        }
        $tail = substr($callbackData, strlen(self::PREFIX));

        return $tail === '' ? null : $tail;
    }

    /**
     * Карточка квеста по хвосту callback'а: `id<N>` — по id, иначе — по title_en.
     * Неизвестный квест → честный отказ с кнопкой «назад к списку».
     *
     * @return array{text: string, keyboard: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    public static function buildCard(string $tail, QuestModel $questModel): array
    {
        $keyboard = ['inline_keyboard' => [[
            ['text' => '📜 К списку квестов', 'callback_data' => 'questInfo'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ]]];

        $quest = self::findQuest($tail, $questModel);
        if ($quest === null) {
            return [
                'text'     => "❓ Такой квест не найден — возможно, его убрали из игры.\n\nВернитесь к списку квестов 👇",
                'keyboard' => $keyboard,
            ];
        }

        $title = MarkdownSafe::name(self::str($quest['title_ru'] ?? ''), 'Квест');
        $text  = "*📜 {$title}*\n\n";

        $description = trim(MarkdownSafe::text(self::str($quest['description'] ?? '')));
        if ($description !== '') {
            $text .= "*Описание*\n{$description}\n\n";
        }

        $minLevel = $quest['min_level'] ?? null;
        $text .= $minLevel !== null
            ? '🔒 *Условия*: доступен с ' . self::int($minLevel) . "-го уровня персонажа.\n"
            : "🔒 *Условия*: без ограничения по уровню.\n";

        $prereq = trim(self::str($quest['prerequisite_quest'] ?? ''));
        if ($prereq !== '') {
            $prev      = $questModel->where('title_en', $prereq)->orderBy('id', 'ASC')->first();
            $prevTitle = is_array($prev) ? self::str($prev['title_ru'] ?? $prereq) : $prereq;
            $text     .= '⛓ *Сначала завершите*: ' . MarkdownSafe::name($prevTitle, 'предыдущий квест') . "\n";
        }

        $text .= "\n🏆 *Награда*: " . self::rewardLine($quest);

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * @return array<mixed>|null
     */
    private static function findQuest(string $tail, QuestModel $questModel): ?array
    {
        if (preg_match('/^id(\d+)$/', $tail, $m) === 1) {
            $row = $questModel->find((int) $m[1]);
        } else {
            $row = $questModel->where('title_en', $tail)->orderBy('id', 'ASC')->first();
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<mixed> $quest
     */
    private static function rewardLine(array $quest): string
    {
        $reward = $quest['reward'] ?? null;
        $type   = self::str($quest['reward_type'] ?? '');
        $label  = self::REWARD_TYPES[$type] ?? MarkdownSafe::text($type);

        if ($reward === null || $reward === '') {
            return $label !== '' ? $label : 'не указана';
        }

        return trim(number_format(self::int($reward), 0, '', ' ') . ' ' . $label);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
