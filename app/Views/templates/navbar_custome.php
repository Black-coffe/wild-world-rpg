<!-- ========== Topbar Start ========== -->
<div class="navbar-custom">
    <?php $user = service('auth')->getCurrentUser(); ?>
    <div class="topbar container-fluid">
        <div class="d-flex align-items-center gap-lg-2 gap-1">

            <!-- Topbar Brand Logo -->
            <div class="logo-topbar">
                <!-- Logo light -->
                <a href="<?= base_url('dashboard')?>" class="logo-light">
                                <span class="logo-lg">
                                    <img src="<?= base_url('images/logo.png')?>" alt="logo">
                                </span>
                    <span class="logo-sm">
                                    <img src="<?= base_url('images/logo-sm.png')?>" alt="small logo">
                                </span>
                </a>

                <!-- Logo Dark -->
                <a href="<?= base_url('/')?>" class="logo-dark">
                                <span class="logo-lg">
                                    <img src="<?= base_url('images/logo-dark.png')?>" alt="dark logo">
                                </span>
                    <span class="logo-sm">
                                    <img src="<?= base_url('images/logo-dark-sm.png')?>" alt="small logo">
                                </span>
                </a>
            </div>

            <!-- Sidebar Menu Toggle Button -->
            <button class="button-toggle-menu">
                <i class="mdi mdi-menu"></i>
            </button>

            <!-- Horizontal Menu Toggle Button -->
            <button class="navbar-toggle" data-bs-toggle="collapse" data-bs-target="#topnav-menu-content">
                <div class="lines">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>

        </div>

        <ul class="topbar-menu d-flex align-items-center gap-3">
            <?php if($user->name == 'Administrator' and $user->is_admin): ?>
                <li class="d-none d-sm-inline-block">
                    <a class="nav-link" href="<?= base_url('admin/setting')?>">
                        <i class="ri-settings-3-line font-22"></i>
                    </a>
                </li>
            <?php endif;?>

            <li class="d-none d-sm-inline-block">
                <div class="nav-link" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="left" title="Theme Mode">
                    <i class="ri-moon-line font-22"></i>
                </div>
            </li>


            <li class="d-none d-md-inline-block">
                <a class="nav-link" href="" data-toggle="fullscreen">
                    <i class="ri-fullscreen-line font-22"></i>
                </a>
            </li>

            <li class="dropdown">

                <?php if ($user): ?>

                    <a class="nav-link dropdown-toggle arrow-none nav-user px-2" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <span class="account-user-avatar">
                            <img src="<?= base_url('uploads/users/') . $user->photo_url; ?>" alt="user-image" width="32" class="rounded-circle">
                        </span>
                                            <span class="d-lg-flex flex-column gap-1 d-none">
                            <h5 class="my-0"><?= $user->name; ?></h5>
                            <h6 class="my-0 fw-normal">
                                <?php echo $user->is_admin ? "Администратор" : "Пользователь"; ?>
                            </h6>
                            <h6 class="my-0"><small>
                                <?php
                                $serverTime = date('m.d.Y H:i', time());
                                echo $serverTime;
                                ?></small>
                            </h6>
                        </span>
                    </a>

                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
                    <!-- item-->
                    <a href="<?= base_url('logout')?>" class="dropdown-item">
                        <i class="mdi mdi-logout me-1"></i>
                        <span>Выйти</span>
                    </a>
                </div>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</div>
<!-- ========== Topbar End ========== -->