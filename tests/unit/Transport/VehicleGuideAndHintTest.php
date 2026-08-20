<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\MarchAction;
use App\Services\Onboarding\GuideCatalog;
use App\Services\Onboarding\OnboardingHintCatalog;
use App\Services\Onboarding\OnboardingHintService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * transport-12 (ADR-174, `docs/specs/transport-system/`) — крючок «Транспорт» на
 * экране Похода, JIT-подсказка на первом длинном Походе, раздел «Транспорт» в /guide.
 *
 * Три блока:
 *  1) `MarchAction::vehicleHookBlock()` — чистая функция (без БД), UX-DISCOVERABILITY
 *     (замок виден всегда, даёт путь, не «недоступно») + «числа только владельцу».
 *  2) `OnboardingHintCatalog::FIRST_LONG_MARCH` — текст + one-shot оркестрация через
 *     `OnboardingHintService::maybeSend()` (генерик, без нового метода в сервисе).
 *  3) `GuideCatalog` раздел `transport` — инварианты /guide (ключ [a-z], read-only
 *     шейп, markdown-safe, без хрупких чисел баланса).
 *
 * @internal
 */
final class VehicleGuideAndHintTest extends CIUnitTestCase
{
    // ── 1) Крючок на экране Похода ───────────────────────────────────────────

    public function testBelowRequiredLevelShowsLockLineWithCurrentAndTargetLevel(): void
    {
        $hook = MarchAction::vehicleHookBlock(4, 6, null);
        $this->assertSame('🔒 Транспорт — с 6 уровня (у тебя 4)', $hook['line']);
    }

    /** UX-DISCOVERABILITY: замок — не тупик, кнопка ведёт куда-то живое. */
    public function testLockLineButtonIsNotADeadEnd(): void
    {
        $hook = MarchAction::vehicleHookBlock(1, 6, null);
        $this->assertNotSame('', trim($hook['button']['callback_data']));
        $this->assertNotSame('', trim($hook['button']['text']));
        // Не vehicleLockInfo: тот рассчитан только на фракционные машины (VehicleAction::lockInfo
        // читает required_faction) и для общей LightCart печатал бы «Только  ?.» — путь ведёт
        // на крафт-витрину story 07 (resourcesCrafting), она честно объясняет любую из 5 машин.
        $this->assertSame('resourcesCrafting', $hook['button']['callback_data']);
    }

    public function testAtRequiredLevelWithoutVehicleShowsEntryToVehicleScreenWithoutPromisingNumbers(): void
    {
        $hook = MarchAction::vehicleHookBlock(6, 6, null);
        $this->assertSame('vehicleScreen', $hook['button']['callback_data']);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*мин/', $hook['line'], 'Без машины крючок не обещает конкретных минут.');
    }

    public function testWithActiveVehicleShowsRealNameAndRemainingCells(): void
    {
        $active = ['icon' => '🛒', 'name' => 'Лёгкая повозка', 'cells_left' => 42];
        $hook   = MarchAction::vehicleHookBlock(6, 6, $active);
        $this->assertStringContainsString('Лёгкая повозка', $hook['line']);
        $this->assertStringContainsString('42', $hook['line']);
        $this->assertSame('vehicleScreen', $hook['button']['callback_data']);
    }

    public function testHookLineIsNeverEmptyRegardlessOfLevelOrOwnership(): void
    {
        $cases = [
            [1, 6, null],
            [6, 6, null],
            [10, 6, ['icon' => '🚚', 'name' => 'Тягловая повозка', 'cells_left' => 0]],
        ];
        foreach ($cases as [$level, $required, $active]) {
            $hook = MarchAction::vehicleHookBlock($level, $required, $active);
            $this->assertNotSame('', trim($hook['line']));
        }
    }

    /**
     * Caption ≤ 1024 (memory `feedback_caption_length_needs_a_test_not_a_note`): крючок
     * обязан оставаться коротким, чтобы не съесть бюджет экрана Похода (маршрут, встречи,
     * расход, разбивка ETA — уже занимают заметную часть 1024). Худшая строка (активная
     * машина с самым длинным именем каталога) держится в разумных пределах.
     */
    public function testWorstCaseHookLineLeavesRoomUnderCaptionLimit(): void
    {
        $active = ['icon' => '🛸', 'name' => 'Автономный дрон', 'cells_left' => 999];
        $hook   = MarchAction::vehicleHookBlock(16, 16, $active);
        $this->assertLessThanOrEqual(120, mb_strlen($hook['line']));

        $lockHook = MarchAction::vehicleHookBlock(1, 6, null);
        $this->assertLessThanOrEqual(120, mb_strlen($lockHook['line']));
    }

    // ── 2) JIT-подсказка «первый длинный Поход» ──────────────────────────────

