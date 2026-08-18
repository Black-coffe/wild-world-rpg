<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterDebuffModel;
use App\Services\GameSettings\GameSettingsService;
use Config\Debuffs;

/**
 * Раны, которые не лечатся едой: выдача, чтение, лечение, срок.
 *
 * Повод — аудит 18.08.2026: лекарства и еда конкурировали на одной оси (HP +
 * выносливость), и еда выигрывала, поэтому вся ветка медикаментов была бессмысленной
 * («быстрее отжираться консервами», сигнал игрока). Состояния дают лекарствам
 * собственную нишу: снять их едой нельзя в принципе.
 *
 * 🔴 Инвариант: снимает состояние ТОЛЬКО предмет из `Config\Debuffs::CATALOG[key]['cured_by']`.
 * Никакой heal-путь (еда, регенерация, сон) состояние не трогает.
 *
 * Все числа — в админке (ADR-024), ключи `debuff.*`:
 *  - `debuff.enabled`                    — killswitch всего слоя;
 *  - `debuff.<key>.duration_minutes`     — сколько держится само по себе (0 = до лечения);
 *  - `debuff.poison.hp_per_tick`         — сколько HP забирает тик отравления;
 *  - `debuff.poison.tick_minutes`        — как часто тикает;
 *  - `debuff.heal_cap_percent`           — потолок лечения при ожоге/обморожении;
 *  - `debuff.slowdown_percent`           — насколько дольше идут дела при переломе.
 */
final class DebuffService
{
    private CharacterDebuffModel $model;
    private GameSettingsService $settings;

