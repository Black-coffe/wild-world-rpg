<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\ResourceModel;
use App\Services\Player\Progression\EarlyProgressionService;
use App\Services\Player\VehicleActivationService;
use App\Services\Telegram\Request;
use App\Services\World\VehicleEffectsService;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * v0.51.105 (GatherTaskHandler decomp Step 2) — extract DB persistence
 * блок (character_resources insert/update + character stats bump) у
 * dedicated final class.
 *
 * persist() ortografує full sequence: resource quantity persist + stat gains.
 * Stat gains formula: per resource +0.0006 strength + 0.0001 agility/intellect
 * + 0.0005 health (capped @ 100).
 *
 * transport-08 (ADR-171/174, `docs/specs/transport-system/plan.md` → Contracts) —
 * карго-сплит: доля добытого сырья (`VehicleEffectsService::profileFor()['cargo_share']`,
 * уже зажатая `world.vehicle.cargo.max_share`) уезжает на склад базы
 * ({@see BaseStorageModel::deliver()}) вместо рюкзака. Ничего не создаётся — только
 * перекладывается: инвариант «дельта рюкзака + дельта склада == добытому» держится
 * СТРУКТУРНО ({@see splitForCargo()} — вычитание, не независимый расчёт двух долей).
 *
 * Нет базы (`ClaimedCellModel::countActiveBases()===0`) → 100% в рюкзак + честный
 * текст «груз некуда везти» (а не молчание). Килсвитч off / нет активного транспорта /
 * машина без груза (`mtb`, `drone_auto`, `cargo_share===0.0`) → 100% в рюкзак,
 * БЕЗ дополнительной записи и БЕЗ текста — байт-идентично поведению до этой стори.
 *
 * Единственная точка записи добычи. Второго write-path для найденных ресурсов в
 * проекте нет и заводить его не нужно (Non-goal стори) — если он найдётся, это
 * повод остановиться, а не чинить оба.
 */
class GatherResultPersister
{
    private EarlyProgressionService $early;
    private VehicleActivationService $vehicleActivation;
    private VehicleEffectsService $vehicleEffects;
    private ClaimedCellModel $claimedCellModel;
    private BaseStorageModel $baseStorageModel;
    private ResourceModel $resourceModel;
    private ?Telegram $telegram = null;

    public function __construct(
        private ?CharacterResourceModel $characterResourceModel = null,
        ?EarlyProgressionService $early = null,
        ?VehicleActivationService $vehicleActivation = null,
        ?VehicleEffectsService $vehicleEffects = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?BaseStorageModel $baseStorageModel = null,
        ?ResourceModel $resourceModel = null
    ) {
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        // ADR-138 (S3): XP за добычу новичку (level<cap). Dormant → gatherXpEarned()=0.0 = легаси.
        $this->early             = $early ?? new EarlyProgressionService();
        $this->vehicleActivation = $vehicleActivation ?? new VehicleActivationService();
        $this->vehicleEffects    = $vehicleEffects ?? new VehicleEffectsService();
        $this->claimedCellModel  = $claimedCellModel ?? new ClaimedCellModel();
        $this->baseStorageModel  = $baseStorageModel ?? new BaseStorageModel();
        $this->resourceModel     = $resourceModel ?? new ResourceModel();
    }

