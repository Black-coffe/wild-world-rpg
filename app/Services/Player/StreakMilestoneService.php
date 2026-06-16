<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;
use Throwable;

/**
 * ADR-132 (надстройка над E6/ADR-108 Ф3) — лестница вех login-стрика.
 *
 * Дневной стрик (`characters.login_streak`) уже на проде (ADR-108 Ф3). Этот сервис выдаёт
 * НАГРАДЫ ЗА ВЕХИ (3/5/7/10/15/30/50/100 дней) с растущим престижем: низкие вехи — припасы
 * (золото + ресурс), высокие — уникальные титулы (чистый престиж, видны в карточке Перса и
 * публичном веб-профиле E30). Зеркало state-driven выдачи {@see AchievementService}/{@see TitleService}.
 *
 * Идемпотентность — таблица `character_streak_milestones` (UNIQUE(character_id, streak_milestone_id)):
 * {@see claim()} «столбит» веху, {@see grantRewards()} выдаёт призы ТОЛЬКО после успешного claim.
 *
 * Killswitch `returnability.streak.milestones_enabled` (default false → dormant). Класс НЕ final,
 * grant-методы overridable (unit без полной модели).
 *
 * @see [[ADR-132-Login-streak-milestone-rewards]]
 */
class StreakMilestoneService
{
    use GameSettingsReaderTrait;

    public const TABLE      = 'streak_milestones';
    public const CHAR_TABLE = 'character_streak_milestones';

    private TitleService $titles;

    public function __construct(?TitleService $titles = null)
    {
        $this->titles = $titles ?? new TitleService();
    }

    /** Killswitch всей лестницы вех. default false → dormant. */
    public function enabled(): bool
    {
        return $this->gsBool('returnability.streak.milestones_enabled', false);
    }

    /** Сколько выдач за один тик cron (throttle анти-шторм). */
    public function maxAwardsPerTick(): int
    {
        return max(1, $this->gsInt('returnability.streak.milestones_max_awards_per_tick', 25));
    }