    public function testCatalogHasFirstLongMarchHint(): void
    {
        $hint = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_LONG_MARCH);
        $this->assertNotNull($hint);
        $this->assertNotSame('', trim($hint['text']));
    }

    /** Анти-дрифт: текст учит пути (media-off самодостаточность). */
    public function testFirstLongMarchHintTeachesPath(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_LONG_MARCH)['text'] ?? '';
        foreach (['Транспорт', 'Крафт', 'Мой транспорт', '6 уровня'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт не упоминает «{$needle}».");
        }
    }

    /**
     * Хинт одноразовый и уходит ВСЕМ на первом длинном Походе (без level-ceiling) —
     * поэтому не должен обещать конкретных сэкономленных минут: это правда только для
     * владельца активной машины, а её показывает `vehicleHookBlock()` на экране Похода.
     */
    public function testFirstLongMarchHintDoesNotPromiseConcreteMinutesToEveryone(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_LONG_MARCH)['text'] ?? '';
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(→|->)\s*\d+\s*мин/u', $text);
    }

    public function testFirstLongMarchHintButtonLinksToCraftShowcase(): void
    {
        $markupRaw = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_LONG_MARCH)['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);

        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });
        $this->assertContains('resourcesCrafting', $callbacks);
    }

    public function testFirstLongMarchHintAsterisksArePaired(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_LONG_MARCH)['text'] ?? '';
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
    }

    /** One-shot через генерик OnboardingHintService::maybeSend() — новый метод сервису не нужен. */
    public function testFirstLongMarchHintSendsExactlyOnce(): void
    {
        $svc       = new FakeVehicleHintService();
        $character = ['id' => 1, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSend($character, 100, OnboardingHintCatalog::FIRST_LONG_MARCH));
        $this->assertCount(1, $svc->sent);

        // Второй длинный Поход — уже показано → no-op.
        $this->assertFalse($svc->maybeSend($character, 100, OnboardingHintCatalog::FIRST_LONG_MARCH));
        $this->assertCount(1, $svc->sent);
    }

    public function testFirstLongMarchHintKillswitchOffSuppresses(): void
    {
        $svc            = new FakeVehicleHintService();
        $svc->killswitch = false;
        $this->assertFalse($svc->maybeSend(['id' => 1, 'daily_tips_enabled' => 1], 100, OnboardingHintCatalog::FIRST_LONG_MARCH));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstLongMarchHintOptOutSuppresses(): void
    {
        $svc = new FakeVehicleHintService();
        $this->assertFalse($svc->maybeSend(['id' => 1, 'daily_tips_enabled' => 0], 100, OnboardingHintCatalog::FIRST_LONG_MARCH));
        $this->assertCount(0, $svc->sent);
    }

    // ── 3) Раздел «Транспорт» в /guide ────────────────────────────────────────

    public function testTransportSectionExistsWithLowercaseKeyNoUnderscore(): void
    {
        $section = GuideCatalog::find('transport');
        $this->assertNotNull($section, 'Раздел «Транспорт» обязан быть в /guide.');
        $this->assertMatchesRegularExpression('/^[a-z]+$/', $section['key']);
    }

    /** 🔴 Шейп раздела — ровно {key, group, button, title, body}, никаких наград. */
    public function testTransportSectionShapeHasNoRewardLikeFields(): void
    {
        $section = GuideCatalog::find('transport');
        $this->assertNotNull($section);
        $this->assertSame(['key', 'group', 'button', 'title', 'body'], array_keys($section));
    }

    public function testTransportSectionExplainsWhereToGetAndHowToUse(): void
    {
        $section = GuideCatalog::find('transport');
        $this->assertNotNull($section);
        $body = $section['title'] . $section['body'];
        foreach (['Лёгкая повозка', 'Мой транспорт', 'Крафт', 'Износ', 'Груз', 'фракц'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $body, "Раздел «Транспорт» не упоминает «{$needle}».");
        }
    }

    /** Про навигацию/понятия, а не про хрупкие числа баланса (они живут в GameSettings, дрейфуют). */
    public function testTransportSectionAvoidsFragileBalanceNumbers(): void
    {
        $section = GuideCatalog::find('transport');
        $this->assertNotNull($section);
        $body = $section['body'];
        foreach (['cells_per_tick', 'wear_per_cell', 'charges_full'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        $this->assertDoesNotMatchRegularExpression('/\d+\s*мин/u', $body, 'Раздел не обещает конкретных минут — это дрейфующий баланс.');
    }

    public function testTransportSectionMarkdownIsBalanced(): void
    {
        $section = GuideCatalog::find('transport');
        $this->assertNotNull($section);
        $text = $section['title'] . $section['body'];
        $this->assertSame(0, substr_count($text, '*') % 2);
        $this->assertSame(0, substr_count($text, '_') % 2);
    }

    /**
     * Общий инвариант каталога держится и на новом разделе — дублируем по-мелкому здесь,
     * т.к. `GuideCatalogTest.php` не входит в `## Files` этой story (не трогаем).
     */
    public function testTransportSectionIsReachableAndNavigable(): void
    {
        $sections = GuideCatalog::sections();
        $keys     = array_column($sections, 'key');
        $this->assertContains('transport', $keys);
        $this->assertCount(count($keys), array_unique($keys), 'Ключи разделов обязаны быть уникальны.');
    }
}

/**
 * Test-double `OnboardingHintService` — тот же паттерн, что `FakeHintService` в
 * `OnboardingHintServiceTest.php` (killswitch/opt-out/one-shot без БД и Telegram).
 */
final class FakeVehicleHintService extends OnboardingHintService
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    /** @var list<string> */
    public array $shownKeys = [];
    public bool $killswitch = true;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->killswitch;
    }

    public function alreadyShown(int $charId, string $hintKey): bool
    {
        return in_array($hintKey, $this->shownKeys, true);
    }

    protected function recordShown(int $charId, int $chatId, string $hintKey): void
    {
        $this->shownKeys[] = $hintKey;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}
