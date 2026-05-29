<?php

declare(strict_types=1);

/**
 * W26 (ADR-081) — i18n foundation PoC: «My Economy» screen strings (en target locale).
 *
 * Keys mirror app/Language/ru/Economy.php. ICU placeholders {0},{1}… match the ru file.
 */

return [
    'char_not_found'     => 'Character not found.',
    'unavailable'        => "💰 *My Economy is temporarily unavailable*\n\n_Section disabled by the administration._",

    'title'              => '💰 *My Economy*',
    'gold'               => '💵 Gold',
    'resources'          => '📦 Resources',
    'crafted'            => '🛠 Gear/crafted',
    'net_worth'          => '💎 *Net worth: {0}*',

    'top_resources'      => '🏆 *Valuable resources:*',
    'top_crafted'        => '🛠 *Valuable crafted items:*',
    'holding_line'       => '• {0} — {1} pcs. ({2})',

    'trade_header'       => '🤝 *Trading with buyers:*',
    'trade_none'         => '_You have not traded yet — sell your surplus in the Shop to see your balance here._',
    'trade_summary'      => 'Sold: {0} · Bought: {1}',
    'trade_balance'      => 'Balance: *{0}{1}* (over {2} deals)',

    'comparison_header'  => '📊 *Compared to survivors:*',
    'comparison_server'  => 'Richer than *{0}%* of survivors · rank *#{1}* of {2}',
    'comparison_median'  => 'Server median: {0}',
    'comparison_faction' => '🏳 *{0}*: #{1} of {2} · median {3}',

    'disclaimer'         => '_Inventory valued at buyer prices. Snapshot of the current moment._',
];
