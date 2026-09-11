<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\StartGame\AutoGenerateNameAction;
use App\Controllers\Telegram\Commands\StartCommand;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * pvp-detection-clarity-07 (доводка) — имя нового персонажа без `@username` обязано быть
 * различимым между игроками, но не раскрывать `telegram_id` (приватный идентификатор
 * аккаунта): `characters.name` публичен — виден в списке обнаружения, на Арене, в рейтинге
 * PvP, в логе боя и на публичной странице достижений сайта.
 *
 * `StartCommand::mintDistinctName()` — pure-хелпер без БД/Telegram, сигнатура берёт ТОЛЬКО
 * `characters.id`: у него физически нет доступа к `telegram_id`, поэтому утечка невозможна
 * by construction, а не по аккуратности вызывающего кода — тест это и доказывает напрямую.
 *
 * @internal
 */
final class StartCommandMintDistinctNameTest extends CIUnitTestCase
{
    public function testNameIsBuiltFromCharacterIdOnly(): void
    {
        $this->assertSame('Путник-42', StartCommand::mintDistinctName(42));
    }

    public function testTwoDifferentIdsGiveTwoDistinguishableNames(): void
    {
        // Прод-инцидент: 24 персонажа с ОДИНАКОВЫМ 'Unknown Hero' в одном списке обнаружения.
        $this->assertNotSame(StartCommand::mintDistinctName(1), StartCommand::mintDistinctName(2));
    }

    /**
     * Прямое доказательство отсутствия утечки: telegram_id намеренно НЕ передаётся в
     * сигнатуру — если бы он туда протекал (как в отменённом первом подходе
     * `Путник-{telegram_id}`), характерное большое число телеграм-id (обычно 9-10 цифр)
     * появилось бы в имени. `characterId` в этом тесте — маленькое число, заведомо не
     * совпадающее по форме с телеграм-id, и метод ничего, кроме него, в строку не подставляет.
     */
    public function testDoesNotContainAnyTelegramIdShapedNumber(): void
    {
        $characterId = 7; // маленький публичный id персонажа, а не приватный telegram_id
        $name        = StartCommand::mintDistinctName($characterId);

        $this->assertSame('Путник-7', $name);
        // Единственное число в строке — это переданный характер-id, а не что-то ещё.
        preg_match_all('/\d+/', $name, $matches);
        $this->assertSame(['7'], $matches[0]);
    }

    public function testSignatureHasNoTelegramIdParameter(): void
    {
        // Метод буквально не умеет принять telegram_id — сигнатура берёт только int $characterId.
        $reflection = new \ReflectionMethod(StartCommand::class, 'mintDistinctName');
        $params     = $reflection->getParameters();

        $this->assertCount(1, $params, 'единственный параметр — characters.id, для telegram_id места нет');
        $this->assertSame('characterId', $params[0]->getName());
    }

    /**
     * pvp-detection-clarity-17: `AutoGenerateNameAction` обязан узнавать машинную заглушку
     * `StartCommand::mintDistinctName()` как «персонаж ещё не назван» — иначе игрок без
     * `@username` навсегда остаётся с `Путник-<id>` вместо шага автогенерации имени.
     */
    public function testMintedPlaceholderIsRecognisedAsUnnamed(): void
    {
        $placeholder = StartCommand::mintDistinctName(42);

        $this->assertTrue($this->invokeIsUnnamed($placeholder));
    }

    public function testChosenNameIsNotRecognisedAsUnnamed(): void
    {
        $this->assertFalse($this->invokeIsUnnamed('Ветеран Пустошей'));
    }

    public function testLegacyLiteralsAreStillRecognisedAsUnnamed(): void
    {
        $this->assertTrue($this->invokeIsUnnamed('Unknown Hero'));
        $this->assertTrue($this->invokeIsUnnamed('NAN'));
        $this->assertTrue($this->invokeIsUnnamed(null));
        $this->assertTrue($this->invokeIsUnnamed(''));
    }

    private function invokeIsUnnamed(?string $name): bool
    {
        $reflection = new \ReflectionMethod(AutoGenerateNameAction::class, 'isUnnamed');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $name);
    }
}
