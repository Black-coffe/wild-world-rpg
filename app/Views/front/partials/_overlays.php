<?php
/**
 * F5 Phase 2A — body-level overlay'и для front layout templates/front.php:
 *   - cookie consent container (HTML; <style> в _head, listeners в _scripts)
 *   - loading screen
 *   - back-to-top button
 *
 * Static контент, no data passing.
 */
?>
<div id="cookieConsentContainer" class="cookie-consent-container">
    <div class="cookie-consent-content">
        <p>The Website uses cookies to give you the best online experience. By clicking "Accept all cookies" you agree to allow all cookies to be placed. To find out more visit our <a href="<?= base_url('privacy-policy')?>">Privacy Policy</a>.</p>
        <button id="acceptCookies" class="btn">Accept all cookies</button>
        <button id="rejectCookies" class="btn">Reject cookies</button>
    </div>
</div>
<!-- Start loading-screen Component-->
<div class="loading-screen" id="loading-screen"><span class="bar top-bar"></span><span class="bar down-bar"></span><span class="progress-line"></span><span class="loading-counter"> </span></div>
<!-- End loading-screen Component-->
<!-- Start back-to-top Button-->
<div class="back-to-top" id="back-to-top"><i class="bi bi-arrow-up icon "></i>
</div>
<!-- End back-to-top Button-->
