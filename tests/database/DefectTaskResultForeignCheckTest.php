<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Unit\Defects\DefectCheckRunner;

require_once __DIR__ . '/../unit/Defects/DefectCheckRunner.php';

/**
 * defect-checks-tests-1124 S1 — живой прогон проверки `task-result-foreign-message`
 * (`php scripts/defects-task-result-foreign-check.php` без аргумента): фикстуры карточки через настоящий
 * `WebTaskResultPicker` должны дать итог задачи, а не чужое фоновое сообщение.
 *
 * Здесь, а не в `tests/unit/Defects/`: каталоги подсказок читают GameSettings из локальной `wildworld_tests`.
 * Вердикт от БД не зависит (замер 2026-11-24: выход 0 и с MySQL, и без), но без MySQL прогон стоит ~29 с
 * таймаутов соединения, с MySQL — меньше секунды; части `tests/database` и так требуют MySQL. Фикстуры той же
 * карточки (форма `window_only`, БД не трогают) — в `DefectCodeChecksTest`.
 *
 * Только локальная `wildworld_tests` (скрипт сам задаёт группу `tests` = 127.0.0.1); ни preprod, ни прод.
 *
 * @internal
 */
final class DefectTaskResultForeignCheckTest extends CIUnitTestCase
{
    public function testLiveRunPicksTheTaskResultNotAForeignMessage(): void
    {
        $card = DefectCheckRunner::card('task-result-foreign-message');
        $this->assertSame('scripts/defects-task-result-foreign-check.php', $card['script']);

        [$code, $out] = DefectCheckRunner::run($card['script']);

        $this->assertSame(0, $code, "итогом задачи на /play снова встаёт чужое сообщение:\n{$out}");
        $this->assertStringContainsString('picker typed', $out, 'живой прогон идёт через настоящий пикер, не window_only');
    }
}
