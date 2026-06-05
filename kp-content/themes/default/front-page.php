<?php
$body_class = 'kp-front-page';
$postCount = $contentRepository->countAll('post');
$pageCount = $contentRepository->countAll('page');
$categoryCount = count($contentRepository->terms('category'));
$heroTitle = $site['name'];
$heroCopy = 'A tiny, professional publishing theme for blogs, SaaS content, headless sites, and developer-first Kivopress projects.';
$theme_title = $site['name'];
require __DIR__ . '/partials/header.php';
?>
<section class="kp-hero">
    <div class="kp-hero-inner">
        <div>
            <p class="kp-eyebrow">Kivopress Default</p>
            <h1><?= e($heroTitle) ?></h1>
            <p class="kp-hero-copy"><?= e($heroCopy) ?></p>
            <div class="kp-hero-actions">
                <a class="kp-button" href="#latest">Read Latest</a>
                <a class="kp-button-secondary" href="/api">Explore API</a>
            </div>
        </div>
        <aside class="kp-hero-panel" aria-label="Site overview">
            <div class="kp-stat"><strong><?= e($postCount) ?></strong><span>Published posts</span></div>
            <div class="kp-stat"><strong><?= e($pageCount) ?></strong><span>Pages</span></div>
            <div class="kp-stat"><strong><?= e($categoryCount) ?></strong><span>Categories</span></div>
        </aside>
    </div>
</section>
<main id="content" class="kp-main">
    <?php kp_before_main('front-page'); ?>
    <div class="kp-layout">
        <section id="latest">
            <header class="kp-section-head">
                <div>
                    <p class="kp-eyebrow">Latest</p>
                    <h2>Recent Posts</h2>
                    <p>Freshly published articles from <?= e($site['name']) ?>.</p>
                </div>
                <a href="/api/posts">JSON</a>
            </header>
            <?php if (!empty($posts)): ?>
                <?php kp_before_loop('home', $posts); ?>
                <div class="kp-grid">
                    <?php foreach ($posts as $post): ?>
                        <?php kp_before_loop_item($post, 'home'); ?>
                        <?php require __DIR__ . '/partials/post-card.php'; ?>
                        <?php kp_after_loop_item($post, 'home'); ?>
                    <?php endforeach; ?>
                </div>
                <?php kp_after_loop('home', $posts); ?>
                <?php require __DIR__ . '/partials/pagination.php'; ?>
            <?php else: ?>
                <div class="kp-empty">
                    <strong>No published posts yet.</strong>
                    <p>Create the first post in the Kivopress admin and it will appear here automatically.</p>
                </div>
            <?php endif; ?>
        </section>
        <aside class="kp-sidebar">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </aside>
    </div>
    <?php kp_after_main('front-page'); ?>
</main>
<section class="kp-api-band">
    <div class="kp-api-band-inner">
        <div><strong>Headless ready</strong><br><span>Use the same content through fast JSON endpoints.</span></div>
        <code>/api/kp/v1/posts</code>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
