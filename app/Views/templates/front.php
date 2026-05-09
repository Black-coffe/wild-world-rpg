<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->include('front/partials/_head') ?>
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

<?= $this->include('front/partials/_overlays') ?>

<?= $this->include('front/partials/_modal_privacy') ?>

<?= $this->include('front/partials/_scripts') ?>
</body>
</html>
