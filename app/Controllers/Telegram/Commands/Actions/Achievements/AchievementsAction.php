<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Achievements;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\AchievementService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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

        // E9 (ADR-110): редкость % (единый источник с вебом ADR-100) + флаг наград.
        $rarity    = $this->service->rarityPercentMap();
        $rewardsOn = $this->service->rewardsEnabled();

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

        // E9 (ADR-110): «🎯 Ближайшие цели» — топ-3 почти достигнутых (подсказка целей).
        $text .= $this->nearestGoals($definitions, $unlocked, $characterId, $currentCache, $rewardsOn);

        foreach (self::CATEGORY_LABELS as $catKey => $label) {
            if (empty($byCategory[$catKey])) {
                continue;
            }
            $text .= "\n*{$label}*\n";
            foreach ($byCategory[$catKey] as $a) {
                $text .= $this->line($a, $unlocked, $characterId, $currentCache, $rarity);
            }
            unset($byCategory[$catKey]);
        }
        // Категории вне словаря (на случай будущих) — под «Прочее».
        if (! empty($byCategory)) {
            $text .= "\n*🎯 Прочее*\n";
            foreach ($byCategory as $list) {
                foreach ($list as $a) {
                    $text .= $this->line($a, $unlocked, $characterId, $currentCache, $rarity);
                }
            }
        }

        if ($rewardsOn) {
            $text .= "\n_💎 — редкое достижение. За разблокировку начисляется золото (см. «Ближайшие цели»)._";
        } else {
            $text .= "\n_💎 — редкое достижение (мало кто открыл)._";
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * E9 (ADR-110) — «🎯 Ближайшие цели»: до 3 ещё НЕ открытых числовых достижений с наибольшим
     * отношением прогресса (current/target, 0<ratio<1). Заодно прогревает $currentCache для line().
     * Пусто → секция не выводится.
     *
     * @param list<array<int|string,mixed>> $definitions
     * @param array<int,int>                $unlocked     flip(unlockedIds)
     * @param array<string,int>             $currentCache criteria_type → currentValue (by-ref)
     */
    private function nearestGoals(array $definitions, array $unlocked, int $characterId, array &$currentCache, bool $rewardsOn): string
    {
        if ($characterId <= 0) {
            return '';
        }

        $cand = [];
        foreach ($definitions as $a) {
            $achId = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
            if ($achId <= 0 || isset($unlocked[$achId])) {
                continue;
            }
            $target = is_numeric($a['criteria_target'] ?? null) ? (int) $a['criteria_target'] : 1;
            if ($target <= 1) {
                continue; // бинарные — не «прогресс»
            }
            $ctype = is_string($a['criteria_type'] ?? null) ? $a['criteria_type'] : '';
            if (! array_key_exists($ctype, $currentCache)) {
                $currentCache[$ctype] = $this->service->currentValue($characterId, $ctype);
            }
            $current = min($currentCache[$ctype], $target);
            if ($current <= 0 || $current >= $target) {
                continue; // нет прогресса или уже выполнено (cron вот-вот выдаст)
            }
            $cand[] = ['a' => $a, 'current' => $current, 'target' => $target, 'ratio' => $current / $target];
        }
        if ($cand === []) {
            return '';
        }
        usort($cand, static fn (array $x, array $y): int => $y['ratio'] <=> $x['ratio']);
        $cand = array_slice($cand, 0, 3);

        $out = "\n*🎯 Ближайшие цели*\n";
        foreach ($cand as $c) {
            $a     = $c['a'];
            $icon  = is_string($a['icon'] ?? null) && $a['icon'] !== '' ? $a['icon'] : '🏅';
            $title = is_string($a['title'] ?? null) ? $a['title'] : '';
            $pct   = (int) round($c['ratio'] * 100);
            $out  .= "{$icon} {$title} — {$c['current']}/{$c['target']} ({$pct}%)";
            if ($rewardsOn) {
                $gold = $this->service->rewardGoldFor($a);
                if ($gold > 0) {
                    $out .= " · 🏆 +{$gold}";
                }
            }
            $out .= "\n";
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $a
     * @param array<int,int>          $unlocked     flip(unlockedIds)
     * @param array<string,int>       $currentCache criteria_type → currentValue
     * @param array<int,float>        $rarity       achievement_id → % открывших
     */
    private function line(array $a, array $unlocked, int $characterId, array &$currentCache, array $rarity): string
    {
        $achId  = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
        $icon   = is_string($a['icon'] ?? null) && $a['icon'] !== '' ? $a['icon'] : '🏅';
        $title  = is_string($a['title'] ?? null) ? $a['title'] : '';
        $ctype  = is_string($a['criteria_type'] ?? null) ? $a['criteria_type'] : '';
        $target = is_numeric($a['criteria_target'] ?? null) ? (int) $a['criteria_target'] : 1;
        $points = is_numeric($a['points'] ?? null) ? (int) $a['points'] : 0;
        $rar    = $this->rarityTag($rarity[$achId] ?? null);

        if (isset($unlocked[$achId])) {
            return "✅ {$icon} *{$title}* (+{$points}){$rar}\n";
        }

        // Locked: числовые — прогрессом X/Y, бинарные — только название (desc опущен ради длины:
        // 70 достижений в одном сообщении близко к лимиту Telegram 4096; редкость % важнее desc).
        if ($target <= 1) {
            return "🔒 {$icon} {$title}{$rar}\n";
        }

        if (! array_key_exists($ctype, $currentCache)) {
            $currentCache[$ctype] = $characterId > 0 ? $this->service->currentValue($characterId, $ctype) : 0;
        }
        $current = min($currentCache[$ctype], $target);
        return "🔒 {$icon} {$title} — {$current}/{$target}{$rar}\n";
    }

    /** Тег редкости « · N%» (+💎 если ≤5% — редкое). null/0 → пусто. Целое без дробной части. */
    private function rarityTag(?float $pct): string
    {
        if ($pct === null || $pct <= 0.0) {
            return '';
        }
        $gem = $pct <= 5.0 ? ' 💎' : '';
        $s   = $pct == floor($pct) ? (string) (int) $pct : number_format($pct, 1, '.', '');
        return " · {$s}%{$gem}";
    }
}
