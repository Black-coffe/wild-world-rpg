<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\BattleJournalService;
use App\Services\Telegram\ButtonPacker;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * w2-n7-combat-01 (ADR-190) — «📜 Мои бои» в боте: тонкий рендерер модели {@see BattleJournalService}.
 *
 * Callback `battles` — список последних боёв; `battleLog_<id>` — карточка боя с разбором по раундам.
 * Кнопка «📜 Разбор боя» стоит под итогом боя (уведомление) — там карточка приходит НОВЫМ сообщением,
 * чтобы итог с наградами не затёрся (ADR-018: уведомления не редактируются). Из списка журнала карточка
 * открывается на месте: кнопки списка несут хвост `_j` (`battleLog_<id>_j`).
 *
 * Текст — HTML (имена игроков и NPC экранируются), только текст (media-off по построению), ≤ лимита
 * сообщения Telegram: длинный бой показывает первые и последние раунды и «… ещё N …».
 *
 * @phpstan-import-type Entry from BattleJournalService
 * @phpstan-import-type Card from BattleJournalService
 */
final class BattleJournalAction extends BaseAction
{
    /** Бюджет текста карточки в символах: лимит Telegram 4096 с запасом на эмодзи (UTF-16) и хвост. */
    public const TEXT_BUDGET = 3500;

    private const TYPE_LABELS = [
        BattleJournalService::TYPE_PVE  => '⚔️ PvE',
        BattleJournalService::TYPE_PVP  => '🗡 PvP',
        BattleJournalService::TYPE_DUEL => '🤺 Дуэль',
    ];

    private const RESULT_LABELS = [
        BattleJournalService::RESULT_WIN     => '✅ Победа',
        BattleJournalService::RESULT_LOSS    => '❌ Поражение',
        BattleJournalService::RESULT_UNKNOWN => '❔ Итог не записан',
    ];

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $journal     = new BattleJournalService();
        $data        = (string) $this->callbackQuery->getData();

        $target = $this->navTarget();
        if (preg_match('/^battleLog_(\d+)(_j)?$/', $data, $m) === 1) {
            $res = $journal->card($characterId, (int) $m[1]);
            if (! $res['ok']) {
                return $this->alert('Этот бой не найден в твоём журнале.');
            }
            $text     = self::renderCard($res['battle']);
            $keyboard = self::cardKeyboard();
            if (($m[2] ?? '') === '') {
                // Из уведомления об итоге — новым сообщением, итог остаётся на месте.
                $target = ['chat_id' => $chatId];
            }
        } else {
            $entries  = $journal->listFor($characterId, BattleJournalService::LIMIT_BOT);
            $text     = self::renderList($entries);
            $keyboard = self::listKeyboard($entries);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($target + [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => json_encode($keyboard) ?: '{}',
        ]);
    }

    /**
     * @param list<Entry> $entries
     */
    public static function renderList(array $entries): string
    {
        $text = "📜 <b>Мои бои</b>\n\n";
        if ($entries === []) {
            return $text . 'Боёв пока нет. Сюда попадает каждый твой бой: с NPC, PvP и дуэли на 🏟 Арене.';
        }
        $text .= "Последние бои, новые сверху. Нажми номер — откроется разбор по раундам.\n\n";
        foreach ($entries as $i => $e) {
            $text .= ($i + 1) . '. ' . self::resultLabel($e['result'])
                . ' · ' . self::typeLabel($e['type'])
                . ' · ' . self::esc($e['opponent'])
                . ($e['at'] !== '' ? ' · ' . self::shortDate($e['at']) : '')
                . "\n";
        }

        return rtrim($text);
    }

