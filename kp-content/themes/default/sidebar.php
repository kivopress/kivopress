<?php
$categories = $contentRepository->terms('category');
$tags = $contentRepository->terms('post_tag');
?>
<section class="kp-widget">
    <h2>Search</h2>
    <?= kp_default_search_form((string) ($_GET['s'] ?? '')) ?>
</section>
<?php if ($categories): ?>
    <section class="kp-widget">
        <h2>Categories</h2>
        <ul class="kp-widget-list">
            <?php foreach ($categories as $term): ?>
                <li>
                    <a href="<?= e(kp_default_term_url($term)) ?>"><?= e($term['name']) ?></a>
                    <span><?= e($term['count']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php if ($tags): ?>
    <section class="kp-widget">
        <h2>Tags</h2>
        <div class="kp-term-list kp-term-cloud">
            <?php foreach ($tags as $term): ?>
                <a href="<?= e(kp_default_term_url($term)) ?>"><?= e($term['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
