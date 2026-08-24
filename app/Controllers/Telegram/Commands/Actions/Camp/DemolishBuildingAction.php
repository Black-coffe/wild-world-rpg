<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Services\Bases\BaseBuildingsList;
use App\Services\Telegram\ButtonPacker;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * chat-requests-batch-07 — «Снос одной постройки».
 *
 * Ярик (14.06.2026): «Я просто настроил лишних думая что бонус добычи будет» /
 * «строю скважину, плачу за нее налог но добычи нет, а сломать можно лишние?».
 * Дубли не суммируют бонус (намеренно, Non-goal) — и налог тоже платится за СТРОКУ
 * `character_buildings` (тип постройки на базе), а не за штуку в её `amount`
 * (`TaxCollectionHandler::execute()` — `SUM(tax)` по строкам, слова `amount` в файле
 * нет). До этой story убрать ошибочную постройку можно было только снеся ВСЮ базу
 * (`DeleteBaseAction`).
 *
 * Единственная точка входа — список построек этой базы, ровно тот состав, что
 * считает {@see BaseBuildingsList::buildSummary()} (дубли и суммарный налог видны
 * там же). Кнопка на карточки отдельных построек (их 17 типов) НЕ добавлена —
 * решение владельца: снос стартует только из списка.
 *
 * callback_data:
 *   - `demolishBuilding` — список построек текущей/единственной базы, кнопка на
 *     каждую строку ведёт в {@see DemolishBuildingConfirmAction} (`demolishBuildingConfirm_<id>`).
 *
 * Ресурсы, потраченные на снесённую постройку, НЕ возвращаются — цена ошибки
 * остаётся, экран прямо предупреждает об этом до подтверждения (сам снос — на
 * следующем шаге, здесь только список).
 */
final class DemolishBuildingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->reply($chatId, '🤖 Пользователь или персонаж не найден.');
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $cellNr = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;

        $targetCell = (new ClaimedCellModel())->resolveTargetBaseCell($charId, $cellNr);
        if ($targetCell === null) {
            return $this->reply($chatId, $this->noTargetBaseMessage($charId));
        }

        $rows = (new BaseBuildingsList())->demolishRows($charId, $targetCell);
        if ($rows === []) {
            return $this->reply(
                $chatId,
                "🔨 *Снос постройки*\n\nНа этой базе нет построек — сносить нечего.",
                [[['text' => '🏠 База', 'callback_data' => 'Base']]],
            );
        }

        $text = "🔨 *Снос постройки*\n\n"
            . "Выбери, что снести. Налог считается за тип постройки на базе целиком (одна "
            . "запись — одна ставка, не за штуку) — несколько одинаковых построек по ошибке "
            . "САМИ ПО СЕБЕ налог не увеличивают. *Ресурсы, потраченные на постройки, не "
            . "возвращаются* — цена ошибки остаётся.\n\n";

        $buttons = [];
        foreach ($rows as $r) {
            // 🔴 Ревью 24.08.2026: постройки одного типа на одной базе стекаются в
            // ОДНУ строку с `amount` > 1 (GenericBuildingCompletionHandler). Список
            // обязан называть фактическое количество — иначе снос стёр бы весь стек,
            // а игрок думал, что сносит одну.
            $amountSuffix = $r['amount'] > 1 ? " ×{$r['amount']}" : '';
            $text        .= "🏗 *{$r['name']}* L{$r['level']}{$amountSuffix} — налог *{$r['tax']}* ед. золота/сутки\n";
            $buttons[]    = [
                'text'          => "🔨 {$r['name']} L{$r['level']}" . ($r['amount'] > 1 ? " ({$r['amount']} шт.)" : ''),
                'callback_data' => "demolishBuildingConfirm_{$r['id']}",
            ];
        }

        $rowsOut   = ButtonPacker::pack($buttons);
        $rowsOut[] = [['text' => '🏠 База', 'callback_data' => 'Base']];

        return $this->reply($chatId, $text, $rowsOut);
    }

    /** Зеркало `DeleteBaseAction::noTargetBaseMessage()` — та же неоднозначность мультибэйса. */
    private function noTargetBaseMessage(int $charId): string
    {
        $bases = (new ClaimedCellModel())->countActiveBases($charId);
        if ($bases === 0) {
            return '🤖 У тебя нет базы — сносить нечего.';
        }

        return '🤖 У тебя несколько баз. Чтобы снести постройку на нужной, *встань на неё* '
            . '(телепортируйся или дойди), затем открой снос заново.';
    }

    /**
     * @param list<list<array{text:string, callback_data:string}>> $rows
     */
    private function reply(int $chatId, string $text, array $rows = []): ServerResponse
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($rows !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $rows]);
        }

        return Request::sendMessage($payload);
    }
}
