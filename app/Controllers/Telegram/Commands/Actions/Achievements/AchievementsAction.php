<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Achievements;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\AchievementService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W10 (ADR-066) — экран «🏅 Достижения». Callback `achievements`.
 *
 * Список открытых (✅ + очки) и закрытых (🔒 + прогресс X/Y) достижений, сгруппированных
 * по категориям, + сводка (открыто N/всего, сумма очков). Вход — кнопка с карточки Перс
 * (видна при killswitch on). Текстовый экран, edit-in-place (ADR-018).
 *
 * Без inline-кнопок: навигация — постоянной reply-клавиатурой бота (Перс/База/Крафт/Карта/
 * Настройки, StartCommand:40). Не дублируем (memory feedback_no_duplicate_persistent_keyboard_buttons).
 */
final class AchievementsAction extends BaseAction
{
    private const CATEGORY_LABELS = [
        'progression' => '📈 Прогресс',
        'exploration' => '🧭 Разведка',
        'survival'    => '🛡 Выживание',
        'combat'      => '⚔️ Бой',
        'crafting'    => '🔨 Крафт',
        'building'    => '🏗 Стройка',
        'economy'     => '💰 Экономика',
        'trade'       => '🤝 Торговля',
        'social'      => '🏳️ Фракции',
    ];

    private AchievementService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new AchievementService();
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

        if (! $this->service->isEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🏅 *Достижения временно недоступны*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $definitions = $this->service->definitions();
        $unlocked    = $characterId > 0 ? array_flip($this->service->unlockedAchievementIds($characterId)) : [];

        if (empty($definitions)) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'text'       => "🏅 *Достижения*\n\n_Пока пусто._",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Группируем по категориям, считаем сводку, кешируем currentValue по criteria_type.
        $byCategory   = [];
        $unlockedCnt  = 0;
        $totalPoints  = 0;
        $currentCache = [];
        foreach ($definitions as $a) {
            $achId = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
            $cat   = is_string($a['category'] ?? null) && $a['category'] !== '' ? $a['category'] : 'other';
            $byCategory[$cat][] = $a;
            if (isset($unlocked[$achId])) {
                $unlockedCnt++;
                $totalPoints += is_numeric($a['points'] ?? null) ? (int) $a['points'] : 0;
            }
        }

        $total = count($definitions);
        $text  = "🏅 *Достижения*\n\n";
        $text .= "Открыто: *{$unlockedCnt}/{$total}* · Очки: *{$totalPoints}*\n";

        foreach (self::CATEGORY_LABELS as $catKey => $label) {
            if (empty($byCategory[$catKey])) {
                continue;
            }
            $text .= "\n*{$label}*\n";
            foreach ($byCategory[$catKey] as $a) {
                $text .= $this->line($a, $unlocked, $characterId, $currentCache);
            }
            unset($byCategory[$catKey]);
        }
        // Категории вне словаря (на случай будущих) — под «Прочее».
        if (! empty($byCategory)) {
            $text .= "\n*🎯 Прочее*\n";
            foreach ($byCategory as $list) {
                foreach ($list as $a) {
                    $text .= $this->line($a, $unlocked, $characterId, $currentCache);
                }
            }
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * @param array<int|string,mixed> $a
     * @param array<int,int>          $unlocked  flip(unlockedIds)
     * @param array<string,int>       $currentCache criteria_type → currentValue
     */
    private function line(array $a, array $unlocked, int $characterId, array &$currentCache): string
    {
        $achId  = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
        $icon   = is_string($a['icon'] ?? null) && $a['icon'] !== '' ? $a['icon'] : '🏅';
        $title  = is_string($a['title'] ?? null) ? $a['title'] : '';
        $desc   = is_string($a['description'] ?? null) ? $a['description'] : '';
        $ctype  = is_string($a['criteria_type'] ?? null) ? $a['criteria_type'] : '';
        $target = is_numeric($a['criteria_target'] ?? null) ? (int) $a['criteria_target'] : 1;
        $points = is_numeric($a['points'] ?? null) ? (int) $a['points'] : 0;

        if (isset($unlocked[$achId])) {
            return "✅ {$icon} *{$title}* (+{$points})\n";
        }

        // Locked: бинарные (target<=1) показываем описанием, числовые — прогрессом X/Y.
        if ($target <= 1) {
            return "🔒 {$icon} {$title} — _{$desc}_\n";
        }

        if (! array_key_exists($ctype, $currentCache)) {
            $currentCache[$ctype] = $characterId > 0 ? $this->service->currentValue($characterId, $ctype) : 0;
        }
        $current = min($currentCache[$ctype], $target);
        return "🔒 {$icon} {$title} — {$current}/{$target}\n";
    }
}
