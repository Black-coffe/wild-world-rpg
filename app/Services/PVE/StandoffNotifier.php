<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\Request;
use Config\Database;
use Throwable;

/**
 * ADR-186 (pvp-detection-clarity-06) — три текстовых сообщения окна противостояния.
 * Никто ещё не зовёт: `-08` подключает `alertDefender()`/контратаку, `-09` —
 * `waitScreen()` для отбитого тапа атакующего, `-10` — `notifyExpired()` из крона.
 *
 * 🔴 Инвариант 10 (ADR-186 §8): все три — `parse_mode = HTML`, не legacy Markdown.
 * На проде 68 персонажей с пустым/чужим именем, полностью контролируемым другим
 * игроком — неэкранированные `*`/`_` под Markdown дают 400 и тихий no-send.
 * Media-off (ADR-020): фото нет вовсе, весь смысл — кто, откуда, сколько
 * осталось, что делает каждая кнопка — в тексте.
 *
 * Отправка (`sendDefenderAlert`/`sendExpiredPing`) вынесена в protected-методы
 * тем же приёмом, что {@see TowerAlertService}: реальный Telegram-путь PHPUnit не
 * исполняет (`## Implementation notes`), тесты подменяют доставку и проверяют
 * состав собранной клавиатуры/текста.
 */
