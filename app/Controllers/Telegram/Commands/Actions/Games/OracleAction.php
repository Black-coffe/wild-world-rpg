<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Games;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Entities\CharacterEntity;
use App\Services\Oracle\OracleService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * 🎲 «Оракул острова» (ADR-133) — золотой пари-мютюэль рынок предсказаний.
 *
 * Игрок ставит золото на исход коллективного события острова; пул проигравших − сжигаемый
 * рейк делится между угадавшими (инвариант Σвыплат ≤ Σставок — НЕ фонтан). Котировки
 * плавают по ставкам, фиксируются на закрытии. Всё текстом (media-off, ADR-020).
 *
 * Callback'и (первый сегмент 'oracle' → exact-роут; хвосты разбирает сам action):
 *   oracle                          — хаб открытых рынков
 *   oracle_m_<id>                   — экран рынка (выбор исхода + котировки)
 *   oracle_o_<id>_<code>            — выбор суммы ставки на исход
 *   oracle_bet_<id>_<code>_<stake>  — размещение ставки
 *   oracle_my                       — мои активные ставки
 *   oracleLocked                    — lock-экран для < min_level (UX-discoverability)
 */
class OracleAction extends BaseAction
{
    private OracleService $oracle;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->oracle = new OracleService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        $chatId             = $this->callbackQuery->getMessage()->getChat()->getId();