    /**
     * transport-15 — `$foldCargoNote=true` возвращает строку про увезённый на склад груз
     * ВМЕСТО отдельной отправки её Telegram-сообщением (caller вклеивает в уже готовый
     * текст добычи). `false` (default) — старое поведение байт-в-байт: отдельное
     * `sendMessage`, возврат `null`. Дефолт-false, а не одностороннее удаление notify(),
     * держит контракт `CargoSplitTest.php` (story-08, не в Files этой стори) целым.
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     * @param array<string, mixed>                                            $task         from character_tasks
     *
     * @return string|null Текст про груз, если `$foldCargoNote` и есть что сказать; иначе null.
     */
    public function persist(array $foundResources, array|\App\Entities\CharacterEntity $character, array $task, bool $foldCargoNote = false): ?string
    {
        $cargoNote = null;
        if (!empty($foundResources)) {
            // ADR-135 Ф2 «Трофейная подать»: zero-sum переток rate% хозяину ДО зачисления
            // добычи жертве (Σ сохраняется, жертва получает остаток). Killswitch tribute.enabled
            // внутри → dormant = identity (byte-equivalent). Stat-gain per-entry → не меняется.
            $foundResources = (new \App\Services\PVE\TributeService())->collectOnGather($character, $foundResources);
            $cargoNote      = $this->writeCharacterResources($foundResources, $character, $foldCargoNote);
        }
        $this->bumpCharacterStats($character, $foundResources);

        return $cargoNote;
    }

    /**
     * v0.51.33 perf: 1 whereIn для существуючих rows замість N SELECT у loop'і.
     *
     * transport-08: перед записью в рюкзак делит найденное между рюкзаком и складом
     * базы (карго-профиль активного транспорта). Килсвитч off / нет машины / машина
     * без груза → `cargo_share===0.0` → путь ниже вырождается в старое поведение
     * один в один (`splitForCargo()` даже не вызывается).
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     *
     * @return string|null Груз-нота, если `$foldCargoNote` и что-то реально уехало на склад.
     */
    private function writeCharacterResources(array $foundResources, array|\App\Entities\CharacterEntity $character, bool $foldCargoNote): ?string
    {
        if ($foundResources === []) {
            return null;
        }

        $charId     = $this->intField($character, 'id');
        $profile    = $this->resolveVehicleProfile($charId);
        $cargoShare = $profile['cargo_share'];

        if ($cargoShare <= 0.0) {
            $this->creditBackpack($foundResources, $character);

            return null;
        }

        if (! $this->hasBase($charId)) {
            // Машина умеет возить груз, но везти некуда — честный текст, а не молчание.
            // Отдельный редкий кейс (машина без базы), story-15 его не сворачивает —
            // остаётся отдельным сообщением, как и раньше.
            $this->creditBackpack($foundResources, $character);
            $this->notify($character, $this->composeNoBaseNote());

            return null;
        }

        $split = self::splitForCargo($foundResources, $cargoShare);
        $this->creditBackpack($split['backpack'], $character);

        if ($split['storage'] !== []) {
            $this->creditStorage($charId, $split['storage']);
            $note = $this->composeCargoNote($split['storage']);

            if ($foldCargoNote) {
                return $note;
            }

            $this->notify($character, $note);
        }

        return null;
    }

    /**
     * Чистое разделение находки: `floor(amount * share)` на склад, остаток — в рюкзак.
     * Инвариант «дельта рюкзака + дельта склада == добытому» держится структурно —
     * `toBackpack` считается ВЫЧИТАНИЕМ из `amount`, а не независимой формулой, поэтому
     * округление никогда не может разойтись с исходной суммой.
     *
     * На стеке в 1-2 единицы `floor(qty*share)` честно даёт 0 — груз не сработал,
     * а не «сломался» (порог срабатывания по каждой из грузовых машин — story-файл
     * → `## Implementation notes`).
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     *
     * @return array{
     *     backpack: array<int, array{resource_id: int, amount: int, type?: string}>,
     *     storage: list<array{resource_id: int, amount: int}>
     * }
     */
    public static function splitForCargo(array $foundResources, float $share): array
    {
        $backpack = [];
        $storage  = [];

        foreach ($foundResources as $res) {
            $rid    = (int) $res['resource_id'];
            $amount = (int) $res['amount'];

            $toStorage = ($amount >= 1 && $share > 0.0) ? (int) floor($amount * $share) : 0;
            $toStorage = max(0, min($amount, $toStorage));

            $newRes           = $res;
            $newRes['amount'] = $amount - $toStorage;
            $backpack[]       = $newRes;

            if ($toStorage > 0) {
                $storage[] = ['resource_id' => $rid, 'amount' => $toStorage];
            }
        }

        return ['backpack' => $backpack, 'storage' => $storage];
    }

