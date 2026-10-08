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
            'dock'        => [['🗺 Карта', '🎒 Рюкзак'], ['⚙️ Ещё']],
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
            if (isset($form['view']) && ! isset($form['op'])) {
                // Переход на нативный экран (док «🛒 Магазин», W2.N6) — навигация, не мутация: без intent_id.
                $this->assertStringEndsWith('play/view', $form['@action']);

                continue;
            }
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

        $this->assertCount(5, $forms);
        foreach (['🗺 Карта', '🎒 Рюкзак', '⚙️ Ещё'] as $i => $label) {
            $this->assertSame('text', $forms[$i]['kind']);
            $this->assertSame($label, $forms[$i]['data']);
            $this->assertSame($label, $forms[$i]['@button']);
        }
        $this->assertDockShopAndBattles($forms[3], $forms[4]);
    }

    /**
     * W2.N6: за кнопками меню в доке всегда «🛒 Магазин» (в боте он на карточке «Я»), с W2.N7 — «⚔️ Бои» (журнал, арена,
     * рейтинг); оба — нативные экраны.
     *
     * @param array<string, string> $shop
     * @param array<string, string> $battles
     */
    private function assertDockShopAndBattles(array $shop, array $battles): void
    {
        foreach ([[$shop, 'shop', '🛒 Магазин'], [$battles, 'battles', '⚔️ Бои']] as [$form, $view, $label]) {
            $this->assertSame($view, $form['view'] ?? null);
            $this->assertSame($label, $form['@button']);
            $this->assertStringEndsWith('play/view', $form['@action']);
            $this->assertArrayNotHasKey('kind', $form);
        }
    }

    public function testEmptyDockRendersMenuFallback(): void
    {
        $state         = self::state();
        $state['dock'] = [];
        $forms         = self::forms($this->renderState($state), '//nav[@class="play-dock"]//');

        $this->assertCount(3, $forms);
        $this->assertSame('command', $forms[0]['kind']);
        $this->assertSame('/menu', $forms[0]['data']);
        $this->assertSame('Меню', $forms[0]['@button']);
        $this->assertDockShopAndBattles($forms[1], $forms[2]);
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

    /** w2-n4-tails-02 (ask 3): карточка апгрейда — «✨ Эффект: сейчас → после» текстом, форма несёт уровень `from`. */
    public function testUpgradeCardShowsEffectNowToNextAsText(): void
    {
        $render = static fn (?string $now, ?string $next): string => html_entity_decode(view('site/_play/native_base', [
            'base' => [
                'state' => 'base', 'base_id' => 7, 'section' => 'upgrade',
                'overview' => ['base' => ['x' => 1, 'y' => 2, 'biome' => 'Лес', 'count' => 1, 'tax_total' => 500], 'coverage' => null, 'buildings' => []],
                'upgrade' => [
                    'code' => 'preview', 'building_id' => 2, 'name' => 'Мастерская', 'current_level' => 2, 'level' => 3,
                    'effect_now' => $now, 'effect_next' => $next,
                    'requirements' => ['level' => 5, 'gold' => 1000, 'resources' => []], 'character' => ['level' => 10, 'gold' => 5000],
                ],
            ],
            'dock' => [],
        ]), ENT_QUOTES | ENT_HTML5);

        $grows = $render('−12% время крафта', '−20% время крафта');
        $this->assertStringContainsString('✨ Эффект: −12% время крафта → −20% время крафта', $grows);
        $this->assertMatchesRegularExpression('~name="id" value="2"><input type="hidden" name="from" value="2">~', $grows);

        $this->assertStringContainsString('✨ Эффект: закрытый рынок — от уровня не меняется', $render('закрытый рынок', 'закрытый рынок'));
        $this->assertStringNotContainsString('✨ Эффект', $render(null, null), 'нет эффекта — нет строки');
    }

    /** w2-n4-tails2 (ask 1): ресурсы апгрейда подписаны именем игрока из ядра, ключ `Water` на экран не попадает. */
    public function testUpgradeCardNamesResourcesInRussian(): void
    {
        $render = static fn (array $names): string => html_entity_decode(view('site/_play/native_base', [
            'base' => [
                'state' => 'base', 'base_id' => 7, 'section' => 'upgrade',
                'overview' => ['base' => ['x' => 1, 'y' => 2, 'biome' => 'Лес', 'count' => 1, 'tax_total' => 500], 'coverage' => null, 'buildings' => []],
                'upgrade' => [
                    'code' => 'preview', 'building_id' => 2, 'name' => 'Мастерская', 'current_level' => 3, 'level' => 4,
                    'effect_now' => null, 'effect_next' => null,
                    'requirements' => ['level' => 14, 'gold' => 100000, 'resources' => ['Water' => 15000, 'Wood' => 10000]],
                    'resource_names' => $names, 'character' => ['level' => 20, 'gold' => 200000],
                ],
            ],
            'dock' => [],
        ]), ENT_QUOTES | ENT_HTML5);

        $html = $render(['Water' => 'Вода', 'Wood' => 'Древесина']);
        $this->assertStringContainsString('<span class="play-craft-req-name">Вода</span><span class="play-craft-req-qty">15000</span>', $html);
        $this->assertStringContainsString('<span class="play-craft-req-name">Древесина</span>', $html);
        $this->assertStringNotContainsString('>Water<', $html);

        // Ресурса нет в справочнике — подписан ключом, как в боте.
        $this->assertStringContainsString('<span class="play-craft-req-name">Water</span>', $render([]));
    }

    /** w2-n5-deeds-03: «📋 Дела» в доке — нативный экран, не текст в мост. */
    public function testDockTasksLabelOpensNativeTasksView(): void
    {
        $forms = self::forms(view('site/_play/dock', ['dock' => [['🧑 Я', '📋 Дела']]]));

        $this->assertCount(4, $forms);
        $this->assertSame('tasks', $forms[1]['view']);
        $this->assertSame('📋 Дела', $forms[1]['@button']);
        $this->assertStringEndsWith('play/view', $forms[1]['@action']);
        $this->assertArrayNotHasKey('kind', $forms[1]);
    }

    /** «Дела» читаются текстом: ни картинки, ни inline-стиля; каждая мутация несёт CSRF и свой intent_id. */
    public function testTasksViewIsTextOnlyAndMutationsCarryCsrfAndIntent(): void
    {
        $html = view('site/_play/native_tasks', [
            'tasks' => [
                'section' => 'available', 'hub_enabled' => true, 'daily_enabled' => true, 'hub' => null, 'card' => null, 'events' => null,
                'active' => [], 'completed' => [],
                'available' => [
                    ['id' => 1, 'title_en' => 'CollectWood', 'title_ru' => 'Запас дров', 'description' => '', 'reward' => 300, 'reward_type_ru' => 'золото', 'locked' => false, 'lock_reason' => '', 'prereq_title_ru' => ''],
                    ['id' => 3, 'title_en' => 'ChainStage', 'title_ru' => 'Второй этап', 'description' => '', 'reward' => 100, 'reward_type_ru' => 'золото', 'locked' => true, 'lock_reason' => 'после квеста «Запас дров»', 'prereq_title_ru' => 'Запас дров'],
                ],
                'branches' => [['branch_point_ru' => 'Разминка', 'options' => [
                    ['quest_id' => 5, 'title_en' => 'A', 'title_ru' => 'Путь торговца', 'label' => 'Торговец'],
                    ['quest_id' => 6, 'title_en' => 'B', 'title_ru' => 'Путь разведчика', 'label' => 'Разведчик'],
                ]]],
            ],
            'dock' => [],
        ]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('🔒 Второй этап (нужно: после квеста «Запас дров»)', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $branches = array_values(array_filter(self::forms($html), static fn (array $f): bool => ($f['op'] ?? '') === 'quest_branch'));
        $this->assertCount(2, $branches);
        foreach ($branches as $form) {
            $this->assertArrayHasKey(csrf_token(), $form);
            $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $form['intent_id'] ?? '');
        }
        $this->assertNotSame($branches[0]['intent_id'], $branches[1]['intent_id']);
    }

    /** w2-n6-trade-storage-03: склад вне базы — замок с путём, кнопки «забрать/положить» остаются; всё текстом, мутации с CSRF и своим intent. */
    public function testStorageOffBaseShowsLockWithPathAndKeepsButtons(): void
    {
        $model = [
            'mode' => 'recent', 'total_units' => 35, 'on_base' => false,
            'rows'    => [['resource_id' => 1, 'name' => 'Древесина', 'quantity' => 30], ['resource_id' => 2, 'name' => 'Глина', 'quantity' => 5]],
            'carried' => [['resource_id' => 3, 'name' => 'Камень', 'quantity' => 4]],
        ];
        $off  = view('site/_play/native_storage', ['storage' => $model, 'dock' => []]);
        $text = html_entity_decode($off, ENT_QUOTES | ENT_HTML5);

        $this->assertStringNotContainsString('<img', $off);
        $this->assertStringContainsString('data-storage-lock', $off);
        $this->assertStringContainsString('🔒 Положить и забрать (нужно: стоять на своей базе)', $text);
        $this->assertStringContainsString('Положить и забрать можно только на базе', $text);
        $this->assertStringContainsString('Путь: 🌍 Мир → дойди до клетки своей базы (🏠 на карте) → 🏠 База → 📦 Склад базы', $text);
        $mutations = array_values(array_filter(self::forms($off), static fn (array $f): bool => in_array($f['op'] ?? '', ['storage_take', 'storage_put'], true)));
        $this->assertSame(['storage_take', 'storage_take', 'storage_take', 'storage_put', 'storage_put'], array_column($mutations, 'op'), 'вне базы кнопки не пропадают');
        foreach ($mutations as $form) {
            $this->assertArrayHasKey(csrf_token(), $form);
            $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $form['intent_id'] ?? '');
        }
        $this->assertCount(count($mutations), array_unique(array_column($mutations, 'intent_id')));

        $on = view('site/_play/native_storage', ['storage' => ['on_base' => true] + $model, 'dock' => []]);
        $this->assertStringNotContainsString('data-storage-lock', $on);
    }

    /** Карточка продажи: цена за 1 шт. и итоги пресетов видны до сделки, пресеты — не больше запаса, «своё число» с потолком. */
    public function testShopSellCardShowsPriceBeforeTheDealAndCapsTheNumber(): void
    {
        $presets = array_map(static fn (int $q): array => ['qty' => $q, 'total' => (int) round($q * 4.5)], [1, 5, 10, 15, 25, 50]);
        $html    = html_entity_decode(view('site/_play/native_shop', [
            'shop' => [
                'section' => 'sell_card', 'hub' => [], 'bulk_on' => true, 'rarity' => null, 'bulk' => null,
                'card'    => ['resource_id' => 7, 'name' => 'Ржавый лом', 'rarity' => 1, 'unit_price' => 4.5, 'unit_text' => '4.5', 'max_qty' => 12, 'presets' => $presets],
            ],
            'dock' => [],
        ]), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('<dt>💰 Торговец платит за 1 шт.</dt><dd>4.5 💰</dd>', $html);
        $this->assertStringContainsString('10 шт · 45 💰</button>', $html);
        $this->assertStringNotContainsString('15 шт ·', $html);
        $this->assertStringContainsString('🧺 Всё — 12 шт</button>', $html);
        $this->assertStringContainsString('min="1" max="12"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * duel-baseline-weapon-02: веб-карточка форматирует урон и остаток так же, как бот (`BattleJournalAction::num`):
     * меньше 1 — двумя знаками, а не «−0».
     */
    public function testWebBattleCardShowsDamageBelowOneLikeTheBot(): void
    {
        $card = ['id' => 9, 'type' => 'DUEL', 'duel' => true, 'me' => 'Ворон', 'opponent' => 'Сова', 'result' => 'win', 'at' => '2026-10-08 21:05:00', 'rounds_total' => 6, 'rounds' => [
            ['n' => 1, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 0.01, 'hp_after' => 999.99, 'lucky' => false, 'mine' => true],
            ['n' => 2, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 0.04, 'hp_after' => 0.4, 'lucky' => false, 'mine' => false],
            ['n' => 3, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 0.4, 'hp_after' => 12.6, 'lucky' => false, 'mine' => true],
            ['n' => 4, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 2.36, 'hp_after' => 0.01, 'lucky' => false, 'mine' => false],
            ['n' => 5, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 12.6, 'hp_after' => 2.36, 'lucky' => false, 'mine' => true],
            ['n' => 6, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 0.0, 'hp_after' => 0.04, 'lucky' => false, 'mine' => false],
        ]];
        $html = html_entity_decode(view('site/_play/native_battle', ['battle' => ['card' => $card, 'arena_on' => true], 'dock' => []]), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('1. Ворон → Сова</span><span class="play-craft-req-qty">−0.01 · осталось 1000 HP', $html);
        $this->assertStringContainsString('2. Сова → Ворон</span><span class="play-craft-req-qty">−0.04 · осталось 0.4 HP', $html);
        $this->assertStringContainsString('3. Ворон → Сова</span><span class="play-craft-req-qty">−0.4 · осталось 13 HP', $html);
        $this->assertStringContainsString('4. Сова → Ворон</span><span class="play-craft-req-qty">−2.4 · осталось 0.01 HP', $html);
        $this->assertStringContainsString('5. Ворон → Сова</span><span class="play-craft-req-qty">−13 · осталось 2.4 HP', $html);
        $this->assertStringContainsString('6. Сова → Ворон</span><span class="play-craft-req-qty">промах · осталось 0.04 HP', $html);
        $this->assertStringNotContainsString('−0 ·', $html);
    }

    /**
     * w2-n7-combat-03: журнал и карточка — только текст; длинный бой показан весь; дуэль помечена «без потерь»; чужой бой —
     * отказ без имён. Арена: вызов и тумблер — мутации с CSRF и своим intent; итог ведёт в карточку; замки с объяснением.
     */
    public function testBattleViewsAreTextOnlyAndArenaMutationsCarryCsrfAndIntent(): void
    {
        $rounds = [];
        for ($i = 1; $i <= 60; $i++) {
            $rounds[] = ['n' => $i, 'attacker' => $i % 2 ? 'Ворон' : 'Сова', 'defender' => $i % 2 ? 'Сова' : 'Ворон', 'damage' => 3.25, 'hp_after' => 60.0 - $i, 'lucky' => $i === 2, 'mine' => $i % 2 === 1];
        }
        $card = ['id' => 7, 'type' => 'DUEL', 'duel' => true, 'me' => 'Ворон', 'opponent' => 'Сова', 'result' => 'win', 'at' => '2026-10-08 21:05:00', 'rounds_total' => 60, 'rounds' => $rounds];

        $html = html_entity_decode(view('site/_play/native_battle', ['battle' => ['card' => $card, 'arena_on' => true], 'dock' => []]), ENT_QUOTES | ENT_HTML5);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<dt>Итог</dt><dd>✅ Победа</dd>', $html);
        $this->assertStringContainsString('🤺 Дуэль — без потерь', $html);
        $this->assertStringContainsString('2. Сова → Ворон</span><span class="play-craft-req-qty">−3.3 ⚡ · осталось 58 HP', $html);
        $this->assertStringContainsString('60. Сова → Ворон', $html, 'все раунды, без обрезки');

        $missing = html_entity_decode(view('site/_play/native_battle', ['battle' => ['card' => null, 'arena_on' => false], 'dock' => []]), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Этот бой не найден в твоём журнале.', $missing);
        $this->assertStringContainsString('🔒 Арена (закрыта)', $missing);

        $entries = [['id' => 7, 'type' => 'PVE', 'duel' => false, 'me' => 'Ворон', 'opponent' => 'Рейдер', 'result' => 'loss', 'at' => '2026-10-08 21:05:00', 'rounds_total' => 4]];
        $list    = view('site/_play/native_battles', ['battles' => ['entries' => $entries, 'arena_on' => true, 'ladder_on' => false], 'dock' => []]);
        $this->assertStringNotContainsString('<img', $list);
        $this->assertStringContainsString('1. ❌ Поражение · ⚔️ PvE · Рейдер', html_entity_decode($list, ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('🔒 Рейтинг PvP (закрыт)', html_entity_decode($list, ENT_QUOTES | ENT_HTML5));
        $this->assertContains(['view' => 'battle', 'id' => '7'], array_map(static fn (array $f): array => array_intersect_key($f, ['view' => 1, 'id' => 1]), self::forms($list)));

        $arena = view('site/_play/native_arena', ['arena' => [
            'section' => 'arena', 'ladder' => null, 'arena_on' => true, 'ladder_on' => true, 'last' => $card,
            'arena'   => ['enabled' => true, 'lock' => '', 'self_open' => false, 'ladder_enabled' => true, 'roster' => [['id' => 5, 'name' => 'Сова', 'level' => 3, 'pts' => 4], ['id' => 6, 'name' => 'Лис', 'level' => 2, 'pts' => 0]]],
        ], 'dock' => []]);
        $this->assertStringNotContainsString('<img', $arena);
        $mutations = array_values(array_filter(self::forms($arena), static fn (array $f): bool => in_array($f['op'] ?? '', ['duel', 'duels_open'], true)));
        $this->assertSame([['duel', '5'], ['duel', '6'], ['duels_open', '']], array_map(static fn (array $f): array => [$f['op'], $f['id'] ?? ''], $mutations));
        $this->assertSame('1', $mutations[2]['open'] ?? null);
        foreach ($mutations as $form) {
            $this->assertArrayHasKey(csrf_token(), $form);
            $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $form['intent_id'] ?? '');
        }
        $this->assertCount(3, array_unique(array_column($mutations, 'intent_id')));
        $this->assertContains(['view' => 'battle', 'id' => '7'], array_map(static fn (array $f): array => array_intersect_key($f, ['view' => 1, 'id' => 1]), self::forms($arena)), 'итог дуэли ведёт в карточку');

        $locked = html_entity_decode(view('site/_play/native_arena', ['arena' => [
            'section' => 'arena', 'ladder' => null, 'last' => null, 'arena_on' => false, 'ladder_on' => false,
            'arena'   => ['enabled' => false, 'lock' => \App\Services\PVE\ArenaScreenService::LOCK_ARENA, 'self_open' => false, 'roster' => [], 'ladder_enabled' => false],
        ], 'dock' => []]), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('🔒 Арена (нужно: дуэли открыты администрацией)', $locked);
        $this->assertStringContainsString(\App\Services\PVE\ArenaScreenService::LOCK_ARENA, $locked);
        $this->assertStringContainsString('🔒 Рейтинг PvP (закрыт)', $locked);
        $this->assertStringNotContainsString('value="duel"', $locked);
    }

    public function testViewsCarryNoInlineStyles(): void
    {
        foreach (['site/play', 'site/play_stub', 'site/_play/state', 'site/_play/inbox', 'site/_play/native_tasks', 'site/_play/native_shop', 'site/_play/native_storage', 'site/_play/native_battles', 'site/_play/native_battle', 'site/_play/native_arena'] as $view) {
            $source = (string) file_get_contents(APPPATH . 'Views/' . $view . '.php');
            $this->assertStringNotContainsString('style=', $source, $view);
            $this->assertStringNotContainsString('<style', $source, $view);
        }
    }
}
