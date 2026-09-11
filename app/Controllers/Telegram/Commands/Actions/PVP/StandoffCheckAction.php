<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\PvpStandoffModel;
use App\Services\PVE\PvpStandoffService;
use App\Services\PVE\StandoffNotifier;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-186 §4 (pvp-detection-clarity-08) — «⏳ Проверить» на экране ожидания
 * атакующего: перечитывает состояние окна и перерисовывает тот же экран
 * (UX-Discoverability — lock-состояние с путём, не тупик). Callback
 * `standoffCheck_<standoffId>`.
 *
 * Читает только `PvpStandoffService`/`PvpStandoffModel` — своей проверки
 * «окно ещё живо» не заводит.
 */
final class StandoffCheckAction extends BaseAction
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

        $rowAttackerId = $this->intField($row, 'attacker_id');
        $rowDefenderId = $this->intField($row, 'defender_id');

        // Проверять может только атакующий, которому принадлежит экран ожидания —
        // чужой тап по чужому standoffId не должен ничего раскрывать.
        if ($rowAttackerId !== (int) $character['id']) {
            return $this->sendError('Это не твоё противостояние.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $standoffService = new PvpStandoffService();
        $active          = $this->normalizeRow($standoffService->activeFor($rowAttackerId, $rowDefenderId));

        if ($active !== null && $this->intField($active, 'id') === $standoffId) {
            $screen = (new StandoffNotifier())->waitScreen($active);

            return Request::sendMessage([
                'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'         => $screen['text'],
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($screen['keyboard']) ?: '{}',
            ]);
        }

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $this->resolvedText($row),
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolvedText(array $row): string
    {
        $status = is_string($row['status'] ?? null) ? $row['status'] : '';

        return match ($status) {
            'fled'      => '🏃 Цель сбежала — окно закрыто, атаковать её сейчас негде.',
            'countered' => '⚔️ Защитник ударил первым — бой уже состоялся.',
            'held'      => '🛡 Защитник остался на месте и приготовился к бою — можно атаковать снова.',
            'cancelled' => '🚶 Ты сам отменил атаку — окно закрыто.',
            default     => '⏰ Окно закрылось — можно атаковать обычным путём.',
        };
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
