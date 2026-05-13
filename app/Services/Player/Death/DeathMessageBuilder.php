<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use App\Entities\CharacterEntity;
use App\Models\ActiveEventModel;
use App\Models\EventModel;

/**
 * Сборщик понятных уведомлений о смерти (задача «валидация причин смерти», карточка
 * `inbox/2026-05-11-validation-card-death-notifications.md`, batch 2).
 *
 * До: `DeathRouletteHandler::sendDeathMessage` слал «погиб, потерял X%, будь осторожнее» —
 * без причины и без «как не допустить». Игрок не понимал, отчего умер.
 *
 * Структура любого death-сообщения, которое строит этот сервис:
 *   1) шапка («😵 {имя}, твой персонаж погиб» / «…но страховка уберегла»)
 *   2) 💀 *Причина:* что убило (рулетка / PvP / голод) + почему (HP упал до X из-за Y)
 *   3) 📦 *Потери:* сколько и почему (0% страховка / 3% есть база / 50% нет базы)
 *   4) 🧭 *Как не допустить:* конкретные действия (аптечка, страховка, защита от событий, биомы)
 *
 * Используют: {@see DeathRouletteHandler} (рулетка), PVP/AttackPlayerAction (блок «как не допустить»).
 * Дальше (batch 3) — будет связано с `last_respawn_at` / чисткой `effect_log` при смерти.
 */
final class DeathMessageBuilder
{
    private ActiveEventModel $activeEventModel;
    private EventModel $eventModel;

    public function __construct(?ActiveEventModel $activeEventModel = null, ?EventModel $eventModel = null)
    {
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->eventModel       = $eventModel       ?? new EventModel();
    }

    /**
     * Полное сообщение о смерти от «рулетки смерти» (health ≤ 0.99).
     *
     * @param array<int|string,mixed>|CharacterEntity $character    строка персонажа ДО респауна
     * @param array<string,mixed>                      $deathResult  результат DeathService (penalty, hasBase, …)
     */
    public function rouletteDeath(array|CharacterEntity $character, array $deathResult): string
    {
        $name        = self::asStr($character['name'] ?? null, 'Выживший');
        $healthDeath  = self::asFloat($character['health'] ?? null, 0.99);
        $charId       = self::asInt($character['id'] ?? null, 0);
        $penalty      = self::asFloat($deathResult['penalty'] ?? null, 0.0);
        $hasBase      = isset($deathResult['hasBase']) ? (bool) $deathResult['hasBase'] : ($penalty > 0.0 && $penalty < 0.5);

        $recentEvent  = $this->activeDamageEvent($charId);
        $eventName     = $recentEvent !== null && $recentEvent['name'] !== '' ? $recentEvent['name'] : null;
        $protectionItem= $recentEvent['protection_item'] ?? null;

        $cause = "💀 *Причина:* здоровье упало до *" . number_format($healthDeath, 2)
            . "* — сработала «рулетка смерти». При здоровье ниже 1.0 каждую минуту есть шанс погибнуть, "
            . "и он тем выше, чем ниже здоровье.";
        if ($eventName !== null) {
            $cause .= " Скорее всего здоровье просело из-за события *{$eventName}* (либо из-за голода, если кончились еда/вода).";
        } else {
            $cause .= " Обычно это голод (кончились еда/вода) или урон от мирового события.";
        }

        return $this->header($name, $penalty)
            . "\n\n" . $cause
            . "\n\n" . $this->lossLine($penalty)
            . "\n\n" . $this->adviceBlock($eventName !== null, $protectionItem);
    }

    /** Шапка сообщения о смерти. */
    public function header(string $name, float $penalty): string
    {
        return $penalty <= 0.0
            ? "😵 *{$name}*, ты умер(ла), но *страховка* уберегла твоё имущество."
            : "😵 *{$name}*, увы, твой персонаж погиб.";
    }

    /** Строка о потерях по penalty (0 страховка / 0<x<0.5 есть база / ≥0.5 нет базы). */
    public function lossLine(float $penalty): string
    {
        if ($penalty <= 0.0) {
            return "📦 *Потери:* страховка списалась — ресурсы, крафт и золото целы.";
        }
        if ($penalty < 0.5) {
            return "📦 *Потери:* ~*" . (int) round($penalty * 100)
                . "%* ресурсов, крафта и золота — база частично спасла запасы.";
        }
        return "📦 *Потери:* ~*" . (int) round($penalty * 100)
            . "%* ресурсов, крафта и золота — у тебя *нет базы*. Построй лагерь, чтобы при смерти терять минимум.";
    }

