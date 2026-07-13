<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram\Commands\Actions;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф: применение препарата ОБЯЗАНО считать эффект от СВЕЖИХ значений
 * персонажа (SELECT ... FOR UPDATE в транзакции), а не от снапшота, прочитанного
 * в начале запроса, и писать только реально затронутые поля.
 *
 * Багрепорт 2026-07-13: «Успокоительное → Стимулятор» — второй препарат брал
 * устаревшие health/tired из request-start снапшота и абсолютной записью
 * (last-writer-wins) молча откатывал эффект первого. Заодно затирались
 * параллельные начисления gold/experience (фоновые worker'ы).
 *
 * Source-scan (как GuideCatalogTest): юнитом конкурентность двух FPM-воркеров
 * не воспроизвести, поэтому фиксируем контракт по исходнику.
 *
 * @internal
 */
final class UsePharmacyAtomicUpdateTest extends CIUnitTestCase
{
    private function source(): string
    {
        $path = APPPATH . 'Controllers/Telegram/Commands/Actions/UsePharmacyAction.php';
        $src  = file_get_contents($path);
        $this->assertIsString($src);

        return $src;
    }

    public function testEffectAppliedFromFreshRowUnderLock(): void
    {
        $src = $this->source();

        // Свежее чтение с блокировкой строки внутри транзакции.
        $this->assertStringContainsString('FOR UPDATE', $src);
        $this->assertStringContainsString('transBegin', $src);
        $this->assertStringContainsString('transCommit', $src);
        $this->assertStringContainsString('transRollback', $src);
    }

    public function testNoStaleSnapshotAsEffectBase(): void
    {
        $src = $this->source();

        // База для расчёта — НЕ снапшот из начала запроса. Персонаж из
        // getUserAndCharacter() может использоваться только как источник id.
        foreach (['health', 'tired', 'gold', 'experience', 'strength', 'agility', 'intellect'] as $field) {
            $this->assertStringNotContainsString(
                "\$character['{$field}']",
                $src,
                "applyMedicineEffect не должен читать {$field} из request-start снапшота — только из свежего SELECT ... FOR UPDATE"
            );
        }
    }
}
