<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WipeManifest;

/**
 * ADR-176 «Community chat bot», story 02 — гарантирует, что обе новые таблицы получили
 * ИМЕННО ту стратегию, что требует контракт story, а не просто «классифицированы хоть как-то».
 *
 * community_messages → TRANSIENT (поток регенерируется чатом).
 * community_answers  → KEEP (авторский корпус наравне с game_tips).
 *
 * @internal
 */
final class CommunityWipeClassificationTest extends CIUnitTestCase
{
    public function testCommunityMessagesIsTransient(): void
    {
        $manifest = (new WipeManifest())->tables;

        $this->assertArrayHasKey('community_messages', $manifest);
        $this->assertSame(WipeManifest::TRANSIENT, $manifest['community_messages']['strategy']);
    }

    public function testCommunityAnswersIsKeep(): void
    {
        $manifest = (new WipeManifest())->tables;

        $this->assertArrayHasKey('community_answers', $manifest);
        $this->assertSame(WipeManifest::KEEP, $manifest['community_answers']['strategy']);
    }
}
