<?php

declare(strict_types=1);

namespace App\Services\PVE;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * w2-n7-combat-01 (ADR-190) — нейтральное ядро журнала боёв: «мои бои» и карточка боя с разбором.
 *
 * Источник — `battle_logs` (PvE пишет `PveBattleLogWriter`, PvP — `AttackPlayerAction`, дуэли — ядро
 * арены). Ядро отдаёт данные, а не текст: бот (`PVP\BattleJournalAction`) и веб (`/play?view=battles`)
 * рисуют их сами.
 *
 * Чей бой: PvE — `player1_id` (у PvE `player2_id` — это `npc_id`, не персонаж, поэтому по нему бой
 * «своим» не становится); PvP и дуэль — любой из `player1_id`/`player2_id`. Чужой бой ядро не отдаёт.
 *
 * `log_data` бывает двух форм: PvE (`characters.player|npc`, раунды `final_damage`/`defender_health_after`/
 * `luckyStrike`) и PvP v2 (`characters.attacker|defender`, раунды `finalDamage`/`defenderHealthAfter`/
 * `luckyStrikeApplied`). Битый или пустой JSON — карточка с итогом без раундов, не ошибка. Полный дамп
 * персонажа, который PvE кладёт в `characters.player`, наружу не выходит: модель берёт только имена.
 *
 * @phpstan-type Round array{n: int, attacker: string, defender: string, damage: float, hp_after: float|null, lucky: bool, mine: bool}
 * @phpstan-type Entry array{id: int, type: string, duel: bool, me: string, opponent: string, result: string, at: string, rounds_total: int}
 * @phpstan-type Card array{id: int, type: string, duel: bool, me: string, opponent: string, result: string, at: string, rounds_total: int, rounds: list<Round>}
 */
final class BattleJournalService
{
    public const TYPE_PVE  = 'PVE';
    public const TYPE_PVP  = 'PVP';
    public const TYPE_DUEL = 'DUEL';

    public const RESULT_WIN     = 'win';
    public const RESULT_LOSS    = 'loss';
    public const RESULT_UNKNOWN = 'unknown';

    /** Сколько последних боёв показывает список. UI-лимит экрана (бот — кнопками в лимит сообщения), не баланс. */
    public const LIMIT_BOT = 10;
    public const LIMIT_WEB = 20;

    public const NOT_FOUND = 'not_found';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Мои последние бои, новые сверху.
     *
     * @return list<Entry>
     */
    public function listFor(int $characterId, int $limit): array
    {
        if ($characterId <= 0 || $limit <= 0) {
            return [];
        }
        $res = $this->db->query(
            'SELECT id, battle_type, player1_id, player2_id, winner_id, created_at, log_data
               FROM battle_logs
              WHERE (battle_type = ? AND player1_id = ?)
                 OR (battle_type IN (?, ?) AND (player1_id = ? OR player2_id = ?))
              ORDER BY id DESC
              LIMIT ' . $limit,
            [self::TYPE_PVE, $characterId, self::TYPE_PVP, self::TYPE_DUEL, $characterId, $characterId]
        );
        if (! $res instanceof BaseResult) {
            return [];
        }

        $out = [];
        foreach ($res->getResultArray() as $row) {
            $card = $this->build($row, $characterId);
            unset($card['rounds']);
            $out[] = $card;
        }

        return $out;
    }

