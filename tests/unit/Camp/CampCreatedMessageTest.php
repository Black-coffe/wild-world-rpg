<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\CampCreateConfirmAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W-REG.2 (F8) — экран «Лагерь разбит» не тупик: оба варианта (без переезда и с ним)
 * несут ряд «🏠 База» · «🏗 Строить» и строку «что дальше»; caption с переездом ≤1024.
 *
 * @internal
 */
final class CampCreatedMessageTest extends CIUnitTestCase
{
    public function testPlainCampHasBaseAndBuildInOneRow(): void
    {
        $msg = CampCreateConfirmAction::campCreatedMessage(12, 34, null, 77);

        $this->assertStringContainsString('X=12, Y=34', $msg['text']);
        $this->assertStringContainsString('Что дальше', $msg['text']);
        $this->assertStringContainsString('🏗 Строить', $msg['text']);
        $this->assertSame([[
            ['text' => '🏠 База', 'callback_data' => 'Base'],
            ['text' => '🏗 Строить', 'callback_data' => 'Build_b77'],
        ]], $msg['reply_markup']['inline_keyboard']);
    }

    public function testRelocatedCampKeepsBuildingsAndButtons(): void
    {
        $names = [];
        for ($i = 1; $i <= 14; $i++) {
            $names[] = ['name' => 'Коммуникационная вышка ' . $i, 'level' => 10];
        }

        $msg = CampCreateConfirmAction::campCreatedMessage(5, 6, $names, 3);

        $this->assertStringContainsString('Коммуникационная вышка 14, уровень 10', $msg['text']);
        $this->assertStringContainsString('Что дальше', $msg['text']);
        $this->assertLessThanOrEqual(1024, mb_strlen($msg['text']));
        $row = $msg['reply_markup']['inline_keyboard'][0];
        $this->assertCount(2, $row);
        $this->assertSame(['Base', 'Build_b3'], array_column($row, 'callback_data'));
    }

    public function testRelocationWithoutBuildingsStillLeadsOn(): void
    {
        $msg = CampCreateConfirmAction::campCreatedMessage(1, 1, [], 0);

        $this->assertStringContainsString('никаких зданий не было перенесено', $msg['text']);
        $this->assertStringContainsString('Что дальше', $msg['text']);
        $this->assertSame('Build', $msg['reply_markup']['inline_keyboard'][0][1]['callback_data']);
    }
}