        if (! $user || ! $character instanceof CharacterEntity) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден или персонаж не определён.']);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $this->oracle->enabled()) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => '🎲 «Оракул острова» временно недоступен.']);
        }

        $charId = $this->entInt($character, 'id');
        $level  = $this->entInt($character, 'level');
        $gold   = $this->entInt($character, 'gold');

        $data   = (string) $this->callbackQuery->getData();
        $params = explode('_', $data);
        $head   = $params[0]; // explode всегда даёт ≥1 элемент

        if ($head === 'oracleLocked' || $level < $this->oracle->minLevel()) {
            return $this->showLock($level);
        }

        $sub = $params[1] ?? '';

        return match ($sub) {
            'm'   => $this->showMarket(is_numeric($params[2] ?? null) ? (int) $params[2] : 0, $charId),
            'o'   => $this->showStakeOptions(is_numeric($params[2] ?? null) ? (int) $params[2] : 0, $params[3] ?? '', $gold),
            'bet' => $this->placeBet(
                is_numeric($params[2] ?? null) ? (int) $params[2] : 0,
                $params[3] ?? '',
                is_numeric($params[4] ?? null) ? (int) $params[4] : 0,
                $charId,
            ),
            'my'  => $this->showMyBets($charId),
            default => $this->showHub(),
        };
    }

    private function showHub(): ServerResponse
    {
        $chatId  = $this->callbackQuery->getMessage()->getChat()->getId();
        $markets = $this->oracle->openMarketsForDisplay();

        $text = "🎲 *Оракул острова*\n\n"
            . "Сделай ставку золотом на то, _куда катится остров_. Выигрыш — доля банка проигравших "
            . "за вычетом комиссии дома (она *сгорает*). Дом не доплачивает: приумножить можно только "
            . "за счёт тех, кто не угадал.\n\n";

        $keyboard = [];
        if ($markets === []) {
            $text .= "_Сейчас открытых рынков нет. Загляни позже — новые открываются каждый день и каждую неделю._\n";
        } else {
            $text .= "*Открытые рынки:*\n";
            foreach ($markets as $m) {
                $icon = $m['type'] === 'daily' ? '🎲' : '🏴';
                $tag  = $m['type'] === 'daily' ? 'дневной' : 'недельный';
                $text .= "\n{$icon} *{$m['question']}*\n"
                    . "   Банк: {$m['bank']}🪙 · закрытие: " . $this->fmtTime($m['locks_at']) . " ({$tag})\n";
                $keyboard[] = [['text' => "{$icon} Ставить — {$m['question']}", 'callback_data' => 'oracle_m_' . $m['id']]];
            }
        }

        $keyboard[] = [['text' => '📊 Мои ставки', 'callback_data' => 'oracle_my']];
        $keyboard[] = [['text' => '⬅️ Развлечения', 'callback_data' => 'entertainment']];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function showMarket(int $marketId, int $charId): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $market = $this->oracle->marketForDisplay($marketId);

        if ($market === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Рынок не найден.', 'reply_markup' => $this->backToHub()]);
        }
        if ($market['status'] !== 'open') {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => "🎲 *{$market['question']}*\n\nСтавки на этот рынок уже закрыты — ждём котировки.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => $this->backToHub(),
            ]);
        }

        $myStake    = $this->oracle->myStakeOnMarket($charId, $marketId);
        $rakePctStr = $this->fmtNum($market['rake_pct'] * 100, 0);

        $text = "🎲 *Оракул острова*\n«{$market['question']}»\n"
            . "Закрытие ставок: " . $this->fmtTime($market['locks_at']) . "\n\n"
            . "Банк: *{$market['bank']}🪙* · комиссия дома {$rakePctStr}% (−{$market['rake']}🪙, сгорает)\n"
            . "К дележу: *{$market['distributable']}🪙*\n\n"
            . "*Исход · ставок · доля · котировка\\**\n";

        $keyboard = [];
        foreach ($market['outcomes'] as $o) {
            $oddsStr = $o['odds'] > 0 ? '×' . $this->fmtNum($o['odds'], 2) : '—';
            $text .= "{$o['label']} — {$o['pool']}🪙 · {$o['share']}% · {$oddsStr}\n";
            $keyboard[] = [['text' => "Ставить на: {$o['label']} ({$oddsStr})", 'callback_data' => 'oracle_o_' . $marketId . '_' . $o['code']]];
        }

        $text .= "\n_\\*котировка = (банк − комиссия) / ставки на исход; плавает по мере ставок, фиксируется на закрытии._\n";
        if ($myStake > 0) {
            $text .= "\nТвои ставки на этом рынке: *{$myStake}🪙* (лимит {$this->oracle->maxStake()}🪙).\n";
        }

        $keyboard[] = [['text' => '📊 Мои ставки', 'callback_data' => 'oracle_my'], ['text' => '⬅️ Рынки', 'callback_data' => 'oracle']];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function showStakeOptions(int $marketId, string $code, int $gold): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $market = $this->oracle->marketForDisplay($marketId);
        if ($market === null || $market['status'] !== 'open') {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Ставки на этот рынок закрыты.', 'reply_markup' => $this->backToHub()]);
        }

        $label = $code;
        $odds  = 0.0;
        foreach ($market['outcomes'] as $o) {
            if ($o['code'] === $code) {
                $label = $o['label'];
                $odds  = $o['odds'];
            }
        }

        $oddsStr = $odds > 0 ? '×' . $this->fmtNum($odds, 2) : '—';

        $text = "🎲 Ставка на: *{$label}*\n"
            . "Текущая котировка: *{$oddsStr}* (плавает до закрытия)\n"
            . "Твоё золото: {$gold}🪙 · лимит ставки на рынок: {$this->oracle->maxStake()}🪙\n\n"
            . "Выбери сумму ставки 👇";

        $keyboard = [];
        $row      = [];
        foreach ($this->oracle->betOptions() as $bet) {
            if ($bet > $this->oracle->maxStake() || $bet > $gold) {
                continue;
            }
            $row[] = ['text' => $bet . '🪙', 'callback_data' => 'oracle_bet_' . $marketId . '_' . $code . '_' . $bet];
            if (count($row) === 3) {
                $keyboard[] = $row;
                $row        = [];
            }
        }
        if ($row !== []) {
            $keyboard[] = $row;
        }
        if ($keyboard === []) {
            $text .= "\n\n_Нет доступной суммы по карману (минимум {$this->oracle->minStake()}🪙)._";
        }
        $keyboard[] = [['text' => '⬅️ Назад к рынку', 'callback_data' => 'oracle_m_' . $marketId]];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function placeBet(int $marketId, string $code, int $stake, int $charId): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $result = $this->oracle->placeBet($charId, $marketId, $code, $stake);

        if (! $result['ok']) {
            $this->logRejected($charId > 0 ? $charId : null, 'ORACLE_BET', $result['error'], ['market' => $marketId, 'stake' => $stake]);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $this->errorText($result['error']),
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '⬅️ Назад к рынку', 'callback_data' => 'oracle_m_' . $marketId]]]]),
            ]);
        }

        $this->logActivity($charId > 0 ? $charId : null, 'ORACLE_BET', "market={$marketId} outcome={$code} stake={$result['stake']}");

        $text = "✅ Ставка принята: *{$result['stake']}🪙*.\n"
            . "Остаток золота: {$result['balance']}🪙.\n\n"
            . "Котировка зафиксируется на закрытии рынка — тогда придёт результат. Можно поставить ещё (в пределах лимита).";

        $keyboard = [
            [['text' => '🎲 К рынку', 'callback_data' => 'oracle_m_' . $marketId], ['text' => '📊 Мои ставки', 'callback_data' => 'oracle_my']],
            [['text' => '⬅️ Рынки', 'callback_data' => 'oracle']],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function showMyBets(int $charId): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $bets   = $this->oracle->myActiveBets($charId);

        $text = "📊 *Мои активные ставки*\n\n";
        if ($bets === []) {
            $text .= "_Пока нет активных ставок. Открой рынок и сделай ставку._";
        } else {
            $total = 0;
            foreach ($bets as $b) {
                $text .= "• «{$b['question']}»\n   на *{$b['outcome']}* — {$b['stake']}🪙\n";
                $total += $b['stake'];
            }
            $text .= "\nВсего в игре: *{$total}🪙*. Результаты придут на закрытии рынков.";
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->backToHub(),
        ]);
    }

    private function showLock(int $level): ServerResponse
    {
        $chatId   = $this->callbackQuery->getMessage()->getChat()->getId();
        $minLevel = $this->oracle->minLevel();

        $text = "🔒 *Оракул острова* (нужен уровень {$minLevel})\n\n"
            . "Это рынок ставок на исходы острова: ставишь золото на то, какая фракция вырвётся вперёд "
            . "или сработает ли событие, а раз в неделю снимается котировка — приумножил или потерял.\n\n"
            . "Сейчас у тебя уровень {$level}. Достигни *{$minLevel}-го* — и Оракул откроется. "
            . "Расти быстрее: разведка, добыча, крафт, бои.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions']],
                [['text' => '⬅️ Развлечения', 'callback_data' => 'entertainment']],
            ]]),
        ]);
    }

    private function errorText(string $code): string
    {
        return match ($code) {
            'level'         => "🔒 Нужен уровень {$this->oracle->minLevel()} для ставок.",
            'market_closed' => 'Ставки на этот рынок уже закрыты.',
            'bad_outcome'   => 'Неизвестный исход.',
            'bad_stake'     => "Ставка вне допустимого диапазона ({$this->oracle->minStake()}–{$this->oracle->maxStake()}🪙).",
            'over_cap'      => "Превышен лимит ставки на рынок ({$this->oracle->maxStake()}🪙).",
            'no_gold'       => 'Недостаточно золота для ставки.',
            'reserve'       => "Нельзя уводить золото ниже резерва ({$this->oracle->reserveFloor()}🪙).",
            'debit_failed'  => 'Не удалось списать ставку — попробуй ещё раз.',
            'disabled'      => '🎲 «Оракул острова» временно недоступен.',
            default         => 'Не удалось принять ставку.',
        };
    }

    /**
     * Безопасное чтение целочисленного поля персонажа (Entity с ArrayAccess).
     */
    private function entInt(CharacterEntity $char, string $key): int
    {
        $raw = $char[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function backToHub(): string
    {
        return json_encode(['inline_keyboard' => [[['text' => '⬅️ Рынки', 'callback_data' => 'oracle']]]]) ?: '';
    }

    private function fmtTime(string $dt): string
    {
        if ($dt === '') {
            return '—';
        }
        $ts = strtotime($dt);

        return $ts === false ? $dt : date('d.m H:i', $ts);
    }

    private function fmtNum(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