    /**
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     */
    private function creditBackpack(array $foundResources, array|\App\Entities\CharacterEntity $character): void
    {
        if ($foundResources === []) {
            return;
        }

        $resourceIds = array_values(array_unique(array_map(
            static fn ($r): int => (int) $r['resource_id'],
            $foundResources
        )));
        $existingRows = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->whereIn('id_resources', $resourceIds)
            ->findAll();
        $existingByResId = [];
        foreach ($existingRows as $row) {
            $existingByResId[(int) $row['id_resources']] = $row;
        }

        foreach ($foundResources as $res) {
            $amount = (int) $res['amount'];
            if ($amount < 1) {
                continue;
            }

            $existingResource = $existingByResId[(int) $res['resource_id']] ?? null;
            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $amount;
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters'     => $character['id'],
                    'id_resources'      => $res['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity'          => $amount,
                ]);
            }
        }
    }

    /** @param list<array{resource_id: int, amount: int}> $storageLines */
    private function creditStorage(int $charId, array $storageLines): void
    {
        foreach ($storageLines as $line) {
            $this->baseStorageModel->deliver($charId, $line['resource_id'], $line['amount']);
        }
    }

    /**
     * Активный карго-профиль персонажа. Seam для тестов (обходит VehicleActivationService/VehicleEffectsService).
     *
     * @return array{key: ?string, cells_per_tick: int, tired_factor: float, max_steps_per_order: int, cargo_share: float, wear_per_cell: int}
     */
    protected function resolveVehicleProfile(int $charId): array
    {
        $active = $this->vehicleActivation->resolveActive($charId);
        $key    = ($active['key'] ?? '') !== '' ? $active['key'] : null;

        return $this->vehicleEffects->profileFor($key, VehicleEffectsService::TERRAIN_EXPLORED);
    }

    /** Есть ли у персонажа своя (активная) база. Seam для тестов. */
    protected function hasBase(int $charId): bool
    {
        return $this->claimedCellModel->countActiveBases($charId) > 0;
    }

    private function composeNoBaseNote(): string
    {
        return '🚚 Груз некуда везти: своей базы нет — вся добыча осталась в рюкзаке.';
    }

    /** @param list<array{resource_id: int, amount: int}> $storageLines */
    private function composeCargoNote(array $storageLines): string
    {
        $ids   = array_map(static fn ($l): int => $l['resource_id'], $storageLines);
        $names = $this->resourceNames($ids);

        $parts = [];
        foreach ($storageLines as $line) {
            $name    = $names[$line['resource_id']] ?? "ресурс #{$line['resource_id']}";
            $parts[] = "{$name} × {$line['amount']}";
        }

        return '🚚 На склад базы уехало: ' . implode(', ', $parts) . '.';
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    protected function resourceNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->resourceModel->whereIn('id', $ids)->findAll() as $entity) {
            $out[(int) $entity->id] = $entity->name;
        }

        return $out;
    }

    /** @param array<string, mixed>|\App\Entities\CharacterEntity $character */
    private function notify(array|\App\Entities\CharacterEntity $character, string $text): void
    {
        $chatId = $this->chatIdFor($character);
        if ($chatId === null) {
            return;
        }

        $this->send($chatId, $text);
    }

    /**
     * chat_id персонажа = telegram_id владельца. Seam для тестов.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    protected function chatIdFor(array|\App\Entities\CharacterEntity $character): ?int
    {
        $userId = $this->intField($character, 'telegram_user_id');
        if ($userId <= 0) {
            return null;
        }

        try {
            $result = Database::connect()
                ->table('telegram_users')
                ->select('telegram_id')
                ->where('id', $userId)
                ->get();
            if (! $result instanceof ResultInterface) {
                return null;
            }
            $row = $result->getRowArray();
        } catch (Throwable $e) {
            log_message('warning', '[GatherResultPersister] chat lookup failed: ' . $e->getMessage());

            return null;
        }

        $tg = $row['telegram_id'] ?? null;

        return is_numeric($tg) && (int) $tg !== 0 ? (int) $tg : null;
    }

    /**
     * Seam для тестов: реальная отправка. Никогда не бросает наружу — добыча не должна
     * падать из-за rate-limit/сетевой ошибки на побочном уведомлении о грузе.
     */
    protected function send(int $chatId, string $text): void
    {
        try {
            $this->telegram();
            $response = Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
            if (! $response->isOk()) {
                log_message('warning', '[GatherResultPersister] cargo note not sent: ' . $response->getDescription());
            }
        } catch (Throwable $e) {
            log_message('error', '[GatherResultPersister] cargo note exception: ' . $e->getMessage());
        }
    }

    /** Ленивая инициализация Telegram-моста (memory feedback_taskhandler_telegram_init_in_tests). */
    private function telegram(): Telegram
    {
        if ($this->telegram === null) {
            try {
                $this->telegram = new Telegram(
                    (string) getenv('telegram.API_KEY'),
                    (string) getenv('telegram.BOT_USERNAME')
                );
                Request::initialize($this->telegram);
            } catch (TelegramException $e) {
                log_message('error', '[GatherResultPersister] Telegram init: ' . $e->getMessage());
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }

        return $this->telegram;
    }

    /** @param array<string, mixed>|\App\Entities\CharacterEntity $character */
    private function intField(array|\App\Entities\CharacterEntity $character, string $key): int
    {
        $v = $character[$key] ?? null;

        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * Per-resource stat gain: +0.0006 str + 0.0001 agi/int + 0.0005 health (capped @ 100).
     * expGain: ADR-138 (S3) — loop-earned XP за ЗАВЕРШЕНИЕ добычи новичку (level<cap),
     * иначе 0.0 (dormant = легаси). Стат-гейны не множим (теряются на DECIMAL(7,2) всё равно).
     *
     * transport-08: считается по ИСХОДНОМУ (пост-tribute) списку находок — карго-сплит меняет
     * только МЕСТО хранения ресурса, не его наличие, поэтому на прогрессию не влияет.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     * @param array<int, mixed>                                  $foundResources
     */
    private function bumpCharacterStats(array|\App\Entities\CharacterEntity $character, array $foundResources): void
    {
        $levelRaw    = $character['level'] ?? 1;
        $level       = is_numeric($levelRaw) ? (float) $levelRaw : 1.0;
        $expGain     = $this->early->gatherXpEarned($level);
        $curExp      = is_numeric($character['experience'] ?? null) ? (float) $character['experience'] : 0.0;
        $healthGain  = 0.0;
        $strength    = 0.0;
        $agility     = 0.0;
        $intellect   = 0.0;

        foreach ($foundResources as $res) {
            $strength   += 0.0006;
            $agility    += 0.0001;
            $intellect  += 0.0001;
            $healthGain += 0.0005;
        }

        // Fix 2026-07-13 (класс lost-update): гейны — атомарным relative-UPDATE от
        // СВЕЖИХ значений (CharacterStatsService); снапшот задачи добычи (минуты
        // давности) больше не затирает параллельные изменения (препарат, бой).
        // Cap health 100 — дефолтный в сервисе.
        $charIdRaw = $character['id'] ?? null;
        $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;
        (new \App\Services\Player\CharacterStatsService())->adjust($charId, [
            'experience' => $expGain,
            'health'     => $healthGain,
            'strength'   => $strength,
            'agility'    => $agility,
            'intellect'  => $intellect,
        ]);
    }
}
