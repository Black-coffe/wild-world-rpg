<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * cron-delivery-integrity-04 — анти-рецидив гейт «отправитель поднимает мост сам».
 *
 * Образец — {@see WipeManifestCoverageTest}: чистый разбор исходников `app/Services/**`
 * (без БД). Класс, в коде которого (комментарии вырезаны токенайзером) есть
 * `Request::send*(` / `Request::edit*(`, обязан звать `TelegramBridge::ensure()` или
 * принятый аналог `->ensureTelegramInitialized()` (EventNotificationSender), либо стоять в
 * {@see self::EXCEPTIONS} с причиной. Новый такой сервис без помощника → тест красный.
 *
 * Скан — НЕ покрытие (feedback_source_scan_tests_are_not_coverage): «зовёт ensure()» не
 * значит «зовёт до отправки и честно обрабатывает false». Поведение реального пути
 * держит `tests/unit/Services/PVE/PveNotificationSenderNoKeyTest.php`.
 *
 * @internal
 */
final class TelegramSenderBridgeCoverageTest extends CIUnitTestCase
{
    /**
     * Сервисы, которые шлют в Telegram, но мост сами не поднимают. Причина сверена по
     * вызывающим на 2026-09-15 (cron-delivery-integrity-04); «webhook» = все вызовы идут
     * из `Controllers/Telegram/**`, где мост поднят `BotController`.
     * Сервис перевели на `TelegramBridge::ensure()` — удали строку (тест ниже потребует).
     *
     * @var array<string, string> basename файла => причина
     */
    private const EXCEPTIONS = [
        // Поднимают мост сами, но inline (`new Telegram` + `Request::initialize` в try/catch), мимо TelegramBridge.
        'BroadcastService.php'       => 'inline initTelegram() перед рассылкой (admin MessageController/WipeController) — свой подъём, не через TelegramBridge',
        'PlayerDetectionService.php' => 'inline new Telegram + Request::initialize в конструкторе, catch TelegramException (крон MarchingTaskHandler) — свой подъём',
        'TowerAlertService.php'      => 'inline new Telegram + Request::initialize в конструкторе, catch TelegramException (крон MarchingTaskHandler) — свой подъём',
        'ObjectSignalService.php'    => 'inline new Telegram + Request::initialize в конструкторе, catch TelegramException (крон MarchingTaskHandler) — свой подъём',

        // Отправка достижима только из webhook-запроса.
        'BaseService.php'               => 'webhook: show*-экраны базы, вызовы только из Controllers/Telegram и BotMenuService',
        'BotMenuService.php'            => 'webhook: sendMainMenu()/noCharacter() зовут только Controllers/Telegram; spark-команды берут лишь каталоги/тексты',
        'CharacterService.php'          => 'webhook: showCharacterInfo() — StartCommand, CallbackqueryCommand, GenericmessageCommand, BotMenuService',
        'ActiveTasksService.php'        => 'webhook: checkRelocationAndBlock() — Controllers/Telegram и Relocation*-сервисы того же запроса',
        'ColdOpenSignalService.php'     => 'webhook: tryReachBait() — только MoveCharacterToDirectionAction; TextMapService (крон) зовёт лишь markerCellsInViewport() без отправки',
        'CraftShortageScreenHelper.php' => 'webhook: render() зовут только Controllers/Telegram',
        'LoginStreakService.php'        => 'webhook: maybeReward() → send() зовёт только BotController',
        'LuckyFindService.php'          => 'webhook: вызывается из NewbieAtmosphereService, а тот — только из Controllers/Telegram',
        'MapService.php'                => 'webhook: showMapWithPlayer() — Controllers/Telegram, NavigationMapService, BotMenuService; GenerateImages берёт только графику',
        'MoreSurfaceService.php'        => 'webhook: show() зовут только Controllers/Telegram',
        'MoveSurfaceService.php'        => 'webhook: show()/renderCompassInPlace() — Controllers/Telegram и BotMenuService',
        'NavMenuRefreshService.php'     => 'webhook: maybeRefresh() зовёт только BotMenuService',
        'NewbieAtmosphereService.php'   => 'webhook: вызывается только из Controllers/Telegram',
        'RelocationRequestService.php'  => 'webhook: вызывается только из Controllers/Telegram',
        'TasksSurfaceService.php'       => 'webhook: show() — Controllers/Telegram, LeaderboardScreen, BotMenuService',

        // Крон, но вызывающий task-handler поднимает мост (BaseTaskHandler::telegram() → TelegramBridge::ensure()) до вызова.
        'StandoffNotifier.php'      => 'крон StandoffExpiryHandler зовёт $this->telegram() перед notifier; остальное — webhook PvP-экшены',
        'ReturnDigestService.php'   => 'крон ComebackNudgeHandler/Day2NudgeHandler зовут $this->telegram() до отправки',
        'OnboardingHintService.php' => 'крон: craft-completion handler’ы зовут подсказку после safeSend* (тот поднял мост); остальное — webhook',

        // Контракт «мост поднимает вызывающий».
        'CommunityChatSender.php' => 'крон CommunityAutoReplyHandler зовёт ensureTelegramInitialized() до каждой отправки; ⚠ admin CommunityController::sendManualAnswer — мост не поднимается (Findings)',
        'MediaSender.php'         => 'транспортный помощник без своего состояния: мост поднимает вызывающий (BaseTaskHandler/BaseObjectHandler::safeSend* через telegram(), остальные — webhook)',
    ];

