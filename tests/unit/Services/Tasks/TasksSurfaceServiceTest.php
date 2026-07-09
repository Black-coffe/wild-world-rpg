<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tasks;

use App\Services\Tasks\TasksSurfaceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-150 Слайс 3 — контракт поверхности «📋 Дела».
 *
 * Рендер проверяется БЕЗ БД: подменяем overridable-сеймы (активные задачи, сводка квестов,
 * полярная звезда). Так же, как PolarStarService/DailyTaskService тестируются на CI.
 *
 * @internal
 */
final class TasksSurfaceServiceTest extends CIUnitTestCase
{
    /**
     * @param list<array{id:int, name:string, left:string}> $tasks
     * @param array<string, mixed>                          $summaryOverrides
     */
    private function surface(array $tasks = [], array $summaryOverrides = [], ?string $star = null): TasksSurfaceService
    {
        $summary = array_merge([
            'active'    => 0,
            'available' => 0,
            'locked'    => 0,
            'completed' => 0,
            'daily'     => ['enabled' => false, 'assigned' => false, 'done' => 0, 'total' => 0, 'bonus' => 0],
            'branches'  => 0,
            'npc_hint'  => false,
        ], $summaryOverrides);

        return new class ($tasks, $summary, $star) extends TasksSurfaceService {
            /** @param list<array{id:int, name:string, left:string}> $tasks */
            public function __construct(private array $tasks, private array $summary, private ?string $star)
            {
                parent::__construct();
            }

            protected function activeTasks(int $charId): array
            {
                return $this->tasks;
            }

            protected function questSummary(int $level, int $charId): array
            {
                return $this->summary;
            }

            protected function polarStarLine(int $charId): ?string
            {
                return $this->star;
            }
        };
    }

    /** @return array<string, mixed> */
    private function character(): array
    {
        return ['id' => 7, 'level' => 3];
    }

    /** Пустой список задач — честное «ничего не идёт», без кнопок прерывания. */
    public function testEmptyStateHasNoInterruptButtons(): void
    {
        $screen = $this->surface()->buildScreen($this->character());

        $this->assertStringContainsString('Идёт сейчас:* ничего', $screen['text']);
        $this->assertStringNotContainsString('⛔️', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Активные задачи нумеруются, а кнопка прерывания ссылается на finishAllTasks_<id>. */
    public function testActiveTasksRenderWithInterruptButtons(): void
    {
        $tasks = [
            ['id' => 11, 'name' => 'Добыча ресурсов', 'left' => '12 мин'],
            ['id' => 22, 'name' => 'Крафт: Повязка', 'left' => 'меньше минуты'],
        ];

        $screen = $this->surface($tasks)->buildScreen($this->character());

        $this->assertStringContainsString('Идёт сейчас (2)', $screen['text']);
        $this->assertStringContainsString('1) *Добыча ресурсов* — осталось `12 мин`', $screen['text']);
        $this->assertStringContainsString('2) *Крафт: Повязка* — осталось `меньше минуты`', $screen['text']);

        $json = json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringContainsString('finishAllTasks_11', $json);
        $this->assertStringContainsString('finishAllTasks_22', $json);
    }

    /**
     * 🔴 Правда текста: кнопка зовётся «Прервать», а не «моментально снять» (легаси-/tasks).
     * FinishTaskAction ставит `interrupted` и теряет награду — обещать обратное нельзя.
     */
    public function testInterruptButtonIsHonestlyNamed(): void
    {
        $tasks  = [['id' => 5, 'name' => 'Стройка', 'left' => '1 ч 0 мин']];
        $screen = $this->surface($tasks)->buildScreen($this->character());

        $json = json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringContainsString('⛔️ 1', $json);
        $this->assertStringNotContainsString('моментально', $screen['text']);
    }

    /** Полярная звезда (ADR-139) выводится первой строкой после заголовка. */
    public function testPolarStarLineIsRendered(): void
    {
        $screen = $this->surface([], [], '🎯 *Сейчас:* Собери 5 Трав (2/5)')->buildScreen($this->character());

        $this->assertStringContainsString('🎯 *Сейчас:* Собери 5 Трав (2/5)', $screen['text']);
    }

    /** Полярная звезда отсутствует (ветеран / killswitch OFF) → экран без неё, не падает. */
    public function testNoPolarStarWhenNull(): void
    {
        $screen = $this->surface()->buildScreen($this->character());

        $this->assertStringNotContainsString('🎯', $screen['text']);
    }

    /** Дейлики включены → строка с прогрессом + кнопка со счётчиком. */
    public function testDailyRowAppearsWhenEnabled(): void
    {
        $screen = $this->surface([], [
            'daily' => ['enabled' => true, 'assigned' => true, 'done' => 1, 'total' => 3, 'bonus' => 500],
        ])->buildScreen($this->character());

        $this->assertStringContainsString('Задания дня:* *1/3*', $screen['text']);
        $this->assertStringContainsString('бонус +500 золота', $screen['text']);

        $json = json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringContainsString('dailyTasks', $json);
        $this->assertStringContainsString('(1/3)', $json);
    }

    /** Дейлики выключены → ни строки, ни кнопки (уважаем чужой killswitch). */
    public function testDailyRowHiddenWhenDisabled(): void
    {
        $screen = $this->surface()->buildScreen($this->character());

        $this->assertStringNotContainsString('Задания дня', $screen['text']);
        $this->assertStringNotContainsString('dailyTasks', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Развилка цепочки — необратимый выбор, о нём предупреждаем заметно. */
    public function testBranchWarningShown(): void
    {
        $screen = $this->surface([], ['branches' => 1])->buildScreen($this->character());

        $this->assertStringContainsString('Развилка цепочки ждёт выбор', $screen['text']);
    }

    /** Экран не тупик: всегда есть выход в мир и обновление. */
    public function testScreenIsNeverDeadEnd(): void
    {
        $json = json_encode($this->surface()->buildScreen($this->character())['keyboard'], JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringContainsString('"callback_data":"move"', $json);
        $this->assertStringContainsString('"callback_data":"tasksHub"', $json);
        $this->assertStringContainsString('"callback_data":"questAndTask"', $json);
    }

    /**
     * Markdown-безопасность: непарная `*`/`_` из имени задачи ломает ВЕСЬ текст (Telegram 400 →
     * тихий не-сенд). Уроки legacy_markdown + per-base tax.
     */
    public function testMarkdownMetacharactersStrippedFromTaskName(): void
    {
        $svc    = $this->surface();
        $method = new \ReflectionMethod($svc, 'markdownSafe');
        $method->setAccessible(true);

        $this->assertSame('Крафт Повязки', $method->invoke($svc, 'Крафт *Повязки'));
        $this->assertSame('Добыча', $method->invoke($svc, '_Добыча_'));
        $this->assertSame('Сбор', $method->invoke($svc, '[Сбор]'));
    }

    /** Итоговый текст держит парные `*` (иначе Telegram отвергнет parse_mode=Markdown). */
    public function testRenderedTextHasBalancedAsterisks(): void
    {
        $tasks  = [['id' => 1, 'name' => 'Добыча', 'left' => '5 мин']];
        $screen = $this->surface($tasks, [
            'active' => 2,
            'daily'  => ['enabled' => true, 'assigned' => true, 'done' => 0, 'total' => 3, 'bonus' => 500],
        ], '🎯 *Сейчас:* Иди на восток')->buildScreen($this->character());

        $this->assertSame(0, substr_count($screen['text'], '*') % 2, 'Непарная `*` в тексте «Дела».');
    }
}
