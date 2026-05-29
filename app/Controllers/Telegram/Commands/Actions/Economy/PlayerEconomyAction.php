<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Economy;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Economy\PlayerEconomyService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W24 (ADR-079) — «💰 Моя экономика»: персональный экономический срез (snapshot).
 *
 * Callback `myEconomy`. Вход — кнопка с карточки Перс (gated killswitch
 * economy.player_report.enabled). Навигация — постоянной reply-клавиатурой бота,
 * без inline-дублей (feedback_no_duplicate_persistent_keyboard_buttons).
 * Caption самодостаточен (media-off safe: только текст).
 */
final class PlayerEconomyAction extends BaseAction
{
    private PlayerEconomyService $economy;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->economy = new PlayerEconomyService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        if (! $this->economy->enabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'text'       => "💰 *Моя экономика временно недоступна*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $r      = $this->economy->report($charId);

        $text = "💰 *Моя экономика*\n\n"
            . "💵 Золото: *" . $this->fmt($r['gold']) . "*\n"
            . "📦 Ресурсы: *" . $this->fmt($r['resources_value']) . "*\n"
            . "🛠 Снаряжение/крафт: *" . $this->fmt($r['crafted_value']) . "*\n"
            . "━━━━━━━━━━\n"
            . "💎 *Чистая стоимость: " . $this->fmt($r['net_worth']) . "*\n";

        if ($r['top_resources'] !== []) {
            $text .= "\n🏆 *Ценные ресурсы:*\n";
            foreach ($r['top_resources'] as $h) {
                $text .= "• {$h['name']} — {$h['qty']} шт. (" . $this->fmt($h['value']) . ")\n";
            }
        }

        if ($r['top_crafted'] !== []) {
            $text .= "\n🛠 *Ценный крафт:*\n";
            foreach ($r['top_crafted'] as $h) {
                $text .= "• {$h['name']} — {$h['qty']} шт. (" . $this->fmt($h['value']) . ")\n";
            }
        }

        $text .= "\n🤝 *Торговля у скупщиков:*\n";
        if ($r['trade'] === null) {
            $text .= "_Ты ещё не торговал — продай излишки в Магазине, чтобы увидеть здесь баланс._\n";
        } else {
            $t       = $r['trade'];
            $sign    = $t['profit'] >= 0 ? '+' : '−';
            $profAbs = abs($t['profit']);
            $text .= "Продано: " . $this->fmt($t['sold']) . " · Куплено: " . $this->fmt($t['bought']) . "\n"
                . "Баланс: *{$sign}" . $this->fmt($profAbs) . "* (за {$t['count']} сделок)\n";
        }

        // W25 (ADR-080) — сравнение «на фоне выживших» (отдельный killswitch).
        if ($this->economy->comparisonEnabled()) {
            $cmp = $this->economy->comparison($charId);
            if ($cmp !== null) {
                $s = $cmp['server'];
                $text .= "\n📊 *На фоне выживших:*\n"
                    . "Богаче *{$s['richer_than_pct']}%* выживших · позиция *#{$s['rank']}* из {$s['count']}\n"
                    . "Медиана сервера: " . $this->fmt($s['median']) . "\n";
                if ($cmp['faction'] !== null) {
                    $f = $cmp['faction'];
                    $text .= "🏳 *{$f['name']}*: #{$f['rank']} из {$f['count']} · медиана " . $this->fmt($f['median']) . "\n";
                }
            }
        }

        $text .= "\n_Стоимость инвентаря оценена по ценам скупки. Снимок на текущий момент._";

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function fmt(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }
}
