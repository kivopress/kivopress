<?php
$body_class = 'kp-single';
$categories = kp_default_terms($content, 'category');
$tags = kp_default_terms($content, 'post_tag');
$related = array_values(array_filter(
    $contentRepository->all('post', ['limit' => 4]),
    fn (array $item): bool => (int) $item['id'] !== (int) $content['id']
));
require __DIR__ . '/partials/header.php';
?>
<main id="content" class="kp-main">
    <?php kp_before_main('single'); ?>
    <div class="kp-layout">
        <article class="kp-entry">
            <header class="kp-entry-header">
                <p class="kp-eyebrow">Article</p>
                <h1 class="kp-entry-title"><?= e($content['title']) ?></h1>
                <div class="kp-entry-meta">
                    <time datetime="<?= e($content['published_at'] ?? $content['created_at']) ?>"><?= e(kp_default_date($content)) ?></time>
                    <span aria-hidden="true">/</span>
                    <span><?= e(kp_default_read_time($content)) ?></span>
                </div>
            </header>
            <?= kp_default_featured_image_html($content, 'kp-entry-featured') ?>
            <?php kp_before_content($content, 'single'); ?>
            <div class="kp-entry-content">
                <?php kp_content($content, 'body', 'single'); ?>
            </div>
            <?php kp_after_content($content, 'single'); ?>
            <?php if ($categories || $tags): ?>
                <nav class="kp-term-list" aria-label="Post terms">
                    <?php foreach (array_merge($categories, $tags) as $term): ?>
                        <a href="<?= e(kp_default_term_url($term)) ?>"><?= e($term['name']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </article>
        <aside class="kp-sidebar">
            <?php if ($related): ?>
                <section class="kp-widget">
                    <h2>More To Read</h2>
                    <ul class="kp-widget-list">
                        <?php foreach ($related as $item): ?>
                            <li><a href="<?= e(content_url($item)) ?>"><?= e($item['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
            <?php require __DIR__ . '/sidebar.php'; ?>
        </aside>
    </div>
    <?php kp_after_main('single'); ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
