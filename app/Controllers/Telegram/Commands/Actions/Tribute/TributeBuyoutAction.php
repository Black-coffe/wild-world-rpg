<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Tribute;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\TributeService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-135 Ф4 — выкуп из-под трофейной подати (gold-burn). Два шага, одна exact-route:
 *  - callback `tributeBuyout`    → экран подтверждения (стоимость, «золото сгорает»).
 *  - callback `tributeBuyout_ok` → исполнение: TributeService::buyOut (списание-burn + снятие).
 *
 * Ключ роута `tributeBuyout` БЕЗ хвостового `_` (урок ADR-089): resolve матчит первый сегмент
 * explode('_')[0]='tributeBuyout' для обоих callback_data. Ветвление — по суффиксу `_ok`.
 * Edit-in-place (ADR-018); terminal-результат без inline-кнопок (навигация — reply-клавиатурой).
 */
class TributeBuyoutAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $svc    = new TributeService();
        if (! $svc->enabled() || $charId <= 0) {
            return $this->alert('Недоступно.');
        }

        $tribute = $svc->getActiveTribute($charId);
        if ($tribute === null) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'       => '⚖️ Ты сейчас не под податью.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $cost    = $svc->ransomCost($tribute);
        $execute = str_ends_with((string) $this->callbackQuery->getData(), '_ok');

        if (! $execute) {
            return $this->confirmScreen($character, $cost);
        }

        // Шаг 2 — исполнение.
        $result = $svc->buyOut($charId);
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $result['ok'] ? 'Выкуп выполнен!' : 'Не удалось.',
        ]);
        $this->logActivity($charId, 'TRIBUTE_BUYOUT', "ok={$result['reason']} cost={$result['cost']}");

        if ($result['ok']) {
            $text = "🔓 *Свобода!*\n\nТы выкупился из-под подати за *{$result['cost']}* 🪙. "
                . 'Больше не отдаёшь долю с добычи.';
        } else {
            $text = match ($result['reason']) {
                'insufficient_gold' => "Не хватает золота на выкуп ({$result['cost']} 🪙).",
                'no_tribute'        => 'Ты сейчас не под податью.',
                default             => 'Не удалось выкупиться. Попробуй ещё раз.',
            };
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function confirmScreen(array|\App\Entities\CharacterEntity $character, int $cost): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $gold    = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        $canPay  = $gold >= $cost;
        $text    = "💰 *Выкуп из-под подати*\n\n"
            . "Стоимость: *{$cost}* 🪙 (золото *сгорает*, не уходит хозяину).\n"
            . "У тебя: {$gold} 🪙.\n\n"
            . ($canPay
                ? 'Подать снимется сразу. Подтвердить?'
                : '_Не хватает ' . ($cost - $gold) . ' 🪙. Накопи или возьми реванш в бою._');

        $keyboard = $canPay
            ? ['inline_keyboard' => [[
                ['text' => "✅ Выкупиться ({$cost} 🪙)", 'callback_data' => 'tributeBuyout_ok'],
                ['text' => '↩ Назад', 'callback_data' => 'tributeStatus'],
            ]]]
            : ['inline_keyboard' => [[['text' => '↩ Назад', 'callback_data' => 'tributeStatus']]]];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::emptyResponse();
    }
}
