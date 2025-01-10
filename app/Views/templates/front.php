<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Rishuchi: Your Bridge to Digital Success. Specializing in SEO Audits, Content Strategy, and Comprehensive Digital Marketing Services.">
    <title><?= $title ?></title>

    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <!-- fav icon -->
    <link rel="icon" href="<?= base_url('assets/images/fav-icon/fav-icon.png')?>">

    <!-- bootstarp -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/bootstrap.min.css')?>">

    <!-- animate.css file -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/animate.css')?>">

    <!-- Swiper -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/swiper-bundle.min.css')?>">

    <!-- flaticon -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/flaticon/flaticon.css')?>">

    <!-- fontAwesome -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/all.min.css')?>">

    <!-- bootstrap icons -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/bootstrap-icons-1.9.1/bootstrap-icons.css')?>">

    <!-- Fancybox -->
    <link rel="stylesheet" href="<?= base_url('css/vendors/jquery.fancybox.min.css')?>">

    <!-- fonts site preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <!-- fonts site preconnect -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Font Family -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700;800&amp;display=swap">

    <!-- main-LTR -->
    <link rel="stylesheet" href="<?= base_url('css/main-LTR.css')?>">

    <style>
        .cookie-consent-container {
            display: none;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: black;
            color: white;
            padding: 20px;
            text-align: center;
            z-index: 1000;
        }

        .cookie-consent-content a {
            color: #4CAF50;
        }

        .btn {
            margin: 10px;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        #acceptCookies {
            background-color: #4CAF50;
            color: white;
        }

        #rejectCookies {
            background-color: #f44336;
            color: white;
        }
    </style>
</head>

<body class=" dark-theme ">
<!-- ========== Topbar Start ========== -->
<?= $this->include('layouts/header') ?>
<!-- ========== Topbar End ========== -->

    <!-- ============================================================== -->
    <!-- Start Page Content here -->
    <!-- ============================================================== -->

        <?= $this->renderSection('content') ?>

    <!-- ============================================================== -->
    <!-- End Page content -->
    <!-- ============================================================== -->


<!-- Start  page-footer Section-->
<?= $this->include('layouts/footer') ?>
<!-- End  page-footer Section-->
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
<!-- Start privacy-policy-modal-->
<div class="modal privacy-policy-modal fade" id="privacyPolicyModal" aria-labelledby="PrivacyPolicyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl ">
        <div class="modal-content text-dark">
            <div class="modal-header">
                <h2 class="modal-title" id="PrivacyPolicyModalLabel">Privacy Policy</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="container">
                    <div class="row">
                        <div class="col-12 col-lg-10 mx-auto">
                            <h2>Privacy Policy</h2>
                            <p>Last modified on January 30, 2023 (the “Effective Date”)</p>
                            <p>This Privacy Policy (the “Privacy Policy”) describes how and why We collect, process, and utilise any information that You share with Rishuchi when using the Website http://www.rishuchi.im (the “Website”).</p>
                            <p>Any reference to “Rishuchi” and “We” refers to RISHUCHI LIMITED, a legal entity incorporated under the laws of the Isle of Man, under number 020616V. This Policy applies where We are acting as a data controller with respect to the information collected from the users via the Website.</p>
                            <p>We are committed to protecting your privacy and safeguarding your data. Any information by which You can be identified while using the Website (the “Personal Information”) will only be used in accordance with this Policy, upon Your consent and in line with applicable laws.</p>
                            <h3>Information We collect from You</h3>
                            <ul>
                                <li>Information We obtain based on your use of Website. This includes which content You view, which products You are interested in, your dynamic IP address, browser type and operating system, referral source, etc., as well as information about the timing, frequency and pattern of your use of the Website. This data may be processed for analysis purposes.</li>
                                <li>Information You provide Rishuchi with when filling forms on Website. This includes your personal details such as name, surname, email address, phone number, resume, etc. This information is obtained through your voluntary submission to Rishuchi system and may be processed for the purposes of answering your queries on Website, operating the Website, ensuring the security of the Website, maintaining backups of Rishuchi databases and communicating with You.</li>
                                <li>Information You provide Rishuchi with during the direct communication with Rishuchi. This includes your personal information and the details of your inquiry which, among others, may include your personal data.</li>
                            </ul>
                            <p>Rishuchi does not collect and does not process any sensitive personal information.</p>
                            <h3>Legal basis for collecting and processing of your Personal Information</h3>
                            <p>The legal basis for such processing is Rishuchi's legitimate interest, your unequivocal consent, compliance with legal obligations, and other purposes permitted by law.</p>
                            <h3>Whom We might share your Personal Information with</h3>
                            <p>We may disclose your Personal Information to Rishuchi employees, business partners, and professional advisers as necessary. We never sell or rent your Personal Information to third parties.</p>
                            <h3>Data retention</h3>
                            <p>We will ensure that any personal data We process for any purpose shall not be kept for longer than necessary for that purpose.</p>
                            <h3>Cookie policy</h3>
                            <p>If You do not wish for cookies to be installed on your device, You can change the settings on your browser or device to reject cookies.</p>
                            <h3>Your rights</h3>
                            <p>You have the right to access, correct, or delete your personal data and the right to restrict their processing, as well as the right to data portability.</p>
                            <h3>Changes to this Privacy Policy</h3>
                            <p>This Privacy Policy may be amended from time to time. Please check this page occasionally to make sure You are comfortable with any changes to the Policy.</p>
                            <h3>Contacting Rishuchi</h3>
                            <p>If You have any questions about this Policy, protection of your personal data or would like to exercise any of your rights in relation to your personal data, You can contact Rishuchi by using Rishuchi contact details on the Contact page.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
</body>
</html>