<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\SettlementNpcModel;
use App\Services\NPC\NpcInteractionService;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\SettlementZoneService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-101 Фаза 1 — экран-хаб поселения «Перекрёсток» (Rust-style Outpost).
 *
 * Callback `settleHub`: по клетке персонажа находит активное поселение (SettlementZoneService),
 * рендерит хаб — имя, тип, правило зоны, список жителей и действия. Фаза 1a: «поговорить с
 * жителями» (→ встреча NPC, safe-зона блокирует атаку) + уход. Лавка/ремонт — Фаза 1b.
 *
 * Caption самодостаточен (media-off). Гейт killswitch — через SettlementZoneService.policyAt.
 */
final class SettlementHubAction extends BaseAction
{
    /** Метка типа поселения для caption. */
    private const TYPE_LABELS = [
        'outpost' => '🏚 Аутпост — нейтральный торговый узел',
        'bandit'  => '🔥 Логово — опасное место',
        'faction' => '🛡 Оплот фракции',
        'ruins'   => '🏛 Руины',
    ];

    /** Эмодзи роли жителя. */
    private const ROLE_ICONS = [
        'mayor'   => '🎖',
        'vendor'  => '🛒',
        'guard'   => '🔫',
        'quest'   => '📜',
        'service' => '🔧',
    ];

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $cell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $x    = is_numeric($character['coordinate_x'] ?? null) ? (int) $character['coordinate_x'] : null;
        $y    = is_numeric($character['coordinate_y'] ?? null) ? (int) $character['coordinate_y'] : null;

        $zone   = new SettlementZoneService();
        $policy = $zone->policyAt($cell, $x, $y);
        if ($policy === null) {
            return $this->alert('Здесь нет поселения.');
        }
        $settlement = $policy['settlement'];
        $sid    = is_numeric($settlement['id'] ?? null) ? (int) $settlement['id'] : 0;
        $name   = is_string($settlement['name_ru'] ?? null) ? $settlement['name_ru'] : 'Поселение';
        $icon   = is_string($settlement['icon'] ?? null) && $settlement['icon'] !== '' ? $settlement['icon'] : '🏚';
        $type   = is_string($settlement['type'] ?? null) ? $settlement['type'] : 'outpost';
        $descr  = is_string($settlement['description_ru'] ?? null) ? $settlement['description_ru'] : '';

        // Жители (ростер + имена).
        $residents = (new SettlementNpcModel())->forSettlement($sid);
        $lines     = [];
        foreach ($residents as $r) {
            $npcId  = is_numeric($r['npc_id'] ?? null) ? (int) $r['npc_id'] : 0;
            $role   = is_string($r['role'] ?? null) ? $r['role'] : 'vendor';
            $npcRow = $npcId > 0 ? (new \App\Models\NpcModel())->find($npcId) : null;
            $nm     = is_array($npcRow) && is_string($npcRow['npc_name_ru'] ?? null) ? $npcRow['npc_name_ru'] : 'Житель';
            $rIcon  = self::ROLE_ICONS[$role] ?? '👤';
            $lines[] = "{$rIcon} {$nm}";
        }

        $typeLabel = self::TYPE_LABELS[$type] ?? '🏚 Поселение';
        $zoneLine  = $policy['policy'] === 'safe'
            ? '🕊 *Безопасная зона.* Здесь не нападают — таков уговор.'
            : ($policy['policy'] === 'hostile' ? '☠️ *Опасная зона.* Держи ухо востро.' : '');

        $head  = "{$icon} *{$name}*\n";
        $head .= "_{$typeLabel}_\n\n";
        if ($zoneLine !== '') {
            $head .= "{$zoneLine}\n\n";
        }
        if ($descr !== '') {
            $head .= "{$descr}\n\n";
        }
        if ($lines !== []) {
            $head .= "👥 *Жители:*\n" . implode("\n", $lines) . "\n\n";
        }
        $head .= 'Что будешь делать?';

        // Действия (Фаза 1a): поговорить с жителями + уйти. Лавка/ремонт — Фаза 1b.
        $rows = [];
        if ((new NpcInteractionService())->enabled()) {
            $rows[] = [['text' => '💬 Подойти к жителям', 'callback_data' => 'npcEncounter']];
        }
        $rows[] = [['text' => '🚶 Уйти', 'callback_data' => 'move']];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $head,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
    }
}
