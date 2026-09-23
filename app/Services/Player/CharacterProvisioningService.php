<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Services\Onboarding\ColdOpenGreetingService;
use App\Services\Onboarding\ColdOpenSignalService;
use App\Services\Onboarding\NewbieGreeterService;
use App\Services\Onboarding\OnboardingChainService;
use App\Services\Onboarding\StarterKitService;
use App\Services\Web\AccountService;

/**
 * web-accounts-p0-04 (ADR-188) — создание персонажа вне Telegram-обработчика `/start`.
 *
 * Перенос записей `StartCommand` один-в-один (числа, список биомов спавна и порядок шагов не
 * менялись): строка `characters` со стартовыми статами → имя-заглушка `Путник-{id}` для
 * пустого имени → привязка к аккаунту → клетка спавна (Y ≥ 900, суша) → обучающая цепочка →
 * стартовый набор → приманка cold-open → встречающий-нейтрал.
 *
 * Два вызывающих: бот (`telegram_users.id` + chat id; аккаунт находится/создаётся через
 * {@see AccountService::ensureForTelegram()}) и сайт (`telegramUserId = null`, `chatId = null`,
 * готовый `accountId`; строка `telegram_users` не создаётся). Сервис ничего не отправляет:
 * тексты Роби для первого экрана бота отдаёт {@see lastTexts()}, веб их просто не читает.
 *
 * Числа старта (gold 1000, health/tired 100, статы 0.01) и список биомов — хардкод, унаследованный
 * из `StartCommand` как есть (story 04: move, not rebalance).
 */
class CharacterProvisioningService
{
    /** Суша для спавна новичка (4 = вода исключена). */
    private const SPAWN_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    /** Новичковая полоса у южной кромки карты. */
    private const SPAWN_MIN_Y = 900;

    /** @var array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} */
    private array $lastTexts = self::EMPTY_TEXTS;

    private const EMPTY_TEXTS = ['spawned' => false, 'singleScreen' => false, 'starterKit' => null, 'signal' => null, 'greeter' => null];

    public function __construct(private readonly ?AccountService $accounts = null)
    {
    }

    /**
     * Создать играбельного персонажа и вернуть его `characters.id`.
     *
     * @param string   $name           имя; '' → `Путник-{id}`
     * @param int|null $telegramUserId `telegram_users.id` бот-игрока, null — персонаж без Telegram
     * @param int|null $chatId         чат бота для записей action_log; null → NULL в `chat_id`
     * @param int|null $accountId      готовый аккаунт; null при $telegramUserId → ensureForTelegram
     */
    public function create(string $name, ?int $telegramUserId, ?int $chatId, ?int $accountId): int
    {
        $this->lastTexts = self::EMPTY_TEXTS;
        $characterModel  = new CharacterModel();

        $characterId = (int) $characterModel->insert([
            'telegram_user_id' => $telegramUserId,
            'name'             => $name,
            'level'            => 1,
            'experience'       => 0.01,
            'health'           => 100,
            'tired'            => 100,
            'strength'         => 0.01,
            'agility'          => 0.01,
            'intellect'        => 0.01,
            'gold'             => 1000,
            'cell_number'      => null,
        ], true);

        if ($name === '') {
            $characterModel->update($characterId, ['name' => self::mintDistinctName($characterId)]);
        }

        $accounts = $this->accounts ?? new AccountService();
        if ($accountId !== null) {
            $accounts->attachCharacter($accountId, $characterId);
        } elseif ($telegramUserId !== null) {
            $accounts->ensureForTelegram($telegramUserId);
        }

        $spawnCells = (new MapModel())
            ->where('coordinate_y >=', self::SPAWN_MIN_Y)
            ->whereIn('biome_id', self::SPAWN_BIOMES)
            ->findAll();
        if (empty($spawnCells)) {
            return $characterId;
        }

        $randomCell = $spawnCells[array_rand($spawnCells)];
        $characterModel->update($characterId, ['cell_number' => is_array($randomCell) ? $randomCell['cell_number'] : null]);
        $this->lastTexts['spawned'] = true;

        (new OnboardingChainService())->ensureChainAssigned(['id' => $characterId, 'level' => 1]);

        // Режим одного окна нужен ДО приманки и встречающего: в нём их навигационный хвост шумит.
        $singleScreen                    = (new ColdOpenGreetingService())->singleScreenEnabled();
        $this->lastTexts['singleScreen'] = $singleScreen;

        $this->lastTexts['starterKit'] = (new StarterKitService())->grant($characterId, $telegramUserId, $chatId);

        if (is_array($randomCell)) {
            $this->lastTexts['signal'] = (new ColdOpenSignalService())
                ->placeBaitForNewChar($characterId, $randomCell, ! $singleScreen);
            $this->lastTexts['greeter'] = (new NewbieGreeterService())
                ->placeGreeterForNewChar($characterId, $randomCell, $chatId, ! $singleScreen);
        }

        return $characterId;
    }

    /**
     * Итог последнего {@see create()} для первого экрана бота: нашлась ли клетка спавна, режим
     * одного окна и тексты Роби (набор / сигнал / встречающий; null — не выдано/выключено).
     *
     * @return array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string}
     */
    public function lastTexts(): array
    {
        return $this->lastTexts;
    }

    /**
     * Публичное имя персонажа без выбранного имени. Берёт ТОЛЬКО `characters.id` (уже публичный),
     * поэтому приватный `telegram_id` в имя попасть не может by construction.
     */
    public static function mintDistinctName(int $characterId): string
    {
        return 'Путник-' . $characterId;
    }
}
