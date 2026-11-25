<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use App\Entities\CharacterEntity;
use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Services\World\MarchService;
use CodeIgniter\Database\BaseResult;
use Config\Database;

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
 *
 * ADR-200 (ask 7): причина называет источник и урон — удары событий из `event_effects_log` за окно
 * ({@see recentHits()}), Поход с его расходом ❤️ ({@see recentMarch()}) и голод с фактической просадкой из
 * строки персонажа ({@see recentHunger()}); текст — {@see causeBlock()}.
 *
 * @phpstan-type Hit array{event_id:int, name:string, health_delta:float, event_time:string}
 * @phpstan-type March array{hp:float, started:string, cells:int}
 * @phpstan-type Hunger array{hp:float, time:string}
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
        $newbieProt   = isset($deathResult['newbieProtected']) && (bool) $deathResult['newbieProtected'];

        // ADR-200 (ask 7): причина — какое событие / поход / голод, сколько ❤️ и за какое время.
        $now            = time();
        $hits           = $this->recentHits($charId, $now);
        $cause          = self::causeBlock($hits, $this->recentMarch($charId, $now), $healthDeath, $now, $this->recentHunger($charId, $now));
        $topEventId     = self::topEventId($hits);
        $topEvent       = $topEventId !== null ? $this->safeEventInfo($topEventId) : null;
        $duringEvent    = $topEventId !== null;
        $protectionItem = $topEvent['protection_item'] ?? null;

        // E31 (ADR-131) — новичку показываем честный смягчающий текст (а не «страховка»,
        // ведь потери снижены по уровню/базе, а не страховым списанием).
        $header  = $newbieProt ? $this->newbieHeader($name) : $this->header($name, $penalty);
        $lossStr = $newbieProt ? $this->newbieLossLine($penalty, $hasBase) : $this->lossLine($penalty);

        return $header
            . "\n\n" . $cause
            . "\n\n" . $lossStr
            . "\n\n" . $this->adviceBlock($duringEvent, $protectionItem);
    }

    /**
     * ADR-200 (ask 7) — окно причины смерти, минут назад от смерти: удары событий и Поход старше уже не причина.
     * Окно отображения, а не баланс (план sudden-death-1125, Assumptions).
     */
    public const CAUSE_WINDOW_MINUTES = 120;

    /** Сколько событий назвать в причине — длина сообщения, не баланс. */
    private const CAUSE_MAX_EVENTS = 3;

    /**
     * ADR-200 (ask 7, ревью раунд 1) — окно голода, минут назад от смерти. Голод списывает здоровье раз в сутки,
     * поэтому окно — сутки: последняя просадка от голода ещё объясняет, куда делось здоровье. Окно отображения.
     */
    public const HUNGER_WINDOW_MINUTES = 1440;

    /**
     * Строка причины смерти: какие события (сумма фактически применённых ударов), Поход (накопленный расход ❤️) и
     * голод (фактическая просадка), сколько ❤️ и за какое время. Ничего из этого — шаг в опасный биом; голод
     * без записи о просадке не называется (сам он до рулетки не доводит — ask 1). Чистая функция.
     *
     * @param list<Hit>   $hits   удары событий за окно ({@see recentHits()}), в порядке времени
     * @param March|null  $march  идущий или недавно кончившийся Поход ({@see recentMarch()})
     * @param Hunger|null $hunger последняя просадка от голода за окно голода ({@see recentHunger()})
     */
    public static function causeBlock(array $hits, ?array $march, float $healthDeath, int $now, ?array $hunger = null): string
    {
        $parts = [];
        foreach (array_slice(self::eventDamage($hits), 0, self::CAUSE_MAX_EVENTS) as $ev) {
            $parts[] = "событие *{$ev['name']}* сняло ❤️" . self::num($ev['hp'])
                . ' за последние ' . self::minutesSince($ev['first'], $now) . ' мин';
        }
        if ($march !== null && $march['hp'] > 0.0) {
            $parts[] = 'поход: ❤️' . self::num($march['hp']) . ' за ' . self::minutesSince($march['started'], $now)
                . " мин пути ({$march['cells']} " . MarchService::plural($march['cells'], 'клетка', 'клетки', 'клеток')
                . ', опасные биомы стоят дороже)';
        }
        if ($hunger !== null && $hunger['hp'] > 0.0) {
            $parts[] = 'голод (не хватило еды или воды): ❤️' . self::num($hunger['hp']) . ', '
                . self::agoText(self::minutesSince($hunger['time'], $now));
        }
        $roulette = 'упало до *' . number_format($healthDeath, 2) . '* — сработала «рулетка смерти»: при здоровье ниже 1.0 '
            . 'каждую минуту есть шанс погибнуть, и он тем выше, чем ниже здоровье.';

        if ($parts === []) {
            return '💀 *Причина:* за последние ' . intdiv(self::CAUSE_WINDOW_MINUTES, 60) . ' ч ни события, ни дальнего пути — '
                . 'обычно это шаг в опасный биом. Здоровье ' . $roulette;
        }

        return '💀 *Причина:* ' . implode('; ', $parts) . ".\nЗдоровье " . $roulette;
    }

    /**
     * Событие, сильнее всех ударившее по здоровью за окно (для совета о защитном предмете); null — таких нет.
     *
     * @param list<Hit> $hits
     */
    public static function topEventId(array $hits): ?int
    {
        $top = self::eventDamage($hits)[0] ?? null;

        return $top !== null ? $top['event_id'] : null;
    }

    /**
     * Удары событий по персонажу за окно {@see CAUSE_WINDOW_MINUTES}: `event_effects_log.effect_details.health_delta` —
     * фактически применённая дельта (ADR-200 S1 пишет её после пассивного пола). Нет таблиц — пусто: текст смерти
     * не должен падать из-за причины.
     *
     * @return list<Hit>
     */
    public function recentHits(int $charId, int $now): array
    {
        if ($charId <= 0) {
            return [];
        }
        try {
            $res = Database::connect()->table('event_effects_log eel')
                ->select('eel.event_id, eel.effect_details, eel.event_time, e.name, e.name_english')
                ->join('events e', 'e.event_id = eel.event_id', 'left')
                ->where('eel.character_id', $charId)
                ->where('eel.event_time >=', date('Y-m-d H:i:s', $now - self::CAUSE_WINDOW_MINUTES * 60))
                ->orderBy('eel.id', 'ASC')
                ->get();
            $rows = $res instanceof BaseResult ? $res->getResultArray() : [];
        } catch (\Throwable $e) {
            log_message('error', '[DeathMessageBuilder] recentHits: ' . $e->getMessage());

            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            $details = json_decode(self::asStr($row['effect_details'] ?? null, '{}'), true);
            $delta   = is_array($details) ? ($details['health_delta'] ?? null) : null;
            if (! is_numeric($delta)) {
                continue;
            }
            $name = trim(self::asStr($row['name'] ?? null));
            if ($name === '') {
                $name = trim(self::asStr($row['name_english'] ?? null));
            }
            $hits[] = [
                'event_id'     => self::asInt($row['event_id'] ?? null),
                'name'         => $name !== '' ? $name : 'событие',
                'health_delta' => (float) $delta,
                'event_time'   => self::asStr($row['event_time'] ?? null),
            ];
        }

        return $hits;
    }

    /**
     * Последняя просадка от голода за окно {@see HUNGER_WINDOW_MINUTES}: `characters.hunger_hp_lost` — фактически
     * списанные ❤️ (после пассивного пола), `characters.hunger_hp_lost_at` — когда (пишет
     * {@see \App\TaskHandlers\FoodAndWaterConsumptionHandler}). Не `action_log`: тот читают как активность игрока.
     * Нет колонок или записи — null: текст смерти не падает из-за причины.
     *
     * @return Hunger|null
     */
    public function recentHunger(int $charId, int $now): ?array
    {
        if ($charId <= 0) {
            return null;
        }
        try {
            $res = Database::connect()->table('characters')
                ->select('hunger_hp_lost, hunger_hp_lost_at')
                ->where('id', $charId)
                ->where('hunger_hp_lost_at >=', date('Y-m-d H:i:s', $now - self::HUNGER_WINDOW_MINUTES * 60))
                ->where('hunger_hp_lost_at <=', date('Y-m-d H:i:s', $now))
                ->limit(1)
                ->get();
            $row = $res instanceof BaseResult ? $res->getRowArray() : null;
        } catch (\Throwable $e) {
            log_message('error', '[DeathMessageBuilder] recentHunger: ' . $e->getMessage());

            return null;
        }
        if (! is_array($row) || ! is_numeric($row['hunger_hp_lost'] ?? null) || (float) $row['hunger_hp_lost'] <= 0.0) {
            return null;
        }

        return ['hp' => (float) $row['hunger_hp_lost'], 'time' => self::asStr($row['hunger_hp_lost_at'] ?? null)];
    }

    /**
     * Поход, который идёт или кончился в окне {@see CAUSE_WINDOW_MINUTES}: накопленный расход ❤️ (`acc.hp` последней
     * строки цепочки шагов), клетки и начало (первая строка той же цепочки: тот же `started_cell`, `steps_done` не
     * растёт назад). `acc` последнего тика в строку не пишется — число может быть чуть меньше настоящего. Нет
     * таблиц или похода — null.
     *
     * @return March|null
     */
    public function recentMarch(int $charId, int $now): ?array
    {
        if ($charId <= 0) {
            return null;
        }
        try {
            $db      = Database::connect();
            $taskRes = $db->table('tasks')->select('id')->where('name', 'Marching')->limit(1)->get();
            $taskRow = $taskRes instanceof BaseResult ? $taskRes->getRowArray() : null;
            $taskId  = is_array($taskRow) ? self::asInt($taskRow['id'] ?? null) : 0;
            if ($taskId <= 0) {
                return null;
            }
            $res = $db->table('character_tasks')
                ->select('start_time, end_time, status, task_settings')
                ->where('character_id', $charId)
                ->where('task_id', $taskId)
                ->groupStart()
                    ->whereIn('status', ['in_work', 'paused'])
                    ->orWhere('end_time >=', date('Y-m-d H:i:s', $now - self::CAUSE_WINDOW_MINUTES * 60))
                ->groupEnd()
                ->orderBy('id', 'DESC')
                ->limit(500)
                ->get();
            $rows = $res instanceof BaseResult ? $res->getResultArray() : [];
        } catch (\Throwable $e) {
            log_message('error', '[DeathMessageBuilder] recentMarch: ' . $e->getMessage());

            return null;
        }
        if ($rows === []) {
            return null;
        }

        $last        = self::settings($rows[0]);
        $startedCell = $last['started_cell'] ?? null;
        $acc         = is_array($last['acc'] ?? null) ? $last['acc'] : [];
        $steps       = self::asInt($last['steps_done'] ?? null);
        $started     = self::asStr($rows[0]['start_time'] ?? null);
        foreach ($rows as $row) {
            $s = self::settings($row);
            if (($s['started_cell'] ?? null) !== $startedCell || self::asInt($s['steps_done'] ?? null) > $steps) {
                break;
            }
            $steps   = self::asInt($s['steps_done'] ?? null);
            $started = self::asStr($row['start_time'] ?? null, $started);
        }

        return [
            'hp'      => self::asFloat($acc['hp'] ?? null),
            'started' => $started,
            'cells'   => self::asInt($last['steps_done'] ?? null),
        ];
    }

    /**
     * Удары по событиям: сумма урона (только `health_delta < 0`, лечение не причина) и время первого удара,
     * сильнейшее первым.
     *
     * @param list<Hit> $hits
     *
     * @return list<array{event_id:int, name:string, hp:float, first:string}>
     */
    private static function eventDamage(array $hits): array
    {
        $by = [];
        foreach ($hits as $hit) {
            if ($hit['health_delta'] >= 0.0) {
                continue;
            }
            $id = $hit['event_id'];
            if (! isset($by[$id])) {
                $by[$id] = ['event_id' => $id, 'name' => self::markdownSafe($hit['name']), 'hp' => 0.0, 'first' => $hit['event_time']];
            }
            $by[$id]['hp'] += -$hit['health_delta'];
            if ($hit['event_time'] !== '' && ($by[$id]['first'] === '' || strcmp($hit['event_time'], $by[$id]['first']) < 0)) {
                $by[$id]['first'] = $hit['event_time'];
            }
        }
        $list = array_values($by);
        usort($list, static fn (array $a, array $b): int => $b['hp'] <=> $a['hp']);

        return $list;
    }

    /** Минут от момента `$time` до смерти, не меньше 1. */
    private static function minutesSince(string $time, int $now): int
    {
        $ts = strtotime($time);

        return $ts === false ? 1 : max(1, (int) round(($now - $ts) / 60));
    }

    /** «90 мин назад»; от двух часов — «5 ч назад» (голод бывает раз в сутки, минуты там не читаются). */
    private static function agoText(int $minutes): string
    {
        return $minutes < 120 ? "{$minutes} мин назад" : intdiv($minutes, 60) . ' ч назад';
    }

    /** ❤️ без хвостовых нулей: 70.00 → «70», 3.50 → «3.5». */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Имя события внутри `*…*` legacy Markdown: служебные символы разметки убираются. */
    private static function markdownSafe(string $name): string
    {
        return str_replace(['*', '_', '`', '['], '', $name);
    }

    /**
     * @param array<int|string, mixed> $row
     *
     * @return array<mixed, mixed>
     */
    private static function settings(array $row): array
    {
        $s = json_decode(self::asStr($row['task_settings'] ?? null, '{}'), true);

        return is_array($s) ? $s : [];
    }

    /** Шапка сообщения о смерти. */
    public function header(string $name, float $penalty): string
    {
        return $penalty <= 0.0
            ? "😵 *{$name}*, ты умер(ла), но *страховка* уберегла твоё имущество."
            : "😵 *{$name}*, увы, твой персонаж погиб.";
    }

    /** E31 — шапка для новичка (потери смягчены по уровню). */
    public function newbieHeader(string $name): string
    {
        return "😵 *{$name}*, твой персонаж погиб — но ты ещё новичок, и мир смягчил последствия.";
    }

    /** E31 — строка потерь для новичка (честно: смягчение по уровню/базе, не страховка). */
    public function newbieLossLine(float $penalty, bool $hasBase): string
    {
        if ($penalty <= 0.0) {
            return $hasBase
                ? "📦 *Потери:* *0%* — у тебя есть база, и как новичку тебе сохранили всё имущество. Стройка лагеря окупилась."
                : "📦 *Потери:* *0%* — тебе как новичку дали полную фору на старте.";
        }

        return "📦 *Потери:* ~*" . (int) round($penalty * 100)
            . "%* ресурсов, крафта и золота — тебе как новичку снизили штраф (обычно без базы теряют 50%). "
            . "Построй и навещай базу, чтобы терять ещё меньше.";
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
     * `is_active` различает «событие идёт прямо сейчас» (status='active') и «уже
     * завершилось, но урон ещё сказывается» (status='completed'). Нужно, чтобы
     * предупреждение не врало «сейчас на тебя действует», когда событие закончилось
     * минуту назад (репорт 2026-06-20: игрок видел «действует», а меню «События»
     * честно показывало «активных нет» — рассинхрон формулировки).
     *
     * @return array{name: string, name_english: string, protection_item: ?string, is_active: bool}|null
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
            // 🔴 Bug-fix (2026-07-05, prod-report Ярик): effect_log содержит запись и для
            // ПОЛЕЗНЫХ событий (heal: `CleanSpring`/«Чистый родник», GeothermalFountains) —
            // они пишут туда `health_delta > 0`. Раньше метод возвращал любое событие из лога,
            // и сообщение о смерти/предупреждение обвиняли ЛЕЧАЩЕЕ событие в просадке здоровья
            // («здоровье просело из-за события Чистый родник»). Реальная причина смерти — голод.
            // Считаем «damage-событием» только то, что реально уронило HP персонажу (суммарный
            // health_delta < 0). Heal / нейтральные (золото/ресурсы/выносливость) — не причина.
            if (!self::logEntryHarmedHealth($log[$key])) {
                continue;
            }
            $info = $this->eventInfo(self::asInt($row['event_id'] ?? null, 0));
            if ($info !== null) {
                $info['is_active'] = (self::asStr($row['status'] ?? null) === 'active');
                return $info;
            }
        }

        return null;
    }

    /**
     * Навредило ли событие ЗДОРОВЬЮ персонажа — по его записи в `effect_log`.
     * Причиной «рулетки смерти» может быть только просадка HP (health < 1.0), поэтому
     * damage-событием считаем лишь то, у которого суммарный `health_delta < 0`.
     * Лечащие (`health_delta > 0`, напр. «Чистый родник»/CleanSpring) и нейтральные
     * по здоровью (золото/ресурсы/только выносливость → `health_delta == 0`) — не причина.
     *
     * @param array<mixed,mixed> $logEntry запись effect_log для персонажа
     */
    public static function logEntryHarmedHealth(array $logEntry): bool
    {
        $delta = $logEntry['health_delta'] ?? 0;

        return is_numeric($delta) && (float) $delta < 0.0;
    }

    /**
     * {@see eventInfo()} без исключения: защитный предмет — подсказка, её отсутствие не валит текст смерти.
     *
     * @return array{name: string, name_english: string, protection_item: ?string}|null
     */
    private function safeEventInfo(int $eventId): ?array
    {
        try {
            return $this->eventInfo($eventId);
        } catch (\Throwable $e) {
            log_message('error', '[DeathMessageBuilder] eventInfo: ' . $e->getMessage());

            return null;
        }
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
