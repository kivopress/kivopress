<?php
/**
 * Template Name: Landing Page
 */
$body_class = 'kp-landing';
$latest = $contentRepository->all('post', ['limit' => 3]);
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('page-landing'); ?>
    <article class="kp-entry">
        <p class="kp-eyebrow">Landing Page</p>
        <h1 class="kp-entry-title"><?= e($content['title']) ?></h1>
        <?php kp_before_content($content, 'page-landing'); ?>
        <div class="kp-entry-content">
            <?php kp_content($content, 'body', 'page-landing'); ?>
        </div>
        <?php kp_after_content($content, 'page-landing'); ?>
        <?php if ($latest): ?>
            <section class="kp-landing-band">
                <header class="kp-section-head">
                    <div>
                        <p class="kp-eyebrow">Latest</p>
                        <h2>Keep Reading</h2>
                    </div>
                </header>
                <div class="kp-grid">
                    <?php foreach ($latest as $post): ?>
                        <?php require __DIR__ . '/partials/post-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </article>
    <?php kp_after_main('page-landing'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
