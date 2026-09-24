<?php
/**
 * web-accounts-p0-07 (ADR-188) — кнопки Google / Яндекс (страница входа и кабинет).
 *
 * Не настроен env провайдера → кнопка НЕ прячется: рисуется `<span aria-disabled>` (не ссылка)
 * с пометкой «недоступно» и причиной из OAuthProviderFactory::unavailableReason().
 * Компоненты — story 03 (`.provider-list`, `.provider-btn`, `.is-unavailable`, `.provider-note`).
 *
 * @var string       $oauthMode    'login' (страница входа) | 'link' (кабинет)
 * @var list<string> $oauthLinked  уже привязанные провайдеры — в кабинете их кнопок нет
 */

use App\Services\Web\OAuthProviderFactory;

$oauthMode   = ($oauthMode ?? 'login') === 'link' ? 'link' : 'login';
$oauthLinked = is_array($oauthLinked ?? null) ? $oauthLinked : [];
$oauthMarks  = ['google' => 'G', 'yandex' => 'Я'];
$oauthVerb   = $oauthMode === 'link' ? 'Привязать' : 'Войти через';
$oauthFactory = new OAuthProviderFactory();
$oauthShown   = 0;
?>
<div class="provider-list">
    <?php foreach (OAuthProviderFactory::PROVIDERS as $oauthProvider): ?>
        <?php if (in_array($oauthProvider, $oauthLinked, true)) {
            continue;
        } ?>
        <?php $oauthShown++; $oauthLabel = OAuthProviderFactory::label($oauthProvider); ?>
        <?php if ($oauthFactory->isConfigured($oauthProvider)): ?>
            <a class="provider-btn" href="<?= esc(base_url('account/oauth/' . $oauthProvider), 'attr') ?>"><span class="provider-mark" aria-hidden="true"><?= esc($oauthMarks[$oauthProvider] ?? '?') ?></span><span class="provider-name"><?= esc($oauthVerb . ' ' . $oauthLabel) ?></span></a>
        <?php else: ?>
            <span class="provider-btn is-unavailable" aria-disabled="true"><span class="provider-mark" aria-hidden="true"><?= esc($oauthMarks[$oauthProvider] ?? '?') ?></span><span class="provider-name"><?= esc($oauthLabel) ?></span><span class="provider-state">недоступно</span></span>
            <p class="provider-note"><?= esc($oauthFactory->unavailableReason($oauthProvider)) ?></p>
        <?php endif ?>
    <?php endforeach ?>
    <?php if ($oauthShown === 0): ?>
        <p class="provider-note">Google и Яндекс уже привязаны — они в списке способов входа.</p>
    <?php endif ?>
</div>