    /**
     * Включённые вехи по возрастанию порога.
     *
     * @return list<array<int|string,mixed>>
     */
    public function definitions(): array
    {
        $db   = Database::connect();
        $rows = $db->table(self::TABLE)->where('enabled', 1)->orderBy('threshold_days', 'ASC')->orderBy('id', 'ASC')->get();
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows->getResultArray() as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * ID персонажей, у кого login_streak ≥ threshold_days и веха ещё НЕ забрана (set-based, cron-cap).
     *
     * @param array<int|string,mixed> $milestone строка streak_milestones
     * @return list<int>
     */
    public function qualifyingCharacterIds(array $milestone, int $limit = 0): array
    {
        $milestoneId = is_numeric($milestone['id'] ?? null) ? (int) $milestone['id'] : 0;
        $threshold   = is_numeric($milestone['threshold_days'] ?? null) ? (int) $milestone['threshold_days'] : 0;
        if ($milestoneId <= 0 || $threshold <= 0) {
            return [];
        }

        $sql = 'SELECT c.id FROM characters c '
            . 'LEFT JOIN ' . self::CHAR_TABLE . ' cm ON cm.character_id = c.id AND cm.streak_milestone_id = ? '
            . 'WHERE cm.id IS NULL AND c.login_streak >= ?';
        $bindings = [$milestoneId, $threshold];
        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $bindings[] = $limit;
        }

        $db    = Database::connect();
        $query = $db->query($sql, $bindings);
        if (! is_object($query) || ! method_exists($query, 'getResultArray')) {
            return [];
        }
        $ids = [];
        foreach ($query->getResultArray() as $row) {
            $raw = $row['id'] ?? null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * Идемпотентно «застолбить» веху за персонажем. true — если впервые (тогда вызывай grantRewards).
     */
    public function claim(int $characterId, int $milestoneId): bool
    {
        if ($characterId <= 0 || $milestoneId <= 0) {
            return false;
        }
        $db = Database::connect();

        $exists = $db->table(self::CHAR_TABLE)->where('character_id', $characterId)->where('streak_milestone_id', $milestoneId)->countAllResults();
        if ($exists > 0) {
            return false;
        }

        try {
            $db->table(self::CHAR_TABLE)->insert([
                'character_id'        => $characterId,
                'streak_milestone_id' => $milestoneId,
                'claimed_at'          => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            return false; // гонка: UNIQUE поймал
        }
        return true;
    }

    /**
     * Выдать награды вехи (золото / ресурс / титул). Возвращает сводку для уведомления.
     * Вызывать ТОЛЬКО после успешного {@see claim()} — иначе двойная выдача.
     *
     * Заморожена в Ф1: `grants_freeze` НЕ выдаётся (нет characters.streak_freezes до Ф3) —
     * не упоминаем в уведомлении (media-off честность).
     *
     * @param array<int|string,mixed> $milestone
     * @return array{gold:int, resource:?string, resource_qty:int, title:?string}
     */
    public function grantRewards(int $characterId, array $milestone): array
    {
        $gold = is_numeric($milestone['reward_gold'] ?? null) ? max(0, (int) $milestone['reward_gold']) : 0;
        if ($gold > 0) {
            $this->grantGold($characterId, $gold);
        }

        $resourceName = null;
        $resourceQty  = is_numeric($milestone['reward_resource_qty'] ?? null) ? max(0, (int) $milestone['reward_resource_qty']) : 0;
        $rawResource  = $milestone['reward_resource'] ?? null;
        if (is_string($rawResource) && $rawResource !== '' && $resourceQty > 0 && $this->grantResourceByName($characterId, $rawResource, $resourceQty)) {
            $resourceName = $rawResource;
        } else {
            $resourceQty = 0;
        }

        $titleName   = null;
        $rawTitleKey = $milestone['title_key'] ?? null;
        if (is_string($rawTitleKey) && $rawTitleKey !== '') {
            $titleName = $this->grantTitleByKey($characterId, $rawTitleKey);
        }

        return [
            'gold'         => $gold,
            'resource'     => $resourceName,
            'resource_qty' => $resourceQty,
            'title'        => $titleName,
        ];
    }

    // ── Ф2: discoverability (карточка Перса + экран «🔥 Серия выживания») ─────

    /**
     * ID вех, уже забранных персонажем.
     *
     * @return list<int>
     */
    public function claimedMilestoneIds(int $charId): array
    {
        if ($charId <= 0) {
            return [];
        }
        $db   = Database::connect();
        $rows = $db->table(self::CHAR_TABLE)->select('streak_milestone_id')->where('character_id', $charId)->get();
        if ($rows === false) {
            return [];
        }
        $ids = [];
        foreach ($rows->getResultArray() as $r) {
            $raw = $r['streak_milestone_id'] ?? null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * Карта title_key → name для титулов-наград серии (source_type='streak'), один запрос.
     *
     * @return array<string,string>
     */
    public function titleNameMap(): array
    {
        $db   = Database::connect();
        $rows = $db->table('titles')->select('title_key, name')->where('source_type', 'streak')->get();
        if ($rows === false) {
            return [];
        }
        $map = [];
        foreach ($rows->getResultArray() as $r) {
            $k = $r['title_key'] ?? null;
            $n = $r['name'] ?? null;
            if (is_string($k) && $k !== '' && is_string($n)) {
                $map[$k] = $n;
            }
        }
        return $map;
    }

    /**
     * Краткое превью награды вехи для строки списка: «💰100 · 📦Вода×5 · 🎖 «Упорный»».
     *
     * @param array<int|string,mixed> $milestone
     * @param array<string,string>    $titleNames title_key → name
     */
    public function rewardPreview(array $milestone, array $titleNames): string
    {
        $parts = [];
        $gold  = is_numeric($milestone['reward_gold'] ?? null) ? (int) $milestone['reward_gold'] : 0;
        if ($gold > 0) {
            $parts[] = "💰{$gold}";
        }
        $resQty = is_numeric($milestone['reward_resource_qty'] ?? null) ? (int) $milestone['reward_resource_qty'] : 0;
        $res    = $milestone['reward_resource'] ?? null;
        if (is_string($res) && $res !== '' && $resQty > 0) {
            $parts[] = "📦{$res}×{$resQty}";
        }
        $tkey = $milestone['title_key'] ?? null;
        if (is_string($tkey) && $tkey !== '') {
            $tname = $titleNames[$tkey] ?? null;
            if (is_string($tname) && $tname !== '') {
                $parts[] = "🎖 «{$tname}»";
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Строка следующей вехи для карточки Перса (discoverability лестницы). null — если выключено,
     * серии ещё нет (<1), или все вехи уже пройдены.
     */
    public function cardProgressLine(int $charId, int $currentStreak): ?string
    {
        if (! $this->enabled() || $currentStreak < 1) {
            return null;
        }
        $next = null;
        foreach ($this->definitions() as $m) { // definitions упорядочены по threshold ASC
            $th = is_numeric($m['threshold_days'] ?? null) ? (int) $m['threshold_days'] : 0;
            if ($th > $currentStreak) {
                $next = $m;
                break;
            }
        }
        if ($next === null) {
            return null; // все вехи взяты
        }
        $th      = is_numeric($next['threshold_days'] ?? null) ? (int) $next['threshold_days'] : 0;
        $left    = max(1, $th - $currentStreak);
        $name    = is_string($next['name'] ?? null) ? $next['name'] : '';
        $icon    = is_string($next['icon'] ?? null) && $next['icon'] !== '' ? $next['icon'] : '🔥';
        $preview = $this->rewardPreview($next, $this->titleNameMap());

        $line = "    → {$icon} До вехи «{$name}» ({$th} дн.): ещё {$left} " . self::plural($left, 'день', 'дня', 'дней');
        if ($preview !== '') {
            $line .= ' · ' . $preview;
        }
        return $line;
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 > 10 && $mod100 < 20) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 > 1 && $mod10 < 5) {
            return $few;
        }
        return $many;
    }

    // ── Overridable grant-seam'ы ─────────────────────────────────────────────

    protected function grantGold(int $characterId, int $gold): void
    {
        (new CharacterModel())->increaseGold($characterId, (float) $gold);
    }

    /** Резолвит resources.name → id, начисляет в инвентарь. false — ресурс не найден/не начислен. */
    protected function grantResourceByName(int $characterId, string $resourceName, int $qty): bool
    {
        $db  = Database::connect();
        $res = $db->table('resources')->select('id')->where('name', $resourceName)->get();
        $row = $res === false ? null : $res->getRowArray();
        if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
            log_message('warning', '[StreakMilestoneService] resource not found: ' . $resourceName);
            return false;
        }
        return (bool) (new CharacterResourceModel())->addOrIncreaseResource($characterId, (int) $row['id'], $qty);
    }

    /**
     * Резолвит titles.title_key → id, идемпотентно выдаёт через TitleService (первый титул авто-экип).
     * Возвращает имя титула, если выдан ВПЕРВЫЕ (иначе null — уже был / не найден).
     */
    protected function grantTitleByKey(int $characterId, string $titleKey): ?string
    {
        $db  = Database::connect();
        $res = $db->table('titles')->select('id, name')->where('title_key', $titleKey)->get();
        $row = $res === false ? null : $res->getRowArray();
        if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
            log_message('warning', '[StreakMilestoneService] title not found: ' . $titleKey);
            return null;
        }
        if (! $this->titles->award($characterId, (int) $row['id'])) {
            return null; // уже владеет
        }
        return is_string($row['name'] ?? null) ? $row['name'] : '';
    }
}
