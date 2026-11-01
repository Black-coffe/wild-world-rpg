<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\ActionLogModel;
use App\Services\Logging\ActionOrigin;
use App\Services\Telegram\BotMenuService;
use App\Services\Telegram\TelegramBridge;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * E-FIRST (e-first-02): полное нижнее меню приезжает новичку один раз — сразу после первого шага.
 *
 * До шага у новичка внизу одна кнопка «🧭 Сделать первый шаг» ({@see ColdOpenWelcomeService},
 * флаг `onboarding.cold_open_v2.first_step_keyboard`). Первый шаг — это `move` с меткой `cold`
 * (inline-кнопка экрана Роби или нижняя кнопка). После ответа компаса отдельным сообщением
 * уходит полная клавиатура со строкой «что где»: reply-клавиатуру нельзя повесить на edit.
 *
 * Форма — как у {@see \App\Services\Telegram\NavMenuRefreshService}: one-shot маркер в
 * `action_log`, ставится только при успешной доставке; исключения наружу не выходят — меню не
 * должно ронять шаг игрока. Флаг OFF → ничего не делает.
 */
class FirstStepMenuService
{
    /** Маркер one-shot в `action_log`. */
    public const MARKER = 'FirstStepMenuAttached';

    /** Зовётся ПОСЛЕ ответа компаса: меню приходит вторым сообщением, не подменяя экран шага. */
    public function maybeAttach(int $charId, int $chatId): void
    {
        try {
            if ($charId <= 0 || $chatId === 0
                || $this->currentOrigin() !== ActionOrigin::FROM_COLDOPEN
                || ! $this->flagEnabled()
                || $this->alreadyAttached($charId)
            ) {
                return;
            }

            $response = $this->send([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => self::text(),
                'reply_markup' => BotMenuService::mainReplyKeyboard(),
            ]);

            // Маркер — только при доставке: иначе новичок с упавшей отправкой навсегда остался бы
            // с одной кнопкой (её тап снова ведёт сюда — и меню доедет на следующем шаге).
            if ($response->isOk()) {
                $this->mark($charId);
            }
        } catch (\Throwable $e) {
            log_message('error', '[FirstStepMenu] failed: ' . $e->getMessage());
        }
    }

    /**
     * Текст сообщения: какие кнопки появились и куда идти дальше. Кнопки и метка «мира» — из
     * {@see BotMenuService}, чтобы текст не врал при смене каркаса (ADR-150).
     */
    public static function text(): string
    {
        return "🧭 *Первый шаг сделан — теперь всё меню под рукой.*\n\n"
            . 'Кнопки внизу экрана: ' . BotMenuService::replyButtonsLine() . ".\n\n"
            . '_Каждая кнопка — своё место: герой, база, крафт, остров. Куда идти дальше — «'
            . BotMenuService::menuLabel('world') . "». Пропадут кнопки — /menu вернёт их._";
    }

    /** Overridable seam (тесты — без GameSettings). */
    protected function flagEnabled(): bool
    {
        return (new ColdOpenGreetingService())->firstStepKeyboardEnabled();
    }

    /** Overridable seam: метка источника текущего апдейта (ADR-168). */
    protected function currentOrigin(): ?string
    {
        return ActionOrigin::current();
    }

    /** Overridable seam (тесты — без БД). */
    protected function alreadyAttached(int $charId): bool
    {
        return (new ActionLogModel())
            ->where('character_id', $charId)
            ->where('action_name', self::MARKER)
            ->countAllResults() > 0;
    }

    /** Overridable seam (тесты — без БД). */
    protected function mark(int $charId): void
    {
        (new ActionLogModel())->insert([
            'character_id'  => $charId,
            'chat_id'       => 0,
            'action_name'   => self::MARKER,
            'action_status' => 'Completed',
            'description'   => 'E-FIRST full reply keyboard after first step',
        ]);
    }

    /**
     * Overridable seam: настоящая отправка.
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): ServerResponse
    {
        // Мост Telegram: без него Request молча не шлёт. Нет моста — честный отказ, маркер не ставится.
        if (! TelegramBridge::ensure()) {
            log_message('error', '[FirstStepMenu] Telegram bridge is not available');

            return new ServerResponse(['ok' => false, 'description' => 'telegram bridge unavailable'], '');
        }

        return Request::sendMessage($payload);
    }
}
