<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Economy;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Economy\PlayerEconomyService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $this->t('char_not_found')]);
        }

        if (! $this->economy->enabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'text'       => $this->t('unavailable'),
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $r      = $this->economy->report($charId);

        // W26 (ADR-081): строки из lang('Economy.*'); метки + значения (числа форматируются
        // в PHP и передаются ICU-плейсхолдерам строкой, чтобы не переформатировались).
        $text = $this->t('title') . "\n\n"
            . $this->t('gold') . ": *" . $this->fmt($r['gold']) . "*\n"
            . $this->t('resources') . ": *" . $this->fmt($r['resources_value']) . "*\n"
            . $this->t('crafted') . ": *" . $this->fmt($r['crafted_value']) . "*\n"
            . "━━━━━━━━━━\n"
            . $this->t('net_worth', [$this->fmt($r['net_worth'])]) . "\n";

        if ($r['top_resources'] !== []) {
            $text .= "\n" . $this->t('top_resources') . "\n";
            foreach ($r['top_resources'] as $h) {
                $text .= $this->t('holding_line', [$h['name'], (string) $h['qty'], $this->fmt($h['value'])]) . "\n";
            }
        }

        if ($r['top_crafted'] !== []) {
            $text .= "\n" . $this->t('top_crafted') . "\n";
            foreach ($r['top_crafted'] as $h) {
                $text .= $this->t('holding_line', [$h['name'], (string) $h['qty'], $this->fmt($h['value'])]) . "\n";
            }
        }

        $text .= "\n" . $this->t('trade_header') . "\n";
        if ($r['trade'] === null) {
            $text .= $this->t('trade_none') . "\n";
        } else {
            $t       = $r['trade'];
            $sign    = $t['profit'] >= 0 ? '+' : '−';
            $profAbs = abs($t['profit']);
            $text .= $this->t('trade_summary', [$this->fmt($t['sold']), $this->fmt($t['bought'])]) . "\n"
                . $this->t('trade_balance', [$sign, $this->fmt($profAbs), (string) $t['count']]) . "\n";
        }

        // W25 (ADR-080) — сравнение «на фоне выживших» (отдельный killswitch).
        if ($this->economy->comparisonEnabled()) {
            $cmp = $this->economy->comparison($charId);
            if ($cmp !== null) {
                $s = $cmp['server'];
                $text .= "\n" . $this->t('comparison_header') . "\n"
                    . $this->t('comparison_server', [(string) $s['richer_than_pct'], (string) $s['rank'], (string) $s['count']]) . "\n"
                    . $this->t('comparison_median', [$this->fmt($s['median'])]) . "\n";
                if ($cmp['faction'] !== null) {
                    $f = $cmp['faction'];
                    $text .= $this->t('comparison_faction', [$f['name'], (string) $f['rank'], (string) $f['count'], $this->fmt($f['median'])]) . "\n";
                }
            }
        }

        $text .= "\n" . $this->t('disclaimer');

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

    /**
     * W26 (ADR-081) — i18n: строка экрана из app/Language/<locale>/Economy.php.
     * Локаль = текущая (defaultLocale=ru). Per-character locale — seam W27.
     *
     * @param list<string> $args ICU-плейсхолдеры {0},{1}… (строками, без переформатирования чисел)
     */
    private function t(string $key, array $args = []): string
    {
        $out = lang('Economy.' . $key, $args);
        return is_string($out) ? $out : $key;
    }
}
