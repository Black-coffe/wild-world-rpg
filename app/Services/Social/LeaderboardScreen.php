<?php

declare(strict_types=1);

namespace App\Services\Social;

/**
 * Единый рендер экрана «🏆 Топ игроков».
 *
 * Один источник истины на ТРИ входа (кнопка «📊 Прогресс», callback-вкладки и текстовое
 * слово «топ»/«рейтинг»): дрейф трёх копий — та же болезнь, которую лечил `TasksSurfaceService`
 * в ADR-150 Слайс 3.
 *
 * Media-off (ADR-020): экран текстовый по природе, весь смысл в тексте.
 */
final class LeaderboardScreen
{
    public const TAB_ACTIVE  = 'active';
    public const TAB_LEGENDS = 'legends';

    private LeaderboardService $service;

    public function __construct(?LeaderboardService $service = null)
    {
        $this->service = $service ?? new LeaderboardService();
    }

    /**
     * Отключён администрацией — честный текст, а не пустой экран.
     *
     * @return array{text:string,parse_mode:string}
     */
    public function disabledPayload(): array
    {
        return [
            'text'       => "🏆 *Топ игроков временно недоступен*\n\n_Раздел отключён администрацией._",
            'parse_mode' => 'Markdown',
        ];
    }

    /**
     * Payload сообщения (text + parse_mode + reply_markup).
     *
     * @return array{text:string,parse_mode:string,reply_markup:string}
     */
    public function payload(int $characterId, string $tab = self::TAB_ACTIVE): array
    {
        $tab = $tab === self::TAB_LEGENDS ? self::TAB_LEGENDS : self::TAB_ACTIVE;

        $rows = $tab === self::TAB_LEGENDS
            ? $this->service->topLegends()
            : $this->service->topActive();

        $text = $this->header($tab) . $this->renderRows($rows, $characterId);
        $text .= $this->myPosition($characterId, $tab);
        $text .= $this->footer($tab);

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $this->keyboard($tab)]) ?: '{}',
        ];
    }

    private function header(string $tab): string
    {
        if ($tab === self::TAB_LEGENDS) {
            return "🏆 *Топ игроков — 👑 Легенды острова*\n"
                . "_За всё время. Некоторых из них на острове уже не встретить._\n\n";
        }

        $days = $this->service->activeDays();

        return "🏆 *Топ игроков — 🔥 Живые*\n"
            . "_Те, кто выходил на связь за последние {$days} дн._\n\n";
    }

    /**
     * @param list<array{rank:int,id:int,name:string,level:int}> $rows
     */
    private function renderRows(array $rows, int $characterId): string
    {
        if ($rows === []) {
            return "_Пока пусто. Стань первым._\n";
        }

        $out = '';
        foreach ($rows as $row) {
            $medal = match ($row['rank']) {
                1       => '🥇',
                2       => '🥈',
                3       => '🥉',
                default => '  ',
            };
            // Себя подсвечиваем — иначе в списке из 10 имён игрок себя не находит.
            $isMe = $row['id'] === $characterId;
            $name = $isMe ? "*{$row['name']}* ← ты" : $row['name'];
            $out .= "{$medal} {$row['rank']}. {$name} — ур. {$row['level']}\n";
        }

        return $out;
    }

    private function myPosition(int $characterId, string $tab): string
    {
        if ($characterId <= 0) {
            return '';
        }

        if ($tab === self::TAB_LEGENDS) {
            $rank  = $this->service->rankOfLegends($characterId);
            $total = $this->service->totalLegends();

            return $rank > 0 ? "\n👤 *Ты:* #{$rank} из {$total}\n" : '';
        }

        $rank  = $this->service->rankOfActive($characterId);
        $total = $this->service->totalActive();

        if ($rank <= 0) {
            return "\n👤 *Ты* пока не в списке живых — просто вернись в игру завтра, и попадёшь.\n";
        }

        return "\n👤 *Ты:* #{$rank} из {$total}\n";
    }

    private function footer(string $tab): string
    {
        $note = "\n_Место в топе — это престиж, наград за него нет._";

        if ($tab === self::TAB_ACTIVE) {
            $note .= "\n_Растёт уровень — растёшь и ты в списке._";
        }

        return $note;
    }

    /**
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function keyboard(string $tab): array
    {
        $switch = $tab === self::TAB_ACTIVE
            ? ['text' => '👑 Легенды острова', 'callback_data' => 'leaderboardLegends']
            : ['text' => '🔥 Живые', 'callback_data' => 'leaderboard'];

        return [
            [$switch],
            [['text' => '◀️ Прогресс', 'callback_data' => 'progressHub']],
        ];
    }
}