    public function __construct(?CharacterDebuffModel $model = null, ?GameSettingsService $settings = null)
    {
        $this->model    = $model    ?? new CharacterDebuffModel();
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch всего слоя: false — состояния не выдаются и не действуют. */
    public function enabled(): bool
    {
        $v = $this->settings->get('debuff.enabled', false);
        if (is_bool($v)) {
            return $v;
        }

        return is_numeric($v) ? (int) $v === 1 : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Выдать состояние. Повторная выдача того же состояния не плодит строки —
     * поднимает тяжесть (до 3) и продлевает срок: иначе три укуса подряд дали бы три
     * независимых тика и мгновенную смерть.
     *
     * @return bool выдано (false — killswitch выключен, неизвестный ключ или уже есть с той же тяжестью)
     */
    public function apply(int $characterId, string $key, string $source = 'unknown', int $severity = 1): bool
    {
        if ($characterId <= 0 || ! $this->enabled() || Debuffs::get($key) === null) {
            return false;
        }

        $severity = max(1, min(3, $severity));
        $now      = date('Y-m-d H:i:s');
        $existing = $this->activeOne($characterId, $key);

        if ($existing !== null) {
            $curSeverity = is_numeric($existing['severity'] ?? null) ? (int) $existing['severity'] : 1;
            $newSeverity = min(3, $curSeverity + 1);
            $id          = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;

            if ($id <= 0) {
                return false;
            }

            $this->model->update($id, [
                'severity'   => $newSeverity,
                'expires_at' => $this->expiryFor($key),
            ]);

            return $newSeverity > $curSeverity;
        }

        $this->model->insert([
            'character_id' => $characterId,
            'debuff_key'   => $key,
            'severity'     => $severity,
            'source'       => $source,
            'applied_at'   => $now,
            'expires_at'   => $this->expiryFor($key),
            'last_tick_at' => $now,
        ]);

        return true;
    }

    /**
     * Активные состояния персонажа (при выключенном killswitch — пусто, чтобы
     * ни один эффект не применялся, даже если строки в БД остались).
     *
     * @return list<array<string, mixed>>
     */
    public function active(int $characterId): array
    {
        if ($characterId <= 0 || ! $this->enabled()) {
            return [];
        }

        return $this->model->activeFor($characterId);
    }

    /** Есть ли конкретное состояние сейчас. */
    public function has(int $characterId, string $key): bool
    {
        return $this->activeOne($characterId, $key) !== null;
    }

    /**
     * Снять состояния, которые лечит этот предмет.
     *
     * @return list<string> какие ключи сняты (пусто — предмет ничего не лечил или нечего было лечить)
     */
    public function cureByItem(int $characterId, string $itemNameEng): array
    {
        if ($characterId <= 0 || ! $this->enabled()) {
            return [];
        }

        $curable = Debuffs::curedByItem($itemNameEng);
        if ($curable === []) {
            return [];
        }

        $now   = date('Y-m-d H:i:s');
        $cured = [];

        foreach ($this->active($characterId) as $row) {
            $key = is_string($row['debuff_key'] ?? null) ? $row['debuff_key'] : '';
            $id  = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

            if ($id <= 0 || ! in_array($key, $curable, true)) {
                continue;
            }

            $this->model->update($id, ['cured_at' => $now, 'cured_by_item' => $itemNameEng]);
            $cured[] = $key;
        }

        return $cured;
    }

    /**
     * Потолок лечения в долях от максимума: ожог/обморожение не дают вылечиться
     * полностью. 1.0 — потолка нет.
     */
    public function healCapFactor(int $characterId): float
    {
        foreach ($this->active($characterId) as $row) {
            $key    = is_string($row['debuff_key'] ?? null) ? $row['debuff_key'] : '';
            $effect = Debuffs::get($key)['effect'] ?? null;

            if ($effect === 'heal_cap') {
                $percent = $this->intSetting('debuff.heal_cap_percent', 70);

                return max(0.1, min(1.0, $percent / 100));
            }
        }

        return 1.0;
    }

    /**
     * Множитель длительности дел: перелом растягивает добычу, крафт, стройку и переход.
     * 1.0 — обычная скорость.
     */
    public function slowdownFactor(int $characterId): float
    {
        foreach ($this->active($characterId) as $row) {
            $key    = is_string($row['debuff_key'] ?? null) ? $row['debuff_key'] : '';
            $effect = Debuffs::get($key)['effect'] ?? null;

            if ($effect === 'slowdown') {
                $severity = is_numeric($row['severity'] ?? null) ? (int) $row['severity'] : 1;
                $percent  = $this->intSetting('debuff.slowdown_percent', 25) * max(1, min(3, $severity));

                return 1.0 + min(100, $percent) / 100;
            }
        }

        return 1.0;
    }

    /** Сколько HP забирает один тик отравления у этой строки. */
    public function poisonDamagePerTick(int $severity): int
    {
        return max(1, $this->intSetting('debuff.poison.hp_per_tick', 3) * max(1, min(3, $severity)));
    }

    /** Как часто тикает отравление, минут. */
    public function poisonTickMinutes(): int
    {
        return max(1, $this->intSetting('debuff.poison.tick_minutes', 20));
    }

    /** Пометить строку как отработавшую тик. */
    public function markTicked(int $debuffId): void
    {
        if ($debuffId > 0) {
            $this->model->update($debuffId, ['last_tick_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Закрыть состояния, у которых вышел срок. Возвращает число закрытых.
     */
    public function expireDue(): int
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->model
            ->where('cured_at', null)
            ->where('expired_at', null)
            ->where('expires_at IS NOT NULL', null, false)
            ->where('expires_at <=', $now)
            ->findAll();

        $count = 0;
        foreach ($rows as $row) {
            $id = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $this->model->update($id, ['expired_at' => $now]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Человеческая строка про состояние для экранов: «🤢 Отравление — здоровье тает…
     * Снимает: Антисептик».
     *
     * @param array<string, mixed> $row
     */
    public function describe(array $row): string
    {
        $key  = is_string($row['debuff_key'] ?? null) ? $row['debuff_key'] : '';
        $meta = Debuffs::get($key);

        if ($meta === null) {
            return '';
        }

        $severity = is_numeric($row['severity'] ?? null) ? (int) $row['severity'] : 1;
        $marks    = $severity > 1 ? ' (' . str_repeat('!', $severity) . ')' : '';

        return "{$meta['emoji']} *{$meta['name']}*{$marks} — {$meta['what']}\n_{$meta['cure_hint']}_";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeOne(int $characterId, string $key): ?array
    {
        foreach ($this->active($characterId) as $row) {
            if (($row['debuff_key'] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    /** Срок жизни состояния: null — держится, пока не вылечат. */
    private function expiryFor(string $key): ?string
    {
        $minutes = $this->intSetting("debuff.{$key}.duration_minutes", 0);
        if ($minutes <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', time() + $minutes * 60);
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }
}
