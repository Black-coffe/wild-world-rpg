<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-03

# Scout report: Базы, лагерь, постройки

## Purpose
Строительство и жизненный цикл баз: лагерь, здания, апгрейды, лимиты, налог, мульти-база,
защита от рейда.

## Entry points
- `app/Services/Bases/` — `BaseLifecycleService`, `BaseLimitService`, `BaseCheckService`,
  `CampCheckService`, `BaseLocationResolver`, `BaseBuildingsList`, `BaseServiceMessageFormatter`.
- `app/Services/Buildings/`, `app/Services/BuildingEffects/`, `app/Services/Housing/`.
- `app/Services/Player/BuildingUpgrade/`.
- TaskHandlers — `app/TaskHandlers/Built/`, `BaseLifecycleHandler.php`, `TaxCollectionHandler.php`.
- Таблица `character_buildings`.

## Key types / contracts
Дубли зданий разрешены намеренно: бонусы не суммируются, но налог растёт ×N и база крепче.
Налог — per-base (ADR-122).

## Dependencies
inbound: action-handler'ы лагеря, `Worker` (стройка, налог), PvP-рейды.
outbound: ресурсы, `GameSettings`, `Services/Coverage`.

## Gotchas
- Защита базы не должна собираться из И-НЕ флагов: комбинация делается недостижимой, и база
  перестаёт укрывать. База обязана укрывать всегда, когда игрок на ней.
- Смерть: −3% с базой, −50% без базы.
- Открытый хвост: штраф при сносе одной базы из нескольких.
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #3, `EA-economy-04`), **ОТКРЫТА,
  сознательно descoped из exploit-fix (решение владельца 2026-09-02)**:
  `ResourcesBankModel::updatePurchasedQuantity()` / `ResourceTradeService::buyResource()` не
  ограничивают объём покупки сырья — арбитраж «купи → дождись тика крона → продай дороже»,
  цена меняется только между тиками `ResourceBankUpdateHandler`. Это вопрос цены/лимита (свой ADR,
  ключи `economy.resource.max_purchase_per_trade`/`.repricing_mode`), не гонки — отдельная спека.
  PoC `EconomyLimitsTest::testSingleUnguardedPurchasePumpsSellPriceAboveOriginalBuyPrice` остаётся
  красным намеренно.
- **ЗАКРЫТО (2026-09, exploit-fix-07, F5, ADR-181).** `BeaconInstaller::install()` — маяк ставился
  до подтверждённого списания предмета (`EA-gaps-04`), при неудачном списании выдавался бесплатно.
  Порядок перевёрнут: условное списание первым, вставка маяка только на `Applied`, одна транзакция.
  См. `mmorpg-vault/tech-writing/services/BeaconInstaller.md`.
- **(2026-09, ADR-181) Заряд карго-дрона и списание ремонта — условная запись, не снимок.**
  `CargoDroneSendAction`/`CargoDroneAutoSendAction` списывали абсолютным значением заряда,
  прочитанным до транзакции (два параллельных вылета делили один заряд); `RepairBuildingAction`
  при отказе на одной строке плана коммитил уже списанные другие строки. Оба — через
  `decrementIfAtLeast()`/условный откат внутри транзакции.

## Vault
`mmorpg-vault/apps/bases/index.md`
