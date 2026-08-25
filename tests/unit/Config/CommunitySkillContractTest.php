<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * community-chat-bot-14 — контракт скилла `/community` и его нового агента
 * `community-antispoiler`. Это тест ТЕКСТА артефактов (SKILL.md + agent-файл),
 * не поведения конвейера — он не может измерить качество черновиков, только то,
 * что скилл называет всё, что обязан называть, и не называет того, что запрещено
 * конституцией (story §Verification: признанный предел).
 *
 * @internal
 */
final class CommunitySkillContractTest extends CIUnitTestCase
{
    private const SKILL_PATH = APPPATH . '../.claude/skills/community/SKILL.md';
    private const AGENT_PATH = APPPATH . '../.claude/agents/community-antispoiler.md';

    private function readFile(string $path): string
    {
        $real = realpath($path);
        $this->assertNotFalse($real, "Файл не найден: {$path}");

        $contents = file_get_contents($real);
        $this->assertNotFalse($contents, "Не удалось прочитать: {$path}");

        return $contents;
    }

    public function testSkillFileExists(): void
    {
        $this->assertFileExists(realpath(self::SKILL_PATH) ?: self::SKILL_PATH);
    }

    public function testAntispoilerAgentFileExists(): void
    {
        $this->assertFileExists(realpath(self::AGENT_PATH) ?: self::AGENT_PATH);
    }

    public function testAgentFrontmatterDeclaresName(): void
    {
        $agent = $this->readFile(self::AGENT_PATH);
        $this->assertStringContainsString('name: community-antispoiler', $agent);
    }

    /**
     * §Contract, шаг 3 плана: панель ровно из четырёх линз. Три существующих
     * агента редколлегии + новый анти-спойлер, ни одного лишнего дубля.
     */
    public function testSkillNamesAllFourPanelLenses(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);

        $this->assertStringContainsString('redkollegiya-lore-keeper', $skill, 'лор-хранитель обязателен в панели');
        $this->assertStringContainsString('redkollegiya-game-designer', $skill, 'гейм-дизайнер обязателен в панели');
        $this->assertStringContainsString('community-antispoiler', $skill, 'анти-спойлер — новая, четвёртая линза');
        $this->assertStringContainsString('redkollegiya-editor-council', $skill, 'редактор обязателен в панели');
    }

    /** Не заводить новых агентов там, где годятся существующие (Non-goals). */
    public function testSkillDoesNotIntroduceExtraReviewerAgents(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);

        foreach (['redkollegiya-writer', 'redkollegiya-designer', 'redkollegiya-mmorpg-player', 'redkollegiya-linker', 'redkollegiya-uiux'] as $foreignAgent) {
            $this->assertStringNotContainsString(
                $foreignAgent,
                $skill,
                "«{$foreignAgent}» не входит в панель /community — не тот конвейер"
            );
        }
    }

    /** §Contract, шаг 2: белый корпус — GuideCatalog / game_tips / glossary / site_posts. */
    public function testSkillNamesWhiteCorpusSources(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);

        $this->assertStringContainsString('GuideCatalog', $skill);
        $this->assertStringContainsString('game_tips', $skill);
        $this->assertStringContainsString('glossary', $skill);
        $this->assertStringContainsString('site_posts', $skill);
    }

    /** GuideCatalog цитируется адресом раздела, не телом (замёрзшая копия начнёт врать). */
    public function testSkillWarnsAgainstCopyingGuideCatalogBody(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);
        $this->assertStringContainsString('адрес', mb_strtolower($skill), 'скилл обязан требовать цитирование адресом раздела, не телом');
    }

    /** GAME_DESCRIPTION.md синхронизирован по устаревшей версии — не источник. */
    public function testSkillExcludesGameDescriptionFromCorpus(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);
        $this->assertStringContainsString('GAME_DESCRIPTION.md', $skill);
        $this->assertStringContainsString('не источник', mb_strtolower($skill));
    }

    /** Имя персонажа — Роби, одна «б» (тот же канон, что и CommunityVoiceCanonTest). */
    public function testNeitherArtifactMisspellsRobiName(): void
    {
        foreach ([self::SKILL_PATH, self::AGENT_PATH] as $path) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Робби',
                $this->readFile($path),
                "«Робби» с двумя «б» запрещено канон: {$path}"
            );
        }
    }

    /**
     * ADMIN-TUNABLE BALANCE + §5 плана: скилл не читает и не пишет
     * `app/Config/GameBalance.php`, и не работает со значениями `game_settings`
     * как с источником текста ответа — только с килсвитчем через requires_setting.
     */
    public function testNeitherArtifactReferencesGameBalance(): void
    {
        foreach ([self::SKILL_PATH, self::AGENT_PATH] as $path) {
            $this->assertStringNotContainsString(
                'GameBalance',
                $this->readFile($path),
                "GameBalance.php не должен упоминаться как источник: {$path}"
            );
        }
    }

    /** community-antispoiler обязан требовать воспроизводимый вердикт, не «выглядит нормально». */
    public function testAntispoilerVerdictIsReproducible(): void
    {
        $agent = $this->readFile(self::AGENT_PATH);

        $this->assertStringContainsString('source_ref', $agent);
        $this->assertStringContainsString('allow', $agent);
        $this->assertStringContainsString('manual', $agent);
        $this->assertStringContainsString('выглядит нормально', $agent, 'агент обязан явно запрещать нерасшифрованный вердикт');
    }

    /** Черновики уходят только в draft через community:import, не напрямую в чат. */
    public function testSkillOnlyPushesViaImportCommand(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);
        $this->assertStringContainsString('community:import', $skill);
        $this->assertStringContainsString('draft', $skill);
    }

    /**
     * story community-chat-bot-28: ADR-176 — канал «локаль → прод», импорт исполняется
     * на проде по SSH тем же ключом, что и pull, JSON подаётся через stdin, не как
     * аргумент. Дословный локальный вызов оставляет черновики в локальной БД —
     * BUILT-BUT-DEAD.
     */
    public function testImportPhaseUsesRemoteSshAndStdinNotLocalEcho(): void
    {
        $skill = $this->readFile(self::SKILL_PATH);

        $this->assertStringContainsString('ssh ', $skill, 'фаза импорта обязана называть удалённое SSH-исполнение');
        $this->assertStringContainsString('wildworld_deploy', $skill, 'фаза импорта обязана называть тот же ключ, что и pull');
        $this->assertStringContainsString('stdin', mb_strtolower($skill), 'JSON обязан подаваться через stdin, не аргументом');
        $this->assertStringContainsString('--no-header', $skill, 'без --no-header баннер spark портит JSON на stdout');
        $this->assertStringNotContainsString(
            "echo '<json>' | php spark community:import",
            $skill,
            'локальный вызов пишет в локальную БД — прод-банк не пополнится'
        );
    }
}
