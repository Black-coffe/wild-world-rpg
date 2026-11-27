<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\GuideCatalog;
use App\Services\Onboarding\GuideService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * HONEST-OFF (honest-off-1127, S1) — справочник не описывает как живое то, что выключено.
 *
 * Раздел «⚖️ Трофейная подать» несёт флаг `tribute.enabled`: пока флаг выключен, его нет
 * ни в оглавлении, ни по прямому callback `guide_tribute`, ни в «Дальше» из соседнего
 * раздела. Включили флаг — раздел возвращается без правки кода. Бот (`GuideCommand`,
 * `GuideAction`) и `/play` (тот же `GuideAction` через мост) строят экраны одним
 * `GuideService`, поэтому проверка здесь закрывает обе поверхности.
 *
 * Флаг подаётся в сервис замыканием, чтобы тест не зависел от `game_settings`.
 *
 * @internal
 */
final class GuideServiceFlagTest extends CIUnitTestCase
{
    public function testTributeIsHiddenFromIndexWhenFlagOff(): void
    {
        $callbacks = $this->indexCallbacks($this->service(false));

        $this->assertNotContains('guide_tribute', $callbacks, 'При tribute.enabled=0 кнопки «⚖️ Трофейная подать» в оглавлении быть не должно.');
        $this->assertContains('guide_standoff', $callbacks, 'Соседние разделы эндгейма остаются в оглавлении.');
        $this->assertStringNotContainsString('Трофейная подать', $this->service(false)->indexPayload()['text']);
    }

    public function testTributeCallbackFallsBackToIndexWhenFlagOff(): void
    {
        $service = $this->service(false);

        $this->assertSame(
            $service->indexPayload()['text'],
            $service->sectionPayload('tribute')['text'],
            'При tribute.enabled=0 прямой callback guide_tribute обязан падать в оглавление.'
        );
    }

    public function testNextFromStandoffSkipsTributeWhenFlagOff(): void
    {
        $markup = (string) $this->service(false)->sectionPayload('standoff')['reply_markup'];

        $this->assertStringNotContainsString('guide_tribute', $markup, 'При tribute.enabled=0 «Дальше» из standoff не должно вести в скрытую подать.');
        $this->assertStringContainsString('guide_endgame', $markup, '«Дальше» из standoff ведёт в следующий видимый раздел.');
    }

    public function testTributeIsBackWhenFlagOn(): void
    {
        $service = $this->service(true);

        $this->assertContains('guide_tribute', $this->indexCallbacks($service));
        $this->assertStringContainsString('Трофейная подать', $service->sectionPayload('tribute')['text']);
        $this->assertStringContainsString('guide_tribute', (string) $service->sectionPayload('standoff')['reply_markup']);
    }

    public function testCatalogStaysUnfilteredForCommunityCorpus(): void
    {
        $keys = array_column(GuideCatalog::sections(), 'key');

        $this->assertContains('tribute', $keys, 'sections() остаётся полным: из него строит корпус community-бот, где подать — стоп-тема.');
    }

    public function testVisibleChainHasNoDanglingNextWhenFlagOff(): void
    {
        $isOn     = static fn (string $flag): bool => false;
        $sections = GuideCatalog::visibleSections($isOn);
        $count    = count($sections);

        foreach ($sections as $i => $section) {
            $next = GuideCatalog::nextKey($section['key'], $isOn);
            if ($i === $count - 1) {
                $this->assertNull($next);
            } else {
                $this->assertSame($sections[$i + 1]['key'], $next);
            }
        }
    }

    /**
     * Ревью р.1 (honest-off-1127): раздел `chat` не флагован, поэтому его текст обязан быть верным
     * в обоих состояниях `community.enabled`. «Роби в чате не пишет» стало бы ложью при включении
     * community-бота — пункт «Кто отвечает» говорит только то, что верно всегда.
     */
    public function testChatSectionHasNoRobiClaimWhenCommunityOn(): void
    {
        $section = GuideCatalog::find('chat', static fn (string $flag): bool => true);
        $this->assertNotNull($section);

        $this->assertStringNotContainsString('Роби в чате не пишет', $section['body'], 'При community.enabled=1 раздел «Общий чат» не должен утверждать, что Роби в чате не пишет.');
        $this->assertStringContainsString(
            "• 🙋 *Кто отвечает.* Живые выжившие — и владелец игры, когда заглядывает.\n",
            $section['body'],
            'Пункт «Кто отвечает» — ровно слова владельца (brief ## Answers).'
        );
    }

    private function service(bool $tributeOn): GuideService
    {
        return new GuideService(static fn (string $flag): bool => $flag === 'tribute.enabled' ? $tributeOn : false);
    }

    /**
     * @return list<mixed>
     */
    private function indexCallbacks(GuideService $service): array
    {
        $markup = json_decode($service->indexPayload()['reply_markup'], true);
        $this->assertIsArray($markup);
        $rows = $markup['inline_keyboard'] ?? [];
        $this->assertIsArray($rows);

        $callbacks = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            foreach ($row as $btn) {
                $this->assertIsArray($btn);
                $callbacks[] = $btn['callback_data'] ?? null;
            }
        }

        return $callbacks;
    }
}
