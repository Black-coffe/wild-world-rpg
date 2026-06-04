<?php

namespace App\TaskHandlers\Other;

use App\Attributes\HandlerKey;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Libraries\TelegramMessages;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.42 (F2.9 batch-7 final): extends BaseTaskHandler. Drop manual Telegram
 * init. process() → handle(array $task = []): void. Drop **broken** `Request::
 * answerCallbackQuery(['callback_query_id' => $telegramId])` (chat_id passed
 * as callback_query_id — fires daily silently без value у cron context).
 * Request::sendMessage → safeSendMessage.
 *
 * ADR-097 — подталкивание выбора фракции. Диагноз: осведомлённость уже максимальна
 * (невыбравшие получали до 288 нагов), но конверсия 15/440 → рычаг НЕ громкость, а
 * убедительность + анти-спам. Изменения: (1) сообщение-заглушка «…(и т.д.)» заменено
 * богатым value-prop (эндгейм-ценность фракции + «подъёмные»-стимул); (2) потолок и
 * каданс нагов вынесены в GameSettings (faction.notification.max_reminders /
 * interval_hours) — крон перестаёт спамить после N напоминаний. Discoverability дальше
 * держат кнопка «⚑ Выбрать фракцию» (экран Персонажа) + tips.
 */
#[HandlerKey(
    key: 'faction_notification',
    displayName: 'Уведомления о фракциях',
    description: 'Recurring (Tasks.php every minute): уведомляет персонажей о возможности выбрать фракцию при достижении условий (с потолком/кадансом ADR-097).',
)]
class FactionNotificationHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    protected $characterModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    protected $telegramMessages;

    public function __construct()
    {
        $this->telegramMessages = new TelegramMessages();
        $this->characterModel = new CharacterModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature.
     */
    public function handle(array $task = []): void
    {
        $maxReminders  = $this->gsInt('faction.notification.max_reminders', 5);
        $intervalHours = $this->gsInt('faction.notification.interval_hours', 24);
        $now           = time();

        // Персонажи уровня 10+ — кандидаты на уведомление о выборе фракции.
        $characters = $this->characterModel->where('level >=', 10)->findAll();

        foreach ($characters as $character) {
            $factionEntry = $this->characterFactionModel->where('character_id', $character['id'])->first();

            if (!$factionEntry) {
                // Записи нет → первое уведомление (создаст запись с faction_id=5, count=1).
                $this->sendFactionNotification($character);
                continue;
            }

            // Запись есть. Напоминаем только пока фракция не выбрана (status='False')
            // и пока не превышен потолок/каданс ADR-097.
            if (($factionEntry['notification_status'] ?? null) !== 'False') {
                continue;
            }
            $count      = is_numeric($factionEntry['notification_count'] ?? null) ? (int) $factionEntry['notification_count'] : 0;
            $notifiedAt = is_string($factionEntry['notified_at'] ?? null) ? $factionEntry['notified_at'] : null;
            if (self::shouldRemind($count, $notifiedAt, $now, $maxReminders, $intervalHours)) {
                $this->sendFactionNotification($character, $factionEntry);
            }
        }
    }

    /**
     * Чистое решение «слать ли повторное напоминание» (анти-спам ADR-097) — без БД/Telegram,
     * юнит-тестируемо.
     *
     * @param int         $count         текущий notification_count (сколько раз уже слали)
     * @param string|null $notifiedAt    метка последнего уведомления (Y-m-d H:i:s) или null
     * @param int         $now           текущий unix-time
     * @param int         $maxReminders  потолок (0 = без потолка)
     * @param int         $intervalHours минимальный интервал между повторами, часы
     */
    public static function shouldRemind(int $count, ?string $notifiedAt, int $now, int $maxReminders, int $intervalHours): bool
    {
        // Потолок: 0/отрицательное = без потолка; иначе шлём пока count < max.
        $capOk = $maxReminders <= 0 || $count < $maxReminders;
        if (!$capOk) {
            return false;
        }

        // Интервал: с последнего уведомления должно пройти >= intervalHours.
        $last = ($notifiedAt !== null && $notifiedAt !== '') ? strtotime($notifiedAt) : false;
        if ($last === false) {
            return true; // нет валидной метки — напоминаем
        }
        $intervalSec = max(1, $intervalHours) * 3600;

        return ($now - $last) >= $intervalSec;
    }

    public function sendFactionNotification($character, $factionEntry = null): void
    {
        $telegramId = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first()['telegram_id'];

        // Если запись о фракции уже есть (но notified_at нужно обновить)
        if ($factionEntry) {
            $this->characterFactionModel->update($factionEntry['id'], [
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_count' => $factionEntry['notification_count'] + 1
            ]);
        } else {
            // ИНАЧЕ создаём запись з faction_id=5 (нейтральная фракция)
            $this->characterFactionModel->insert([
                'character_id' => $character['id'],
                'faction_id' => 5,
                'joined_at' => null,
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_status' => 'False',
                'notification_count' => 1
            ]);
        }

        $message = $this->buildNotificationMessage();

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡️ Милитари',  'callback_data' => 'chooseFaction_Military'],
                    ['text' => '🌲 Партизаны', 'callback_data' => 'chooseFaction_Partisans'],
                ],
                [
                    ['text' => '🛠️ Инженеры',  'callback_data' => 'chooseFaction_Engineers'],
                    ['text' => '🌾 Фермеры',   'callback_data' => 'chooseFaction_Farmers'],
                ],
            ]
        ];

        $this->safeSendMessage(
            $telegramId,
            $message,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }

    /**
     * Богатое value-prop сообщение (ADR-097) — конкретная эндгейм-ценность фракции +
     * «подъёмные»-стимул (если включён). Самодостаточно текстом (media-off N/A — это
     * текстовое уведомление).
     */
    private function buildNotificationMessage(): string
    {
        $incentiveLine = '';
        if ($this->gsBool('faction.choice.incentive_enabled', true)) {
            $gold = $this->gsInt('faction.choice.incentive_gold', 1000);
            if ($gold > 0) {
                $incentiveLine = "\n💰 *Подъёмные за присягу: +" . number_format($gold) . "💰* — единоразово, сразу при выборе.\n";
            }
        }

        return "*🎉 Уровень 10 — пустошь открывает фракции.*\n\n"
            . "Четыре силы делят эти земли. Вступи в одну — получишь доступ к её эндгейму:\n"
            . "🛡️ *Милитари* — тяжёлое оружие и захват точек\n"
            . "🌲 *Партизаны* — скрытность, засады, мобильность\n"
            . "🛠️ *Инженеры* — роботы и продвинутая электроника\n"
            . "🌾 *Фермеры* — еда, экономика, снабжение\n\n"
            . "Членство открывает: *легендарное оружие и броню* своей фракции, общий *крафт-проект* (−10% к крафту всем своим на время) и *скидки у караванов-союзников*.\n"
            . $incentiveLine
            . "\n⚠️ Выбор окончателен до вайпа персонажа. Реши, на чьей ты стороне — кнопки ниже.";
    }
}