    /** Блок «как не допустить» — общий для рулетки / PvP / голода. */
    public function adviceBlock(bool $duringEvent = false, ?string $protectionItem = null): string
    {
        $tips = [
            'следи за здоровьем — держи ≥ 1.0; `💊 Аптечка` лечит мгновенно;',
            'включи *страховку от смерти* — тогда при смерти потеряешь 0% вместо 3–50%;',
        ];
        if ($duringEvent) {
            $tips[] = $protectionItem !== null && $protectionItem !== ''
                ? "при опасных событиях уходи на базу или держи в инвентаре защитный предмет (`{$protectionItem}`);"
                : 'при опасных событиях уходи на базу или используй защитный предмет события;';
        }
        $tips[] = 'не задерживайся в опасных биомах на низком уровне.';

        return "🧭 *Как не допустить:*\n• " . implode("\n• ", $tips);
    }

    /**
     * Название «damage»-события, которое сейчас активно и применялось к персонажу.
     * Удобная обёртка над {@see activeDamageEvent()} — только имя (для текста причины смерти).
     */
    public function activeDamageEventName(int $charId): ?string
    {
        $ev = $this->activeDamageEvent($charId);

        return $ev !== null && $ev['name'] !== '' ? $ev['name'] : null;
    }

    /**
     * «Damage»-событие, которое **сейчас активно или недавно завершилось** и применялось
     * к персонажу (персонаж есть в `effect_log` записи `active_events`). Лучшее усилие:
     * если не вышло определить — null. Используют: причина смерти ({@see rouletteDeath}),
     * предупреждение о низком здоровье ({@see \App\TaskHandlers\LowHealthWarningHandler}).
     *
     * **Bug-fix (2026-05-13, prod-report):** раньше фильтровали `status='active'`, но
     * рулетка смерти может сработать через 1–2 минуты ПОСЛЕ того, как событие закрылось
     * (`status='completed'`) — HP уже просело до 0.01, событие закончилось, через тик
     * пришла рулетка. В этом случае `activeDamageEvent()` возвращало `null` → сообщение
     * о смерти не называло событие, говорило «обычно это голод или урон от мирового
     * события». Игрок (Arseny/Arich, 2026-05-13 15:06 UTC): «Это про извержение,
     * убили за несколько минут» — он не понимал, что именно его убило.
     *
     * Fix: смотрим на 20 последних event'ов независимо от status (active+completed).
     * Если char_id есть в `effect_log` одного из последних 20 — это релевантное событие
     * (rolling window закрывает race-условие закрытия event'а до death-tick'а).
     *
     * @return array{name: string, name_english: string, protection_item: ?string}|null
     */
    public function activeDamageEvent(int $charId): ?array
    {
        if ($charId <= 0) {
            return null;
        }
        $key  = (string) $charId;
        $rows = $this->activeEventModel
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('id', 'DESC')
            ->findAll(20);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $log = $this->decodeLog($row['effect_log']);
            if (!array_key_exists($key, $log) || !is_array($log[$key])) {
                continue;
            }
            $info = $this->eventInfo(self::asInt($row['event_id'] ?? null, 0));
            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, name_english: string, protection_item: ?string}|null
     */
    private function eventInfo(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }
        $ev = $this->eventModel->find($eventId);
        if (!is_array($ev)) {
            return null;
        }
        $name    = self::asStr($ev['name'] ?? null);
        $nameEng = self::asStr($ev['name_english'] ?? null);
        $name    = trim($name);
        $nameEng = trim($nameEng);
        if ($name === '' && $nameEng === '') {
            return null;
        }

        $protectionItem = null;
        if ($nameEng !== '') {
            $worldEvents = config('WorldEvents');
            $cfg = $worldEvents instanceof \Config\WorldEvents ? $worldEvents->get($nameEng) : null;
            if (is_array($cfg) && isset($cfg['protection_item']) && is_string($cfg['protection_item']) && $cfg['protection_item'] !== '') {
                $protectionItem = $cfg['protection_item'];
            }
        }

        return [
            'name'            => $name !== '' ? $name : $nameEng,
            'name_english'    => $nameEng,
            'protection_item' => $protectionItem,
        ];
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeLog(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }

    private static function asFloat(mixed $v, float $default = 0.0): float
    {
        return is_numeric($v) ? (float) $v : $default;
    }

    private static function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
