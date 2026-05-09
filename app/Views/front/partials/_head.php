<?php
/**
 * F5 Phase 2A — общая часть <head> для front layout templates/front.php (Rishuchi-theme).
 *   - meta + favicon
 *   - vendor CSS (bootstrap, animate, swiper, flaticon, fontawesome,
 *     bootstrap-icons, fancybox)
 *   - Google Fonts (Jost)
 *   - main-LTR.css
 *   - inline cookie consent <style>
 *
 * $title попадает сюда через tempData родительского шаблона (CI4 inheritance
 * при $this->include() без options-аргумента — см. F5 Phase 1 lesson #7).
 * Defensive fallback на случай отсутствия — не падать.
 */
$title = $title ?? 'Wildworld';
?>
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
