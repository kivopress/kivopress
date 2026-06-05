<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Kivopress</title>
<style>
html{background:#f5f7f4}
body{margin:0}
.kp-topbar{min-height:36px}
.kp-shell{display:grid;grid-template-columns:188px minmax(0,1fr);min-height:calc(100vh - 36px)}
.kp-icon{display:inline-flex;width:18px;height:18px}
</style>
<link rel="stylesheet" href="/kp-admin/assets/kivopress-ui.css">
<link rel="stylesheet" href="/kp-admin/assets/kivopress-shell.css">
<?= $styles ?>
<?= $inlineCss ?>
<?= $head ?>
<?php do_action('admin_head', $title, $screen ?? []); ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php do_action('admin_body_open', $title, $screen ?? []); ?>
<?= $nav ? $view->adminShell($title, $noticeHtml . $body) : $view->authShell($title, $noticeHtml . $body) ?>
<?= $scripts ?>
<?= $footer ?>
<?php do_action('admin_footer', $title, $screen ?? []); ?>
</body>
</html>
