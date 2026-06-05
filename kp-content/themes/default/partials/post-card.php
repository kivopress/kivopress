<?php
$categories = kp_default_terms($post, 'category');
$category = $categories[0] ?? null;
$image = kp_default_featured_image($post);
?>
<article class="kp-card">
    <a class="kp-card-media" href="<?= e(content_url($post)) ?>" aria-label="<?= e($post['title']) ?>">
        <?php if ($image): ?>
            <img src="<?= e($image['url']) ?>" alt="<?= e($image['alt'] ?: $post['title']) ?>">
        <?php else: ?>
            <span><?= e(substr($post['title'], 0, 1)) ?></span>
        <?php endif; ?>
    </a>
    <div class="kp-card-body">
        <div class="kp-card-meta">
            <?php if ($category): ?>
                <a href="<?= e(kp_default_term_url($category)) ?>"><?= e($category['name']) ?></a>
                <span aria-hidden="true">/</span>
            <?php endif; ?>
            <time datetime="<?= e($post['published_at'] ?? $post['created_at']) ?>"><?= e(kp_default_date($post)) ?></time>
        </div>
        <h2><a href="<?= e(content_url($post)) ?>"><?= e($post['title']) ?></a></h2>
        <p><?= e(kp_default_excerpt($post)) ?></p>
        <div class="kp-card-actions">
            <a href="<?= e(content_url($post)) ?>">Read article</a>
            <span><?= e(kp_default_read_time($post)) ?></span>
        </div>
    </div>
</article>
