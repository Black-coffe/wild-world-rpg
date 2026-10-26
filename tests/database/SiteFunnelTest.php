<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\AccountRegister;
use App\Database\Migrations\CreateSiteCategoriesTable;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

/**
 * W-REG.1 — воронка сайта под регистрацию. При закрытом `web.open_registration` главная, вход и /about
 * прежние; при открытом — вторая дверь «Играть в браузере», вход говорит, что Google/Яндекс создаст
 * аккаунт, блок «Как начать» общий для главной и /about. Шапке нужна `site_categories`; гостю на входе таблицы аккаунтов не нужны.
 *
 * @internal
 */
final class SiteFunnelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const FLAG_CACHE_KEY = 'game_settings_web_open_registration';

    private BaseConnection $conn;

    private ?Forge $forgeInstance = null;

    /** @var array<string,bool> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->conn->resetDataCache();
        $classes = [
            CreateSiteCategoriesTable::class => '2026-05-25-180000_CreateSiteCategoriesTable.php',
        ];
        foreach ($classes as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }
        $forge               = Database::forge();
        $this->forgeInstance = $forge instanceof Forge ? $forge : null;

        $this->created['site_categories'] = ! $this->conn->tableExists('site_categories');
        if ($this->created['site_categories']) {
            (new CreateSiteCategoriesTable($this->forgeInstance))->up();
        }

        $this->mockSession();
        $this->mockCache();
    }

    protected function tearDown(): void
    {
        if (($this->created['site_categories'] ?? false) === true) {
            $this->forgeInstance?->dropTable('site_categories', true);
        }
        $this->created = [];
        $this->conn->resetDataCache();
        Services::resetSingle('cache');
        parent::tearDown();
    }

    public function testClosedRegistrationKeepsTheTelegramOnlySite(): void
    {
        $this->setFlag(false);
        $this->assertFalse(AccountRegister::registrationOpen());

        $home = $this->home();
        $this->assertStringContainsString('Никаких регистраций', $home);
        $this->assertStringNotContainsString('Играть в браузере', $home);
        $this->assertStringNotContainsString('data-start-doors', $home);

        $login = $this->login();
        $this->assertStringNotContainsString('аккаунт создастся', $login);
        $this->assertStringNotContainsString('data-login-new', $login);

        $this->assertStringNotContainsString('data-start-doors', $this->about(false));
    }

    public function testOpenRegistrationShowsBothDoors(): void
    {
        $this->setFlag(true);
        $this->assertTrue(AccountRegister::registrationOpen());

        $home = $this->home();
        $this->assertStringNotContainsString('Никаких регистраций', $home);
        $this->assertStringNotContainsString('Только Telegram', $home);
        $this->assertStringContainsString('Telegram · браузер', $home);
        $playLink = 'href="' . esc(base_url('play'), 'attr') . '">▶ Играть в браузере';
        // hero, блок «Как начать», финальный CTA
        $this->assertSame(3, substr_count($home, $playLink));
        $this->assertSame(1, substr_count($home, 'data-start-doors'));

        $login = $this->login();
        $this->assertStringContainsString('data-login-new', $login);
        $this->assertStringContainsString('аккаунт создастся', $login);
        $this->assertStringContainsString('Уже играешь в Telegram?', $login);
        $this->assertLessThan(strpos($login, 'Ввести код из бота'), strpos($login, 'аккаунт создастся'), 'новичку — первым блоком');
        $this->assertSame(1, substr_count($login, 'Войти через Google') + substr_count($login, '>Google<'), 'кнопки Google не дублируются');

        $about = $this->about(true);
        $this->assertStringContainsString('data-start-doors', $about);
        $this->assertStringContainsString('▶ Играть в браузере', $about);
    }

    private function setFlag(bool $open): void
    {
        $cache = service('cache');
        $this->assertIsObject($cache);
        $cache->save(self::FLAG_CACHE_KEY, ['v' => $open, 't' => 'bool'], 60);
    }

    private function home(): string
    {
        return view('site/home', [
            'latest'           => [],
            'wikiSections'     => [],
            'factions'         => [],
            'registrationOpen' => AccountRegister::registrationOpen(),
        ]);
    }

    private function login(): string
    {
        $result = $this->get('account/login');
        $result->assertStatus(200);

        return (string) $result->response()->getBody();
    }

    private function about(bool $startDoors): string
    {
        return view('site/page', [
            'page'        => ['slug' => 'about', 'title' => 'О проекте Wild World', 'content_html' => '<p>Текст из БД.</p>'],
            'breadcrumbs' => [],
            'startDoors'  => $startDoors,
        ]);
    }
}
