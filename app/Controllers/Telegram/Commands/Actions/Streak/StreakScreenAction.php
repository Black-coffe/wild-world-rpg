<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Streak;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\StreakMilestoneService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-132 Ф2 — экран «🔥 Серия выживания». Callback `streakScreen`.
 *
 * Лестница вех (3/5/7/10/15/30/50/100): для каждой — ✅ забрана / 🔒 (ещё N дней) + превью награды
 * (золото/ресурс/титул). Шапка — текущая серия. Вход — кнопка с хаба «📊 Прогресс»
 * ({@see \App\Services\Player\ProfileHubService::progressButtons}). Текстовый экран (media-off),
 * edit-in-place (ADR-018).
 *
 * Без inline-кнопок: навигация — постоянной reply-клавиатурой бота (Перс/База/Крафт/Карта/Настройки) —
 * как сиблинги AchievementsAction/CollectionsAction (memory feedback_no_duplicate_persistent_keyboard_buttons).
 */
final class StreakScreenAction extends BaseAction
{
    private StreakMilestoneService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new StreakMilestoneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if (! $this->service->enabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🔥 *Серия выживания временно недоступна*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId    = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $streakRaw = $character['login_streak'] ?? 0;
        $streak    = is_numeric($streakRaw) ? (int) $streakRaw : 0;

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'text'       => $this->buildText($charId, $streak),
            'parse_mode' => 'Markdown',
        ]);
    }

    private function buildText(int $charId, int $streak): string
    {
        $defs       = $this->service->definitions();
        $claimed    = $charId > 0 ? array_flip($this->service->claimedMilestoneIds($charId)) : [];
        $titleNames = $this->service->titleNameMap();

        $text  = "🔥 *Серия выживания*\n\n";
        $text .= "Заходи каждый день и что-нибудь делай (разведка, добыча, крафт, стройка) — серия растёт, "
            . "а награды на вехах становятся всё ценнее.\n\n";
        $text .= "🔥 *Текущая серия:* {$streak} " . self::plural($streak, 'день', 'дня', 'дней') . "\n";

        if ($defs === []) {
            return $text . "\n_Вехи пока не настроены._";
        }

        $text .= "\n*Вехи:*\n";
        foreach ($defs as $m) {
            $id      = is_numeric($m['id'] ?? null) ? (int) $m['id'] : 0;
            $th      = is_numeric($m['threshold_days'] ?? null) ? (int) $m['threshold_days'] : 0;
            $name    = is_string($m['name'] ?? null) ? $m['name'] : '';
            $icon    = is_string($m['icon'] ?? null) && $m['icon'] !== '' ? $m['icon'] : '🔥';
            $preview = $this->service->rewardPreview($m, $titleNames);

            if (isset($claimed[$id])) {
                $text .= "✅ {$icon} *{$name}* — {$th} " . self::plural($th, 'день', 'дня', 'дней');
            } else {
                $left  = max(0, $th - $streak);
                $text .= "🔒 {$icon} {$name} — {$th} " . self::plural($th, 'день', 'дня', 'дней') . " (ещё {$left})";
            }
            if ($preview !== '') {
                $text .= " · {$preview}";
            }
            $text .= "\n";
        }

        $text .= "\n_Награды выдаются автоматически при достижении вехи. Серия видна на карточке Перса._";

        return $text;
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }
        return $many;
    }
}
