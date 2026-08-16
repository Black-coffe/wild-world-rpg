<?php

declare(strict_types=1);

namespace App\Services\Player\Progression;

use App\Services\GameSettings\GameSettingsReaderTrait;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * Слайс «Видимая лестница L1→L10» — момент повышения уровня.
 *
 * До этого слайса момента level-up в игре НЕ СУЩЕСТВОВАЛО: уровень производный от статов и
 * молча пересчитывается кроном раз в минуту ({@see \App\TaskHandlers\HealthRegenerationHandler}).
 * Игрок, набравший второй уровень, узнавал об этом только если сам зашёл в карточку Персонажа
 * и заметил, что цифра сменилась. Единственная награда за первые часы игры не имела события.
 *
 * Теперь при РОСТЕ уровня игроку уходит сообщение: новый уровень, что на нём открылось
 * ({@see LevelUnlockService}) и сколько до следующего ({@see LevelProgressService}).
 * Падение уровня (потеря статов при смерти) молчит — добивать не за чем.
 *
 * Гейт: `progression.ladder.levelup_notify` (default false → dormant, ни одного сообщения)
 * + верхний порог `progression.ladder.notify_max_level` (0 = без ограничения).
 *
 * Устойчивость: сообщение — побочный эффект горячего крона, который каждую минуту обходит
 * всех персонажей. Ни одна ошибка отправки не имеет права уронить регенерацию, поэтому
 * send() ловит всё, а Telegram инициализируется ЛЕНИВО — только когда кто-то реально поднял
 * уровень (memory feedback_taskhandler_telegram_init_in_tests: eager-init валит тесты без API_KEY).
 *
 * Самодостаточно в тексте (media-off, ADR-020) — картинок не шлём.
 */
class LevelUpNotifier
{
    use GameSettingsReaderTrait;

    private ?Telegram $telegram = null;

    /** Killswitch: слать ли уведомление о новом уровне. */
    public function isEnabled(): bool
    {
        return $this->gsBool('progression.ladder.levelup_notify', false);
    }

    /**
     * Уведомляет о ПОВЫШЕНИИ уровня. Молчит при: выключенном killswitch, падении/неизменности
     * уровня, уровне выше настроенного порога, отсутствии чата у персонажа.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character строка персонажа ДО пересчёта
     */
    public function notifyLevelUp(array|\App\Entities\CharacterEntity $character, int $oldLevel, int $newLevel): void
    {
        if ($newLevel <= $oldLevel || ! $this->isEnabled()) {
            return;
        }

        $maxLevel = $this->gsInt('progression.ladder.notify_max_level', 0);
        if ($maxLevel > 0 && $newLevel > $maxLevel) {
            return;
        }

        $charIdRaw = $character['id'] ?? null;
        $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;
        if ($charId <= 0) {
            return;
        }

        $chatId = $this->chatIdFor($character);
        if ($chatId === null) {
            return;
        }

        $this->send($chatId, $this->compose($character, $newLevel));
    }

    /**
     * Текст уведомления. Публичный — его же проверяют тесты и он же переиспользуется,
     * если экран level-up понадобится где-то ещё.
     *
     * Markdown legacy (парные `*`, без `_`): memory feedback_legacy_markdown_no_backslash_escape.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    public function compose(array|\App\Entities\CharacterEntity $character, int $newLevel): string
    {
        $text = sprintf("🎉 *Новый уровень: %d*\n", $newLevel);

        $unlocks = $this->unlocks()->summaryFor($newLevel);
        if ($unlocks !== null) {
            $text .= $unlocks . "\n";
        }

        $sum       = LevelProgressService::statSum($character);
        [, $need]  = LevelProgressService::spanFor($newLevel);
        $remaining = max(0.0, $need - $sum);
        $text .= sprintf(
            '🪜 До уровня %d осталось набрать %.1f (сейчас %.1f из %.1f)',
            $newLevel + 1,
            $remaining,
            $sum,
            $need
        );

        return $text;
    }

    /** Seam для тестов: источник строки «что откроется» (ходит в контент-таблицы). */
    protected function unlocks(): LevelUnlockService
    {
        return new LevelUnlockService();
    }

    /**
     * chat_id персонажа = telegram_id владельца. Отдельная выборка, но она случается
     * только в момент реального повышения уровня (единицы раз в сутки на всём проде).
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    protected function chatIdFor(array|\App\Entities\CharacterEntity $character): ?int
    {
        $userIdRaw = $character['telegram_user_id'] ?? null;
        $userId    = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            $result = Database::connect()
                ->table('telegram_users')
                ->select('telegram_id')
                ->where('id', $userId)
                ->get();
            if (! $result instanceof ResultInterface) {
                return null;
            }
            $row = $result->getRowArray();
        } catch (Throwable $e) {
            log_message('warning', '[LevelUpNotifier] chat lookup failed: ' . $e->getMessage());

            return null;
        }

        $tg = $row['telegram_id'] ?? null;

        return is_numeric($tg) && (int) $tg !== 0 ? (int) $tg : null;
    }

    /**
     * Seam для тестов: реальная отправка. Никогда не бросает наружу — крон регенерации
     * не должен падать из-за rate-limit или сетевой ошибки.
     */
    protected function send(int $chatId, string $text): void
    {
        try {
            $this->telegram();
            $response = Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
            if (! $response->isOk()) {
                log_message('warning', '[LevelUpNotifier] sendMessage not ok: ' . $response->getDescription());
            }
        } catch (Throwable $e) {
            log_message('error', '[LevelUpNotifier] sendMessage exception: ' . $e->getMessage());
        }
    }

    /** Ленивая инициализация Telegram-моста (как в BaseTaskHandler). */
    private function telegram(): Telegram
    {
        if ($this->telegram === null) {
            try {
                $this->telegram = new Telegram(
                    (string) getenv('telegram.API_KEY'),
                    (string) getenv('telegram.BOT_USERNAME')
                );
                Request::initialize($this->telegram);
            } catch (TelegramException $e) {
                log_message('error', '[LevelUpNotifier] Telegram init: ' . $e->getMessage());
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }

        return $this->telegram;
    }
}
