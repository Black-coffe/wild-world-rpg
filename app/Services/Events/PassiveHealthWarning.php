<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CraftedItemsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use App\Services\Telegram\TelegramChatResolver;
use App\TaskHandlers\LowHealthWarningHandler;
use Throwable;

/**
 * ADR-200 (спека sudden-death-1125, S3) — предупреждение до пола, а не в минуту рулетки.
 *
 * Игрок 24.11: «предпосылок не было» — первое сообщение о здоровье пришло в ту же минуту, что смерть. Теперь удар
 * мирового события, который переводит здоровье через порог `health.passive_warn_threshold` (GameSettings, дефолт
 * 30) вниз, сразу даёт одно предупреждение «уходи на базу» — из пути урона (`EventDispatcher`), а не из минутного
 * крона. Одно на пересечение: удар, перепрыгнувший порог (и полосы крона) разом, даёт одно сообщение; уже ниже
 * порога — ни одного. Отключившему предупреждения в «⚙️ Настройки» (`characters.health_warnings_enabled = 0`) —
 * ничего, как у крона.
 *
 * Текст — тот же построитель, что у крона ({@see LowHealthWarningHandler::warningText()}), плюс строка «событие
 * не добьёт, но добыча прервётся». Только текст, через `Request` приложения: доходит и в Telegram, и во входящие
 * `/play` (виртуальный чат, ADR-189), где отсекается как чужое фоновое по началу текста.
 */
class PassiveHealthWarning
{
    public const KEY = 'health.passive_warn_threshold';

    /** Фолбэк без таблицы `game_settings`; совпадает с посевом `SeedPassiveFloorSettings`. */
    public const DEFAULT = 30.0;

    public function __construct(private ?GameSettingsService $settings = null, private ?TelegramChatResolver $chats = null)
    {
    }

    public function threshold(): float
    {
        $this->settings ??= new GameSettingsService();
        $value = $this->settings->get(self::KEY, self::DEFAULT);

        return is_numeric($value) ? (float) $value : self::DEFAULT;
    }

    /**
     * Пересёк ли удар порог вниз: до удара на пороге или выше, после — ниже.
     */
    public static function crosses(float $healthBefore, float $healthAfter, float $threshold): bool
    {
        return $healthBefore >= $threshold && $healthAfter < $threshold;
    }

    /**
     * Тумблер «⚙️ Настройки» (opt-out, дефолт — включено).
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    public static function warningsEnabled(array|\App\Entities\CharacterEntity $character): bool
    {
        $raw = $character['health_warnings_enabled'] ?? 1;

        return ! is_numeric($raw) || (int) $raw !== 0;
    }

    /**
     * Предупредить, если удар пересёк порог. `true` — сообщение ушло.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character строка персонажа после удара
     * @param string|null $protectionItem защитный предмет события из {@see \Config\WorldEvents}
     */
    public function maybeWarn(array|\App\Entities\CharacterEntity $character, float $healthBefore, float $healthAfter, string $eventName, ?string $protectionItem): bool
    {
        if (! self::crosses($healthBefore, $healthAfter, $this->threshold()) || ! self::warningsEnabled($character)) {
            return false;
        }
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $chatId = $charId > 0 ? $this->chatIdFor($charId) : null;
        if ($chatId === null) {
            return false;
        }

        $name = is_string($character['name'] ?? null) ? $character['name'] : '';
        $text = LowHealthWarningHandler::warningText(
            $name,
            $healthAfter,
            ['name' => $eventName, 'protection_item' => $protectionItem !== null && $protectionItem !== '' ? $this->protectionName($protectionItem) : null, 'is_active' => true],
            'Событие не добьёт, но с низким здоровьем добыча в его биоме прервётся, а новая там не начнётся.'
        );
        $this->send($chatId, $text, LowHealthWarningHandler::healKeyboard());

        return true;
    }

    /**
     * Русское имя защитного предмета (`crafted_items.name_rus` по `name_eng`): игрок не должен видеть код
     * `Bandage`. Нет строки — ключ как есть.
     */
    protected function protectionName(string $nameEng): string
    {
        try {
            $row = (new CraftedItemsModel())->select('name_rus')->where('name_eng', $nameEng)->first();
        } catch (Throwable) {
            return $nameEng;
        }
        $rus = is_array($row) ? ($row['name_rus'] ?? null) : null; // returnType = array

        return is_string($rus) && $rus !== '' ? $rus : $nameEng;
    }

    protected function chatIdFor(int $characterId): ?int
    {
        $this->chats ??= new TelegramChatResolver();

        return $this->chats->chatIdForCharacter($characterId);
    }

    /**
     * Отправка через `Request` приложения: Telegram или входящие `/play`. Никогда не бросает.
     *
     * @param array<string, mixed> $keyboard
     */
    protected function send(int $chatId, string $text, array $keyboard): void
    {
        if (! TelegramBridge::ensure()) {
            return;
        }
        try {
            $response = Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
            if (! $response->isOk()) {
                log_message('error', '[PassiveHealthWarning] message not sent: ' . $response->getDescription());
            }
        } catch (Throwable $e) {
            log_message('error', '[PassiveHealthWarning] send failed: ' . $e->getMessage());
        }
    }
}
