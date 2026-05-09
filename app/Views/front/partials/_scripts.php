<?php
/**
 * F5 Phase 2A — общие vendor скрипты + cookie consent inline JS
 * для front layout templates/front.php (Rishuchi-theme):
 *   - jQuery, appear, bootstrap.bundle, jquery.countTo, wow,
 *     swiper-bundle, particles, vanilla-tilt, isotope, jquery.fancybox
 *   - js/main.js
 *   - inline cookie consent UI logic (setCookie/getCookie/listeners)
 *
 * Layout-specific скрипты (если появятся для отдельных страниц)
 * остаются в самих view'ах через section('extraJs') или эквивалент.
 */
?>
    <!--     JQuery     -->
    <script src="<?= base_url('js/vendors/jquery-3.6.1.min.js')?>"></script>

    <!--     appear     -->
    <script src="<?= base_url('js/vendors/appear.min.js')?>"></script>

    <!--     bootstrap     -->
    <script src="<?= base_url('js/vendors/bootstrap.bundle.min.js')?>"></script>

    <!--     countTo     -->
    <script src="<?= base_url('js/vendors/jquery.countTo.js')?>"></script>

    <!--     wow     -->
    <script src="<?= base_url('js/vendors/wow.min.js')?>"></script>

    <!--     swiper     -->
    <script src="<?= base_url('js/vendors/swiper-bundle.min.js')?>"></script>

    <!--     particles     -->
    <script src="<?= base_url('js/vendors/particles.min.js')?>"></script>

    <!--     Vanilla-tilt     -->
    <script src="<?= base_url('js/vendors/vanilla-tilt.min.js')?>"></script>

    <!--     isotope     -->
    <script src="<?= base_url('js/vendors/isotope-min.js')?>"></script>

    <!--     fancybox     -->
    <script src="<?= base_url('js/vendors/jquery.fancybox.min.js')?>"></script>

    <!--     main     -->
    <script src="<?= base_url('js/main.js')?>"></script>
    <script>
        window.addEventListener("load", function() {
            setTimeout(function() {
                const consent = getCookie("cookieConsent");
                if (consent !== "true") {
                    document.getElementById("cookieConsentContainer").style.display = "flex";
                    document.getElementById("cookieConsentContainer").style.justifyContent = "center";
                    document.getElementById("cookieConsentContainer").style.alignItems = "center";
                }
            }, 3000);
        });

        document.getElementById("acceptCookies").addEventListener("click", function() {
            setCookie("cookieConsent", "true", 365);
            document.getElementById("cookieConsentContainer").style.display = "none";
        });

        document.getElementById("rejectCookies").addEventListener("click", function() {
            document.getElementById("cookieConsentContainer").style.display = "none";
        });

        function setCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "") + expires + "; path=/";
        }

        function getCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }
    </script>
