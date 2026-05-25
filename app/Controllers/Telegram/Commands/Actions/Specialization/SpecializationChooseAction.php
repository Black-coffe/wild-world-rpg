<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Specialization;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\SpecializationService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V16 (ADR-047) — выбор/смена крафт-специализации (callback `specChoose_<branch>`).
 *
 * Валидация: killswitch → валидная ветка → гейт уровня → (для смены) кулдаун → золото.
 * Применение: characters.specialization + specialization_changed_at; при смене списывает
 * золото. Первый выбор — бесплатно. Edit-in-place, media-off safe.
 */
class SpecializationChooseAction extends BaseAction
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

        $svc    = new SpecializationService();
        $data   = (string) $this->callbackQuery->getData();
        $prefix = 'specChoose_';
        $branch = str_starts_with($data, $prefix) ? substr($data, strlen($prefix)) : '';

        $idRaw  = $character['id'] ?? 0;
        $charId = is_numeric($idRaw) ? (int) $idRaw : 0;

        if (! $svc->isEnabled() || ! $svc->isValidBranch($branch)) {
            return $this->backToMenu($chatId, '⚠️ Специализация недоступна или неизвестна.');
        }

        $levelRaw = $character['level'] ?? 0;
        $level    = is_numeric($levelRaw) ? (int) $levelRaw : 0;
        if ($level < $svc->minLevel()) {
            $this->logRejected($charId, 'CHOOSE_SPECIALIZATION', 'level_too_low', ['need' => $svc->minLevel(), 'have' => $level]);
            return $this->backToMenu($chatId, "⚠️ Специализация доступна с уровня {$svc->minLevel()} (у вас {$level}).");
        }

        $curRaw  = $character['specialization'] ?? null;
        $current = is_string($curRaw) ? $curRaw : null;

        // Уже выбрана эта ветка — ничего не делаем.
        if ($current === $branch) {
            return $this->backToMenu($chatId, "Ветка *" . $svc->labelFor($branch) . "* уже выбрана.");
        }

        $isRespec = $current !== null;
        $cost     = 0;
        if ($isRespec) {
            if (! $svc->canChangeNow($character['specialization_changed_at'] ?? null)) {
                $next = $svc->nextChangeTs($character['specialization_changed_at'] ?? null);
                $when = $next !== null ? (' после ' . date('Y-m-d H:i', $next)) : '';
                $this->logRejected($charId, 'CHOOSE_SPECIALIZATION', 'cooldown');
                return $this->backToMenu($chatId, "⏳ Сменить ветку можно не чаще раза в {$svc->respecCooldownDays()} дн.{$when}.");
            }
            $cost    = $svc->respecCostGold();
            $goldRaw = $character['gold'] ?? 0;
            $gold    = is_numeric($goldRaw) ? (int) $goldRaw : 0;
            if ($gold < $cost) {
                $this->logRejected($charId, 'CHOOSE_SPECIALIZATION', 'not_enough_gold', ['need' => $cost, 'have' => $gold]);
                return $this->backToMenu($chatId, "⚠️ Для смены ветки нужно " . number_format($cost) . " 🪙 (у вас " . number_format($gold) . ").");
            }
        }

        $update = [
            'specialization'            => $branch,
            'specialization_changed_at' => date('Y-m-d H:i:s'),
        ];
        if ($isRespec && $cost > 0) {
            $goldRaw = $character['gold'] ?? 0;
            $gold    = is_numeric($goldRaw) ? (int) $goldRaw : 0;
            $update['gold'] = $gold - $cost;
        }
        $this->characterModel->update($charId, $update);

        $pct  = (int) round((1.0 - $svc->craftTimeMultiplierForLevel($branch, $level)) * 100);
        $text = "✅ Специализация: *" . $svc->labelFor($branch) . "*\n\n"
              . "Крафт предметов своей категории теперь идёт на *−{$pct}%* быстрее (на L{$level}). Бонус растёт с уровнем.";
        if ($isRespec && $cost > 0) {
            $text .= "\n\nСписано " . number_format($cost) . " 🪙.";
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🎓 Специализация', 'callback_data' => 'specialization']],
                [['text' => '↩️ Назад', 'callback_data' => 'character']],
            ]]),
        ]);
    }

    private function backToMenu(int $chatId, string $message): ServerResponse
    {
        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🎓 К специализации', 'callback_data' => 'specialization']],
            ]]),
        ]);
    }
}
