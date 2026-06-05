<?php
/**
 * Template Name: Canvas
 */
$body_class = 'kp-canvas';
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('page-canvas'); ?>
    <article class="kp-entry">
        <header class="kp-entry-header">
            <h1 class="kp-entry-title"><?= e($content['title']) ?></h1>
        </header>
        <?php kp_before_content($content, 'page-canvas'); ?>
        <div class="kp-entry-content">
            <?php kp_content($content, 'body', 'page-canvas'); ?>
        </div>
        <?php kp_after_content($content, 'page-canvas'); ?>
    </article>
    <?php kp_after_main('page-canvas'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
