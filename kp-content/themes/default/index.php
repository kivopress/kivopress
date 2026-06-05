<?php
$body_class = $body_class ?? 'kp-index';
$archive ??= [
    'title' => 'Latest Posts',
    'description' => 'Fresh writing from ' . $site['name'] . '.',
];
$theme_title = $archive['title'] . ' - ' . $site['name'];
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('archive'); ?>
    <div class="kp-layout">
        <section>
            <header class="kp-section-head">
                <div>
                    <p class="kp-eyebrow">Archive</p>
                    <h1 class="kp-page-title"><?= e($archive['title']) ?></h1>
                    <?php if (!empty($archive['description'])): ?>
                        <p><?= e($archive['description']) ?></p>
                    <?php endif; ?>
                </div>
            </header>
            <?php if (!empty($posts)): ?>
                <?php kp_before_loop('archive', $posts); ?>
                <div class="kp-grid">
                    <?php foreach ($posts as $post): ?>
                        <?php kp_before_loop_item($post, 'archive'); ?>
                        <?php require __DIR__ . '/partials/post-card.php'; ?>
                        <?php kp_after_loop_item($post, 'archive'); ?>
                    <?php endforeach; ?>
                </div>
                <?php kp_after_loop('archive', $posts); ?>
                <?php require __DIR__ . '/partials/pagination.php'; ?>
            <?php else: ?>
                <div class="kp-empty">
                    <strong>No posts found.</strong>
                    <p>Create published posts in the admin, or adjust your search.</p>
                </div>
            <?php endif; ?>
        </section>
        <aside class="kp-sidebar">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </aside>
    </div>
    <?php kp_after_main('archive'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
