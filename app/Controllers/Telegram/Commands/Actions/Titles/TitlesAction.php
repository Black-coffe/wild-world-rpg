<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Titles;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\TitleService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E11 (ADR-112) — экран «🎖 Титулы». Callback `titles`.
 *
 * Открытые титулы (с пометкой активного + кнопки «надеть» `titleSet_<id>`) + закрытые (как
 * получить + редкость %). Пустое состояние — как получить первый (UX-DISCOVERABILITY). Вход —
 * кнопка с карточки Перса (видна при killswitch on). Текстовый экран (media-off), edit-in-place.
 */
final class TitlesAction extends BaseAction
{
    private TitleService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new TitleService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->service->enabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🎖 *Титулы временно недоступны*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $this->buildText($charId),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->buildKeyboard($charId)),
        ]);
    }

    private function buildText(int $charId): string
    {
        $defs     = $this->service->definitions();
        $unlocked = array_flip($this->service->unlockedTitleIds($charId));
        $activeId = $this->service->activeTitleId($charId);
        $rarity   = $this->service->rarityPercentMap();

        $text = "🎖 *Титулы*\n\n";

        $ownedCount = count($unlocked);
        if ($ownedCount === 0) {
            $text .= "У тебя пока нет титулов.\n\n";
            $text .= "Титулы — это видимый знак статуса рядом с именем. Они открываются за *уровни* "
                . "(первый — уже за 1-й уровень) и за *достижения*.\n\n";
            $text .= "_Качайся и открывай достижения — первый титул не за горами._";
            return $text;
        }

        $text .= "Открыто: *{$ownedCount}/" . count($defs) . "*\n\n";

        $text .= "*Твои титулы:*\n";
        foreach ($defs as $t) {
            $id = is_numeric($t['id'] ?? null) ? (int) $t['id'] : 0;
            if (! isset($unlocked[$id])) {
                continue;
            }
            $icon = is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖';
            $name = is_string($t['name'] ?? null) ? $t['name'] : '';
            $mark = $id === $activeId ? ' ✅ _активен_' : '';
            $text .= "{$icon} *{$name}*{$mark}{$this->rarityTag($rarity[$id] ?? null)}\n";
        }

        // Закрытые — как получить.
        $lockedLines = [];
        foreach ($defs as $t) {
            $id = is_numeric($t['id'] ?? null) ? (int) $t['id'] : 0;
            if (isset($unlocked[$id])) {
                continue;
            }
            $icon = is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖';
            $name = is_string($t['name'] ?? null) ? $t['name'] : '';
            $desc = is_string($t['description'] ?? null) ? $t['description'] : '';
            $lockedLines[] = "🔒 {$icon} {$name} — _{$desc}_{$this->rarityTag($rarity[$id] ?? null)}";
        }
        if ($lockedLines !== []) {
            $text .= "\n*Ещё не открыто:*\n" . implode("\n", $lockedLines) . "\n";
        }

        $text .= "\n_Нажми на титул ниже, чтобы надеть его._";
        return $text;
    }

    /**
     * Кнопки «надеть» для открытых, но НЕ активных титулов (по 2 в строку) + назад.
     *
     * @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}
     */
    private function buildKeyboard(int $charId): array
    {
        $defs     = $this->service->definitions();
        $unlocked = array_flip($this->service->unlockedTitleIds($charId));
        $activeId = $this->service->activeTitleId($charId);

        $btns = [];
        foreach ($defs as $t) {
            $id = is_numeric($t['id'] ?? null) ? (int) $t['id'] : 0;
            if (! isset($unlocked[$id]) || $id === $activeId) {
                continue;
            }
            $icon = is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖';
            $name = is_string($t['name'] ?? null) ? $t['name'] : '';
            $btns[] = ['text' => "{$icon} {$name}", 'callback_data' => 'titleSet_' . $id];
        }

        $rows = [];
        for ($i = 0; $i < count($btns); $i += 2) {
            $rows[] = array_slice($btns, $i, 2);
        }
        $rows[] = [['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character']];

        return ['inline_keyboard' => $rows];
    }

    /** Тег редкости « · N%» (+💎 ≤5%). null/0 → пусто. */
    private function rarityTag(?float $pct): string
    {
        if ($pct === null || $pct <= 0.0) {
            return '';
        }
        $gem = $pct <= 5.0 ? ' 💎' : '';
        $s   = $pct == floor($pct) ? (string) (int) $pct : number_format($pct, 1, '.', '');
        return " · {$s}%{$gem}";
    }
}
