<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\BaseStorageModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\GameBalance;

/**
 * v0.51.19 (F2.9 batch-1): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — це історично-неправильно (handler НЕ контроллер).
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendMessage → safeSendMessage.
 * `handle()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * v0.51.24 (C/F6 expansion): greenhouseLevels + water shortage cooldown + threshold
 * читаються через config('GameBalance'). Раніше hardcoded private $greenhouseLevels.
 */
#[HandlerKey(
    key: 'greenhouse_production',
    displayName: 'Производство Теплицы',
    description: 'Recurring (Tasks.php every minute): теплица производит еду из воды/удобрения. Per-level config через GameBalance.',
)]
class GreenhouseProductionHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    /** W28 (ADR-083) — рутинное завершение задачи: при активном killswitch уведомление шлётся тихо (disable_notification). */
    protected function isRoutineNotification(): bool
    {
        return true;
    }

    protected $characterBuildingModel;
    protected $characterResourceModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterModel;
    protected $telegramUserModel;
    protected BaseStorageModel $baseStorageModel;

    private GameBalance $cfg;
    private BuildingEffectsService $buildingEffects;

    /** ADR-171 (story storage-craft-insurance-04) — killswitch того же пула, что и рюкзак+склад. */
    private const POOL_KILLSWITCH_KEY = 'storage.pool_enabled';

    public function __construct(?GameBalance $cfg = null, ?BuildingEffectsService $buildingEffects = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->baseStorageModel       = new BaseStorageModel();
        $this->buildingEffects        = $buildingEffects ?? new BuildingEffectsService();
    }

    /**
     * Вызывается по крону. Обрабатывает всех игроков, у кого есть Теплица.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // 1) Получаем ID здания "Greenhouse"
        $greenhouseId = $this->getGreenhouseId();
        if (!$greenhouseId) {
            log_message('error', '[GreenhouseProductionHandler] "Greenhouse" building not found in DB.');
            return;
        }

        // 2) Ищем все character_buildings, указывающие на Greenhouse
        $charsGreenhouse = $this->characterBuildingModel
            ->where('building_id', $greenhouseId)
            ->findAll();

        if (empty($charsGreenhouse)) {
            return;
        }

        // v0.51.25 perf: lift Water resource lookup outside loop (loop-invariant).
        // v0.51.26 perf: pre-load усі harvest resources (Water + Fruit + Berries +
        // Mushrooms + Crops) одним SQL запитом замість per-call name lookup.
        // Раніше: N greenhouses × ~5 resource queries = ~5N queries.
        // Тепер: 1 SQL `whereIn name_en` = 1 query незалежно від N.
        $harvestNames = ['Water', 'Fruit', 'Berries', 'Mushrooms', 'Crops'];
        $resourceRows = $this->resourceModel->whereIn('name_en', $harvestNames)->findAll();
        $resourceByName = [];
        foreach ($resourceRows as $row) {
            $resourceByName[$row['name_en']] = $row;
        }
        $waterResource = $resourceByName['Water'] ?? null;
        if (!$waterResource) {
            log_message('error', '[GreenhouseProductionHandler] Resource "Water" not found in DB.');
            return;
        }

        foreach ($charsGreenhouse as $charBuild) {
            $characterId = $charBuild['character_id'];
            $level       = (int) $charBuild['level'];

            // S13b (v0.51.195): production table читається через BuildingEffectsService
            // (foundation для future GameSettings migration). Behaviour identical
            // — read-through від `Config\GameBalance::$greenhouseLevels`.
            $production = $this->buildingEffects->getGreenhouseProductionForLevel($level);
            if (empty($production) || ! isset($production['water'])) {
                log_message('error', "[GreenhouseProductionHandler] Invalid greenhouse level: $level");
                continue;
            }

            $waterNeeded = (int) $production['water'];

            // Массив ресурсов, который теплица будет генерировать (Fruit, Berries и т.п.)
            $harvest = $production;
            unset($harvest['water']); // убираем ключ water, оставляем только еду

            // Ищем, сколько у персонажа сейчас воды в рюкзаке
            $charResWater = $this->characterResourceModel
                ->where('id_characters', $characterId)
                ->where('id_resources', $waterResource['id'])
                ->first();

            $backpackQty = (is_array($charResWater) && isset($charResWater['quantity']) && is_numeric($charResWater['quantity']))
                ? (int) $charResWater['quantity']
                : 0;

            // ADR-171: теплица стоит на базе, вода на складе той же базы ей доступна.
            // ResourcePoolService гейтит пул тем, стоит ли ПЕРСОНАЖ на базе прямо сейчас
            // (BaseCheckService::checkBaseStatus) — верно для крафта/ремонта, где игрок сам
            // жмёт кнопку стоя на базе, но не для теплицы: крон обходит всех, включая тех,
            // кто гуляет в поле, пока теплица дома продолжает работать. Поэтому здесь читаем
            // склад напрямую через BaseStorageModel, минуя гейт «на базе» — только killswitch.
            $poolEnabled = $this->gsBool(self::POOL_KILLSWITCH_KEY, true);
            $storageQty  = $poolEnabled ? $this->baseStorageModel->quantityFor($characterId, (int) $waterResource['id']) : 0;

            $poolQty = $backpackQty + $storageQty;

            // Если воды нет нигде
            if ($poolQty <= 0) {
                continue;
            }

            // === (1) Проверяем, не надо ли отправить уведомление "мало воды" (<= threshold) ===
            if ($poolQty <= $this->gsInt('building.greenhouse.water_shortage_threshold', (int) $this->cfg->greenhouseWaterShortageThreshold)) {
                // Дубли построек разрешены намеренно — у одного персонажа может быть несколько
                // Теплиц. Cooldown ключуется по конкретной постройке (id строки character_buildings),
                // не по персонажу — иначе шортаж на одной теплице глушил бы предупреждение о другой.
                $this->checkAndNotifyWaterShortage($characterId, (int) $charBuild['id'], $poolQty);
            }

            // === (2) Если воды меньше, чем нужно — пропускаем списание и генерацию ===
            if ($poolQty < $waterNeeded) {
                continue;
            }

            // === (3) Иначе списываем воду: сначала рюкзак, остаток — склад ===
            $fromBackpack = min($backpackQty, $waterNeeded);
            $fromStorage  = $waterNeeded - $fromBackpack;

            // Story storage-craft-insurance-10: списание+начисление в одной транзакции —
            // сбой посередине (например обрыв соединения) раньше мог оставить персонажа
            // с потраченной водой и без урожая, либо наоборот.
            $db = \Config\Database::connect();
            $db->transStart();

            if ($fromBackpack > 0 && is_array($charResWater)) {
                // decreaseResources() удаляет строку при уходе в 0 — как decreaseResources()
                // у ResourcePoolService. Раньше здесь был прямой update(), который при полном
                // расходе рюкзака оставлял строку «Вода | 0 шт», всплывающую в
                // ResourcesGatheredAction (тот экран листает рюкзак без фильтра quantity > 0).
                $this->characterResourceModel->decreaseResources($characterId, (int) $waterResource['id'], $fromBackpack);
            }
            if ($fromStorage > 0) {
                $this->baseStorageModel->withdraw($characterId, (int) $waterResource['id'], $fromStorage);
            }

            // Начисляем harvest (Fruit / Berries / Mushrooms / Crops и т.д.)
            foreach ($harvest as $resourceNameEn => $count) {
                $this->addResourceToCharacter($characterId, $resourceNameEn, $count, $resourceByName);
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                log_message('error', "[GreenhouseProductionHandler] транзакция урожая упала для character {$characterId}");
            }
        }
    }

    /** Префикс ключа CI4-кэша для дедупа уведомлений о нехватке воды (см. ниже). */
    private const NOTIFY_CACHE_PREFIX = 'greenhouse_water_shortage_';

    /**
     * Проверяет, не пора ли отправить уведомление о малом количестве воды, и шлёт его
     * с cooldown-дедупом.
     *
     * v0.51 (story storage-craft-insurance-04, ревью team-lead): метку последней отправки
     * раньше писали в `character_resources.custom_data` строки воды. С пулом рюкзак+склад
     * это перестало работать надёжно в обе стороны: (а) если весь рюкзак выложен на склад,
     * строки для воды может не быть вовсе (`CharacterResourceModel::decreaseResources` удаляет
     * строку при quantity<=0) — класть метку было некуда; (б) заведённая ради этого нулевая
     * строка всплывает игроку на экране инвентаря `ResourcesGatheredAction` («📦 Вода | 0 шт»)
     * — тот экран читает `character_resources` без фильтра `quantity > 0` (в отличие от
     * `SellResourceAction`/`ResourceOverviewService`, где фильтр стоит). Чинить сам экран —
     * вне scope этой story. Поэтому метка переехала в CI4-кэш (тот же `service('cache')`,
     * которым в этом же handler'е уже пользуется `GameSettingsService`), TTL = cooldown:
     * никакая игровая таблица не трогается, протечка в инвентарь структурно невозможна.
     * Плата — сброс кэша (деплой/`cache:clear`) может стереть отметку раньше срока, тогда
     * уйдёт одно лишнее предупреждение; это дешевле испорченного экрана инвентаря.
     *
     * Ключ кэша — по конкретной ПОСТРОЙКЕ (`character_buildings.id`), не по персонажу: дубли
     * построек в проекте разрешены намеренно, у одного игрока может стоять несколько Теплиц
     * одновременно, и у каждой свой cooldown. TTL пишется ОДИН раз при `save()` и больше не
     * трогается — здесь нет read-increment-write цикла (memory
     * `feedback_ci4_cache_increment_refreshes_ttl`: `increment()` продлевает TTL и окно никогда
     * не закрывается; `save()` с фиксированным TTL этой ловушке не подвержен).
     */
    private function checkAndNotifyWaterShortage(int $characterId, int $buildingInstanceId, int $poolQty): void
    {
        // Если пула больше threshold (default 3), ничего не делаем
        if ($poolQty > $this->gsInt('building.greenhouse.water_shortage_threshold', (int) $this->cfg->greenhouseWaterShortageThreshold)) {
            return;
        }

        $cache    = service('cache');
        $cacheKey = self::NOTIFY_CACHE_PREFIX . $buildingInstanceId;

        // Отметка ещё не истекла — cooldown не прошёл, повторно не шлём.
        if (is_object($cache) && method_exists($cache, 'get') && $cache->get($cacheKey) !== null) {
            return;
        }

        $this->notifyWaterShortage($characterId, $poolQty);

        if (is_object($cache) && method_exists($cache, 'save')) {
            $cooldownSec = $this->gsInt('building.greenhouse.water_shortage_cooldown_sec', (int) $this->cfg->greenhouseWaterShortageCooldownSec);
            $cache->save($cacheKey, time(), $cooldownSec);
        }
    }

    /**
     * Возвращает ID здания "Greenhouse" (по name_en).
     */
    private function getGreenhouseId(): ?int
    {
        $greenhouse = $this->buildingModel
            ->where('name_en', 'Greenhouse')
            ->first();
        return $greenhouse ? (int) $greenhouse['id'] : null;
    }

    /**
     * Начисляет (или создаёт) ресурс персонажу.
     *
     * v0.51.26 perf: $resourceByName preloaded outside hot loop — was N+1 lookup
     * per harvest item per character.
     *
     * @param array<string, array<string, mixed>> $resourceByName name_en => row map
     */
    private function addResourceToCharacter(int $characterId, string $resourceNameEn, int $quantity, array $resourceByName): void
    {
        $resource = $resourceByName[$resourceNameEn] ?? null;
        if (!$resource) {
            log_message('error', "[GreenhouseProductionHandler] Resource {$resourceNameEn} not in pre-loaded map.");
            return;
        }

        // Ищем, есть ли такая запись у персонажа
        $charRes = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->where('id_resources', $resource['id'])
            ->first();

        if ($charRes) {
            // Увеличиваем quantity
            $newQty = $charRes['quantity'] + $quantity;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQty]);
        } else {
            // Создаём новую запись
            $this->characterResourceModel->insert([
                'id_characters' => $characterId,
                'id_resources'  => $resource['id'],
                'quantity'      => $quantity,
            ]);
        }
    }

    /**
     * Отправляет предупреждение, что воды мало (<= threshold). $waterQuantity — суммарный
     * пул (рюкзак + склад базы), а не только карман: иначе игрок со складом видел бы «осталось
     * 3», хотя дома лежат тысячи (ADR-171, story storage-craft-insurance-04).
     */
    protected function notifyWaterShortage(int $characterId, int $waterQuantity): void
    {
        // 1) Найдём персонажа
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "[GreenhouseProductionHandler] Character ID {$characterId} not found.");
            return;
        }

        // 2) У персонажа должен быть telegram_user_id
        $telegramUserId = $character['telegram_user_id'];
        if (!$telegramUserId) {
            return; // нет привязки к телеге
        }

        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "[GreenhouseProductionHandler] Telegram user not found for ID {$telegramUserId}.");
            return;
        }

        // 3) Формируем и отправляем
        $chatId = $telegramUser['telegram_id'];
        $text = "Внимание!\n"
            . "У вас осталось всего *{$waterQuantity}* единиц воды (рюкзак + склад базы).\n"
            . "Если вы не пополните запас, Теплица скоро перестанет приносить урожай!";

        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }
}