class StandoffNotifier
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Тревога защитнику — РОВНО три хода (дословное требование владельца:
     * «укрыться, убежать, первым атаковать»), ни одна из трёх не выводится из
     * значения `status` ENUM. Callback'и — из `## Contracts` плана буквально:
     * `standoffHold_<id>` (новый), `runAway` (существующий), `attackPlayer_<attackerId>`
     * (существующий, цель — напавший).
     *
     * @param array<string,mixed> $standoff строка `pvp_standoffs`
     */
    public function alertDefender(array $standoff): void
    {
        $standoffId = $this->intField($standoff, 'id');
        $defenderId = $this->intField($standoff, 'defender_id');
        $attackerId = $this->intField($standoff, 'attacker_id');
        if ($standoffId <= 0 || $defenderId <= 0 || $attackerId <= 0) {
            return;
        }

        $chatId = $this->chatIdFor($defenderId);
        if ($chatId === null) {
            return;
        }

        $this->sendDefenderAlert(
            $chatId,
            $this->buildAlertText($standoff, $attackerId),
            $this->buildAlertKeyboard($standoffId, $attackerId)
        );
    }

    /**
     * Экран ожидания для атакующего, отбитого тапа по цели под тревогой —
     * lock-состояние (UX-Discoverability), не молчаливый отказ: сколько осталось,
     * что может сделать защитник, «⏳ Проверить» / «🚶 Уйти» (`standoffCheck_<id>`
     * / `standoffLeave_<id>`, ADR-186 §4 — «передумать и уйти» из заказа).
     *
     * @param array<string,mixed> $standoff
     * @return array{text:string,keyboard:array<string,mixed>}
     */
    public function waitScreen(array $standoff): array
    {
        $standoffId   = $this->intField($standoff, 'id');
        $defenderId   = $this->intField($standoff, 'defender_id');
        $defenderName = $this->nameFor($defenderId);
        $nameTag      = $defenderName !== '' ? '<b>' . esc($defenderName, 'html') . '</b>' : 'База защитника';
        $secondsText  = $this->secondsLeftText($standoff);

        $text = "🛡 {$nameTag} подняла тревогу — атаковать пока нельзя.\n\n"
            . "Осталось {$secondsText}: за это время она может укрыться, убежать или ударить первой. "
            . "Пока окно не закрылось или не истекло, атака по этой цели отклоняется.\n\n"
            . '⏳ Проверить — перечитать, сколько осталось.'
            . "\n🚶 Уйти — передумать и снять тревогу с цели.";

        return [
            'text'     => $text,
            'keyboard' => [
                'inline_keyboard' => [[
                    ['text' => '⏳ Проверить', 'callback_data' => "standoffCheck_{$standoffId}"],
                    ['text' => '🚶 Уйти', 'callback_data' => "standoffLeave_{$standoffId}"],
                ]],
            ],
        ];
    }

    /**
     * Одноразовый пинг атакующему об истечении окна (`pvp.standoff.notify_attacker_on_expiry`,
     * читает вызывающий крон-хендлер `-10`) — иначе единственный способ узнать про
     * конец окна — долбить «⏳ Проверить».
     *
     * @param array<string,mixed> $standoff
     */
    public function notifyAttackerExpired(array $standoff): void
    {
        $attackerId = $this->intField($standoff, 'attacker_id');
        if ($attackerId <= 0) {
            return;
        }

        $chatId = $this->chatIdFor($attackerId);
        if ($chatId === null) {
            return;
        }

        $defenderName = $this->nameFor($this->intField($standoff, 'defender_id'));
        $nameTag      = $defenderName !== '' ? '<b>' . esc($defenderName, 'html') . '</b>' : 'Цель';

        $text = "⏰ Окно закрылось — можно атаковать.\n\n"
            . "Пять минут прошли, {$nameTag} больше не может остановить атаку одним касанием — нажми «⚔️ Атаковать» ещё раз.";

        $this->sendExpiredPing($chatId, $text);
    }

    /**
     * @param array<string,mixed> $standoff
     */
    private function buildAlertText(array $standoff, int $attackerId): string
    {
        $attackerName = $this->nameFor($attackerId);
        $nameTag      = $attackerName !== '' ? '<b>' . esc($attackerName, 'html') . '</b>' : 'Игрок';
        $secondsText  = $this->secondsLeftText($standoff);
        $holdBonus    = (int) $this->settings->get('pvp.standoff.hold_damage_reduction_percent', 10);

        return "🛡 Тревога! Твоя база под атакой.\n\n"
            . "{$nameTag} стоит рядом с твоей базой. У тебя есть {$secondsText}, чтобы отреагировать — "
            . "пока ты не выберешь ход или время не выйдет, атака не начнётся.\n\n"
            . "🛡 <b>Укрыться</b> — остаться и сразу принять бой с добавкой к защите (+{$holdBonus}% снижения урона).\n"
            . "🏃 <b>Убежать</b> — прыжок 10–50 клеток (1000 золота, −50% здоровья, −90% выносливости); "
            . "атакующий сразу разморожен, окно закрывается.\n"
            . '⚔️ <b>Ударить первым</b> — атаковать в ответ, пока инициатива у тебя.';
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAlertKeyboard(int $standoffId, int $attackerId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🛡 Укрыться', 'callback_data' => "standoffHold_{$standoffId}"],
                ['text' => '🏃 Убежать', 'callback_data' => 'runAway'],
                ['text' => '⚔️ Ударить первым', 'callback_data' => "attackPlayer_{$attackerId}"],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $standoff
     */
    private function secondsLeftText(array $standoff): string
    {
        $expiresAtRaw = $standoff['expires_at'] ?? null;
        $expiresAt    = is_string($expiresAtRaw) ? strtotime($expiresAtRaw) : false;
        $seconds      = $expiresAt !== false ? max(0, $expiresAt - time()) : 0;
        $minutes      = intdiv($seconds, 60);
        $secondsRest  = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $secondsRest);
    }

    /**
     * Реальная отправка — protected, чтобы тест подменил доставку (как
     * {@see TowerAlertService::deliverAlertMessage()}) и проверил ровно то, что
     * требует акцептанс: состав клавиатуры по `callback_data`, не по подписи.
     *
     * @param array<string,mixed> $keyboard
     */
    protected function sendDefenderAlert(int $chatId, string $text, array $keyboard): bool
    {
        try {
            $resp = Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard) ?: '{}',
            ]);
            return $resp->isOk();
        } catch (Throwable $e) {
            log_message('error', '[StandoffNotifier] sendDefenderAlert failed: ' . $e->getMessage());
            return false;
        }
    }

    protected function sendExpiredPing(int $chatId, string $text): bool
    {
        try {
            $resp = Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
            return $resp->isOk();
        } catch (Throwable $e) {
            log_message('error', '[StandoffNotifier] sendExpiredPing failed: ' . $e->getMessage());
            return false;
        }
    }

    private function chatIdFor(int $characterId): ?int
    {
        try {
            $db  = Database::connect();
            $row = $db->table('characters c')
                ->select('tu.telegram_id')
                ->join('telegram_users tu', 'tu.id = c.telegram_user_id')
                ->where('c.id', $characterId)
                ->get();
            if ($row === false) {
                return null;
            }
            $r         = $row->getRowArray();
            $telegramId = is_array($r) ? ($r['telegram_id'] ?? null) : null;
            return is_numeric($telegramId) ? (int) $telegramId : null;
        } catch (Throwable $e) {
            log_message('error', '[StandoffNotifier] chatIdFor failed: ' . $e->getMessage());
            return null;
        }
    }

    private function nameFor(int $characterId): string
    {
        if ($characterId <= 0) {
            return '';
        }
        try {
            $db  = Database::connect();
            $row = $db->table('characters')->select('name')->where('id', $characterId)->get();
            if ($row === false) {
                return '';
            }
            $r = $row->getRowArray();
            return is_array($r) && is_string($r['name'] ?? null) ? $r['name'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function intField(array $row, string $key): int
    {
        $v = $row[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }
}
