<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Sell\BulkSellAction;
use App\Controllers\Telegram\Commands\Actions\Sell\BuyResourceAction;
use App\Controllers\Telegram\Commands\Actions\Sell\SellAction;
use App\Controllers\Telegram\Commands\Actions\Sell\SellResourceAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * w2-n6-trade-storage-02 — паритет бота: магазин сырья после выноса данных в
 * `ResourceShopScreenService` даёт тот же текст и те же кнопки. Снимки сняты со старых
 * handler'ов (тест зелёный и до переноса, и после). Экраны с фото (хаб магазина, итог продажи)
 * сюда не входят: `encodeFile()` открывает URL картинки, в тесте его нет — их рендер проверяет
 * `ResourceShopScreenServiceTest` на чистых функциях.
 *
 * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
 *
 * @internal
 */
final class ResourceShopBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'game_settings',
        'resources', 'character_resources', 'resources_bank',
    ];

    private const TG = 771000026;

    /**
     * Снимки «до» (сняты со старых handler'ов): метод, текст, разметка — дословно.
     *
     * @var array<string, array{method: string, text: string, reply_markup: ?string}>
     */
    private const SNAPSHOTS = [
        'sell_hub' => [
            'method'       => 'editMessageText',
            'text'         => '📦 *У тебя есть разных: 3 вид(а) всех ресурсов*
👉Их общая стоимость = *186💰*

_📌ВАЖНО📌 Чтобы и тебе, и мне, как торговцу, было проще, пересмотри ресурсы и обрати внимание на их редкость. Ниже отметь цифрой, какой редкости ресурсы ты готов продать. Если их там будет несколько, на следующем шаге ты выберешь нужный ресурс._

🧺 *Оптом* — продать сразу долю *всех* ресурсов:',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_1"},{"text":"2\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_2"},{"text":"3\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_3"}],[{"text":"4\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_4"},{"text":"5\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_5"},{"text":"6\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_6"}],[{"text":"7\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_7"},{"text":"8\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_8"},{"text":"9\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_9"}],[{"text":"\\ud83d\\udd1f \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_10"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"}],[{"text":"\\ud83d\\udcb0 10%","callback_data":"bulkSell_all_10"},{"text":"\\ud83d\\udcb0 25%","callback_data":"bulkSell_all_25"},{"text":"\\ud83d\\udcb0 50%","callback_data":"bulkSell_all_50"},{"text":"\\ud83e\\uddfa \\u0412\\u0441\\u0451","callback_data":"bulkSell_all_100"}],[{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'sell_rarity_1' => [
            'method'       => 'editMessageText',
            'text'         => '📦 *Ресурсы редкости 1:*

*Ржавый лом* | Единиц: *40* | На сумму: ~180💰
*Глина* | Единиц: *3* | На сумму: ~6💰
*Семена* | Единиц: *6* | На сумму: ~0💰

*❗️Реальная цена может быть другой исходя из спроса ресурса❗️*
🧺 *Оптом по этой редкости* — продать долю всех показанных ресурсов:',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u0420\\u0436\\u0430\\u0432\\u044b\\u0439 \\u043b\\u043e\\u043c | \\ud83d\\udce6 40 | ~180\\ud83d\\udcb0","callback_data":"sellResource_7_quantity"},{"text":"\\u0413\\u043b\\u0438\\u043d\\u0430 | \\ud83d\\udce6 3 | ~6\\ud83d\\udcb0","callback_data":"sellResource_8_quantity"},{"text":"\\u0421\\u0435\\u043c\\u0435\\u043d\\u0430 | \\ud83d\\udce6 6 | ~0\\ud83d\\udcb0","callback_data":"sellResource_9_quantity"}],[{"text":"\\ud83d\\udcb0 10%","callback_data":"bulkSell_rarity_1_10"},{"text":"\\ud83d\\udcb0 25%","callback_data":"bulkSell_rarity_1_25"},{"text":"\\ud83d\\udcb0 50%","callback_data":"bulkSell_rarity_1_50"},{"text":"\\ud83e\\uddfa \\u0412\\u0441\\u0451","callback_data":"bulkSell_rarity_1_100"}],[{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"sell"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'sell_rarity_3_empty' => [
            'method'       => 'sendMessage',
            'text'         => 'Ресурсы редкости 3 не найдены!',
            'reply_markup' => null,
        ],
        'sell_card' => [
            'method'       => 'editMessageText',
            'text'         => 'Выберите количество для продажи ресурса:
 📦 *Ржавый лом*:
Текущая цена продажи (за 1 ед.) = *4.5* 💰',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1 \\u2192 5\\ud83d\\udcb0","callback_data":"sellResource_7_1_sell"},{"text":"5 \\u2192 23\\ud83d\\udcb0","callback_data":"sellResource_7_5_sell"},{"text":"10 \\u2192 45\\ud83d\\udcb0","callback_data":"sellResource_7_10_sell"},{"text":"15 \\u2192 68\\ud83d\\udcb0","callback_data":"sellResource_7_15_sell"}],[{"text":"25 \\u2192 113\\ud83d\\udcb0","callback_data":"sellResource_7_25_sell"},{"text":"50 \\u2192 225\\ud83d\\udcb0","callback_data":"sellResource_7_50_sell"},{"text":"100 \\u2192 450\\ud83d\\udcb0","callback_data":"sellResource_7_100_sell"},{"text":"150 \\u2192 675\\ud83d\\udcb0","callback_data":"sellResource_7_150_sell"}],[{"text":"250 \\u2192 1,125\\ud83d\\udcb0","callback_data":"sellResource_7_250_sell"},{"text":"500 \\u2192 2,250\\ud83d\\udcb0","callback_data":"sellResource_7_500_sell"},{"text":"1000 \\u2192 4,500\\ud83d\\udcb0","callback_data":"sellResource_7_1000_sell"},{"text":"5000 \\u2192 22,500\\ud83d\\udcb0","callback_data":"sellResource_7_5000_sell"}],[{"text":"\\ud83d\\udcdd \\u0421\\u0432\\u043e\\u0451 \\u0447\\u0438\\u0441\\u043b\\u043e","callback_data":"sellResource_7_custom"},{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"sellResource_rarity_1"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'sell_custom_prompt' => [
            'method'       => 'sendMessage',
            'text'         => '📝 Введите число для продажи *Ржавый лом* (1 ед. = 4.5💰).

Ответьте на это сообщение числом.
_(код заявки: SELL:7)_',
            'reply_markup' => '{"force_reply":true,"selective":true}',
        ],
        'sell_stale' => [
            'method'       => 'answerCallbackQuery',
            'text'         => '⚠️ Кнопка устарела. Открой продажу ресурсов заново.',
            'reply_markup' => null,
        ],
        'buy_hub' => [
            'method'       => 'editMessageText',
            'text'         => '👉*У тебя есть* _1,000_ *золотых монет*💰

📌Выбери редкость ресурсов, которые хочешь купить:',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_1"},{"text":"2\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_2"},{"text":"3\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_3"}],[{"text":"4\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_4"},{"text":"5\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_5"},{"text":"6\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_6"}],[{"text":"7\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_7"},{"text":"8\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_8"},{"text":"9\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_9"}],[{"text":"\\ud83d\\udd1f \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy_rarity_10"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}],[{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_rarity_1' => [
            'method'       => 'editMessageText',
            'text'         => '📦 *Ресурсы редкости 1:*

🧺 *Ржавый лом* | _Цена покупки_: ~*10*💰
🧺 *Глина* | _Цена покупки_: ~*5*💰
*
❗️Реальная цена может быть другой исходя из спроса ресурса❗️*',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u0420\\u0436\\u0430\\u0432\\u044b\\u0439 \\u043b\\u043e\\u043c","callback_data":"buy_select_7"},{"text":"\\u0413\\u043b\\u0438\\u043d\\u0430","callback_data":"buy_select_8"}],[{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"buy"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_rarity_5_empty' => [
            'method'       => 'editMessageText',
            'text'         => '*Ресурсы редкости 5 не найдены.*',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_card' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 *Выберите желаемое количество*
📦 _Ржавый лом_ *для покупки.*
Текущая цена за 1 ед: ~*10* 💰

Реальная цена может быть другой исходя из спроса ресурса.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1 \\u2192 10\\ud83d\\udcb0","callback_data":"buy_quantity_7_1"},{"text":"5 \\u2192 50\\ud83d\\udcb0","callback_data":"buy_quantity_7_5"},{"text":"10 \\u2192 100\\ud83d\\udcb0","callback_data":"buy_quantity_7_10"},{"text":"15 \\u2192 150\\ud83d\\udcb0","callback_data":"buy_quantity_7_15"}],[{"text":"25 \\u2192 250\\ud83d\\udcb0","callback_data":"buy_quantity_7_25"},{"text":"50 \\u2192 500\\ud83d\\udcb0","callback_data":"buy_quantity_7_50"},{"text":"100 \\u2192 1,000\\ud83d\\udcb0","callback_data":"buy_quantity_7_100"},{"text":"150 \\u2192 1,500\\ud83d\\udcb0","callback_data":"buy_quantity_7_150"}],[{"text":"250 \\u2192 2,500\\ud83d\\udcb0","callback_data":"buy_quantity_7_250"},{"text":"500 \\u2192 5,000\\ud83d\\udcb0","callback_data":"buy_quantity_7_500"},{"text":"1000 \\u2192 10,000\\ud83d\\udcb0","callback_data":"buy_quantity_7_1000"},{"text":"5000 \\u2192 50,000\\ud83d\\udcb0","callback_data":"buy_quantity_7_5000"}],[{"text":"\\ud83d\\udcdd \\u0421\\u0432\\u043e\\u0451 \\u0447\\u0438\\u0441\\u043b\\u043e","callback_data":"buy_custom_7"},{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"buy_rarity_1"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_card_need' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 *Выберите желаемое количество*
📦 _Глина_ *для покупки.*
Текущая цена за 1 ед: ~*5* 💰

Реальная цена может быть другой исходя из спроса ресурса.

🎯 Для задуманного не хватает *3* ед.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1 \\u2192 5\\ud83d\\udcb0","callback_data":"buy_quantity_8_1"},{"text":"5 \\u2192 25\\ud83d\\udcb0","callback_data":"buy_quantity_8_5"},{"text":"10 \\u2192 50\\ud83d\\udcb0","callback_data":"buy_quantity_8_10"},{"text":"15 \\u2192 75\\ud83d\\udcb0","callback_data":"buy_quantity_8_15"}],[{"text":"25 \\u2192 125\\ud83d\\udcb0","callback_data":"buy_quantity_8_25"},{"text":"50 \\u2192 250\\ud83d\\udcb0","callback_data":"buy_quantity_8_50"},{"text":"100 \\u2192 500\\ud83d\\udcb0","callback_data":"buy_quantity_8_100"},{"text":"150 \\u2192 750\\ud83d\\udcb0","callback_data":"buy_quantity_8_150"}],[{"text":"250 \\u2192 1,250\\ud83d\\udcb0","callback_data":"buy_quantity_8_250"},{"text":"500 \\u2192 2,500\\ud83d\\udcb0","callback_data":"buy_quantity_8_500"},{"text":"1000 \\u2192 5,000\\ud83d\\udcb0","callback_data":"buy_quantity_8_1000"},{"text":"5000 \\u2192 25,000\\ud83d\\udcb0","callback_data":"buy_quantity_8_5000"}],[{"text":"\\ud83c\\udfaf \\u041d\\u0435 \\u0445\\u0432\\u0430\\u0442\\u0430\\u0435\\u0442 3 \\u2192 15\\ud83d\\udcb0","callback_data":"buy_quantity_8_3"},{"text":"\\ud83d\\udcdd \\u0421\\u0432\\u043e\\u0451 \\u0447\\u0438\\u0441\\u043b\\u043e","callback_data":"buy_custom_8"}],[{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"buy_rarity_1"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_custom_prompt' => [
            'method'       => 'sendMessage',
            'text'         => '📝 Введите число для покупки *Глина* (1 ед. = ~5💰).

Ответьте на это сообщение числом.
_(код заявки: BUY:8)_',
            'reply_markup' => '{"force_reply":true,"selective":true}',
        ],
        'buy_level_gate' => [
            'method'       => 'editMessageText',
            'text'         => 'Торговец не отдаёт *Уран* новичку: нужен *50* уровень (у тебя *10*).',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2b05\\ufe0f \\u041a \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u0438 2","callback_data":"buy_rarity_2"},{"text":"\\ud83d\\udecd\\ufe0f \\u0414\\u0440\\u0443\\u0433\\u0430\\u044f \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy"}],[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_done' => [
            'method'       => 'editMessageText',
            'text'         => 'Вы успешно купили *4* ед. ресурса *Глина* по цене *5*💰 за штуку.

Итого потрачено: *20* 💰',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2b05\\ufe0f \\u041a \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u0438 1","callback_data":"buy_rarity_1"},{"text":"\\ud83d\\udecd\\ufe0f \\u0414\\u0440\\u0443\\u0433\\u0430\\u044f \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"buy"}],[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'buy_poor' => [
            'method'       => 'editMessageText',
            'text'         => 'К сожалению, у вас недостаточно золотых монет для торговли! Необходимо минимум 10.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'bulk_preview_all_50' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 *Оптовая продажа — 50% всех ресурсов*

Будет продано: *2* вид(ов), всего *21* ед.
Примерная выручка: *~92* 💰

_Доля берётся от каждого запаса. Реальная цена может отличаться от спроса. Действие необратимо._

Продолжить?',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2705 \\u0414\\u0430, \\u043f\\u0440\\u043e\\u0434\\u0430\\u0442\\u044c 50%","callback_data":"bulkSell_go_all_50"},{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"sell"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'bulk_preview_rarity_1_100' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 *Оптовая продажа — ВСЁ — ресурсов редкости 1*

Будет продано: *2* вид(ов), всего *43* ед.
Примерная выручка: *~186* 💰

_Будут проданы все ходовые ресурсы в этом объёме. Реальная цена может отличаться от спроса. Действие необратимо._

Продолжить?',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2705 \\u0414\\u0430, \\u043f\\u0440\\u043e\\u0434\\u0430\\u0442\\u044c \\u0432\\u0441\\u0451","callback_data":"bulkSell_go_rarity_1_100"},{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"sellResource_rarity_1"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'bulk_preview_bad_pct' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 Действие недоступно. Вернитесь к продаже.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434","callback_data":"sell"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'bulk_go_all_50' => [
            'method'       => 'editMessageText',
            'text'         => '✅ *Оптовая продажа выполнена*

Продано по *50%* всех ресурсов: *2* вид(ов), всего *21* ед.
Выручка: *+92* 💰',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83d\\udcb0 \\u041f\\u0440\\u043e\\u0434\\u0430\\u0442\\u044c \\u0435\\u0449\\u0451","callback_data":"sell"},{"text":"\\ud83d\\udecd\\ufe0f \\u041a\\u0443\\u043f\\u0438\\u0442\\u044c","callback_data":"buy"}],[{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"},{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"}]]}',
        ],
        'bulk_disabled' => [
            'method'       => 'editMessageText',
            'text'         => '🧺 Оптовая продажа временно недоступна.',
            'reply_markup' => '{"inline_keyboard":[[{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
        'sell_hub_bulk_off' => [
            'method'       => 'editMessageText',
            'text'         => '📦 *У тебя есть разных: 3 вид(а) всех ресурсов*
👉Их общая стоимость = *94💰*

_📌ВАЖНО📌 Чтобы и тебе, и мне, как торговцу, было проще, пересмотри ресурсы и обрати внимание на их редкость. Ниже отметь цифрой, какой редкости ресурсы ты готов продать. Если их там будет несколько, на следующем шаге ты выберешь нужный ресурс._',
            'reply_markup' => '{"inline_keyboard":[[{"text":"1\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_1"},{"text":"2\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_2"},{"text":"3\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_3"}],[{"text":"4\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_4"},{"text":"5\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_5"},{"text":"6\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_6"}],[{"text":"7\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_7"},{"text":"8\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_8"},{"text":"9\\ufe0f\\u20e3 \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_9"}],[{"text":"\\ud83d\\udd1f \\u0440\\u0435\\u0434\\u043a\\u043e\\u0441\\u0442\\u044c","callback_data":"sellResource_rarity_10"},{"text":"\\u25c0\\ufe0f \\u042f","callback_data":"character"}],[{"text":"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c","callback_data":"inventory"},{"text":"\\ud83d\\uded2 \\u041c\\u0430\\u0433\\u0430\\u0437\\u0438\\u043d","callback_data":"shop"}]]}',
        ],
    ];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' icon_text VARCHAR(255) NULL, rarity INT NULL, sell_price DECIMAL(10,2) NULL, buy_price DECIMAL(10,2) NULL,'
                . ' is_tradeable TINYINT NOT NULL DEFAULT 1, level_required INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE resources_bank (id INT AUTO_INCREMENT PRIMARY KEY, resource_id INT, current_quantity INT DEFAULT 0,'
                . ' resources_purchased INT DEFAULT 0, resources_sold INT DEFAULT 0, last_update DATETIME NULL, UNIQUE KEY uq_res (resource_id))'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSellScreens(): void
    {
        $this->assertScreen('sell_hub', SellAction::class, 'sell');
        $this->assertScreen('sell_rarity_1', SellResourceAction::class, 'sellResource_rarity_1');
        $this->assertScreen('sell_rarity_3_empty', SellResourceAction::class, 'sellResource_rarity_3');
        $this->assertScreen('sell_card', SellResourceAction::class, 'sellResource_7_quantity');
        $this->assertScreen('sell_custom_prompt', SellResourceAction::class, 'sellResource_7_custom');
        $this->assertScreen('sell_stale', SellResourceAction::class, 'sellResource_bogus');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBuyScreensAndPurchase(): void
    {
        $this->assertScreen('buy_hub', BuyResourceAction::class, 'buy');
        $this->assertScreen('buy_rarity_1', BuyResourceAction::class, 'buy_rarity_1');
        $this->assertScreen('buy_rarity_5_empty', BuyResourceAction::class, 'buy_rarity_5');
        $this->assertScreen('buy_card', BuyResourceAction::class, 'buy_select_7');
        $this->assertScreen('buy_card_need', BuyResourceAction::class, 'buy_need_8_3');
        $this->assertScreen('buy_custom_prompt', BuyResourceAction::class, 'buy_custom_8');
        $this->assertScreen('buy_level_gate', BuyResourceAction::class, 'buy_quantity_10_1');
        $this->assertScreen('buy_done', BuyResourceAction::class, 'buy_quantity_8_4');
        $this->assertSame(7, $this->owned(8));
        $this->assertSame(980.0, $this->gold());

        $this->conn->query('UPDATE characters SET gold = 5 WHERE id = 1');
        $this->assertScreen('buy_poor', BuyResourceAction::class, 'buy');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBulkPreviewAndExecute(): void
    {
        $this->assertScreen('bulk_preview_all_50', BulkSellAction::class, 'bulkSell_all_50');
        $this->assertScreen('bulk_preview_rarity_1_100', BulkSellAction::class, 'bulkSell_rarity_1_100');
        $this->assertScreen('bulk_preview_bad_pct', BulkSellAction::class, 'bulkSell_all_33');
        $this->assertScreen('bulk_go_all_50', BulkSellAction::class, 'bulkSell_go_all_50');
        $this->assertSame(20, $this->owned(7));
        $this->assertSame(2, $this->owned(8));
        $this->assertSame(1092.0, $this->gold());

        $this->conn->query('UPDATE game_settings SET value_bool = 0 WHERE setting_key = ?', [BulkSellAction::KEY_ENABLED]);
        service('cache')->clean();
        $this->assertScreen('bulk_disabled', BulkSellAction::class, 'bulkSell_all_50');
        $this->assertScreen('sell_hub_bulk_off', SellAction::class, 'sell');
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 1000, 5, 0)"
        );
        $this->conn->query(
            'INSERT INTO resources (id, name, icon_text, rarity, sell_price, buy_price, is_tradeable, level_required) VALUES'
            . " (7, 'Ржавый лом', '🔧', 1, 4.50, 10.00, 1, 0),"
            . " (8, 'Глина', '🟫', 1, 2.00, 5.00, 1, 0),"
            . " (9, 'Семена', '🌱', 1, 0.00, 0.00, 0, 0),"
            . " (10, 'Уран', '☢️', 2, 100.00, 250.50, 1, 50)"
        );
        $now = date('Y-m-d H:i:s');
        foreach ([[7, 40], [8, 3], [9, 6]] as [$res, $qty]) {
            $this->conn->table('character_resources')->insert([
                'id_characters' => 1, 'id_resources' => $res, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $base = [
            'category' => 'world', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => 'onboarding.contextual_hints.enabled', 'value_type' => 'bool', 'value_bool' => 0, 'default_value_text' => '1',
        ]);
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => BulkSellAction::KEY_ENABLED, 'value_type' => 'bool', 'value_bool' => 1, 'default_value_text' => '1',
        ]);
    }

    private function owned(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM character_resources WHERE id_characters = 1 AND id_resources = ?', [$resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function gold(): float
    {
        return (float) ($this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRowArray()['gold'] ?? -1);
    }

    private function assertScreen(string $name, string $actionClass, string $data): void
    {
        $this->assertArrayHasKey($name, self::SNAPSHOTS, "нет снимка {$name}");
        $this->assertSame(self::SNAPSHOTS[$name], $this->sent($actionClass, $data), $name);
    }

    /** @return array{method: string, text: mixed, reply_markup: mixed} */
    private function sent(string $actionClass, string $data): array
    {
        $sent = [];
        new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        LongmanRequest::setClient(new Client(['handler' => static function (RequestInterface $request) use (&$sent): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path   = explode('/', $request->getUri()->getPath());
            $sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]));

        $cbq = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => self::TG, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci', 'data' => $data,
        ]);
        $action = new $actionClass($cbq);
        $this->assertTrue(method_exists($action, 'handle'));
        $action->handle();

        $calls = array_values(array_filter($sent, static fn (array $c): bool => in_array($c['method'], ['sendMessage', 'editMessageText'], true)));
        $alert = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === 'answerCallbackQuery' && isset($c['text'])));
        if ($calls === [] && $alert !== []) {
            return ['method' => 'answerCallbackQuery', 'text' => $alert[0]['text'], 'reply_markup' => null];
        }
        $this->assertCount(1, $calls, 'screen calls in ' . json_encode(array_column($sent, 'method')));

        return ['method' => $calls[0]['method'], 'text' => $calls[0]['text'] ?? null, 'reply_markup' => $calls[0]['reply_markup'] ?? null];
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
