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

    /**
     * Услуги жителей → существующий ungated callback (Фаза 1b). trade=продажа ресурсов за золото
     * (SellAction, с переходом в магазин), repair=ремонт изношенных инструментов (RepairToolsListAction).
     */
    private const SERVICE_ROUTES = [
        'trade'  => ['🛒 Торговать', 'sell'],
        'repair' => ['🔧 Ремонт', 'repairToolsList'],
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

        // Жители (ростер + имена) + услуги (Фаза 1b: data-driven по service_key, реюз ungated flows).
        $residents      = (new SettlementNpcModel())->forSettlement($sid);
        $lines          = [];
        $serviceButtons = [];
        $seenService    = [];
        foreach ($residents as $r) {
            $npcId  = is_numeric($r['npc_id'] ?? null) ? (int) $r['npc_id'] : 0;
            $role   = is_string($r['role'] ?? null) ? $r['role'] : 'vendor';
            $svcKey = is_string($r['service_key'] ?? null) ? $r['service_key'] : '';
            $npcRow = $npcId > 0 ? (new \App\Models\NpcModel())->find($npcId) : null;
            $nm     = is_array($npcRow) && is_string($npcRow['npc_name_ru'] ?? null) ? $npcRow['npc_name_ru'] : 'Житель';
            $rIcon  = self::ROLE_ICONS[$role] ?? '👤';
            $svcTag = $svcKey !== '' && isset(self::SERVICE_ROUTES[$svcKey]) ? ' — ' . self::SERVICE_ROUTES[$svcKey][0] : '';
            $lines[] = "{$rIcon} {$nm}{$svcTag}";

            if ($svcKey !== '' && isset(self::SERVICE_ROUTES[$svcKey]) && ! isset($seenService[$svcKey])) {
                $seenService[$svcKey] = true;
                $serviceButtons[]     = ['text' => self::SERVICE_ROUTES[$svcKey][0], 'callback_data' => self::SERVICE_ROUTES[$svcKey][1]];
            }
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

        // Действия: услуги жителей (Фаза 1b) + поговорить + уйти.
        $rows = [];
        for ($i = 0, $n = count($serviceButtons); $i < $n; $i += 2) {
            $rows[] = array_slice($serviceButtons, $i, 2);
        }
        if ((new NpcInteractionService())->enabled()) {
            $rows[] = [['text' => '💬 Поговорить с жителями', 'callback_data' => 'npcEncounter']];
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
