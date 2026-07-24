<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Request;

/**
 * S9 (ROADMAP-RETENTION-10, ADR-147) — ранняя выживальческая атмосфера: телеграфированная
 * НЕСМЕРТЕЛЬНАЯ опасность + выбор новичку в сессии-1.
 *
 * Genuine gap (audit S9): движок событий мощный, но пассивный (биом-скоуп, без уровневого
 * таргетинга и без выбора), а march-мини с выбором гейтнуты `min_level=25` → новичок, который в
 * сессии-1 тапает/двигается в поясе и НЕ ходит в Поход, «вау» первого часа не получает. Этот
 * сервис реактивно (on-move) доставляет новичку (level ≤ `max_level`) две вещи:
 *
 *  - 🌪 **шорох с выбором** (one-shot): телеграфированное «вдалеке погромыхивает буря… рядом —
 *    шорох в кустах» + выбор «🔍 Подкрасться / 🌿 Затаиться / 🚶 Уйти». Исход — РАЗНЫЙ нарратив,
 *    🔴 БЕЗ урона/наград (атмосфера, не лут): «вау» = напряжение + агентность + письмо. Выбор
 *    обрабатывает {@see \App\Controllers\Telegram\Commands\Actions\NewbieAtmosphereAction}.
 *  - 🌊 **«выносливость на исходе — найди воду»** (one-shot): just-in-time подсказка, когда
 *    `tired` опускается ≤ `tired_prompt_threshold` — учит искать воду / вернуться на базу.
 *
 * 🔴 НЕСМЕРТЕЛЬНО ПО КОНСТРУКЦИИ: сервис НЕ трогает health/tired/ресурсы/золото (никакого
 *    damage-пути) — DeathRoulette и пол HP к S9 неприменимы. media-off (ADR-020): весь смысл в
 *    тексте. markdown-safe (парные `*`/`_`).
 *
 * Killswitch `world.events.newbie_atmosphere.enabled` (default OFF → maybeSendAtmosphere = no-op =
 * MoveCharacterToDirectionAction byte-identical). One-shot маркеры в action_log (зеркало
 * NewbieGreeterService / LuckyFindService). Overridable seam'ы (тесты без БД/Telegram).
 *
 * @see [[ADR-147-Early-survival-atmosphere]]
 */
