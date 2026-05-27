<?php
/**
 * Подвал публичного сайта.
 *
 * @var array<int,array{slug:string,name:string}|array<string,mixed>> $navCats
 * @var string $tgLink
 * @var string $groupLink
 */
?>
<footer class="footer" role="contentinfo">
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="brand"><span class="mark" aria-hidden="true"></span>Wild&nbsp;World</div>
                <p class="dim mt-2" style="font-size:14px;max-width:42ch">Постапокалиптическая текстовая MMORPG в&nbsp;Telegram. Исследуй, выживай, крафти, сражайся и&nbsp;строй базу в&nbsp;большом открытом мире.</p>
                <div class="row mt-2">
                    <a class="btn primary sm" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
                </div>
            </div>
            <div>
                <h5>Мир</h5>
                <ul>
                    <li><a href="<?= base_url('map') ?>">Карта</a></li>
                    <li><a href="<?= base_url('wiki') ?>">Вики</a></li>
                    <li><a href="<?= base_url('devblog') ?>">Девблог</a></li>
                </ul>
            </div>
            <div>
                <h5>Разделы</h5>
                <ul>
                    <?php foreach (array_slice(is_array($navCats) ? $navCats : [], 0, 6) as $c): ?>
                        <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                            <li><a href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a></li>
                        <?php endif ?>
                    <?php endforeach ?>
                </ul>
            </div>
            <div>
                <h5>Сообщество</h5>
                <ul>
                    <li><a href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">🎮 Играть в&nbsp;боте</a></li>
                    <li><a href="<?= esc($groupLink, 'attr') ?>" target="_blank" rel="noopener">💬 Новости и&nbsp;обсуждение</a></li>
                </ul>
            </div>
        </div>
        <div class="bottom">
            <span>© <?= date('Y') ?> Wild&nbsp;World · текстовая MMORPG в&nbsp;Telegram</span>
            <span>UI · «Найденная фотоплёнка» · v0.1</span>
        </div>
    </div>
</footer>
