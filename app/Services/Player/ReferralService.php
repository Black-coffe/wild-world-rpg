<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;
use Throwable;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — реферальная петля «позови выжившего».
 *
 * Telegram-нативный двигатель роста+ретеншна. Игрок делится deep-link `t.me/<bot>?start=ref_<id>`
 * (id = telegram_users.id реферрера). Приглашённый при первом /start кредитуется реферреру
 * (first-touch, UNIQUE). Когда приглашённый достигает qualify-уровня — реферреру выдаётся
 * non-power титул «Зовущий» (ADR-112) через {@see TitleService::award} (тот же путь, что
 * awardByBossKill: cron такой source_type='referral' пропускает → выдаём императивно).
 *
 * 🔴 НАГРАДА НЕ СИЛА (П9): только honor-титул, 0 PvE/PvP/эконом-преимущества — НЕ трогаем
 *    gold/resources API.
 * 🔴 АНТИ-ФАРМ: выплата за РЕАЛЬНЫЙ прогресс приглашённого (qualify_level, не за регистрацию),
 *    anti-self-invite, cap referral.max_per_referrer, first-touch (только новый TG-аккаунт).
 *
 * Killswitch `referral.enabled` (default false → dormant: ничего не парсится/не пишется/не
 * показывается, /start byte-identical). DB-примитивы — protected seam'ы (тесты переопределяют
 * без БД). Класс НЕ final.
 */
class ReferralService
{
    use GameSettingsReaderTrait;

    public const TABLE = 'referrals';

    protected TitleService $titles;

    public function __construct(?TitleService $titles = null)
    {
        $this->titles = $titles ?? new TitleService();
    }

    // ── Конфиг (GameSettings) ────────────────────────────────────────────────

    /** Killswitch всей петли. default false (dormant на ship). */
    public function enabled(): bool
    {
        return $this->gsBool('referral.enabled', false);
    }

    /** Уровень приглашённого для qualify+выдачи титула. hard_min=2 (L1=регистрация без игры). */
    public function qualifyLevel(): int
    {
        return max(2, $this->gsInt('referral.qualify_level', 2));
    }

    /** Cap засчитанных приглашённых на одного реферрера (анти-фарм). */
    public function maxPerReferrer(): int
    {
        return max(1, $this->gsInt('referral.max_per_referrer', 50));
    }

    // ── Чистые хелперы (тестируются напрямую) ────────────────────────────────

