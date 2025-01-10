<!--Start Page Header-->
<header class=" page-header   header-basic" id="page-header">
    <div class="header-search-box">
        <div class="close-search"></div>

    </div>
    <div class="container">
        <nav class="menu-navbar">
            <div class="header-logo"><a class="logo-link" href="<?= base_url('')?>"><img class="logo-img light-logo" loading="lazy" src="<?= base_url('assets/images/logo/logo-light.png')?>" alt="logo"/><img class="logo-img  dark-logo" loading="lazy" src="assets/images/logo/logo-dark.png" alt="logo"/></a></div>
            <div class="links menu-wrapper ">
                <ul class="list-js links-list">
                    <li class="menu-item has-sub-menu"><a class="menu-link <?= (uri_string() == '') ? 'active' : '' ?>" href="<?= base_url('')?>">home</a></li>
                    <li class="menu-item"><a class="menu-link <?= (uri_string() == 'about-us') ? 'active' : '' ?>" href="<?= base_url('about-us')?>">about us</a></li>
                    <li class="menu-item has-sub-menu"><a class="menu-link <?= (uri_string() == 'services') ? 'active' : '' ?>" href="<?= base_url('services')?>">services</a></li>
                    <li class="menu-item has-sub-menu"><a class="menu-link <?= (uri_string() == 'portfolio') ? 'active' : '' ?>" href="<?= base_url('portfolio')?>">portfolio</a></li>
                    <li class="menu-item"><a class="menu-link <?= (uri_string() == 'contact-us') ? 'active' : '' ?>" href="<?= base_url('contact-us')?>">contact us</a></li>
                </ul>
            </div>
            <div class="controls-box">
                <!--Menu Toggler button-->
                <div class="control  menu-toggler"><span></span><span></span><span></span></div>

                <!--Dark/Light mode button-->
                <div class="mode-switcher ">
                    <div class="switch-inner go-light " title="Switch To Light Mode "><i class="bi bi-sun icon "></i></div>
                    <div class="switch-inner go-dark" title="Switch To Dark Mode "><i class="bi bi-moon icon "></i></div>
                </div>
                <!--mini shoping cart-->
            </div>
        </nav>
    </div>
</header>
<!--End Page Header-->