class NewbieAtmosphereService
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    /** action_log.action_name маркеры (idempotency, one-shot per char). */
    public const RUSTLE_FLAG = 'NEWBIE_ATMOS_RUSTLE';
    public const THIRST_FLAG = 'NEWBIE_ATMOS_THIRST';

    public function __construct(private readonly ?ActionLogModel $log = null)
    {
    }

    /**
     * Реактивно (после хода новичка) доставить максимум ОДНУ атмосферную бьющую за ход:
     * приоритет — JIT-подсказка про воду (полезнее), иначе — one-shot шорох-выбор по шансу.
     * No-op при killswitch OFF / не-новичке. Сайд-эффект: Request::sendMessage (зеркало
     * LuckyFindService::maybeGrantFirstMove). Возвращает отправленный «beat» для тестов/логов:
     * 'thirst' | 'rustle' | null.
     *
     * @param array<int|string, mixed>|CharacterEntity $character на ходу прилетает CharacterEntity
     *        (ArrayAccess) — union обязателен: `array`-only даёт латентный TypeError, который phpstan
     *        L9 НЕ ловит (см. зеркало LuckyFindService::maybeGrantFirstMove). Поля читаем через `[]`.
     */
    public function maybeSendAtmosphere(array|CharacterEntity $character, int $chatId): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($charId <= 0) {
            return null;
        }
        $level = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        if ($level > $this->maxLevel()) {
            return null; // не новичок — атмосфера сессии-1 не для него
        }

        // 1) Вода — just-in-time, когда выносливость на исходе (one-shot, полезнее «вау»).
        $tired = is_numeric($character['tired'] ?? null) ? (float) $character['tired'] : 100.0;
        if ($tired <= $this->tiredThreshold() && ! $this->markerSet($charId, self::THIRST_FLAG)) {
            $this->send($chatId, self::thirstText());
            $this->writeMarker($charId, $chatId, self::THIRST_FLAG, 'ADR-147 S9: JIT-подсказка про воду');

            return 'thirst';
        }

        // 2) Шорох с выбором — one-shot, по шансу (телеграфированная несмертельная опасность).
        if (! $this->markerSet($charId, self::RUSTLE_FLAG) && $this->roll($this->rustleChance())) {
            $msg = self::buildRustleMessage();
            $this->send($chatId, $msg['text'], $msg['reply_markup']);
            $this->writeMarker($charId, $chatId, self::RUSTLE_FLAG, 'ADR-147 S9: шорох-выбор у новичка');

            return 'rustle';
        }

        return null;
    }

    // ── Конфиг (GameSettings) ────────────────────────────────────────────────

    public function enabled(): bool
    {
        return $this->gsBool('world.events.newbie_atmosphere.enabled', false);
    }

    /** Верхняя граница уровня «новичка сессии-1». */
    public function maxLevel(): int
    {
        return max(1, $this->gsInt('world.events.newbie_atmosphere.max_level', 5));
    }

    /** Шанс шороха за один ход новичка (0..1). One-shot → суммарно сработает ≤1 раз. */
    public function rustleChance(): float
    {
        $c = $this->gsFloat('world.events.newbie_atmosphere.rustle_chance', 0.18);

        return $c < 0.0 ? 0.0 : ($c > 1.0 ? 1.0 : $c);
    }

    /** Порог выносливости для JIT-подсказки про воду. */
    public function tiredThreshold(): int
    {
        return max(1, $this->gsInt('world.events.newbie_atmosphere.tired_prompt_threshold', 20));
    }

    // ── Чистый текст (media-off, markdown-safe; static — тестируется напрямую) ──

    /**
     * Сообщение шороха: телеграфированная буря (форшадоу) + шорох + 3 выбора.
     *
     * @return array{text:string, reply_markup:string}
     */
    public static function buildRustleMessage(): array
    {
        $text = "🌪 *Пустошь подаёт голос.*\n\n"
            . "Где-то у горизонта глухо погромыхивает — далёкая буря катится над землёй, пока "
            . "не сюда. А совсем рядом, в сухих кустах, что-то *зашуршало* и замерло.\n\n"
            . "Никакой явной угрозы — но сердце ёкнуло. Что делаешь?";

        $reply = json_encode([
            'inline_keyboard' => [
                [['text' => '🔍 Подкрасться', 'callback_data' => 'atmRustle_peek']],
                [['text' => '🌿 Затаиться', 'callback_data' => 'atmRustle_hide']],
                [['text' => '🚶 Уйти', 'callback_data' => 'atmRustle_leave']],
            ],
        ]);

        return ['text' => $text, 'reply_markup' => is_string($reply) ? $reply : '{}'];
    }

    /** Исход выбора (РАЗНЫЙ нарратив, 🔴 без урона/наград). Неизвестный ключ → безопасный default. */
    public static function resolveChoice(string $choice): string
    {
        switch ($choice) {
            case 'peek':
                return "🔍 *Ты крадёшься на шорох.*\n\n"
                    . "В кустах — облезлый койот, рывшийся в чьём-то старом тюке. Заметив тебя, он "
                    . "огрызается и удирает в пыль. Сердце колотится, но ты цел. Пустошь проверяет "
                    . "на смелость — и сегодня ты прошёл проверку.\n\n"
                    . "_Буря у горизонта ушла стороной. Идём дальше._";
            case 'hide':
                return "🌿 *Ты замираешь и ждёшь.*\n\n"
                    . "Дыхание тихое, мышцы напряжены. Шорох ещё раз — и удаляется: зверёк ушёл своей "
                    . "дорогой. Иногда выжить — значит просто не высовываться. Ты усвоил это рано.\n\n"
                    . "_Гроза вдалеке стихла. Можно двигаться._";
            case 'leave':
            default:
                return "🚶 *Ты тихо уходишь в другую сторону.*\n\n"
                    . "Любопытство подождёт. На пустоши целее тот, кто не лезет на каждый звук. "
                    . "Шорох остаётся позади — пусть что было, там и останется.\n\n"
                    . "_Раскат грома стих за горизонтом. Путь свободен._";
        }
    }

    /** JIT-подсказка про воду (media-off, markdown-safe). */
    public static function thirstText(): string
    {
        // Метка кнопки — из BotMenuService, НЕ хардкодом: до ADR-150 здесь звали «Открой
        // «🗺 Карту»», но в нижнем меню такой кнопки больше нет — там «🌍 Мир».
        $world = \App\Services\Telegram\BotMenuService::worldButtonLabel();

        return "🌊 *Выносливость на исходе.*\n\n"
            . "Ноги наливаются тяжестью — без воды и отдыха долго не протянуть. Найди источник "
            . "воды на карте или вернись на базу передохнуть, прежде чем уходить дальше в пустошь.\n\n"
            . "_Жми «{$world}» в нижнем меню — у воды выносливость восстановится быстрее._";
    }

    // ── Overridable seam'ы (БД / RNG / Telegram; тесты переопределяют) ─────────

    protected function markerSet(int $charId, string $flag): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', $flag)
            ->countAllResults() > 0;
    }

    protected function writeMarker(int $charId, int $chatId, string $flag, string $desc): void
    {
        // action_status строго из enum('Pending','Completed','Skipped','REJECTED').
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => $flag,
            'action_status' => 'Completed',
            'description'   => $desc,
        ]);
    }

    /** Бросок шанса (0..1). Overridable — тесты делают детерминированным. */
    protected function roll(float $chance): bool
    {
        if ($chance <= 0.0) {
            return false;
        }
        if ($chance >= 1.0) {
            return true;
        }

        return mt_rand(1, 10000) <= (int) round($chance * 10000);
    }

    /** Отправка follow-up сообщения (зеркало LuckyFindService). Overridable — тесты не шлют. */
    protected function send(int $chatId, string $text, ?string $replyMarkup = null): void
    {
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        Request::sendMessage($payload);
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }
}
