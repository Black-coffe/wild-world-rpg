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
        'anomaly' => '🔬 Аномалия пояса',
    ];

    /**
     * Услуги жителей → существующий ungated callback (Фаза 1b). trade=продажа ресурсов за золото
     * (SellAction, с переходом в магазин), repair=ремонт изношенных инструментов (RepairToolsListAction).
     */
    private const SERVICE_ROUTES = [
        'trade'       => ['🛒 Торговать', 'sell'],
        'repair'      => ['🔧 Ремонт', 'repairToolsList'],
        'blackmarket' => ['🖤 Чёрный рынок', 'sell'],          // Фаза 2 — Логово (тёмная торговля)
        'casino'      => ['🎰 Казино', 'entertainment'],       // Фаза 2 — Логово (азартные игры)
        'project'     => ['💎 Проект фракции', 'factionProject'], // Фаза 3 — оплот (factionProject сам гейтит)
        'ruinloot'    => ['💀 Обыскать руины', 'ruinLoot'],     // Фаза 4 — руины (повторяемый лут по кулдауну)
        'anomalyloot' => ['🔬 Исследовать аномалию', 'anomalyLoot'], // E16 Ф2 — поясная аномалия (лут по кулдауну)
    ];

    /** Услуги по ТИПУ поселения (в дополнение к резидентским service_key). Ф3: оплоты дают лавку+проект; Ф4: руины — обыск; E16 Ф2: аномалии — исследование. */
    private const TYPE_SERVICES = [
        'faction' => ['trade', 'project'],
        'ruins'   => ['ruinloot'],
        'anomaly' => ['anomalyloot'],
    ];

    /** Метка фракции для caption оплота. */
    private const FACTION_LABELS = [
        1 => 'Милитари', 2 => 'Партизаны', 3 => 'Инженеры', 4 => 'Фермеры',
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
        $talkButtons    = [];
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

            // ADR-101 resident-picker: разговор адресуется КОНКРЕТНОМУ жителю (несколько делят
            // якорную клетку; единый `npcEncounter`→`first()` показывал лишь одного — остальные
            // деревья были недостижимы). Кнопка — только для разговорчивых (passive) жителей.
            $behavior = is_array($npcRow) && is_string($npcRow['ai_behavior'] ?? null) ? $npcRow['ai_behavior'] : '';
            if ($npcId > 0 && $behavior === 'passive') {
                $talkButtons[] = ['text' => "💬 {$nm}", 'callback_data' => "npcEncounter_{$npcId}"];
            }
        }

        // Фаза 3 — услуги по типу поселения (оплоты всегда дают лавку+проект, независимо от резидентов).
        // TYPE_SERVICES ссылается только на валидные SERVICE_ROUTES-ключи → доп. isset не нужен.
        foreach (self::TYPE_SERVICES[$type] ?? [] as $svcKey) {
            if (! isset($seenService[$svcKey])) {
                $seenService[$svcKey] = true;
                $serviceButtons[]     = ['text' => self::SERVICE_ROUTES[$svcKey][0], 'callback_data' => self::SERVICE_ROUTES[$svcKey][1]];
            }
        }

        // Фаза 3 — метка фракции для оплота.
        $facId   = is_numeric($settlement['faction_id'] ?? null) ? (int) $settlement['faction_id'] : 0;
        $facLine = ($type === 'faction' && isset(self::FACTION_LABELS[$facId]))
            ? "🚩 Фракция: *" . self::FACTION_LABELS[$facId] . "*\n\n"
            : '';

        $typeLabel = self::TYPE_LABELS[$type] ?? '🏚 Поселение';
        $zoneLine  = $policy['policy'] === 'safe'
            ? '🕊 *Безопасная зона.* Здесь не нападают — таков уговор.'
            : ($policy['policy'] === 'hostile'
                ? '☠️ *Опасная зона.* Держи ухо востро.'
                : ($type === 'anomaly' ? '⚠️ *Аномальная зона.* Воздух дрожит, материя ведёт себя странно.' : ''));

        $head  = "{$icon} *{$name}*\n";
        $head .= "_{$typeLabel}_\n\n";
        $head .= $facLine;
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
        // ADR-101 Фаза 3 (рефайн) — фракционная лавка (unique-stock): только в оплоте, gated killswitch.
        if ($type === 'faction' && (new \App\Services\Settlement\SettlementShopService())->enabled()) {
            $rows[] = [['text' => '🛍 Лавка фракции', 'callback_data' => 'settleShop']];
        }
        // ADR-101 resident-picker: кнопка разговора на КАЖДОГО разговорчивого жителя. Заголовок-
        // подсказка в caption (ниже) объясняет, что можно подойти к любому. Fallback: если ни
        // одного passive-жителя нет — кнопок разговора нет (раньше единая «Поговорить» вела к ->first()).
        if ((new NpcInteractionService())->enabled() && $talkButtons !== []) {
            foreach ($talkButtons as $tb) {
                $rows[] = [$tb];
            }
        }
        // ADR-101 Фаза 5 — быстрое перемещение между открытыми поселениями (gated killswitch).
        if ((new \App\Services\Settlement\SettlementTeleportService())->enabled()) {
            $rows[] = [['text' => '🛰 Быстрое перемещение', 'callback_data' => 'settleTeleport']];
        }
        // E25 (ADR-124) — «🏟 Арена» дуэлей в безопасной зоне (тематично: бойцы сходятся в поселении).
        // Гейт killswitch pvp.duel.enabled. Делает opt-in дуэли (ADR-071) достижимыми.
        if ($policy['policy'] === 'safe' && (new \App\Services\PVE\DuelService())->enabled()) {
            $rows[] = [['text' => '🏟 Арена (дуэли)', 'callback_data' => 'arena']];
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
