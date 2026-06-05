<div class="kp-page-head">
    <div>
        <p><?= e(apply_filters('admin.page_eyebrow', 'Admin', $title)) ?></p>
        <h1><?= e(apply_filters('admin.page_title', $title)) ?></h1>
    </div>
    <div class="kp-page-actions">
        <?php do_action('admin.page_actions', $title); ?>
    </div>
</div>
