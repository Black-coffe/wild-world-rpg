<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Specialization;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\SpecializationService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V16 (ADR-047) — меню крафт-специализации (callback `specialization`).
 *
 * Показывает текущую ветку, 3 опции с описанием бонуса, политику смены (цена/кулдаун)
 * и гейт по уровню. Edit-in-place (#12). Media-off safe (чистый текст).
 */
class SpecializationAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден. Попробуйте /start.',
            ]);
        }

        $svc = new SpecializationService();

        if (! $svc->isEnabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'      => $chatId,
                'text'         => '🎓 Специализации временно недоступны.',
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '↩️ Назад', 'callback_data' => 'character']]]]),
            ]);
        }

        $levelRaw = $character['level'] ?? 0;
        $level    = is_numeric($levelRaw) ? (int) $levelRaw : 0;
        $curRaw   = $character['specialization'] ?? null;
        $current  = is_string($curRaw) ? $curRaw : null;
        $minLevel = $svc->minLevel();
        $pct      = (int) round((1.0 - $svc->craftTimeMultiplier()) * 100);

        $text  = "🎓 *Крафт-специализация*\n\n";
        $text .= "Текущая: *" . $svc->labelFor($current) . "*\n\n";
        $text .= "Специалист крафтит предметы «своей» категории на *−{$pct}%* быстрее (стакается с верстаком и сытостью).\n\n";
        foreach ($svc->branches() as $key => $def) {
            $mark  = $current === $key ? ' ✅' : '';
            $text .= "{$def['emoji']} *{$def['label']}*{$mark} — {$def['desc']}\n";
        }
        $text .= "\n";

        $rows = [];
        if ($level < $minLevel) {
            $text .= "_Выбор доступен с уровня {$minLevel} (у вас {$level})._\n";
        } else {
            if ($current === null) {
                $text .= "Первый выбор — *бесплатно*.\n";
            } else {
                $text .= "Смена ветки: *" . number_format($svc->respecCostGold()) . "* 🪙, не чаще раза в {$svc->respecCooldownDays()} дн.\n";
                $next = $svc->nextChangeTs($character['specialization_changed_at'] ?? null);
                if ($next !== null) {
                    $text .= "⏳ Сменить можно после " . date('Y-m-d H:i', $next) . ".\n";
                }
            }
            foreach ($svc->branches() as $key => $def) {
                if ($key === $current) {
                    continue;
                }
                $rows[] = [['text' => $def['emoji'] . ' ' . $def['label'], 'callback_data' => 'specChoose_' . $key]];
            }
        }
        $rows[] = [['text' => '↩️ Назад', 'callback_data' => 'character']];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}
