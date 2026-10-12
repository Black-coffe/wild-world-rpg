<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Events\EventMessageFormatter;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\OnboardingHintCatalog;
use App\Services\Player\TipService;
use App\Services\Web\WebTaskResultPicker;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WebPlay;

/**
 * web-onboarding-02 — {@see WebTaskResultPicker}: итог задачи — первое по id сообщение в окне после
 * `updated_at` задачи, кроме чужих по типу (шаг цепочки, подсказка, титул, совет дня, событие,
 * рассылки). Сигнатуры-константы сверяются с исходником отправителя.
 *
 * Без БД.
 *
 * @internal
 */
final class WebTaskResultPickerTest extends CIUnitTestCase
{
    private const DONE = '2026-10-11 12:00:00';

    private const RESULT = '✅ Сбор завершён: дерево ×3. Персонаж свободен.';

    private int $nextId = 1;

    // ── окно и порядок ───────────────────────────────────────────────────

    public function testFirstNonForeignInWindowIsPickedById(): void
    {
        $rows = [
            $this->row(5, self::DONE, $this->msg(105, 'второй')),
            $this->row(4, self::DONE, $this->msg(104, self::RESULT)),
        ];

        $this->assertSame(104, $this->pick($rows)['message_id'] ?? null);
    }

    public function testMessageBeforeTaskEndOrAfterWindowIsNotPicked(): void
    {
        $window = (new WebPlay())->taskResultWindowSeconds;
        $rows   = [
            $this->row(1, '2026-10-11 11:59:59', $this->msg(101, self::RESULT)),
            $this->row(2, date('Y-m-d H:i:s', (int) strtotime(self::DONE) + $window + 1), $this->msg(102, self::RESULT)),
        ];
        $this->assertNull($this->pick($rows));

        $rows[] = $this->row(3, date('Y-m-d H:i:s', (int) strtotime(self::DONE) + $window), $this->msg(103, self::RESULT));
        $this->assertSame(103, $this->pick($rows)['message_id'] ?? null, 'край окна включён');
    }

    public function testChainStepEarlierInSameSecondLosesToGatherResult(): void
    {
        $rows = [
            $this->row(1, self::DONE, $this->chainStep(201)),
            $this->row(2, self::DONE, $this->msg(202, self::RESULT)),
        ];

        $this->assertSame(202, $this->pick($rows)['message_id'] ?? null);
    }

    public function testOnlyForeignInWindowGivesNull(): void
    {
        $rows = [
            $this->row(1, self::DONE, $this->chainStep(301)),
            $this->row(2, self::DONE, $this->msg(302, "🎖 *Новый титул!*\n\n🎖 *Новичок*\n")),
            $this->row(3, self::DONE, $this->hint(303, OnboardingHintCatalog::DAILY_TASKS)),
        ];

        $this->assertNull($this->pick($rows));
    }

    // ── каждый чужой тип ─────────────────────────────────────────────────

