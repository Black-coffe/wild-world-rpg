<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

/**
 * v0.51.104 (GatherTaskHandler decomp Step 1) — extract HTML reply
 * формування для gather completion у dedicated formatter.
 *
 * Pure formatting — caller передає pre-loaded resource map + tool map
 * (formatter сам не робить DB queries).
 */
final class GatherMessageFormatter
{
    /**
     * @param array<int, mixed>            $foundResources    list of [resource_id, amount, type]
     * @param string                       $biomeName         localized biome name
     * @param int                          $spentMinutes      duration of gather
     * @param array<int, object>           $resourceMap       resource_id => ResourceEntity (pre-loaded)
     * @param array<string, int>           $usedToolsCount    tool_name_eng => uses_count
     * @param array<string, array<string, mixed>> $toolByName tool_name_eng => crafted_items row (pre-loaded)
     * @param array<string, bool>          $brokenTools       S4: tool_name_eng → true для инструментов,
     *                                                        сломавшихся за сессию (последняя единица
     *                                                        исчерпала durability)
     * @param list<string>                 $signatureNames    V22 (ADR-054): signature-ресурсы биома
     *                                                        для хинта «этот биом богат на …»
     * @param string|null                  $cargoNote         transport-15/M10: строка про груз —
     *                                                        либо про то, что уехало на склад базы,
     *                                                        либо (машина без базы) что везти
     *                                                        некуда (`GatherResultPersister::persist`,
     *                                                        `$foldCargoNote=true`). `null` — как
     *                                                        сегодня, без грузовой машины строка
     *                                                        не добавляется вовсе (байт-идентично).
     *
     * @return array{text: string, keyboard: string}
     */
    public function buildResourcesFoundReply(
        array $foundResources,
        string $biomeName,
        int $spentMinutes,
        array $resourceMap,
        array $usedToolsCount,
        array $toolByName,
        array $brokenTools = [],
        array $signatureNames = [],
        ?string $cargoNote = null
    ): array {
        $msg  = "<b>Успешная добыча ресурсов!</b>\n";
        $msg .= "Время, затраченное на добычу: <b>{$spentMinutes}</b> мин.\n";
        $msg .= "Биом: <b>" . htmlspecialchars($biomeName, ENT_QUOTES, 'UTF-8') . "</b>\n";

        // V22 (ADR-054): биом-специализация — куда идти за чем (media-off safe: текст в caption).
        if ($signatureNames !== []) {
            $sig = htmlspecialchars(implode(', ', $signatureNames), ENT_QUOTES, 'UTF-8');
            $msg .= "🌟 Этот биом богат на: <b>{$sig}</b>\n";
        }
        $msg .= "\n";

        $groupedByRarity = []; // rarity (int) => list of [entity, amount]
        foreach ($foundResources as $item) {
            $entity = $resourceMap[(int) $item['resource_id']] ?? null;
            if ($entity === null) {
                continue;
            }
            $groupedByRarity[$entity->rarity][] = [$entity, (int) $item['amount']];
        }
        krsort($groupedByRarity);

        $rarityEmojis = [
            1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣',
            6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'
        ];

        if (empty($groupedByRarity)) {
            $msg .= "<i>Не удалось найти ресурсы.</i>\n";
        } else {
            $msg .= "<b>Найдены следующие ресурсы:</b>\n";
            foreach ($groupedByRarity as $rarity => $items) {
                $rarityEmoji = $rarityEmojis[$rarity] ?? (string) $rarity;
                $msg .= "\n<b>{$rarityEmoji} Редкость...</b>\n";
                foreach ($items as [$entity, $amount]) {
                    $resourceName = htmlspecialchars($entity->name, ENT_QUOTES, 'UTF-8');
                    $msg .= "— <b>{$resourceName}</b>: {$amount} шт.\n";
                }
            }
        }

        if (!empty($usedToolsCount)) {
            $msg .= "\n<b>Использованные инструменты:</b>\n";

            foreach ($usedToolsCount as $toolNameEng => $countUsed) {
                $toolRow     = $toolByName[$toolNameEng] ?? null;
                $toolNameRus = is_array($toolRow) && isset($toolRow['name_rus']) && is_string($toolRow['name_rus'])
                    ? $toolRow['name_rus']
                    : $toolNameEng;
                $toolNameEsc = htmlspecialchars($toolNameRus, ENT_QUOTES, 'UTF-8');
                $msg .= "- {$toolNameEsc}: {$countUsed} раз(а)\n";
            }
        }

        // S4 (v0.51.186+): уведомление о сломавшихся инструментах. Раньше
        // tool тихо удалялся из crafted_items_log без оповещения игрока.
        if (!empty($brokenTools)) {
            $msg .= "\n<b>💔 Инструменты сломались:</b>\n";
            foreach (array_keys($brokenTools) as $toolNameEng) {
                $toolRow     = $toolByName[$toolNameEng] ?? null;
                $toolNameRus = is_array($toolRow) && isset($toolRow['name_rus']) && is_string($toolRow['name_rus'])
                    ? $toolRow['name_rus']
                    : $toolNameEng;
                $toolNameEsc = htmlspecialchars($toolNameRus, ENT_QUOTES, 'UTF-8');
                $msg .= "- {$toolNameEsc} — последняя единица исчерпала прочность.\n";
            }
            $msg .= "<i>Скрафти новый, чтобы продолжить получать бонус добычи.</i>\n";
        }

        // transport-15: строка про увезённый на склад груз живёт внутри этого же сообщения
        // (второе сообщение на каждую добычу с грузовой машиной было заметным шумом).
        // Нет грузовой машины / нечего везти → $cargoNote===null → строка не появляется вовсе.
        if ($cargoNote !== null && $cargoNote !== '') {
            $msg .= "\n\n" . htmlspecialchars($cargoNote, ENT_QUOTES, 'UTF-8');
        }

        $msg .= "\n<b>Твои усилия были вознаграждены!</b>";

        // ADR-150 (чистка дублей): суп дублировал нижнюю панель → канонический «что дальше».
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith()
            : [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ]
                ]
            ];

        return [
            'text'     => $msg,
            'keyboard' => json_encode($keyboard) ?: '',
        ];
    }
}
