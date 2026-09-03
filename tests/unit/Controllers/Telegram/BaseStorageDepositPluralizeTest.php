<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageDepositAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * exploit-fix-34 (R3-minor, ревью m9): `pluralizeKinds()` — чистая функция русского
 * склонения «вид/вида/видов», уже дважды переписанная (exploit-fix-26) без теста.
 * Закрывает ветки 1, 2–4, 5–20 (в т.ч. исключение 11–14) и «после 20» (21/22/25).
 */
final class BaseStorageDepositPluralizeTest extends CIUnitTestCase
{
    /**
     * @dataProvider provideCounts
     */
    public function testPluralizeKinds(int $count, string $expected): void
    {
        $this->assertSame($expected, BaseStorageDepositAction::pluralizeKinds($count));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function provideCounts(): iterable
    {
        yield '1 -> вид'     => [1, 'вид'];
        yield '2 -> вида'    => [2, 'вида'];
        yield '3 -> вида'    => [3, 'вида'];
        yield '4 -> вида'    => [4, 'вида'];
        yield '5 -> видов'   => [5, 'видов'];
        yield '11 -> видов'  => [11, 'видов'];
        yield '12 -> видов'  => [12, 'видов'];
        yield '13 -> видов'  => [13, 'видов'];
        yield '14 -> видов'  => [14, 'видов'];
        yield '20 -> видов'  => [20, 'видов'];
        yield '21 -> вид'    => [21, 'вид'];
        yield '22 -> вида'   => [22, 'вида'];
        yield '25 -> видов'  => [25, 'видов'];
    }
}
