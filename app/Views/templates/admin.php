<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>

    <script src="https://polyfill.io/v3/polyfill.min.js?features=default"></script>

    <meta content="Система, помогающая в своевременном и регулируемом режиме проводить технические проверки (чекины) автомобилей с использованием онлайн-сервиса." name="description" />
    <meta content="Checking of cars" name="author" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="<?= base_url('images/favicon.ico') ?>">
    <!-- Theme Config Js -->
    <script src="<?= base_url('js/hyper-config.js') ?>"></script>
    <!-- App css -->
    <link href="<?= base_url('css/app-saas.min.css') ?>" rel="stylesheet" type="text/css" id="app-style" />
    <!-- Icons css -->
    <link href="<?= base_url('css/icons.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- My css -->
    <link href="<?= base_url('css/my.css') ?>" rel="stylesheet" type="text/css" />

    <!-- Google Maps API -->
    <!-- prettier-ignore -->
    <script>(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})
        ({key: "AIzaSyAD_oPV0bvF2We_n1RNZPwRDGFmadhXEBk", v: "beta"});</script>

    <script src="https://mozilla.github.io/pdf.js/build/pdf.js"></script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.9.179/web/pdf_viewer.min.css">

</head>

<body>
<!-- Begin page -->
<div class="wrapper">

    <!-- ========== Topbar Start ========== -->
    <?= $this->include('templates/navbar_custome') ?>
    <!-- ========== Topbar End ========== -->

    <!-- ========== Left Sidebar Start ========== -->
    <?= $this->include('templates/sidebar') ?>
    <!-- ========== Left Sidebar End ========== -->

    <!-- ============================================================== -->
    <!-- Start Page Content here -->
    <!-- ============================================================== -->

    <div class="content-page">

        <?= $this->renderSection('content') ?>

        <!-- Footer Start -->
        <footer class="footer">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6">
                        <script>document.write(new Date().getFullYear())</script> © <a target="_blank" href="https://www.youtube.com/@andrievskii">Andrievskii</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- end Footer -->

    </div>

    <!-- ============================================================== -->
    <!-- End Page content -->
    <!-- ============================================================== -->

</div>
<!-- END wrapper -->

<!-- Vendor js -->
<script src="<?= base_url('js/vendor.min.js')?>"></script>

<!-- Apex  Charts js -->
<script src="<?= base_url('vendor/apexcharts/apexcharts.min.js')?>"></script>

<!-- Todo js -->
<script src="<?= base_url('js/ui/component.todo.js')?>"></script>

<!-- CRM Dashboard Demo App Js -->
<script src="<?= base_url('js/pages/demo.crm-dashboard.js')?>"></script>

<!-- App js -->
<script src="<?= base_url('js/app.min.js')?>"></script>

<!-- My js -->
<script src="<?= base_url('js/my.js')?>"></script>
<script src="<?= base_url('js/pdf.js')?>"></script>

</body>
</html>