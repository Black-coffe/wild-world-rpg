<?php

declare(strict_types=1);

namespace App\Services\PVE;

/**
 * v0.51.86 (PvEService decomp Step 1) — extract HTML message template
 * для PvE fight result у dedicated formatter.
 *
 * Pure formatting: 0 model deps, 0 DB writes. Caller передає всі дані
 * як arrays. Returns HTML string (parse_mode HTML required).
 */
final class PveMessageFormatter
{
    /**
     * Формирует итоговое сообщение о бое (HTML).
     *
     * @param array<string, mixed>             $playerData   Данные персонажа.
     * @param string                           $npcName      Имя NPC.
     * @param array<string, mixed>             $fightResult  Результат BattleService::startFight.
     * @param array{coordinate_x: int|string, coordinate_y: int|string} $mapLocation
     * @param array<string, mixed>             $rewards      Награды от RewardService::grantRewards.
     */
    public function buildFightResultMessage(
        array $playerData,
        string $npcName,
        array $fightResult,
        array $mapLocation,
        array $rewards
    ): string {
        $winner        = $fightResult['winner'] ?? null;
        $loser         = $fightResult['loser'] ?? null;
        $winnerName    = $winner ? $winner->name : '???';
        $loserName     = $loser ? $loser->name : '???';
        $rounds        = $fightResult['rounds'] ?? 0;
        $firstAttacker = $fightResult['firstAttacker'] ?? 'Неизвестен';
        $x             = $mapLocation['coordinate_x'] ?? '???';
        $y             = $mapLocation['coordinate_y'] ?? '???';

        $npcMuchWeaker = ($loser && $winner && $winner->level >= 2 * $loser->level);

        $loreText = "Долгие странствия привели тебя в этот зловещий край.\n"
            . "И на пути ты встретил: <b>{$npcName}</b>\n"
            . "Не имея времени, ты вынужден сражаться!\n\n";

        $battleText  = "<u>Сводка боя:</u>\n";
        $battleText .= "• <b>Раундов:</b> {$rounds} ⚔️\n";
        $battleText .= "• <b>Первым атаковал:</b> {$firstAttacker} 🎯\n";
        $battleText .= "• <b>Проигравший:</b> {$loserName} 💀\n";
        $battleText .= "• <b>Победитель:</b> {$winnerName} 🏆\n";
        $battleText .= "• <b>Координаты:</b> X={$x}, Y={$y}\n\n";

        $resultText  = "<b>Итог боя:</b>\n";
        $resultText .= "В ожесточённой схватке <b>{$loserName}</b> пал, а <b>{$winnerName}</b> одержал верх!\n\n"
            . "⚔️ <i>Ты проявил отвагу, {$playerData['name']}!</i>\n\n";

        $currentHealth = number_format($playerData['health'], 2, '.', '');
        $currentTired  = number_format($playerData['tired'], 2, '.', '');
        $resultText .= "У тебя осталось: 💖 Здоровье: {$currentHealth}, 🥱 Выносливость: {$currentTired}\n\n";

        $rewardLines = [];
        if (!empty($rewards['exp'])) {
            $rewardLines[] = "• ✨ Опыт: +{$rewards['exp']}";
        }
        if (!empty($rewards['gold'])) {
            $rewardLines[] = "• 💰 Золото: +{$rewards['gold']}";
        }
        if (!empty($rewards['resource'])) {
            $rewardLines[] = "• 📦 Ресурс: <b>{$rewards['resource']}</b>";
        }
        if (!empty($rewards['craftedItem'])) {
            $rewardLines[] = "• 🛠 Крафт-предмет: <b>{$rewards['craftedItem']}</b>";
        }
        $extraComment = ($npcMuchWeaker) ? "Противник был в разы слабее, поэтому ты получил не так уж много наград.\n\n" : "";
        $rewardText = !empty($rewardLines)
            ? "🎖 <b>Награды за победу:</b>\n" . implode("\n", $rewardLines) . "\n\n"
            : "Противник был слишком слаб, ничего ценного не досталось.\n\n";

        return "🤖 <b>PvE-бой завершён!</b>\n\n"
            . $loreText
            . $battleText
            . $resultText
            . $extraComment
            . $rewardText;
    }
}