    /**
     * Разбирает payload deep-link `/start ref_<id>` в telegram_users.id реферрера.
     * Только строгий формат `ref_<положительные цифры>`; иначе null (это src_*-атрибуция или мусор).
     * Pure — тестируется напрямую, без БД.
     */
    public function parseReferrerId(?string $payload): ?int
    {
        if ($payload === null) {
            return null;
        }
        if (preg_match('/^ref_(\d{1,18})$/', trim($payload), $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    /** Личная пригласительная ссылка реферрера. */
    public function inviteLink(int $referrerUserId): string
    {
        $bot = $this->botUsername();

        return "https://t.me/{$bot}?start=ref_{$referrerUserId}";
    }

    // ── Запись ребра (first-touch при /start) ────────────────────────────────

    /**
     * Записать реферальное ребро при регистрации нового приглашённого. Идемпотентно
     * (UNIQUE referred_user_id). Возвращает статус-строку для логов/тестов:
     * disabled | self | invalid | capped | dup | recorded.
     */
    public function recordReferralOnRegister(int $referrerUserId, int $referredUserId, ?int $referredCharacterId): string
    {
        if (! $this->enabled()) {
            return 'disabled';
        }
        if ($referrerUserId <= 0 || $referredUserId <= 0) {
            return 'invalid';
        }
        if ($referrerUserId === $referredUserId) {
            return 'self'; // anti-self-invite
        }
        if (! $this->referrerExists($referrerUserId)) {
            return 'invalid'; // ghost-реферрер (подделанный ref_<id>)
        }
        if ($this->referralExists($referredUserId)) {
            return 'dup'; // уже кредитован (first-touch)
        }
        if ($this->referralCount($referrerUserId) >= $this->maxPerReferrer()) {
            return 'capped';
        }

        return $this->insertReferral($referrerUserId, $referredUserId, $referredCharacterId) ? 'recorded' : 'dup';
    }

    // ── Выдача награды (cron) ────────────────────────────────────────────────

    /**
     * Найти дозревших, но не награждённых приглашённых, выдать реферрерам титул «Зовущий» и
     * вернуть список уведомлений реферреру [['chat_id'=>int,'text'=>string], ...] (cron шлёт).
     * Throttle $limit. No-op при killswitch OFF / выключенной системе титулов / отсутствии титула.
     *
     * @return list<array{chat_id:int, text:string}>
     */
    public function qualifyAndReward(int $limit = 25): array
    {
        if (! $this->enabled() || ! $this->titles->enabled()) {
            return [];
        }
        $titleId = $this->referralTitleId();
        if ($titleId <= 0) {
            return [];
        }

        $notices = [];
        foreach ($this->findQualifiedUnrewarded($this->qualifyLevel(), $limit) as $row) {
            $referralId     = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $referrerUserId = is_numeric($row['referrer_user_id'] ?? null) ? (int) $row['referrer_user_id'] : 0;
            if ($referralId <= 0 || $referrerUserId <= 0) {
                continue;
            }

            $referrerCharId = $this->referrerCharacterId($referrerUserId);
            if ($referrerCharId <= 0) {
                continue; // реферрер без персонажа (удалён) — оставляем на следующий тик
            }

            // Идемпотентно: award вернёт false если титул уже есть — всё равно закрываем ребро.
            $this->titles->award($referrerCharId, $titleId);
            $this->markRewarded($referralId);

            $chatId = $this->referrerChatId($referrerUserId);
            if ($chatId > 0) {
                $notices[] = ['chat_id' => $chatId, 'text' => self::notifyText()];
            }
        }

        return $notices;
    }

    // ── Экран «Позови выжившего» ──────────────────────────────────────────────

    /**
     * Payload экрана: текст + reply_markup (кнопки «поделиться» + назад). media-off самодостаточно.
     *
     * @return array{text:string, reply_markup:string}
     */
    public function screenPayload(int $referrerUserId): array
    {
        $link  = $this->inviteLink($referrerUserId);
        $stats = $this->referrerStats($referrerUserId);
        $text  = self::buildScreenText($link, $stats['invited'], $stats['qualified']);

        $shareUrl = 'https://t.me/share/url?url=' . rawurlencode($link)
            . '&text=' . rawurlencode('Выживаю на острове после катастрофы — давай со мной. Я твой проводник.');

        $reply = json_encode([
            'inline_keyboard' => [
                [['text' => '📤 Поделиться ссылкой', 'url' => $shareUrl]],
                [['text' => '⬅️ К персонажу', 'callback_data' => 'character']],
            ],
        ]);

        return ['text' => $text, 'reply_markup' => is_string($reply) ? $reply : '{}'];
    }

    /** @return array{invited:int, qualified:int} */
    public function referrerStats(int $referrerUserId): array
    {
        return $this->statsRow($referrerUserId);
    }

    // ── Чистые строители текста (static, тестируются напрямую) ─────────────────

    /** Текст экрана: ссылка + честный счётчик. markdown-safe (парные `*`), media-off. */
    public static function buildScreenText(string $link, int $invited, int $qualified): string
    {
        $t  = "👥 *Позови выжившего*\n\n";
        $t .= "Остров оживает, когда на нём появляются настоящие люди. Перешли свою ссылку другу — "
            . "он начнёт здесь как новый выживший, а ты, когда он освоится, получишь honor-титул "
            . "*«Зовущий»*. Это знак уважения, а не игровое преимущество.\n\n";
        $t .= "🔗 Твоя ссылка-приглашение:\n`{$link}`\n\n";

        if ($invited > 0) {
            $settling = max(0, $invited - $qualified);
            $t .= "📊 Твой зов услышали: *{$invited}*";
            if ($qualified > 0) {
                $t .= " · освоились: *{$qualified}*";
            }
            if ($settling > 0) {
                $t .= " · обживаются: *{$settling}*";
            }
            $t .= "\n";
        } else {
            $t .= "_Пока никто не откликнулся. Перешли ссылку — и ты будешь не один._\n";
        }

        return $t;
    }

    /** Текст уведомления реферреру о выданном титуле. markdown-safe, media-off. */
    public static function notifyText(): string
    {
        return "📣 *Ты вернул в мир ещё одного выжившего!*\n\n"
            . "Приглашённый тобой игрок освоился на острове. Тебе присвоен титул *«Зовущий»*.\n\n"
            . "_Выбрать активный титул — «🎖 Титулы» в карточке Персонажа._";
    }

    // ── DB-примитивы (protected seam'ы; тесты переопределяют без БД) ───────────

    /** Имя бота для ссылки (env). Overridable в тестах. */
    protected function botUsername(): string
    {
        $u = (string) getenv('telegram.BOT_USERNAME');

        return $u !== '' ? ltrim($u, '@') : 'bot';
    }

    protected function referrerExists(int $userId): bool
    {
        return Database::connect()->table('telegram_users')->where('id', $userId)->countAllResults() > 0;
    }

    protected function referralExists(int $referredUserId): bool
    {
        return Database::connect()->table(self::TABLE)->where('referred_user_id', $referredUserId)->countAllResults() > 0;
    }

    protected function referralCount(int $referrerUserId): int
    {
        $c = Database::connect()->table(self::TABLE)->where('referrer_user_id', $referrerUserId)->countAllResults();

        return is_numeric($c) ? (int) $c : 0;
    }

    protected function insertReferral(int $referrerUserId, int $referredUserId, ?int $referredCharacterId): bool
    {
        try {
            return Database::connect()->table(self::TABLE)->insert([
                'referrer_user_id'      => $referrerUserId,
                'referred_user_id'      => $referredUserId,
                'referred_character_id' => $referredCharacterId,
                'created_at'            => date('Y-m-d H:i:s'),
            ]) !== false;
        } catch (Throwable) {
            return false; // UNIQUE-гонка
        }
    }

    /** id титула-награды (source_type='referral', enabled). */
    protected function referralTitleId(): int
    {
        $r = Database::connect()->table('titles')
            ->select('id')
            ->where('source_type', 'referral')
            ->where('enabled', 1)
            ->orderBy('id', 'ASC')
            ->get();
        $row = $r === false ? null : $r->getRowArray();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    /**
     * Дозревшие (приглашённый.level ≥ qualify), но не награждённые ребра.
     *
     * @return list<array<string,mixed>>
     */
    protected function findQualifiedUnrewarded(int $qualifyLevel, int $limit): array
    {
        $sql = 'SELECT r.id, r.referrer_user_id, r.referred_user_id '
            . 'FROM ' . self::TABLE . ' r '
            . 'JOIN characters c ON c.id = r.referred_character_id '
            . 'WHERE r.rewarded_at IS NULL AND c.level >= ?';
        $binds = [$qualifyLevel];
        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $binds[] = $limit;
        }
        $q = Database::connect()->query($sql, $binds);
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }
        $out = [];
        foreach ($q->getResultArray() as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /** id персонажа реферрера (характер привязан к его telegram_users.id). */
    protected function referrerCharacterId(int $referrerUserId): int
    {
        $r = Database::connect()->table('characters')->select('id')->where('telegram_user_id', $referrerUserId)->get();
        $row = $r === false ? null : $r->getRowArray();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    /** chat_id (raw telegram_id) реферрера для уведомления. */
    protected function referrerChatId(int $referrerUserId): int
    {
        $r = Database::connect()->table('telegram_users')->select('telegram_id')->where('id', $referrerUserId)->get();
        $row = $r === false ? null : $r->getRowArray();

        return is_array($row) && is_numeric($row['telegram_id'] ?? null) ? (int) $row['telegram_id'] : 0;
    }

    protected function markRewarded(int $referralId): void
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table(self::TABLE)->where('id', $referralId)->update([
            'qualified_at' => $now,
            'rewarded_at'  => $now,
        ]);
    }

    /** @return array{invited:int, qualified:int} */
    protected function statsRow(int $referrerUserId): array
    {
        $db         = Database::connect();
        $invitedRaw = $db->table(self::TABLE)->where('referrer_user_id', $referrerUserId)->countAllResults();
        $qualRaw    = $db->table(self::TABLE)->where('referrer_user_id', $referrerUserId)->where('rewarded_at IS NOT NULL')->countAllResults();

        return [
            'invited'   => is_numeric($invitedRaw) ? (int) $invitedRaw : 0,
            'qualified' => is_numeric($qualRaw) ? (int) $qualRaw : 0,
        ];
    }
}
