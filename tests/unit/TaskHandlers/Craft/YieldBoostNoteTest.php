<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers\Craft;

use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Видимость бонуса постройки в уведомлении о завершении крафта (2026-07-22).
 *
 * Багрепорт игрока: «+55% выход плавки работают, только если крафтить на базе».
 * По коду это не так — множитель зависит только от character_id и уровня здания.
 * Игрок пришёл к неверному выводу потому, что количество молча умножалось: отличить
 * «бонус сработал» от «не сработал» было нечем. Строка-пояснение закрывает разрыв.
 *
 * 🔴 Главное, что проверяем — ПАРНОСТЬ `*`: уведомление уходит с parse_mode=Markdown,
 * непарная звёздочка даёт 400 от Telegram и сообщение **молча не доходит**
 * (класс-баг, из-за которого в проекте появилось правило markdown-safe).
 *
 * @internal
 */
final class YieldBoostNoteTest extends CIUnitTestCase
{
    private function note(string $building, float $mult, int $base, int $final): string
    {
        $m = new ReflectionMethod(GenericCraftCompletionHandler::class, 'buildYieldBoostNote');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(GenericCraftCompletionHandler::class))->newInstanceWithoutConstructor();

        return (string) $m->invoke($handler, $building, $mult, $base, $final);
    }

    /** Непарная `*` = 400 от Telegram = тихая недоставка уведомления. */
    public function testNoteIsMarkdownSafe(): void
    {
        foreach ([['BlastFurnace', 1.55], ['Workshop', 1.1], ['НетТакогоЗдания', 2.0]] as [$b, $mult]) {
            $note = $this->note($b, $mult, 10, 16);
            $this->assertSame(
                0,
                substr_count($note, '*') % 2,
                "Непарная звёздочка в строке бонуса для «{$b}» — Telegram вернёт 400 и сообщение не дойдёт."
            );
            $this->assertStringNotContainsString('_', $note, 'Подчёркивание в legacy Markdown тоже ломает разметку.');
        }
    }

    /** Игрок должен видеть и процент, и оба числа — иначе бонус снова неотличим. */
    public function testNoteStatesPercentAndBothQuantities(): void
    {
        $note = $this->note('BlastFurnace', 1.55, 10, 16);

        $this->assertStringContainsString('Доменная печь', $note, 'Название постройки берётся из Config\Buildings.');
        $this->assertStringContainsString('+55%', $note, 'Процент округляется из множителя 1.55.');
        $this->assertStringContainsString('16', $note, 'Итоговое количество.');
        $this->assertStringContainsString('10', $note, 'Базовое количество — чтобы был виден эффект.');
    }

    /** Неизвестный ключ здания не должен ронять уведомление — цифры важнее имени. */
    public function testUnknownBuildingStillProducesUsefulNote(): void
    {
        $note = $this->note('NoSuchBuilding', 1.2, 5, 6);

        $this->assertStringContainsString('+20%', $note);
        $this->assertStringContainsString('6', $note);
        $this->assertNotSame('', trim($note));
    }

    /** Процент округляется, а не обрезается: 1.075 → +8%, не +7%. */
    public function testPercentIsRounded(): void
    {
        $this->assertStringContainsString('+8%', $this->note('Workshop', 1.075, 10, 11));
    }
}
