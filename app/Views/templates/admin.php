<!--/Views/templates/admin.php-->
<!DOCTYPE html>
<html>
<head>
    <?= $this->include('admin/partials/_head_common') ?>

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

        <?= $this->include('admin/partials/_footer') ?>

    </div>

    <!-- ============================================================== -->
    <!-- End Page content -->
    <!-- ============================================================== -->

</div>
<!-- END wrapper -->

<?= $this->include('admin/partials/_scripts_common') ?>

<!-- Apex Charts js -->
<script src="<?= base_url('vendor/apexcharts/apexcharts.min.js')?>"></script>

<!-- Todo js -->
<script src="<?= base_url('js/ui/component.todo.js')?>"></script>

<!-- CRM Dashboard Demo App Js -->
<script src="<?= base_url('js/pages/demo.crm-dashboard.js')?>"></script>

<!-- PDF.js viewer -->
<script src="<?= base_url('js/pdf.js')?>"></script>

</body>
</html>
