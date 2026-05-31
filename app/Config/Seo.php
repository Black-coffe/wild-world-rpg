<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * SEO-пайплайн: доступ к Google Search Console API (service-account).
 *
 * Аутентификация — JWT (RS256, openssl_sign) → access-token → GSC API через CURLRequest,
 * без тяжёлого google/apiclient (ноль новых composer-зависимостей). Ключ service-account'а
 * лежит ВНЕ git (`writable/secrets/`, см. .gitignore); путь переопределяется env `seo.gscCredentialsPath`.
 *
 * Property в Search Console — URL-префикс `https://wildworld.fun/` (тип ресурса подтверждён
 * при сетапе GSC), поэтому siteUrl с хвостовым слэшем.
 */
class Seo extends BaseConfig
{
    /**
     * Путь к JSON-ключу service-account'а. Пусто → дефолт `writable/secrets/gsc-service-account.json`
     * (резолвится в конструкторе: WRITEPATH — рантайм-константа). Override: env `seo.gscCredentialsPath`.
     */
    public string $gscCredentialsPath = '';

    /** siteUrl property в GSC. URL-префикс → с хвостовым слэшем. Override: env `seo.gscSiteUrl`. */
    public string $gscSiteUrl = 'https://wildworld.fun/';

    /** Период по умолчанию для пулла (дней). GSC отдаёт данные с лагом ~2-3 дня. */
    public int $gscDefaultDays = 28;

    public function __construct()
    {
        parent::__construct();

        if ($this->gscCredentialsPath === '') {
            $this->gscCredentialsPath = WRITEPATH . 'secrets/gsc-service-account.json';
        }
    }
}
