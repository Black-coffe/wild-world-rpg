<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\NavMenuRefreshService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-150 ФИНАЛ — анти-мёртвая-кнопка для reply-каркаса.
 *
 * У reply-кнопок нет таблицы роутов (в отличие от callback'ов и `CallbackRoutes`): Telegram
 * присылает их подпись обычным ТЕКСТОМ, и её ловит `switch` в GenericmessageCommand. Значит
 * опечатка в подписи (или забытый `case`) = кнопка, отвечающая «Не понял команду». Именно так
 * ломались `npcAct_`-кнопки, только тише.
 *
 * Тест сканирует исходники: каждая подпись финальной сетки 2×3 обязана иметь `case` в роутере
 * (в нижнем регистре — роутер лоуэркейсит входящий текст).
 *
 * @internal
 */
final class FinalGridLabelsRoutedTest extends CIUnitTestCase
{
    /** Подписи целевого каркаса — ровно те, что рисует BotMenuService::replyRows при finalGrid. */
    private const FINAL_LABELS = ['🌍 Мир', '🧑 Я', '🔨 Крафт', '🏠 База', '📋 Дела', '⚙️ Ещё'];

    public function testFinalGridLabelsExistInBotMenuService(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Services/Telegram/BotMenuService.php');

        foreach (self::FINAL_LABELS as $label) {
            $this->assertStringContainsString(
                "'" . $label . "'",
                $src,
                "Подписи «{$label}» нет в BotMenuService — каркас и тест разошлись."
            );
        }
    }

    public function testEveryFinalGridLabelIsRoutedByTextHandler(): void
    {
        $src = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php'
        );

        foreach (self::FINAL_LABELS as $label) {
            $case = "case '" . mb_strtolower($label) . "':";
            $this->assertStringContainsString(
                $case,
                $src,
                "Кнопка «{$label}» нижнего меню не имеет case в GenericmessageCommand → "
                . 'нажатие ответит «Не понял команду».'
            );
        }
    }

    /**
     * Маркер финального каркаса обязан отличаться от переходного: маркер one-shot, и при
     * совпадении игрок, уже получивший каркас слайсов 1-4, НИКОГДА не получил бы сетку 2×3.
     */
    public function testFinalMarkerDiffersFromTransitionalOne(): void
    {
        $this->assertNotSame(NavMenuRefreshService::MARKER, NavMenuRefreshService::MARKER_FINAL);
        $this->assertStringStartsWith(NavMenuRefreshService::MARKER, NavMenuRefreshService::MARKER_FINAL);
        // action_log.action_name = VARCHAR(255)
        $this->assertLessThanOrEqual(255, mb_strlen(NavMenuRefreshService::MARKER_FINAL));
    }
}
