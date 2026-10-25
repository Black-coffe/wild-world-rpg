<?php

declare(strict_types=1);

namespace App\Services\Quest;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * W-REG F6 — журнал добытого для цели `gather_resource` («добудь N ресурса»).
 *
 * `collect_resource` считает, сколько ресурса ЛЕЖИТ в рюкзаке, поэтому шаг «Дары пустоши»
 * закрывался стартовым пайком (10 воды) ещё до первой добычи. `gather_resource` считает
 * только то, что пришло завершённой добычей: {@see \App\Services\Player\Gather\GatherResultPersister}
 * на каждую добычу пишет сюда отметку, обработчик целей и «🎯 Сейчас» суммируют отметки.
 *
 * Хранение — строки `action_log` (`action_name = gathered_resource`, `description = "<resources.id>:<qty>"`),
 * таблица уже PLAYER_DATA в WipeManifest, новой схемы нет. Отметка пишется ТОЛЬКО пока у
 * персонажа активен шаг с `gather_resource` — у ветеранов лог не растёт, а тем, у кого шаг
 * был активен до выкатки, счёт идёт с нуля (до выкатки отметок не было).
 */
class GatheredResourceLedger
{
    public const EVENT = 'gathered_resource';

    public const OBJECTIVE_TYPE = 'gather_resource';

    /** Есть ли у персонажа незавершённый активный квест с целью `gather_resource`. */
    public function hasActiveObjective(int $charId): bool
    {
        if ($charId <= 0) {
            return false;
        }

        return $this->db()->table('quest_steps qs')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('qs.character_id', $charId)
            ->where('qs.is_completed', 0)
            ->where('q.status', 'active')
            ->where('q.objective_type', self::OBJECTIVE_TYPE)
            ->countAllResults() > 0;
    }

    /**
     * Записать добычу: одна строка на ресурс с количеством ≥ 1. Caller сам решает, вызывать
     * ли (гейт {@see hasActiveObjective()}), — так персистер не платит за запрос chat_id зря.
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     */
    public function record(int $charId, ?int $chatId, array $foundResources): void
    {
        if ($charId <= 0) {
            return;
        }

        $rows = [];
        foreach ($foundResources as $res) {
            $rid    = (int) $res['resource_id'];
            $amount = (int) $res['amount'];
            if ($rid <= 0 || $amount < 1) {
                continue;
            }
            // action_status строго из enum('Pending','Completed','Skipped','REJECTED').
            $rows[] = [
                'character_id'  => $charId,
                'chat_id'       => $chatId,
                'action_name'   => self::EVENT,
                'action_status' => 'Completed',
                'description'   => "{$rid}:{$amount}",
            ];
        }
        if ($rows !== []) {
            $this->db()->table('action_log')->insertBatch($rows);
        }
    }

    /** Сколько ресурса (`resources.name_en = $resourceNameEn`) персонаж добыл по отметкам. */
    public function gatheredTotal(int $charId, string $resourceNameEn): int
    {
        if ($charId <= 0 || $resourceNameEn === '') {
            return 0;
        }

        $db     = $this->db();
        $refRes = $db->table('resources')->select('id')->where('name_en', $resourceNameEn)->get();
        $refRow = $refRes === false ? null : $refRes->getRowArray();
        if (! is_array($refRow) || ! is_numeric($refRow['id'] ?? null)) {
            return 0;
        }
        $prefix = (int) $refRow['id'] . ':';

        $res = $db->table('action_log')
            ->select('description')
            ->where('character_id', $charId)
            ->where('action_name', self::EVENT)
            ->like('description', $prefix, 'after')
            ->get();
        if ($res === false) {
            return 0;
        }

        $total = 0;
        foreach ($res->getResultArray() as $row) {
            $desc = is_string($row['description'] ?? null) ? $row['description'] : '';
            $qty  = substr($desc, strlen($prefix));
            if (str_starts_with($desc, $prefix) && ctype_digit($qty)) {
                $total += (int) $qty;
            }
        }

        return $total;
    }

    /** @return BaseConnection<object, object> */
    private function db(): BaseConnection
    {
        return Database::connect();
    }
}
