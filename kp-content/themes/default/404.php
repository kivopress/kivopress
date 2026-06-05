<?php
$body_class = 'kp-404';
$theme_title = 'Page Not Found - ' . $site['name'];
require __DIR__ . '/partials/header.php';
$latest = $contentRepository->all('post', ['limit' => 3]);
?>
<main id="content" class="kp-main">
    <?php kp_before_main('404'); ?>
    <section class="kp-entry">
        <p class="kp-eyebrow">404</p>
        <h1 class="kp-entry-title">Page Not Found</h1>
        <p class="kp-page-intro">The content for <strong><?= e($slug ?? '') ?></strong> does not exist yet. Try searching or head back home.</p>
        <div class="kp-hero-actions">
            <a class="kp-button" href="/">Return Home</a>
            <a class="kp-button-secondary" href="/search/">Search Site</a>
        </div>
    </section>
    <?php if ($latest): ?>
        <section class="kp-section-head">
            <div>
                <p class="kp-eyebrow">Latest</p>
                <h2>Recent Posts</h2>
            </div>
        </section>
        <div class="kp-grid">
            <?php kp_before_loop('404', $latest); ?>
            <?php foreach ($latest as $post): ?>
                <?php kp_before_loop_item($post, '404'); ?>
                <?php require __DIR__ . '/partials/post-card.php'; ?>
                <?php kp_after_loop_item($post, '404'); ?>
            <?php endforeach; ?>
            <?php kp_after_loop('404', $latest); ?>
        </div>
    <?php endif; ?>
    <?php kp_after_main('404'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
