<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use App\Database\Migrations\CreateSiteCategoriesTable;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * web-bridge-p1-06 — вьюхи `/play` на фикстурах через view(): экран (фото + полная подпись в
 * media-off, текст инертен), док и запасная «Меню», история, ввод, колокол, входящие, заглушка.
 * Каждая форма несёт CSRF и свой intent_id; telegram id в выводе не появляется.
 * Атрибуты читаются через DOM: esc(…, 'attr') кодирует `:`/`/` сущностями.
 * `site/play` и `site/play_stub` рендерят `site/layout.php`, который читает `site_categories`:
 * таблица строится настоящей миграцией, если её нет (CI на пустой БД), и сносится только тогда.
 *
 * @internal
 */
final class PlayViewsTest extends CIUnitTestCase
{
    private const TELEGRAM_ID = 7_104_559_321;

    private ?Forge $forgeInstance = null;

    private bool $createdSiteCategories = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(CreateSiteCategoriesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-25-180000_CreateSiteCategoriesTable.php';
        }
        $conn = Database::connect();
        $conn->resetDataCache();
        $forge                       = Database::forge();
        $this->forgeInstance         = $forge instanceof Forge ? $forge : null;
        $this->createdSiteCategories = ! $conn->tableExists('site_categories');
        if ($this->createdSiteCategories) {
            (new CreateSiteCategoriesTable($this->forgeInstance))->up();
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdSiteCategories) {
            $this->forgeInstance?->dropTable('site_categories', true);
            $this->createdSiteCategories = false;
            Database::connect()->resetDataCache();
        }
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function msg(int $id, array $over = []): array
    {
        return array_merge([
            'message_id'      => $id,
            'text'            => 'Экран ' . $id,
            'caption'         => null,
            'parse_mode'      => null,
            'photo_url'       => null,
            'inline_keyboard' => [],
            // Лишний ключ, который вьюха обязана не выводить (ADR-189 инв. 6).
            'chat_id' => self::TELEGRAM_ID,
        ], $over);
    }

