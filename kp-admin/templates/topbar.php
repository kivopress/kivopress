<?php $user = $auth->user(); ?>
<header class="kp-topbar">
    <div class="kp-topbar-left">
        <a class="kp-brand" href="/admin"><span>K</span>Kivopress</a>
        <?php do_action('admin.topbar_left', $title, $user); ?>
    </div>
    <div class="kp-top-actions">
        <?php do_action('admin.topbar_right_before', $title, $user); ?>
        <a href="/" class="kp-top-link"><?= $view->icon('open_in_new') ?>View Site</a>
        <span class="kp-user-chip"><?= $view->icon('account_circle') ?><?= e($user['name'] ?? 'Admin') ?></span>
        <?= $view->logoutForm() ?>
        <?php do_action('admin.topbar_right_after', $title, $user); ?>
    </div>
</header>
