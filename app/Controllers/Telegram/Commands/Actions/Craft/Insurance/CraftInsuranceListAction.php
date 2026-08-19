<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Insurance;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Services\Display\MarkdownSafe;
use App\Services\Notifications\MediaSender;
use App\Services\Player\CraftInsuranceService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V24 (ADR-056) — список eligible нестрахованных предметов для NPC-страхового
 * агента. Entry-point из `PersonalInsurance` экрана (кнопка «📦 Крафт-страховка»)
 * или прямого callback `craftInsuranceList`.
 *
 * Eligible = `crafted_items.type` ∈ `craft_insurance.eligible_types` (default
 * robots/workbench/transport), `crafted_items_log.insured=0`, `quantity>0`.
 */
class CraftInsuranceListAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private CraftInsuranceService $insurance;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel  = new CraftedItemsLogModel();
        $this->insurance = new CraftInsuranceService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $this->insurance->enabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'text'       => '🛡 *Страховка крафта* временно не работает.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = $this->extractCharId($character);
        $types  = $this->insurance->eligibleTypes();

        $rows = $this->logModel
            ->select('crafted_items_log.id AS log_id, crafted_items_log.quantity AS qty, crafted_items_log.insured, crafted_items.name_rus, crafted_items.name_eng, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $charId)
            ->where('crafted_items_log.insured', 0)
            ->where('crafted_items_log.quantity >', 0)
            ->whereIn('crafted_items.type', $types)
            ->orderBy('crafted_items.type', 'ASC')
            ->orderBy('crafted_items.name_rus', 'ASC')
            ->findAll();

        $policyRows = $this->logModel
            ->select('crafted_items.name_rus, crafted_items_log.quantity AS qty')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $charId)
            ->where('crafted_items_log.insured', 1)
            ->where('crafted_items_log.quantity >', 0)
            ->orderBy('crafted_items.name_rus', 'ASC')
            ->findAll();

        $text  = "🛡 *Страховка крафта* (NPC-страховой агент)\n\n"
            . "_Полис вечный — оплата один раз, защита до продажи/потери. При смерти insured-предметы НЕ списываются._\n\n"
            . $this->renderPolicies($policyRows);

        if (empty($rows)) {
            $text .= empty($policyRows)
                ? "_У тебя нет предметов под страховку (типы: " . $this->insurance->typeLabels($types) . ")._"
                : '_Всё, что подходит под страховку, уже застраховано._';

            return MediaSender::editTextOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛡 Личная страховка', 'callback_data' => 'PersonalInsurance'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
                ]]]),
            ]);
        }

        $buttons = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $logId = is_numeric($row['log_id'] ?? null) ? (int) $row['log_id'] : 0;
            if ($logId <= 0) {
                continue;
            }
            $name  = MarkdownSafe::name(is_string($row['name_rus'] ?? null) ? $row['name_rus'] : '???', '???');
            $qty   = is_numeric($row['qty'] ?? null) ? (int) $row['qty'] : 0;
            $type  = is_string($row['type'] ?? null) ? $row['type'] : '';
            $label = $this->insurance->typeLabel($type);

            $text .= "• *{$name}* ({$label}) × {$qty}\n";
            $buttons[] = [[
                'text'          => "🛡 {$name} ×{$qty}",
                'callback_data' => "craftInsure_{$logId}",
            ]];
        }

        $buttons[] = [
            ['text' => '🛡 Личная страховка', 'callback_data' => 'PersonalInsurance'],
            ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /**
     * Блок «Действующие полисы» — рисуется над списком доступного к страхованию,
     * а при пустом $rows заменяет собой лживое «у тебя нет предметов» (V24.2).
     * Лимит строк защищает caption от тихого обрезания Telegram (>1024 символов).
     *
     * @param list<mixed> $policyRows
     */
    private function renderPolicies(array $policyRows): string
    {
        if ($policyRows === []) {
            return '';
        }

        $limit = 15;
        $lines = [];
        foreach ($policyRows as $row) {
            if (! is_array($row) || count($lines) >= $limit) {
                continue;
            }
            $name = MarkdownSafe::name(is_string($row['name_rus'] ?? null) ? $row['name_rus'] : '???', '???');
            $qty  = is_numeric($row['qty'] ?? null) ? (int) $row['qty'] : 0;
            $lines[] = "• {$name} × {$qty}";
        }

        $rest  = count($policyRows) - count($lines);
        $block = "📜 *Действующие полисы:*\n" . implode("\n", $lines);
        if ($rest > 0) {
            $block .= "\n_…и ещё {$rest}_";
        }

        return $block . "\n\n";
    }

    private function extractCharId(mixed $character): int
    {
        if (is_array($character)) {
            $v = $character['id'] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($character)) {
            $v = $character->id ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }
}
