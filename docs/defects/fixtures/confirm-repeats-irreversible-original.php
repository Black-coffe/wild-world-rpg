<?php

// Исходный случай (v0.51.688, BulkSellAction): кнопка подтверждения опта без отпечатка плана.
// Второе нажатие той же кнопки пересчитывало план от остатка и продавало ещё N%.

$goScope = $rarity === null ? "all_{$percent}" : "rarity_{$rarity}_{$percent}";

$rows = [
    [['text' => $confirmText, 'callback_data' => "bulkSell_go_{$goScope}"]],
];

$result = (new ResourceTradeService())->bulkSellResources($charArr, $percent, $rarity);
