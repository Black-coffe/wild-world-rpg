<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WipeManifest;

/**
 * ADR-176 «Community chat bot», story 02 — гарантирует, что обе новые таблицы получили
 * ИМЕННО ту стратегию, что требует контракт story, а не просто «классифицированы хоть как-то».
 *
 * community_messages → PLAYER_DATA (story community-chat-bot-60). Изначальная классификация
 * TRANSIENT с пометкой «поток регенерируется чатом» была неверна по существу: строки живут до
 * TTL и несут telegram_user_id на каждой — как и структурный близнец player_action_log. Из-за
 * TRANSIENT сброс одного персонажа (WipeService::resetCharacter()) не трогал его сообщения в
 * чате сообщества, хотя чистил его firehose действий.
 * community_answers  → KEEP (авторский корпус наравне с game_tips).
 *
 * @internal
 */
final class CommunityWipeClassificationTest extends CIUnitTestCase
{
    public function testCommunityMessagesIsPlayerDataLinkedByTelegram(): void
    {
        $manifest = (new WipeManifest())->tables;

        $this->assertArrayHasKey('community_messages', $manifest);
        $this->assertSame(WipeManifest::PLAYER_DATA, $manifest['community_messages']['strategy']);
        $this->assertSame('telegram_user_id', $manifest['community_messages']['link']);
        $this->assertSame('telegram', $manifest['community_messages']['by']);
    }

    public function testCommunityAnswersIsKeep(): void
    {
        $manifest = (new WipeManifest())->tables;

        $this->assertArrayHasKey('community_answers', $manifest);
        $this->assertSame(WipeManifest::KEEP, $manifest['community_answers']['strategy']);
    }
}
