<!-- Start  page-footer Section-->
<footer class="page-footer dark-color-footer" id="page-footer">
    <div class="overlay-photo-image-bg" data-bg-img="<?= base_url('assets/images/sections-bg-images/footer-bg-1.jpg')?>" data-bg-opacity=".25"></div>
    <div class="container">
        <div class="row footer-cols">
            <div class="col-12 col-md-8 col-lg-4 footer-col"><img class="img-fluid footer-logo" loading="lazy" src="<?= base_url('assets/images/logo/logo-colored.png')?>" alt="RISHUCHI logo"/>
                <div class="footer-col-content-wrapper">
                    <p class="footer-text-about-us ">
                        <?= $textBlocks['12']['content'] ?? '' ?>
                    </p>
                </div>
                <div class="form-area">

                </div>
            </div>
            <div class="col-6 col-lg-2 footer-col">
                <h2 class="footer-col-title"><?= $textBlocks['16']['content'] ?? '' ?></h2>
                <div class="footer-col-content-wrapper">
                    <ul class="footer-menu">
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('')?>">Home</a></li>
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('services')?>">Services</a></li>
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('portfolio')?>">portfolio</a></li>
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('contact-us')?>">Contacts</a></li>
                    </ul>
                </div>
            </div>
            <div class="col-6 col-lg-2 footer-col">
                <h2 class="footer-col-title"><?= $textBlocks['17']['content'] ?? '' ?></h2>
                <div class="footer-col-content-wrapper">
                    <ul class="footer-menu">
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('about-us')?>">about us</a></li>
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('privacy-policy')?>">Privacy Policy</a></li>
                        <li class="footer-menu-item"><i class="bi bi-arrow-right icon"></i><a class="footer-menu-link" href="<?= base_url('terms')?>">Terms of Use</a></li>
                    </ul>
                </div>
            </div>
            <div class="col-12 col-lg-4 footer-col">
                <h2 class="footer-col-title"><?= $textBlocks['18']['content'] ?? '' ?></h2>
                <div class="footer-col-content-wrapper">
                    <div class="contact-info-card"><i class="bi bi-envelope icon"></i><a class="text-lowercase info" href="mailto:<?= $textBlocks['13']['content'] ?? '' ?>"><?= $textBlocks['13']['content'] ?? '' ?></a></div>
                    <div class="contact-info-card"><i class="bi bi-geo-alt icon"></i><span class="text-lowercase info"><?= $textBlocks['14']['content'] ?? '' ?></span></div>
                    <div class="contact-info-card"><i class="bi bi-phone icon"></i><a class="info" href="tel:<?= $textBlocks['15']['content'] ?? '' ?>"><?= $textBlocks['15']['content'] ?? '' ?></a></div>
                    <div class="contact-info-card">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="copyrights">
        <div class="container">
            <div class="row">
                <div class="col-12 col-md-6 d-flex justify-content-start">
                    <p class="credits">
                        &copy; <?= date('Y') ?> Created by: <a class="link" href="<?= base_url('')?>">Rishuchi</a>
                    </p>
                </div>
                <div class="col-12 col-md-6 d-flex justify-content-end">
                    <div class="terms-links"><a href="<?= base_url('terms')?>">Terms of Use</a>
                        | <a href="<?= base_url('privacy-policy')?>" data-bs-toggle="modal" data-bs-target="#privacyPolicyModal">Privacy Policy.</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</footer>
<!-- End  page-footer Section-->