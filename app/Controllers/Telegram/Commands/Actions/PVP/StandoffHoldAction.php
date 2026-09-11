<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\PvpStandoffModel;
use App\Services\PVE\PvpStandoffService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;

/**
 * ADR-186 §3 (pvp-detection-clarity-09) — «🛡 Укрыться» на тревоге защитника:
 * остаться на месте и немедленно отдать инициативу, закрыв окно как `held`.
 * Это не безопасная комната — надбавка к снижению урона
 * (`pvp.standoff.hold_damage_reduction_percent`) не выдаётся здесь цифрами, её
 * читает `-08` (`AttackPlayerAction::standoffHeldWithinCooldown()`) из свежести
 * `held`-строки в момент реальной атаки; эта кнопка только переводит статус.
 * Закрывает окно через `PvpStandoffService::close()` (`transitionIfCurrent` из
 * `open` — второй тап по любой из трёх кнопок получает честное «уже
 * отреагировал», а не второй переход). Callback `standoffHold_<standoffId>`.
 */
final class StandoffHoldAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден в базе данных.');
        }

        $parts      = explode('_', $this->callbackQuery->getData());
        $standoffId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        if ($standoffId <= 0) {
            return $this->sendError('Не указано окно противостояния.');
        }

        $model = new PvpStandoffModel();
        $row   = $this->normalizeRow($model->find($standoffId));
        if ($row === null) {
            return $this->sendError('Это окно противостояния больше не существует.');
        }
        if ($this->intField($row, 'defender_id') !== (int) $character['id']) {
            return $this->sendError('Это не твоя тревога.');
        }

        $standoffService = new PvpStandoffService();
        $closed          = $standoffService->close($standoffId, 'held');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (!$closed) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => 'Ты уже отреагировал — окно противостояния закрыто раньше.',
                'parse_mode' => 'HTML',
            ]);
        }

        $this->notifyAttackerDefenderHeld($this->intField($row, 'attacker_id'), (string) $character['name']);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => '🛡 Ты остаёшься на месте и готовишься к бою — инициатива у нападавшего, '
                . 'но твоя защита усилена.',
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Атакующий разморожен немедленно (ADR-186 §3) — узнаёт об этом сразу, а не
     * следующим тапом «⏳ Проверить». Media-off (ADR-020): текст самодостаточен,
     * имя защитника экранируется (на проде есть персонажи с пустым/чужим
     * контролируемым именем, ADR-186 §8). Present tense — без гендерного
     * согласования глагола с чужим именем.
     */
    private function notifyAttackerDefenderHeld(int $attackerId, string $defenderName): void
    {
        if ($attackerId <= 0) {
            return;
        }

        try {
            $attacker = $this->characterModel->find($attackerId);
            if (!$attacker) {
                return;
            }
            $attUser = $this->telegramUserModel->find($attacker['telegram_user_id']);
            if (!$attUser || empty($attUser['telegram_id'])) {
                return;
            }

            $nameTag = $defenderName !== '' ? '<b>' . esc($defenderName, 'html') . '</b>' : 'Защитник';

            Request::sendMessage([
                'chat_id'    => $attUser['telegram_id'],
                'text'       => "🛡 {$nameTag} остаётся на месте и готовится к бою — "
                    . 'можно атаковать снова.',
                'parse_mode' => 'HTML',
            ]);
        } catch (TelegramException $e) {
            log_message('error', '[StandoffHoldAction] notifyAttackerDefenderHeld error: ' . $e->getMessage());
        }
    }

    /**
     * CI4 `Model::find()` типизируется `mixed` шире реального `$returnType = 'array'`
     * (тот же приём, что `PvpStandoffService::normalizeRow()`).
     *
     * @return array<string,mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function intField(array $row, string $key): int
    {
        $v = $row[$key] ?? 0;

        return is_numeric($v) ? (int) $v : 0;
    }

    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]);
    }
}
