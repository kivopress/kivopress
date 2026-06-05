<?php
$body_class = 'kp-search-results';
$searchValue = (string) ($archive['search'] ?? '');
$theme_title = ($archive['title'] ?? 'Search') . ' - ' . $site['name'];
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('search'); ?>
    <div class="kp-layout">
        <section>
            <header class="kp-section-head">
                <div>
                    <p class="kp-eyebrow">Search</p>
                    <h1 class="kp-page-title"><?= e($archive['title'] ?? 'Search') ?></h1>
                    <p><?= e($archive['description'] ?? 'Search published content.') ?></p>
                </div>
            </header>
            <div class="kp-widget">
                <?= kp_default_search_form($searchValue) ?>
            </div>
            <?php if (!empty($posts)): ?>
                <?php kp_before_loop('search', $posts); ?>
                <div class="kp-grid kp-results-grid">
                    <?php foreach ($posts as $post): ?>
                        <?php kp_before_loop_item($post, 'search'); ?>
                        <?php require __DIR__ . '/partials/post-card.php'; ?>
                        <?php kp_after_loop_item($post, 'search'); ?>
                    <?php endforeach; ?>
                </div>
                <?php kp_after_loop('search', $posts); ?>
                <?php require __DIR__ . '/partials/pagination.php'; ?>
            <?php else: ?>
                <div class="kp-empty kp-results-grid">
                    <strong>No results found.</strong>
                    <p>Try another keyword or browse the latest posts.</p>
                </div>
            <?php endif; ?>
        </section>
        <aside class="kp-sidebar">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </aside>
    </div>
    <?php kp_after_main('search'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
