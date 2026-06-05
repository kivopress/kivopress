<?php
$themeContent = $content ?? $post ?? $page ?? null;
$title = $theme_title ?? (is_array($themeContent)
    ? ($themeContent['title'] . ' - ' . $site['name'])
    : $site['name']);
$bodyClass = kp_default_body_class($body_class ?? '');
$description = trim((string) option('site_tagline', 'A lightweight publishing core for fast websites and REST APIs.'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(kp_document_title($title, $themeContent, $site)) ?></title>
<link rel="stylesheet" href="<?= e(kp_default_asset('site.css')) ?>">
<?php kp_head($themeContent, $site); ?>
<?php do_action('kivopress_default_head', $themeContent, $site); ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php kp_body_open($themeContent, $site); ?>
<?php kp_before_header($themeContent, $site); ?>
<?php do_action('kivopress_default_before_header', $themeContent, $site); ?>
<a class="kp-skip-link" href="#content">Skip to content</a>
<header class="kp-header">
    <div class="kp-header-inner">
        <a class="kp-brand" href="/">
            <span>K</span>
            <strong><?= e($site['name']) ?></strong>
        </a>
        <?= kp_default_primary_navigation($site, $contentRepository) ?>
    </div>
    <p class="kp-site-description"><?= e($description) ?></p>
</header>
<?php do_action('kivopress_default_after_header', $themeContent, $site); ?>
<?php kp_after_header($themeContent, $site); ?>
