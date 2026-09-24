<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\SyntheticUpdateFactory;
use App\Services\Web\VirtualChat;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\Update;

/**
 * web-bridge-p1-05 (ADR-189 §1) — {@see SyntheticUpdateFactory}: выход разбирается Longman'ом как
 * настоящий апдейт; `from`/`chat` — только из личности; отрицательный `update_id` сохраняется.
 *
 * @internal
 */
final class SyntheticUpdateFactoryTest extends CIUnitTestCase
{
    private const BOT = 'wildworldtest_bot';

    /** @return array{telegram_id:int, first_name:string, username:?string, language_code:?string} */
    private function identity(): array
    {
        return ['telegram_id' => VirtualChat::idForAccount(42), 'first_name' => 'Странник', 'username' => null, 'language_code' => 'ru'];
    }

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>> $keyboard
     *
     * @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}
     */
    private function msg(int $id, ?string $text, ?string $caption = null, ?string $photo = null, array $keyboard = []): array
    {
        return ['message_id' => $id, 'text' => $text, 'caption' => $caption, 'parse_mode' => 'Markdown', 'photo_url' => $photo, 'inline_keyboard' => $keyboard];
    }

    public function testCallbackParsesAsCallbackQueryWithScreenMessage(): void
    {
        $id  = $this->identity();
        $raw = (new SyntheticUpdateFactory())->callback(
            $id,
            $this->msg(1000000007, 'Экран', null, null, [[['text' => '📖 Путь', 'callback_data' => 'guide']]]),
            'guide',
            -15
        );

        $update = new Update($raw, self::BOT);
        $this->assertSame('callback_query', $update->getUpdateType());
        $this->assertSame(-15, $update->getUpdateId());

        $cq = $update->getCallbackQuery();
        $this->assertNotNull($cq);
        $this->assertSame('guide', $cq->getData());
        $this->assertSame($id['telegram_id'], $cq->getFrom()->getId());

        $message = $cq->getMessage();
        $this->assertSame(1000000007, $message->getMessageId());
        $this->assertSame($id['telegram_id'], $message->getChat()->getId());
        $this->assertTrue($message->getChat()->isPrivateChat());
        $this->assertSame('Экран', $message->getText());
        $this->assertSame('guide', $raw['callback_query']['message']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testCallbackOnPhotoScreenCarriesCaptionAndPhotoMarker(): void
    {
        $raw = (new SyntheticUpdateFactory())->callback($this->identity(), $this->msg(5, null, 'Подпись', '/img/x.jpg'), 'map', -1);

        $message = (new Update($raw, self::BOT))->getCallbackQuery()?->getMessage();
        $this->assertNotNull($message);
        $this->assertSame('photo', $message->getType());
        $this->assertSame('Подпись', $message->getCaption());
    }

    public function testCommandParsesAsCommandWithBotCommandEntity(): void
    {
        $id  = $this->identity();
        $raw = (new SyntheticUpdateFactory())->message($id, '/guide combat', null, -16);

        $update  = new Update($raw, self::BOT);
        $message = $update->getMessage();
        $this->assertSame(-16, $update->getUpdateId());
        $this->assertSame('command', $message->getType());
        $this->assertSame('guide', $message->getCommand());
        $this->assertSame('combat', $message->getText(true));
        $this->assertSame([['type' => 'bot_command', 'offset' => 0, 'length' => 6]], $raw['message']['entities']);
        $this->assertSame($id['telegram_id'], $message->getFrom()->getId());
        $this->assertSame($id['telegram_id'], $message->getChat()->getId());
        $this->assertSame('private', $message->getChat()->getType());
    }

    public function testTextReplyCarriesReplyToMessageOnlyWhenAsked(): void
    {
        $factory = new SyntheticUpdateFactory();

        $plain = new Update($factory->message($this->identity(), '🏠 База', null, -17), self::BOT);
        $this->assertSame('text', $plain->getMessage()->getType());
        $this->assertNull($plain->getMessage()->getReplyToMessage());
        $this->assertArrayNotHasKey('entities', $factory->message($this->identity(), 'база', null, -17)['message']);

        $reply = new Update($factory->message($this->identity(), 'Олег', $this->msg(77, '✍ NAME Как тебя зовут?'), -18), self::BOT);
        $replyTo = $reply->getMessage()->getReplyToMessage();
        $this->assertNotNull($replyTo);
        $this->assertSame(77, $replyTo->getMessageId());
        $this->assertSame('✍ NAME Как тебя зовут?', $replyTo->getText());
    }

    public function testIdentityIsTheOnlySourceOfFromAndChat(): void
    {
        $id  = $this->identity();
        $raw = (new SyntheticUpdateFactory())->message($id, '/start 999', null, -19);

        $this->assertSame(['id' => $id['telegram_id'], 'is_bot' => false, 'first_name' => 'Странник', 'language_code' => 'ru'], $raw['message']['from']);
        $this->assertSame(['id' => $id['telegram_id'], 'type' => 'private', 'first_name' => 'Странник'], $raw['message']['chat']);
    }
}
