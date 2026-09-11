<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * ADR-186 (pvp-detection-clarity-06) — окно противостояния перед полевой PvP-атакой.
 * Владелец записи и чтения — `App\Services\PVE\PvpStandoffService`, он же держит
 * условную вставку (`insertUnique()`) и переход статуса (`transitionIfCurrent()`) —
 * эта модель не пишет строки напрямую там, где нужна гонко-безопасная запись.
 *
 * `$allowedFields` НЕ включает `open_defender_id` — это STORED-генерируемая колонка
 * (`IF(status='open', defender_id, NULL)`), запись в неё MySQL отвергает.
 */
class PvpStandoffModel extends Model
{
    protected $table      = 'pvp_standoffs';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'attacker_id',
        'defender_id',
        'cell_number',
        'started_at',
        'expires_at',
        'status',
        'notified_expired',
        'last_alerted_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'attacker_id' => 'required|integer',
        'defender_id' => 'required|integer',
        'cell_number' => 'required|integer',
        'status'      => 'required|in_list[open,fled,countered,held,expired,cancelled]',
    ];

    /**
     * ADR-186 §5 (pvp-detection-clarity-26) — была ли этому ЗАЩИТНИКУ отправлена
     * тревога (любым окном, не только текущим открытым) за последние
     * `$minIntervalSec` секунд. Часы БД (`NOW() - INTERVAL`), не PHP-время — тот
     * же приём, что `StandoffExpiryHandler::handle()`.
     *
     * Безопасная деградация (тот же приём, что `GameSettingsService::get()`):
     * если `last_alerted_at` ещё не накатана (частичная тест-БД / рассинхрон
     * миграции) — считаем «недавней тревоги не было» и НЕ гасим уведомление,
     * это прежнее поведение до этой story, не тихая дыра.
     */
    public function hasRecentAlert(int $defenderId, int $minIntervalSec): bool
    {
        try {
            $result = $this->db->table($this->table)
                ->select('id')
                ->where('defender_id', $defenderId)
                ->where('last_alerted_at IS NOT NULL')
                ->where("last_alerted_at > (NOW() - INTERVAL {$minIntervalSec} SECOND)", null, false)
                ->orderBy('last_alerted_at', 'DESC')
                ->get();
        } catch (Throwable $e) {
            log_message('error', '[PvpStandoffModel] hasRecentAlert failed: ' . $e->getMessage());
            return false;
        }

        $row = $result === false ? null : $result->getRowArray();

        return is_array($row);
    }

    /**
     * Стамп момента тревоги — `NOW()` БД, не `date('Y-m-d H:i:s')` PHP (тот же приём,
     * что `CharacterModel::incrementNpcKills()`), чтобы {@see hasRecentAlert()}
     * сравнивала однородные часы. Та же безопасная деградация, что выше — молчаливый
     * no-op, если колонки ещё нет, не фатал посреди боевого хода.
     */
    public function markAlerted(int $standoffId): void
    {
        try {
            $this->db->table($this->table)
                ->where('id', $standoffId)
                ->set('last_alerted_at', 'NOW()', false)
                ->update();
        } catch (Throwable $e) {
            log_message('error', '[PvpStandoffModel] markAlerted failed: ' . $e->getMessage());
        }
    }
}