    public function testChainStepIsForeign(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->assertSame('chain_step', $this->picker()->foreignType($this->chainStep(1, $step['title_en'])), $step['title_en']);
        }
    }

    public function testEveryHintIsForeign(): void
    {
        foreach (array_keys(OnboardingHintCatalog::all()) as $key) {
            $this->assertSame('hint', $this->picker()->foreignType($this->hint(1, $key)), $key);
        }
    }

    public function testDailyTipIsForeign(): void
    {
        $text = TipService::renderTip(['id' => 42, 'title_ru' => 'Вода', 'tip_type' => 'ресурсы', 'content' => 'Пей воду.']);

        $this->assertSame('daily_tip', $this->picker()->foreignType($this->msg(1, $text)));
    }

    public function testWorldEventStartAndEndAreForeign(): void
    {
        $f     = new EventMessageFormatter();
        $event = ['name' => 'Буря', 'description' => 'Ветер', 'duration' => 30];
        $start = $this->msg(1, $f->buildStartMessage($event, []), $f->buildStartKeyboard('weather')['inline_keyboard']);
        $end   = $this->msg(2, $f->buildEndMessage($event, ['applied_count' => 3]), $f->buildEndKeyboard('weather', ['health_delta' => -20])['inline_keyboard']);

        $this->assertSame('world_event', $this->picker()->foreignType($start));
        $this->assertSame('world_event', $this->picker()->foreignType($end));
    }

    public function testEveryConstantPrefixIsForeignAsTextAndAsCaption(): void
    {
        foreach (WebTaskResultPicker::FOREIGN_TEXT_PREFIXES as $type => $prefixes) {
            foreach ($prefixes as $prefix) {
                $this->assertSame($type, $this->picker()->foreignType($this->msg(1, $prefix . "\n\nтело")), $prefix);
                $caption            = $this->msg(2, null);
                $caption['caption'] = $prefix . ' подпись';
                $this->assertSame($type, $this->picker()->foreignType($caption), $prefix . ' (caption)');
            }
        }
    }

    public function testTaskResultsAreNotForeign(): void
    {
        foreach ([self::RESULT, '⛏ Сбор ресурсов окончен! Добыто: камень ×2', '🛠 Крафт завершён: Повязка ×1'] as $text) {
            $this->assertNull($this->picker()->foreignType($this->msg(1, $text, [[['text' => '⛏ Ещё', 'callback_data' => 'gather']]])), $text);
        }
    }

    // ── константы сверены с исходником отправителя ───────────────────────

    public function testConstantPrefixesMatchSenderSource(): void
    {
        $plural = [
            'title'       => ['app/TaskHandlers/Titles/TitleCheckCron.php', '"🎖 *{$plural}!*', "'Новые титулы' : 'Новый титул'", ['Новый титул', 'Новые титулы'], '🎖 *%s!*'],
            'achievement' => ['app/TaskHandlers/Achievements/AchievementCheckCron.php', '"🏅 *{$plural}!*', "'Новые достижения' : 'Новое достижение'", ['Новое достижение', 'Новые достижения'], '🏅 *%s!*'],
        ];
        foreach ($plural as $type => [$file, $template, $choice, $words, $format]) {
            $src = $this->source($file);
            $this->assertStringContainsString($template, $src, "{$type}: шаблон заголовка в {$file}");
            $this->assertStringContainsString($choice, $src, "{$type}: варианты заголовка в {$file}");
            $this->assertSame(array_map(static fn (string $w): string => sprintf($format, $w), $words), WebTaskResultPicker::FOREIGN_TEXT_PREFIXES[$type]);
        }

        $verbatim = [
            'daily_task'   => 'app/TaskHandlers/Quests/DailyTaskProgressHandler.php',
            'streak'       => 'app/TaskHandlers/Streak/StreakMilestoneCron.php',
            'event_legacy' => 'app/TaskHandlers/Events/EventActivationHandler.php',
            'season'       => 'app/TaskHandlers/Other/SeasonalTransitionHandler.php',
            'pvp_ladder'   => 'app/TaskHandlers/PVP/PvpLadderWeeklyBroadcastHandler.php',
            'node_digest'  => 'app/TaskHandlers/NPC/NodeAnnounceDigestHandler.php',
            'meal'         => 'app/TaskHandlers/FoodAndWaterConsumptionHandler.php',
            'low_health'   => 'app/TaskHandlers/LowHealthWarningHandler.php',
        ];
        foreach ($verbatim as $type => $file) {
            $src = $this->source($file);
            foreach (WebTaskResultPicker::FOREIGN_TEXT_PREFIXES[$type] as $prefix) {
                $this->assertStringContainsString($prefix, $src, "{$type}: «{$prefix}» нет в {$file}");
            }
        }

        $this->assertSame(array_keys(WebTaskResultPicker::FOREIGN_TEXT_PREFIXES), array_merge(array_keys($plural), array_keys($verbatim)), 'каждая константа сверена');
    }

    // ── помощники ────────────────────────────────────────────────────────

    private function picker(): WebTaskResultPicker
    {
        return new WebTaskResultPicker(new WebPlay());
    }

    /**
     * @param list<array{id:int, created_at:string, msg:array<string,mixed>}> $rows
     *
     * @return array<string,mixed>|null
     */
    private function pick(array $rows): ?array
    {
        return $this->picker()->pick(['id' => 9, 'status' => 'completed', 'updated_at' => self::DONE], $rows);
    }

    /**
     * @param array<string,mixed> $msg
     *
     * @return array{id:int, created_at:string, msg:array<string,mixed>}
     */
    private function row(int $id, string $at, array $msg): array
    {
        return ['id' => $id, 'created_at' => $at, 'msg' => $msg];
    }

    /**
     * @param list<list<array<string,string>>> $keyboard
     *
     * @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}
     */
    private function msg(int $messageId, ?string $text, array $keyboard = []): array
    {
        $rows = [];
        foreach ($keyboard as $r) {
            $row = [];
            foreach ($r as $b) {
                $row[] = ['text' => $b['text'] ?? '', 'callback_data' => $b['callback_data'] ?? ''];
            }
            $rows[] = $row;
        }

        return ['message_id' => $messageId, 'text' => $text, 'caption' => null, 'parse_mode' => 'Markdown', 'photo_url' => null, 'inline_keyboard' => $rows];
    }

    /** @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>} */
    private function chainStep(int $messageId, string $titleEn = OnboardingChainCatalog::STEP_GATHER): array
    {
        $c = OnboardingChainCatalog::completion($titleEn);
        $this->assertNotNull($c);
        $markup = json_decode($c['reply_markup'], true);
        $this->assertIsArray($markup);

        return $this->msg($messageId, $c['text'] . "\n\n🏆 +50 золота за шаг.", $markup['inline_keyboard']);
    }

    /** @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>} */
    private function hint(int $messageId, string $key): array
    {
        $h = OnboardingHintCatalog::get($key);
        $this->assertNotNull($h);
        $markup = isset($h['reply_markup']) ? json_decode($h['reply_markup'], true) : null;

        return $this->msg($messageId, $h['text'], is_array($markup) ? $markup['inline_keyboard'] : []);
    }

    private function source(string $file): string
    {
        $src = file_get_contents(ROOTPATH . $file);
        $this->assertIsString($src, $file);

        return $src;
    }
}
