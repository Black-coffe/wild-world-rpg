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
 * ADR-186 §4 (pvp-detection-clarity-08) — «🚶 Уйти» на экране ожидания: атакующий
 * сам передумывает и снимает тревогу с цели («передумать и уйти» из заказа
 * владельца дословно). Закрывает окно как `cancelled` через
 * `PvpStandoffService::close()` (`transitionIfCurrent` из `open` — второй тап
 * получает честное «уже отреагировал», а не второе закрытие). Callback
 * `standoffLeave_<standoffId>`.
 */
final class StandoffLeaveAction extends BaseAction
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
        if ($this->intField($row, 'attacker_id') !== (int) $character['id']) {
            return $this->sendError('Это не твоё противостояние.');
        }

        $standoffService = new PvpStandoffService();
        $closed          = $standoffService->close($standoffId, 'cancelled');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (!$closed) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => 'Ты уже отреагировал — окно противостояния закрыто раньше.',
                'parse_mode' => 'HTML',
            ]);
        }

        $this->notifyDefenderAttackerLeft($this->intField($row, 'defender_id'), $character['name']);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => '🚶 Ты передумал — атака снята, цель свободна.',
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Media-off (ADR-020): текст самодостаточен, имя атакующего экранируется —
     * на проде есть персонажи с пустым/чужим контролируемым именем (ADR-186 §8).
     */
    private function notifyDefenderAttackerLeft(int $defenderId, string $attackerName): void
    {
        if ($defenderId <= 0) {
            return;
        }

        try {
            $defender = $this->characterModel->find($defenderId);
            if (!$defender) {
                return;
            }
            $defUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if (!$defUser || empty($defUser['telegram_id'])) {
                return;
            }

            $nameTag = $attackerName !== '' ? '<b>' . esc($attackerName, 'html') . '</b>' : 'Нападавший';

            Request::sendMessage([
                'chat_id'    => $defUser['telegram_id'],
                'text'       => "🚶 {$nameTag} передумал атаковать — тревога снята, можно продолжать как обычно.",
                'parse_mode' => 'HTML',
            ]);
        } catch (TelegramException $e) {
            log_message('error', '[StandoffLeaveAction] notifyDefenderAttackerLeft error: ' . $e->getMessage());
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
