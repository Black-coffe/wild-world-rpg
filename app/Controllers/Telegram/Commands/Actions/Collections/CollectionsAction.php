<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Collections;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\CollectionService;
use App\Services\Telegram\ButtonPacker;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E19 (ADR-119) — экран «🏛 Музей базы» (обзор коллекций). Callback `museum` / `museumLocked`.
 *
 * Список коллекций с прогресс-баром + редкостью; каждая → `collOpen_<id>`. При уровне < min —
 * lock-вид с пояснением (UX-DISCOVERABILITY). Вход — хаб «📊 Прогресс». Текстовый (media-off),
 * edit-in-place.
 */
final class CollectionsAction extends BaseAction
{
    private CollectionService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new CollectionService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->service->isEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🏛 *Музей временно недоступен*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level  = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $min    = $this->service->minLevel();

        [$text, $keyboard] = $level < $min
            ? $this->lockView($level, $min)
            : $this->overview($charId);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard) ?: '{}',
        ]);
    }

    /**
     * Lock-вид для уровня ниже гейта (вход НЕ скрыт — объясняем prerequisite).
     *
     * @return array{0:string, 1:array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}}
     */
    private function lockView(int $level, int $min): array
    {
        $text = "🔒 *Музей базы*\n\n"
            . "Музей открывается на *{$min}-м уровне*. Сейчас ты {$level}-го.\n\n"
            . "Копи редкие находки — реликвии древних, сокровища глубин, — чтобы было что выставить, "
            . "когда дорастёшь. За полные коллекции дают почётные титулы.";
        $keyboard = ['inline_keyboard' => [[['text' => '◀️ Прогресс', 'callback_data' => 'progressHub']]]];
        return [$text, $keyboard];
    }

    /**
     * Обзор всех коллекций: прогресс-бар + редкость; кнопка на каждую коллекцию.
     *
     * @return array{0:string, 1:array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}}
     */
    private function overview(int $charId): array
    {
        $defs   = $this->service->definitions();
        $filled = array_flip($this->service->filledSlotIds($charId));
        $rarity = $this->service->rarityPercentMap();

        $text = "🏛 *Музей базы*\n\n"
            . "Сдавай редкие находки в коллекции — экспонат останется здесь навсегда. "
            . "За полный сет — почётный титул.\n\n";

        $rows              = [];
        $collectionButtons = [];
        if ($defs === []) {
            $text .= "_Коллекций пока нет._";
        } else {
            $text .= "*Коллекции:*\n";
            foreach ($defs as $c) {
                $cid   = is_numeric($c['id'] ?? null) ? (int) $c['id'] : 0;
                $icon  = is_string($c['icon'] ?? null) && $c['icon'] !== '' ? $c['icon'] : '🏛';
                $name  = is_string($c['name'] ?? null) ? $c['name'] : '';
                $slots = $cid > 0 ? $this->service->slotsFor($cid) : [];
                $total = count($slots);
                $done  = 0;
                foreach ($slots as $s) {
                    $sid = is_numeric($s['id'] ?? null) ? (int) $s['id'] : 0;
                    if (isset($filled[$sid])) {
                        $done++;
                    }
                }
                $complete = $total > 0 && $done >= $total;
                $bar      = $this->progressBar($done, $total);
                $mark     = $complete ? ' ✅ _собрана_' : '';
                $text .= "{$icon} *{$name}* — {$bar} {$done}/{$total}{$mark}{$this->rarityTag($rarity[$cid] ?? null)}\n";

                $collectionButtons[] = ['text' => "{$icon} {$name} ({$done}/{$total})", 'callback_data' => 'collOpen_' . $cid];
            }
            // Коллекции пакуем по 2-3 в ряд: колонкой список был бы простынёй.
            foreach (ButtonPacker::pack($collectionButtons) as $packedRow) {
                $rows[] = $packedRow;
            }
            $text .= "\n_Открой коллекцию, чтобы посмотреть, что нужно сдать._";
        }

        $rows[] = [['text' => '◀️ Прогресс', 'callback_data' => 'progressHub']];
        return [$text, ['inline_keyboard' => $rows]];
    }

    private function progressBar(int $done, int $total): string
    {
        if ($total <= 0) {
            return '▱';
        }
        $done = max(0, min($done, $total));
        return str_repeat('▰', $done) . str_repeat('▱', $total - $done);
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