    /**
     * @param Card $card
     */
    public static function renderCard(array $card): string
    {
        $head = '📜 <b>Разбор боя</b> · ' . self::typeLabel($card['type'])
            . ($card['at'] !== '' ? ' · ' . self::shortDate($card['at']) : '') . "\n"
            . '<b>' . self::esc($card['me']) . '</b> против <b>' . self::esc($card['opponent']) . "</b>\n"
            . 'Итог: ' . self::resultLabel($card['result']) . ' · раундов: ' . $card['rounds_total'];
        if ($card['duel']) {
            $head .= "\n🤺 Дуэль — без потерь: здоровье, опыт и ресурсы не меняются.";
        }
        if ($card['rounds'] === []) {
            return $head . "\n\n<i>Подробности раундов для этого боя не сохранились.</i>";
        }

        $lines = [];
        foreach ($card['rounds'] as $r) {
            $hit = $r['damage'] > 0.0
                ? '−' . self::num($r['damage']) . ($r['lucky'] ? ' ⚡' : '')
                : 'промах';
            $lines[] = $r['n'] . '. ' . self::esc($r['attacker']) . ' → ' . self::esc($r['defender']) . ': ' . $hit
                . ($r['hp_after'] !== null ? ' (осталось ' . self::num($r['hp_after']) . ' HP)' : '');
        }

        return $head . "\n\n" . implode("\n", self::fit($lines, self::TEXT_BUDGET - mb_strlen($head) - 4));
    }

    /**
     * Первые и последние строки раундов в пределах бюджета, середина — «… ещё N раундов …».
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function fit(array $lines, int $budget): array
    {
        $total = 0;
        foreach ($lines as $l) {
            $total += mb_strlen($l) + 1;
        }
        if ($total <= $budget) {
            return $lines;
        }
        $half = intdiv(max(0, $budget - 40), 2);
        $head = [];
        $used = 0;
        foreach ($lines as $l) {
            if ($used + mb_strlen($l) + 1 > $half) {
                break;
            }
            $head[] = $l;
            $used  += mb_strlen($l) + 1;
        }
        $tail = [];
        $used = 0;
        for ($i = count($lines) - 1; $i >= count($head); $i--) {
            if ($used + mb_strlen($lines[$i]) + 1 > $half) {
                break;
            }
            array_unshift($tail, $lines[$i]);
            $used += mb_strlen($lines[$i]) + 1;
        }
        $skipped = count($lines) - count($head) - count($tail);

        return array_merge($head, ['… ещё ' . $skipped . ' ' . self::roundsWord($skipped) . ' …'], $tail);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function listKeyboard(array $entries): array
    {
        $buttons = [];
        foreach ($entries as $i => $e) {
            $icon      = $e['result'] === BattleJournalService::RESULT_WIN ? '✅' : ($e['result'] === BattleJournalService::RESULT_LOSS ? '❌' : '❔');
            $buttons[] = ['text' => ($i + 1) . '. ' . $icon . ' ' . self::short($e['opponent'], 14), 'callback_data' => 'battleLog_' . $e['id'] . '_j'];
        }
        $rows   = $buttons === [] ? [] : ButtonPacker::pack($buttons);
        $rows[] = [
            ['text' => '🏟 Арена', 'callback_data' => 'arena'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function cardKeyboard(): array
    {
        return ['inline_keyboard' => [[
            ['text' => '📜 Мои бои', 'callback_data' => 'battles'],
            ['text' => '🏟 Арена', 'callback_data' => 'arena'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ]]];
    }

    private static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? '⚔️ Бой';
    }

    private static function resultLabel(string $result): string
    {
        return self::RESULT_LABELS[$result] ?? self::RESULT_LABELS[BattleJournalService::RESULT_UNKNOWN];
    }

    private static function shortDate(string $at): string
    {
        $ts = strtotime($at);

        return $ts === false ? '' : date('d.m H:i', $ts);
    }

    private static function num(float $v): string
    {
        return $v >= 10.0 ? (string) (int) round($v) : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function short(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function roundsWord(int $n): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) {
            return 'раунд';
        }
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
            return 'раунда';
        }

        return 'раундов';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
    }
}
