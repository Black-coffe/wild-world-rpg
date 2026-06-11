<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Collections;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\CollectionService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * E19 (ADR-119) — экран одной коллекции. Callback `collOpen_<id>` (prefix `collOpen`).
 *
 * Слоты со статусом ✅/⬜ + есть/нужно; кнопки «Сдать <ресурс>» (`collDonate_<slotId>`) для пустых
 * слотов, которые игрок может закрыть. Завершённая коллекция — строка с титулом-наградой + редкость.
 * Текстовый (media-off самодостаточен). Публичный {@see renderFor} реюзит CollectionDonateAction.
 */
final class CollectionOpenAction extends BaseAction
{
    private CollectionService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new CollectionService();
    }

    public function handle(): ServerResponse
    {
        $data         = (string) $this->callbackQuery->getData();
        $parts        = explode('_', $data);
        $collectionId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return $this->renderFor($collectionId);
    }

    /** Перерисовать экран коллекции по явному id (реюз из CollectionDonateAction). */
    public function renderFor(int $collectionId): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character || ! $this->service->isEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🏛 *Музей недоступен.*",
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId     = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $collection = $this->service->collectionById($collectionId);
        if ($collection === null) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'      => $chatId,
                'text'         => "Коллекция не найдена.",
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '◀️ Музей', 'callback_data' => 'museum']]]]) ?: '{}',
            ]);
        }

        [$text, $keyboard] = $this->buildView($charId, $collection);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard) ?: '{}',
        ]);
    }

    /**
     * @param array<int|string,mixed> $collection
     * @return array{0:string, 1:array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}}
     */
    private function buildView(int $charId, array $collection): array
    {
        $cid    = is_numeric($collection['id'] ?? null) ? (int) $collection['id'] : 0;
        $icon   = is_string($collection['icon'] ?? null) && $collection['icon'] !== '' ? $collection['icon'] : '🏛';
        $name   = is_string($collection['name'] ?? null) ? $collection['name'] : '';
        $desc   = is_string($collection['description'] ?? null) ? $collection['description'] : '';
        $slots  = $this->service->slotsFor($cid);
        $filled = array_flip($this->service->filledSlotIds($charId));

        $total = count($slots);
        $done  = 0;
        $lines = [];
        $btns  = [];

        foreach ($slots as $s) {
            $sid  = is_numeric($s['id'] ?? null) ? (int) $s['id'] : 0;
            $res  = is_string($s['resource_name'] ?? null) ? $s['resource_name'] : '';
            $need = is_numeric($s['required_qty'] ?? null) ? max(1, (int) $s['required_qty']) : 1;
            $isFilled = isset($filled[$sid]);

            if ($isFilled) {
                $done++;
                $lines[] = "✅ {$res} ×{$need}";
                continue;
            }

            $have = $this->service->heldQty($charId, $res);
            $lines[] = "⬜ {$res} ×{$need} — _у тебя {$have}/{$need}_";
            if ($have >= $need) {
                $btns[] = ['text' => "Сдать {$res} (×{$need})", 'callback_data' => 'collDonate_' . $sid];
            }
        }

        $bar      = $this->progressBar($done, $total);
        $complete = $total > 0 && $done >= $total;

        $text = "{$icon} *{$name}*\n";
        if ($desc !== '') {
            $text .= "_{$desc}_\n";
        }
        $text .= "\n{$bar} *{$done}/{$total}*\n\n" . implode("\n", $lines) . "\n";

        if ($complete) {
            $rewardName = $this->rewardTitleName($collection);
            $text .= "\n✅ *Коллекция собрана!*";
            if ($rewardName !== null) {
                $text .= " Награда: 🎖 *{$rewardName}*";
            }
        } else {
            $text .= "\n_Сдай недостающие предметы — они уйдут в музей навсегда._";
        }

        $rows = [];
        foreach ($btns as $b) {
            $rows[] = [$b];
        }
        $rows[] = [['text' => '◀️ Музей', 'callback_data' => 'museum']];

        return [$text, ['inline_keyboard' => $rows]];
    }

    /**
     * @param array<int|string,mixed> $collection
     */
    private function rewardTitleName(array $collection): ?string
    {
        $key = is_string($collection['reward_title_key'] ?? null) ? $collection['reward_title_key'] : '';
        if ($key === '') {
            return null;
        }
        $r   = \Config\Database::connect()->table('titles')->select('name')->where('title_key', $key)->get();
        $row = $r === false ? null : $r->getRowArray();
        return is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : null;
    }

    private function progressBar(int $done, int $total): string
    {
        if ($total <= 0) {
            return '▱';
        }
        $done = max(0, min($done, $total));
        return str_repeat('▰', $done) . str_repeat('▱', $total - $done);
    }
}