    /**
     * Карточка боя с раундами — только своего.
     *
     * @return array{ok: true, battle: Card}|array{ok: false, code: string}
     */
    public function card(int $characterId, int $battleId): array
    {
        if ($characterId <= 0 || $battleId <= 0) {
            return ['ok' => false, 'code' => self::NOT_FOUND];
        }
        $res = $this->db->query(
            'SELECT id, battle_type, player1_id, player2_id, winner_id, created_at, log_data
               FROM battle_logs WHERE id = ? LIMIT 1',
            [$battleId]
        );
        $row = $res instanceof BaseResult ? $res->getRowArray() : null;
        if (! is_array($row) || ! self::isMine($row, $characterId)) {
            return ['ok' => false, 'code' => self::NOT_FOUND];
        }

        return ['ok' => true, 'battle' => $this->build($row, $characterId)];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isMine(array $row, int $characterId): bool
    {
        $type = is_string($row['battle_type'] ?? null) ? $row['battle_type'] : '';
        $p1   = self::intOf($row['player1_id'] ?? null);
        $p2   = self::intOf($row['player2_id'] ?? null);
        if ($type === self::TYPE_PVE) {
            return $p1 === $characterId;
        }
        if ($type === self::TYPE_PVP || $type === self::TYPE_DUEL) {
            return $p1 === $characterId || $p2 === $characterId;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return Card
     */
    private function build(array $row, int $characterId): array
    {
        $type    = is_string($row['battle_type'] ?? null) ? $row['battle_type'] : '';
        $raw     = is_string($row['log_data'] ?? null) ? json_decode($row['log_data'], true) : null;
        $log     = is_array($raw) ? $raw : [];
        $chars   = is_array($log['characters'] ?? null) ? $log['characters'] : [];
        $winner  = self::intOf($row['winner_id'] ?? null);

        if ($type === self::TYPE_PVE) {
            $player   = is_array($chars['player'] ?? null) ? $chars['player'] : [];
            $npc      = is_array($chars['npc'] ?? null) ? $chars['npc'] : [];
            $me       = self::str($player['name'] ?? null, 'Ты');
            $opponent = self::str($npc['npc_name_ru'] ?? ($npc['name'] ?? null), 'противник');
            // Победитель-NPC пишется id спауна; при совпадении с id персонажа решает последний удар.
            $npcId  = self::intOf($npc['id'] ?? null);
            $result = $winner === null ? self::RESULT_UNKNOWN : ($winner === $characterId ? self::RESULT_WIN : self::RESULT_LOSS);
            if ($npcId !== null && $npcId === $characterId) {
                $result = self::RESULT_UNKNOWN;
            }
        } else {
            $att = is_array($chars['attacker'] ?? null) ? $chars['attacker'] : [];
            $def = is_array($chars['defender'] ?? null) ? $chars['defender'] : [];
            $p1  = self::intOf($row['player1_id'] ?? null);
            // Я — атакующий, если мой id совпал с attacker.id (или, без id в логе, с player1_id).
            $attId    = self::intOf($att['id'] ?? null) ?? $p1;
            $iAmAtt   = $attId === $characterId;
            $me       = self::str(($iAmAtt ? $att : $def)['name'] ?? null, 'Ты');
            $opponent = self::str(($iAmAtt ? $def : $att)['name'] ?? null, 'соперник');
            $result   = $winner === null ? self::RESULT_UNKNOWN : ($winner === $characterId ? self::RESULT_WIN : self::RESULT_LOSS);
        }

        $rounds = self::rounds($log['rounds'] ?? null, $me);
        if ($result === self::RESULT_UNKNOWN && $rounds !== []) {
            $last = $rounds[count($rounds) - 1];
            if ($last['hp_after'] !== null && $last['hp_after'] <= 0.0) {
                $result = $last['mine'] ? self::RESULT_WIN : self::RESULT_LOSS;
            }
        }

        return [
            'id'           => self::intOf($row['id'] ?? null) ?? 0,
            'type'         => $type,
            'duel'         => $type === self::TYPE_DUEL,
            'me'           => $me,
            'opponent'     => $opponent,
            'result'       => $result,
            'at'           => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            'rounds_total' => count($rounds),
            'rounds'       => $rounds,
        ];
    }

    /**
     * @return list<Round>
     */
    private static function rounds(mixed $raw, string $me): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $i   = 0;
        foreach ($raw as $r) {
            if (! is_array($r)) {
                continue;
            }
            $i++;
            $attacker = self::str($r['attacker'] ?? null, '?');
            $damage   = $r['finalDamage'] ?? ($r['final_damage'] ?? null);
            $hpAfter  = $r['defenderHealthAfter'] ?? ($r['defender_health_after'] ?? null);
            $out[] = [
                'n'        => is_numeric($r['round'] ?? null) ? (int) $r['round'] : $i,
                'attacker' => $attacker,
                'defender' => self::str($r['defender'] ?? null, '?'),
                'damage'   => is_numeric($damage) ? max(0.0, (float) $damage) : 0.0,
                'hp_after' => is_numeric($hpAfter) ? max(0.0, (float) $hpAfter) : null,
                'lucky'    => ($r['luckyStrikeApplied'] ?? ($r['luckyStrike'] ?? false)) === true,
                'mine'     => $attacker === $me,
            ];
        }

        return $out;
    }

    private static function intOf(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function str(mixed $v, string $fallback): string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? (string) $v : $fallback;
    }
}
