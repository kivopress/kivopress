<?php $footerColumns = kp_default_footer_columns($site); ?>
<?php kp_before_footer(null, $site); ?>
<?php do_action('kivopress_default_before_footer', $site); ?>
<footer class="kp-footer">
    <div>
        <a class="kp-brand kp-brand-footer" href="/"><span>K</span><strong><?= e($site['name']) ?></strong></a>
        <p><?= e(option('site_tagline', 'Fast publishing, flexible themes, and clean APIs.')) ?></p>
    </div>
    <div class="kp-footer-links">
        <?php foreach ($footerColumns as $heading => $links): ?>
            <section>
                <h2><?= e($heading) ?></h2>
                <?php foreach ($links as $link): ?>
                    <a href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
</footer>
<?php do_action('kivopress_default_after_footer', $site); ?>
<?php kp_after_footer(null, $site); ?>
<?php kp_footer(null, $site); ?>
</body>
</html>