    /**
     * @return array<string,mixed>
     */
    private static function state(): array
    {
        $caption = "*🚰 Скважина* у станции\n" . str_repeat('Вода 12/40, насос изношен. ', 45) . 'КОНЕЦ-ПОДПИСИ';

        return [
            'screen' => [
                self::msg(1_000_000_010, [
                    'text'            => null,
                    'caption'         => $caption,
                    'parse_mode'      => 'Markdown',
                    'inline_keyboard' => [[
                        ['text' => '💧 Набрать', 'callback_data' => 'well:draw'],
                        ['text' => 'Гайд', 'url' => 'https://wildworld.fun/guide'],
                    ]],
                ]),
                self::msg(1_000_000_011, ['text' => '<script>alert(1)</script> злой текст']),
            ],
            'history' => [
                [self::msg(1_000_000_009, ['text' => 'Карта-новее', 'inline_keyboard' => [[['text' => 'Назад', 'callback_data' => 'map:back']]]])],
                [self::msg(1_000_000_008, ['text' => 'Рюкзак-средний'])],
                [self::msg(1_000_000_007, ['text' => 'Персонаж-старее'])],
            ],
            'dock'        => [['🗺 Карта', '🎒 Рюкзак'], ['🏠 База']],
            'input'       => ['placeholder' => 'Введи имя базы', 'reply_to' => 1_000_000_011],
            'telegram_id' => self::TELEGRAM_ID,
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function renderState(array $state, ?string $alert = null): string
    {
        return view('site/_play/state', ['state' => $state, 'alert' => $alert]);
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /**
     * Поля каждой формы под $scope: name → value, плюс `@action`, `@button`, `@placeholder`.
     *
     * @return list<array<string,string>>
     */
    private static function forms(string $html, string $scope = '//'): array
    {
        $xp  = self::xpath($html);
        $out = [];
        foreach ($xp->query($scope . 'form') ?: [] as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }
            $fields = ['@action' => $form->getAttribute('action')];
            foreach ($xp->query('.//input', $form) ?: [] as $input) {
                if ($input instanceof DOMElement) {
                    $fields[$input->getAttribute('name')] = $input->getAttribute('value');
                    if ($input->hasAttribute('placeholder')) {
                        $fields['@placeholder'] = $input->getAttribute('placeholder');
                    }
                }
            }
            $fields['@button'] = trim((string) $xp->query('.//button', $form)?->item(0)?->textContent);
            $out[]             = $fields;
        }

        return $out;
    }

    private function assertFormsCarryCsrfAndUniqueIntent(string $html): void
    {
        $forms = self::forms($html);
        $this->assertNotEmpty($forms);
        $intents = [];
        foreach ($forms as $form) {
            $this->assertArrayHasKey(csrf_token(), $form, 'CSRF field in every form');
            $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $form['intent_id'] ?? '', 'intent_id in every form');
            $this->assertStringEndsWith('play/act', $form['@action']);
            $intents[] = $form['intent_id'];
        }
        $this->assertSame(count($intents), count(array_unique($intents)), 'intent_id unique per form');
    }

    public function testPhotoWithLongCaptionAndNoPhotoUrlRendersWholeCaptionAndNoImg(): void
    {
        $html = $this->renderState(self::state());

        $this->assertStringContainsString('<figcaption class="play-msg-caption">', $html);
        $this->assertStringContainsString('<b>🚰 Скважина</b>', $html, 'Markdown rendered as HTML');
        $this->assertStringContainsString('КОНЕЦ-ПОДПИСИ', $html, 'whole caption, not truncated');
        $this->assertSame(45, substr_count($html, 'Вода 12/40, насос изношен.'));
        $this->assertStringNotContainsString('<img', $html, 'no broken img without photo_url');
    }

    public function testPhotoUrlRendersImageAboveCaption(): void
    {
        $state           = self::state();
        $state['screen'] = [self::msg(5, ['text' => null, 'caption' => 'Подпись', 'photo_url' => '/og-default.jpg'])];
        $xp              = self::xpath($this->renderState($state));

        $this->assertSame('/og-default.jpg', $xp->evaluate('string(//figure[@class="play-msg-figure"]/img/@src)'));
        $this->assertSame('Подпись', $xp->evaluate('string(//figure/figcaption)'));
    }

    /** p1-13 (#10): img только для http(s):// и пути сайта; `//host` — одна подпись. */
    public function testPhotoSrcAcceptsOnlyHttpOrSiteRelativePath(): void
    {
        // p1-14: путь сайта рисуется только при существующем файле — фикстура создаётся тестом.
        $fixture = FCPATH . 'uploads/x.png';
        $this->assertFileDoesNotExist($fixture);
        file_put_contents($fixture, 'png');

        try {
            $this->assertPhotoCases();
        } finally {
            @unlink($fixture);
        }
    }

    private function assertPhotoCases(): void
    {
        $cases = [
            '//evil.example/x.png'          => false,
            '/\\evil.example/x.png'         => false,
            'javascript:alert(1)'           => false,
            '/uploads/x.png'                => true,
            'https://wildworld.fun/x.png'   => true,
        ];
        foreach ($cases as $url => $img) {
            $state           = self::state();
            $state['screen'] = [self::msg(5, ['text' => null, 'caption' => 'Подпись целиком: вода 12/40', 'photo_url' => $url])];
            $xp              = self::xpath($this->renderState($state));

            $this->assertSame($img ? 1.0 : 0.0, $xp->evaluate('count(//img)'), $url);
            if ($img) {
                $this->assertSame($url, $xp->evaluate('string(//figure[@class="play-msg-figure"]/img/@src)'), $url);
            }
            $this->assertSame('Подпись целиком: вода 12/40', $xp->evaluate('string(//figure/figcaption)'), $url);
        }
    }

    /** p1-14 (A18, A12): путь сайта без файла (копия удалена) — без img, подпись целиком. */
    public function testSiteRelativePhotoRendersImgOnlyWhenFileExists(): void
    {
        $dir     = FCPATH . 'uploads/web/';
        $madeDir = ! is_dir($dir);
        if ($madeDir) {
            mkdir($dir, 0775, true);
        }
        $name    = 'p14-view-' . bin2hex(random_bytes(6)) . '.png';
        $caption = "*🗺 Карта* участка
" . str_repeat('Клетка 12, лес, вода рядом. ', 30) . 'КОНЕЦ-КАРТЫ';

        try {
            $state           = self::state();
            $state['screen'] = [self::msg(5, ['text' => null, 'caption' => $caption, 'parse_mode' => 'Markdown', 'photo_url' => '/uploads/web/' . $name])];

            $xp = self::xpath($this->renderState($state));
            $this->assertSame(0.0, $xp->evaluate('count(//img)'), 'нет файла — нет битой картинки');
            $this->assertStringContainsString('КОНЕЦ-КАРТЫ', $xp->evaluate('string(//figure/figcaption)'));
            $this->assertSame(30, substr_count($xp->evaluate('string(//figure/figcaption)'), 'Клетка 12, лес, вода рядом.'));

            file_put_contents($dir . $name, 'png');
            $xp = self::xpath($this->renderState($state));
            $this->assertSame('/uploads/web/' . $name, $xp->evaluate('string(//figure[@class="play-msg-figure"]/img/@src)'));
        } finally {
            @unlink($dir . $name);
            if ($madeDir) {
                @rmdir($dir);
            }
        }
    }

    public function testScriptInTextIsInert(): void
    {
        $html = $this->renderState(self::state());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testInlineButtonsPostCallbackAndUrlButtonsAreExternalLinks(): void
    {
        $html  = $this->renderState(self::state());
        $forms = self::forms($html, '//article[contains(@class,"play-msg")]//');

        $this->assertCount(1, $forms);
        $this->assertSame('callback', $forms[0]['kind']);
        $this->assertSame('well:draw', $forms[0]['data']);
        $this->assertSame('1000000010', $forms[0]['message_id']);
        $this->assertSame('💧 Набрать', $forms[0]['@button']);

        $xp   = self::xpath($html);
        $link = $xp->query('//a[contains(@class,"play-kb-btn") and contains(@class,"is-url")]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame('https://wildworld.fun/guide', $link->getAttribute('href'));
        $this->assertStringContainsString('noopener', $link->getAttribute('rel'));
    }

    public function testDockRendersOneFormPerReplyButtonWithLabelAsData(): void
    {
        $forms = self::forms($this->renderState(self::state()), '//nav[@class="play-dock"]//');

        $this->assertCount(3, $forms);
        foreach (['🗺 Карта', '🎒 Рюкзак', '🏠 База'] as $i => $label) {
            $this->assertSame('text', $forms[$i]['kind']);
            $this->assertSame($label, $forms[$i]['data']);
            $this->assertSame($label, $forms[$i]['@button']);
        }
    }

    public function testEmptyDockRendersMenuFallback(): void
    {
        $state         = self::state();
        $state['dock'] = [];
        $forms         = self::forms($this->renderState($state), '//nav[@class="play-dock"]//');

        $this->assertCount(1, $forms);
        $this->assertSame('command', $forms[0]['kind']);
        $this->assertSame('/menu', $forms[0]['data']);
        $this->assertSame('Меню', $forms[0]['@button']);
    }

    public function testHistoryRendersAllScreensNewestFirstWithPressableButtons(): void
    {
        $xp    = self::xpath($this->renderState(self::state()));
        $items = $xp->query('//ol[@class="play-history-list"]/li');
        $this->assertNotFalse($items);

        $this->assertSame(3, $items->length);
        $this->assertStringContainsString('Карта-новее', (string) $items->item(0)?->textContent);
        $this->assertStringContainsString('Рюкзак-средний', (string) $items->item(1)?->textContent);
        $this->assertStringContainsString('Персонаж-старее', (string) $items->item(2)?->textContent);

        $forms = self::forms($this->renderState(self::state()), '//ol[@class="play-history-list"]//');
        $this->assertCount(1, $forms, 'history buttons stay pressable');
        $this->assertSame('map:back', $forms[0]['data']);
        $this->assertSame('1000000009', $forms[0]['message_id']);
    }

    public function testTextInputCarriesPlaceholderAndForceReplyId(): void
    {
        $forms = self::forms($this->renderState(self::state()), '//*[@class="play-screen"]/');

        $this->assertCount(1, $forms);
        $this->assertSame('text', $forms[0]['kind']);
        $this->assertSame('Введи имя базы', $forms[0]['@placeholder']);
        $this->assertSame('1000000011', $forms[0]['message_id']);
        $this->assertArrayHasKey('data', $forms[0]);
    }

    public function testTextInputWithoutForceReplyHasNoMessageId(): void
    {
        $state          = self::state();
        $state['input'] = null;
        $forms          = self::forms($this->renderState($state), '//*[@class="play-screen"]/');

        $this->assertCount(1, $forms);
        $this->assertArrayNotHasKey('message_id', $forms[0]);
    }

    public function testAlertRendersEscapedWhenPresent(): void
    {
        $this->assertStringNotContainsString('play-alert', $this->renderState(self::state()));

        $html = $this->renderState(self::state(), 'Дрон <b>уже</b> в пути');
        $this->assertStringContainsString('class="play-alert"', $html);
        $this->assertStringContainsString('Дрон &lt;b&gt;уже&lt;/b&gt; в пути', $html);
    }

    public function testEveryStateFormHasCsrfAndUniqueIntentAndNoTelegramId(): void
    {
        $html = $this->renderState(self::state());

        $this->assertFormsCarryCsrfAndUniqueIntent($html);
        $this->assertStringNotContainsString((string) self::TELEGRAM_ID, $html);
    }

    public function testInboxMarksUnreadNewestFirstAndButtonsPost(): void
    {
        $items = [
            ['msg' => self::msg(20, ['text' => 'Крафт-готов-старое']), 'created_at' => '2026-09-24 10:00:00', 'read' => true],
            ['msg' => self::msg(21, ['text' => 'На-базу-напали-новое', 'inline_keyboard' => [[['text' => 'К базе', 'callback_data' => 'base:open']]]]), 'created_at' => '2026-09-24 12:00:00', 'read' => false],
        ];
        $html = view('site/_play/inbox', ['items' => $items]);
        $xp   = self::xpath($html);
        $li   = $xp->query('//ul[@class="play-inbox"]/li');
        $this->assertNotFalse($li);

        $this->assertSame(2, $li->length);
        $first = $li->item(0);
        $this->assertInstanceOf(DOMElement::class, $first);
        $this->assertStringContainsString('На-базу-напали-новое', $first->textContent, 'newest first');
        $this->assertStringContainsString('is-unread', $first->getAttribute('class'));
        $second = $li->item(1);
        $this->assertInstanceOf(DOMElement::class, $second);
        $this->assertStringNotContainsString('is-unread', $second->getAttribute('class'));

        $forms = self::forms($html);
        $this->assertCount(1, $forms);
        $this->assertSame(['callback', 'base:open', '21'], [$forms[0]['kind'], $forms[0]['data'], $forms[0]['message_id']]);
        $this->assertFormsCarryCsrfAndUniqueIntent($html);
        $this->assertStringNotContainsString((string) self::TELEGRAM_ID, $html);
    }

    public function testEmptyInboxSaysSo(): void
    {
        $this->assertStringContainsString('Входящих нет.', view('site/_play/inbox', ['items' => []]));
    }

    public function testPlayPageShowsBellCountNameAndState(): void
    {
        $html = view('site/play', ['state' => self::state(), 'unread' => 3, 'poll_seconds' => 30, 'character_name' => 'ВОРОН-7']);
        $xp   = self::xpath($html);

        $this->assertSame('ВОРОН-7', $xp->evaluate('string(//*[@class="play-bar-title"])'));
        $this->assertSame('3', $xp->evaluate('string(//a[contains(@class,"play-bell") and contains(@class,"is-unread")]/span[@class="play-bell-count"])'));
        $this->assertSame(2.0, $xp->evaluate('count(//*[@id="play-state"]//article)'));
        $this->assertSame(1.0, $xp->evaluate('count(//*[@id="play-inbox"])'));
        $this->assertSame('30', $xp->evaluate('string(//*[@id="play-root"]/@data-poll-seconds)'));
        $this->assertStringContainsString('assets/js/wildworld-play.js', $html);
        $this->assertStringNotContainsString((string) self::TELEGRAM_ID, $html);
    }

    public function testPlayPageShowsNoCountAtZeroAndClampsPoll(): void
    {
        $html = view('site/play', ['state' => self::state(), 'unread' => 0, 'poll_seconds' => 1, 'character_name' => 'ВОРОН-7']);
        $xp   = self::xpath($html);

        $this->assertSame('', $xp->evaluate('string(//span[@class="play-bell-count"])'));
        $this->assertSame(0.0, $xp->evaluate('count(//a[contains(@class,"is-unread")])'));
        $min = config(\Config\WebPlay::class)->inboxPollMinSeconds;
        $this->assertSame((string) $min, $xp->evaluate('string(//*[@id="play-root"]/@data-poll-seconds)'), 'never below the server minimum');
    }

    /**
     * @return list<string> href всех ссылок страницы
     */
    private static function hrefs(string $html): array
    {
        $out = [];
        foreach (self::xpath($html)->query('//main//a') ?: [] as $a) {
            if ($a instanceof DOMElement) {
                $out[] = $a->getAttribute('href');
            }
        }

        return $out;
    }

    public function testStubFlagOffExplainsAndLinksBotAndAccount(): void
    {
        $html  = view('site/play_stub', ['reason' => 'flag_off', 'can_register' => false]);
        $hrefs = self::hrefs($html);

        $this->assertStringContainsString('Игра на сайте скоро: включается после проверки', $html);
        $this->assertContains(config('Social')->botStart('src_site_play'), $hrefs);
        $this->assertContains(base_url('account'), $hrefs);
    }

    public function testStubNoCharacterWithRegistrationLinksCharacterCreation(): void
    {
        $html = view('site/play_stub', ['reason' => 'no_character', 'can_register' => true]);

        $this->assertStringContainsString('нет персонажа', $html);
        $this->assertContains(base_url('account/character'), self::hrefs($html));
    }

    public function testStubNoCharacterWithoutRegistrationShowsWebCodePath(): void
    {
        $html  = view('site/play_stub', ['reason' => 'no_character', 'can_register' => false]);
        $hrefs = self::hrefs($html);

        $this->assertStringContainsString('/web', $html);
        $this->assertContains(base_url('account/link'), $hrefs);
        $this->assertNotContains(base_url('account/character'), $hrefs);
    }

    /**
     * web-bridge-p1-17 — `no_character` видно только вошедшему, а код у вошедшего в другой аккаунт
     * отказывает (LinkCodeService, F1): заглушка не обещает код «как есть», а ведёт через выход.
     */
    public function testStubNoCharacterLeadsBotPlayerThroughLogoutInBothRegistrationStates(): void
    {
        foreach ([true, false] as $canRegister) {
            $label = $canRegister ? 'can_register' : 'no register';
            $html  = view('site/play_stub', ['reason' => 'no_character', 'can_register' => $canRegister]);
            $text  = (string) preg_replace('/\s+/u', ' ', strip_tags($html));

            $this->assertStringContainsString('выйди из другого входа и введи код', $text, $label);
            $this->assertStringNotContainsString('войдёшь в своего персонажа', $text, $label);
            $this->assertStringNotContainsString('привяж', $text, $label);

            $logout = array_values(array_filter(
                self::forms($html),
                static fn (array $f): bool => $f['@action'] === base_url('account/logout'),
            ));
            $this->assertCount(1, $logout, $label . ': one logout form');
            $this->assertArrayHasKey(csrf_token(), $logout[0], $label . ': logout carries CSRF');
            $this->assertSame('Выйти, чтобы ввести код', $logout[0]['@button'], $label);

            $hrefs = self::hrefs($html);
            $this->assertContains(base_url('account/link'), $hrefs, $label);
            if ($canRegister) {
                $this->assertContains(base_url('account/character'), $hrefs, 'Создать персонажа kept');
                $this->assertStringContainsString('Создать персонажа', $html);
            } else {
                $this->assertNotContains(base_url('account/character'), $hrefs);
            }
        }
    }

    public function testStubFlagOffHasNoLogoutPath(): void
    {
        $html = view('site/play_stub', ['reason' => 'flag_off', 'can_register' => true]);

        $this->assertStringNotContainsString(base_url('account/logout'), $html);
        $this->assertStringNotContainsString('Выйти, чтобы ввести код', $html);
    }

    public function testViewsCarryNoInlineStyles(): void
    {
        foreach (['site/play', 'site/play_stub', 'site/_play/state', 'site/_play/inbox'] as $view) {
            $source = (string) file_get_contents(APPPATH . 'Views/' . $view . '.php');
            $this->assertStringNotContainsString('style=', $source, $view);
            $this->assertStringNotContainsString('<style', $source, $view);
        }
    }
}
