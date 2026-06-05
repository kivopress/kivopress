<?php do_action('admin.before_shell', $title); ?>
<?= $view->template('topbar', compact('title')) ?>
<main class="kp-shell">
    <?= $view->template('sidebar', compact('title')) ?>
    <section class="kp-main">
        <?php do_action('admin.before_page_head', $title); ?>
        <?= $view->template('page-head', compact('title')) ?>
        <?php do_action('admin.after_page_head', $title); ?>
        <?php do_action('admin.before_content', $title); ?>
        <?= $body ?>
        <?php do_action('admin.after_content', $title); ?>
    </section>
</main>
<?php do_action('admin.after_shell', $title); ?>
