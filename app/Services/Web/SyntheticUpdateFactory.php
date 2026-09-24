<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * web-bridge-p1-05 (ADR-189 §1) — синтетические Telegram-апдейты для `/play`.
 *
 * Выход — валидный вход Longman (`new Update($array, $bot)`), который {@see \App\Services\Telegram\UpdatePipeline}
 * гонит через тот же диспетчер, что и вебхук. 🔴 `from`/`chat` берутся ТОЛЬКО из личности персонажа
 * ({@see VirtualIdentityService::identityForCharacter()}), `chat.type` всегда `private`; ввод игрока
 * попадает лишь в `data`/`text`. `update_id` — отрицательный номер от вызывающего: в
 * `telegram_updates_seen` он не пишется (дедуп веба — story 07).
 *
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-type Identity array{telegram_id:int, first_name:string, username:?string, language_code:?string}
 */
final class SyntheticUpdateFactory
{
    /**
     * Нажатие inline-кнопки на экране `$message`.
     *
     * @param Identity $identity
     * @param Msg      $message
     *
     * @return array<string, mixed>
     */
    public function callback(array $identity, array $message, string $data, int $updateId): array
    {
        return [
            'update_id'      => $updateId,
            'callback_query' => [
                'id'            => 'web' . abs($updateId),
                'from'          => $this->user($identity),
                'chat_instance' => 'web' . $identity['telegram_id'],
                'data'          => $data,
                'message'       => $this->screenMessage($identity, $message),
            ],
        ];
    }

    /**
     * Текст или команда игрока. `$replyTo` — экран, который просил force-reply (тогда ответ несёт
     * `reply_to_message`); команда (`/cmd args`) получает сущность `bot_command`.
     *
     * @param Identity  $identity
     * @param Msg|null  $replyTo
     *
     * @return array<string, mixed>
     */
    public function message(array $identity, string $text, ?array $replyTo, int $updateId): array
    {
        $message = [
            'message_id' => abs($updateId),
            'from'       => $this->user($identity),
            'chat'       => $this->chat($identity),
            'date'       => time(),
            'text'       => $text,
        ];

        if (str_starts_with($text, '/')) {
            $command             = (string) strtok($text, " \n");
            $message['entities'] = [[
                'type'   => 'bot_command',
                'offset' => 0,
                'length' => intdiv(strlen((string) mb_convert_encoding($command, 'UTF-16LE', 'UTF-8')), 2),
            ]];
        }

        if ($replyTo !== null) {
            $message['reply_to_message'] = $this->screenMessage($identity, $replyTo);
        }

        return ['update_id' => $updateId, 'message' => $message];
    }

    /**
     * Экранное сообщение бота: id, чат, текст или подпись + маркер фото, клавиатура.
     *
     * @param Identity $identity
     * @param Msg      $msg
     *
     * @return array<string, mixed>
     */
    private function screenMessage(array $identity, array $msg): array
    {
        $out = [
            'message_id' => $msg['message_id'],
            'chat'       => $this->chat($identity),
            'date'       => time(),
        ];

        if ($msg['photo_url'] !== null) {
            $out['photo']   = [['file_id' => 'web', 'file_unique_id' => 'web', 'width' => 1, 'height' => 1]];
            $out['caption'] = $msg['caption'] ?? $msg['text'] ?? '';
        } elseif ($msg['text'] !== null) {
            $out['text'] = $msg['text'];
        } else {
            $out['caption'] = $msg['caption'] ?? '';
        }

        if ($msg['inline_keyboard'] !== []) {
            $out['reply_markup'] = ['inline_keyboard' => $msg['inline_keyboard']];
        }

        return $out;
    }

    /**
     * @param Identity $identity
     *
     * @return array<string, mixed>
     */
    private function user(array $identity): array
    {
        $user = ['id' => $identity['telegram_id'], 'is_bot' => false, 'first_name' => $identity['first_name']];
        if ($identity['username'] !== null) {
            $user['username'] = $identity['username'];
        }
        if ($identity['language_code'] !== null) {
            $user['language_code'] = $identity['language_code'];
        }

        return $user;
    }

    /**
     * @param Identity $identity
     *
     * @return array{id:int, type:string, first_name:string}
     */
    private function chat(array $identity): array
    {
        return ['id' => $identity['telegram_id'], 'type' => 'private', 'first_name' => $identity['first_name']];
    }
}