    public function testEverySenderRaisesBridgeOrIsExcepted(): void
    {
        $flagged = $this->servicesSendingWithoutBridge(APPPATH . 'Services');

        $missing = array_values(array_diff($flagged, array_keys(self::EXCEPTIONS)));
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            '🔴 Сервис шлёт в Telegram (Request::send*/edit*), но не поднимает мост: '
            . implode(', ', $missing)
            . '. Позови TelegramBridge::ensure() до отправки и обработай false (лог error, без'
            . ' ложного успеха) — или добавь в EXCEPTIONS с проверенной причиной.'
        );
    }

    public function testEveryExceptionHasReasonAndIsStillNeeded(): void
    {
        $flagged = $this->servicesSendingWithoutBridge(APPPATH . 'Services');

        foreach (self::EXCEPTIONS as $file => $reason) {
            $this->assertGreaterThan(20, mb_strlen(trim($reason)), "Исключение {$file}: причина пуста или отписка");
            $this->assertContains(
                $file,
                $flagged,
                "Исключение {$file} больше не нужно (класс поднимает мост или не шлёт) — удали строку."
            );
        }
    }

    /**
     * Сам детектор на фикстуре: без помощника — ловит, с ensure()/аналогом — пропускает,
     * упоминание в комментарии — не отправка. Иначе гейт мог бы молча ослепнуть.
     */
    public function testDetectorFlagsViolatorAndAcceptsHelpers(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdi04_' . bin2hex(random_bytes(4));
        mkdir($dir . DIRECTORY_SEPARATOR . 'Sub', 0777, true);

        $files = [
            'Sub' . DIRECTORY_SEPARATOR . 'NakedSender.php' => "<?php\nclass NakedSender { function go() { Request::sendMessage(['chat_id' => 1]); } }\n",
            'EditSender.php'    => "<?php\nclass EditSender { function go() { Request::editMessageText([]); } }\n",
            'BridgedSender.php' => "<?php\nclass BridgedSender { function go() { if (! TelegramBridge::ensure()) { return; } Request::sendMessage([]); } }\n",
            'EventLike.php'     => "<?php\nclass EventLike { function go() { \$this->ensureTelegramInitialized(); Request::sendPhoto([]); } }\n",
            'CommentOnly.php'   => "<?php\n/** зовёт Request::sendMessage( — только в доке */\nclass CommentOnly { // Request::editMessageText(\n}\n",
            'FakeBridge.php'    => "<?php\n// TelegramBridge::ensure() в комментарии не считается\nclass FakeBridge { function go() { Request::sendMessage([]); } }\n",
        ];

        try {
            foreach ($files as $name => $src) {
                file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $src);
            }

            $flagged = $this->servicesSendingWithoutBridge($dir);
            sort($flagged);

            $this->assertSame(['EditSender.php', 'FakeBridge.php', 'NakedSender.php'], $flagged);
        } finally {
            foreach (array_keys($files) as $name) {
                @unlink($dir . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($dir . DIRECTORY_SEPARATOR . 'Sub');
            @rmdir($dir);
        }
    }

    /**
     * Basename'ы файлов под $root, чей код (без комментариев) шлёт через Request::send*|edit*
     * и не зовёт ни TelegramBridge::ensure(), ни ->ensureTelegramInitialized().
     *
     * @return list<string>
     */
    private function servicesSendingWithoutBridge(string $root): array
    {
        $flagged = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if ($src === false) {
                continue;
            }

            $code = '';
            foreach (token_get_all($src) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            if (preg_match('/\bRequest::(?:send|edit)\w*\s*\(/', $code) !== 1) {
                continue;
            }
            if (preg_match('/TelegramBridge::ensure\s*\(|->ensureTelegramInitialized\s*\(/', $code) === 1) {
                continue;
            }

            $flagged[] = $file->getFilename();
        }

        return $flagged;
    }
}
