<?php
$body_class = 'kp-page';
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('page'); ?>
    <article class="kp-entry">
        <header class="kp-entry-header">
            <p class="kp-eyebrow">Page</p>
            <h1 class="kp-entry-title"><?= e($content['title']) ?></h1>
        </header>
        <?= kp_default_featured_image_html($content, 'kp-entry-featured') ?>
        <?php kp_before_content($content, 'page'); ?>
        <div class="kp-entry-content">
            <?php kp_content($content, 'body', 'page'); ?>
        </div>
        <?php kp_after_content($content, 'page'); ?>
    </article>
    <?php kp_after_main('page'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
