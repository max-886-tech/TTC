<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
$me = require_login();

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$currentFile = basename($currentPath ?: '');
if ($currentPath === '/admin/' || $currentFile === '') {
  $currentFile = 'index.php';
}

if (!function_exists('admin_current_file')) {
  function admin_current_file(): string {
    global $currentFile;
    return (string)$currentFile;
  }
}

if (!function_exists('admin_nav_match')) {
  function admin_nav_match(array $files): bool {
    $current = admin_current_file();
    return in_array($current, $files, true);
  }
}

if (!function_exists('admin_nav_active')) {
  function admin_nav_active(array $files): string {
    return admin_nav_match($files) ? 'active' : '';
  }
}

if (!function_exists('admin_nav_expanded')) {
  function admin_nav_expanded(array $files): string {
    return admin_nav_match($files) ? 'true' : 'false';
  }
}

if (!function_exists('admin_nav_show')) {
  function admin_nav_show(array $files): string {
    return admin_nav_match($files) ? 'show' : '';
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --admin-topbar-h: 56px;
      --admin-sidebar-w: 272px;
      --admin-sidebar-w-collapsed: 82px;
      --admin-bg: #f0f2f5;
      --admin-surface: #ffffff;
      --admin-border: rgba(15, 23, 42, .08);
      --admin-sidebar-bg: #1d2327;
      --admin-sidebar-hover: #2c3338;
      --admin-sidebar-active: #2271b1;
      --admin-muted: #646970;
      --admin-text-muted: #a7aaad;
      --admin-shadow: 0 10px 30px rgba(2, 6, 23, .06);
      --admin-radius: 18px;
    }

    html, body { min-height: 100%; }
    body {
      background: var(--admin-bg);
      overflow-x: hidden;
      color: #1f2937;
    }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

    .admin-topbar {
      height: var(--admin-topbar-h);
      z-index: 1040;
      background: #101418 !important;
    }
    .admin-shell { min-height: calc(100vh - var(--admin-topbar-h)); }
    .admin-sidebar {
      position: fixed;
      top: var(--admin-topbar-h);
      left: 0;
      bottom: 0;
      width: var(--admin-sidebar-w);
      background: var(--admin-sidebar-bg);
      color: #fff;
      overflow-y: auto;
      transition: width .2s ease, transform .2s ease;
      z-index: 1030;
      box-shadow: inset -1px 0 0 rgba(255,255,255,.06);
    }
    .admin-content {
      margin-left: var(--admin-sidebar-w);
      min-height: calc(100vh - var(--admin-topbar-h));
      padding: 1.5rem;
      transition: margin-left .2s ease;
    }
    .admin-sidebar .sidebar-heading {
      color: var(--admin-text-muted);
      font-size: .74rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: .85rem 1rem .45rem;
    }
    .sidebar-user-card {
      margin: .85rem;
      padding: .9rem;
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 16px;
      background: rgba(255,255,255,.04);
    }
    .admin-sidebar .nav-link,
    .admin-sidebar .submenu-link,
    .admin-sidebar .menu-toggle {
      color: #e9ecef;
      border-radius: 10px;
      margin: .15rem .75rem;
      padding: .72rem .9rem;
      display: flex;
      align-items: center;
      gap: .75rem;
      text-decoration: none;
      white-space: nowrap;
      border: 0;
      width: calc(100% - 1.5rem);
      background: transparent;
      text-align: left;
    }
    .admin-sidebar .nav-link:hover,
    .admin-sidebar .submenu-link:hover,
    .admin-sidebar .menu-toggle:hover {
      background: var(--admin-sidebar-hover);
      color: #fff;
    }
    .admin-sidebar .nav-link.active,
    .admin-sidebar .submenu-link.active,
    .admin-sidebar .menu-toggle.active {
      background: var(--admin-sidebar-active);
      color: #fff;
      font-weight: 600;
    }
    .admin-sidebar .nav-link i,
    .admin-sidebar .menu-toggle i,
    .admin-sidebar .submenu-link i {
      min-width: 18px;
      text-align: center;
      font-size: 1rem;
    }
    .admin-sidebar .menu-toggle::after {
      content: "\F282";
      font-family: bootstrap-icons;
      margin-left: auto;
      font-size: .9rem;
      transition: transform .2s ease;
      opacity: .75;
    }
    .admin-sidebar .menu-toggle[aria-expanded="true"]::after { transform: rotate(90deg); }
    .admin-sidebar .submenu-wrap { margin-bottom: .2rem; }
    .admin-sidebar .submenu {
      padding: .15rem 0 .35rem;
    }
    .admin-sidebar .submenu-link {
      margin-left: 1.55rem;
      width: calc(100% - 2.3rem);
      font-size: .94rem;
      color: #c7cbd1;
    }
    .admin-sidebar .submenu-link .dot {
      width: 7px;
      height: 7px;
      border-radius: 999px;
      background: currentColor;
      opacity: .6;
      min-width: 7px;
    }
    .admin-page-header {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 1.25rem;
    }
    .admin-page-title {
      margin: 0;
      font-size: 1.55rem;
      font-weight: 700;
      color: #111827;
    }
    .admin-page-subtitle {
      margin-top: .25rem;
      color: var(--admin-muted);
    }
    .admin-page-card,
    .admin-panel,
    .card.shadow-sm,
    .card.border-0.shadow-sm,
    .card.border-0.shadow-sm.rounded-4,
    form.card {
      background: var(--admin-surface);
      border: 1px solid var(--admin-border) !important;
      border-radius: var(--admin-radius) !important;
      box-shadow: var(--admin-shadow) !important;
    }
    .admin-panel-body { padding: 1.25rem; }
    .table-wrap { background: var(--admin-surface); border-radius: var(--admin-radius); }
    .table {
      --bs-table-bg: transparent;
      margin-bottom: 0;
    }
    .table > :not(caption) > * > * {
      padding-top: .9rem;
      padding-bottom: .9rem;
      border-bottom-color: rgba(15,23,42,.08);
      vertical-align: middle;
    }
    .table thead th {
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #6b7280;
      background: #f8fafc !important;
      border-bottom-width: 1px;
    }
    .table-sticky thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      box-shadow: inset 0 -1px 0 rgba(15,23,42,.08);
    }
    .admin-panel-header {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--admin-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .admin-section-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
      color: #111827;
    }
    .admin-section-subtitle {
      margin-top: .15rem;
      color: var(--admin-muted);
      font-size: .875rem;
    }
    .admin-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }
    .admin-toolbar .form-control,
    .admin-toolbar .form-select {
      min-height: 40px;
    }
    .admin-table-compact > :not(caption) > * > * {
      padding-top: .7rem;
      padding-bottom: .7rem;
    }
    .admin-icon-badge {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #eef6ff;
      color: #2271b1;
      flex: 0 0 auto;
    }
    .admin-user-cell {
      display: flex;
      align-items: center;
      gap: .75rem;
      min-width: 0;
    }
    .admin-meta-stack {
      display: flex;
      flex-direction: column;
      gap: .1rem;
      min-width: 0;
    }
    .admin-meta-stack .title {
      font-weight: 600;
      color: #111827;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .admin-meta-stack .sub {
      color: var(--admin-muted);
      font-size: .84rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .wp-pill {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      border-radius: 999px;
      padding: .38rem .72rem;
      font-size: .8rem;
      font-weight: 600;
      border: 1px solid transparent;
      line-height: 1;
    }
    .wp-pill i { font-size: .82rem; }
    .wp-pill-blue { background: #eef6ff; color: #2271b1; border-color: #cfe2ff; }
    .wp-pill-green { background: #edf9f0; color: #157347; border-color: #cfe9d6; }
    .wp-pill-gray { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
    .wp-pill-amber { background: #fff7e6; color: #b26a00; border-color: #ffe0a3; }
    .admin-list-tight .list-group-item {
      padding: .9rem 1rem;
      border-left: 0;
      border-right: 0;
    }
    .admin-list-tight .list-group-item:first-child { border-top: 0; }
    .admin-list-tight .list-group-item:last-child { border-bottom: 0; }
    .admin-form-grid {
      display: grid;
      grid-template-columns: repeat(12, minmax(0, 1fr));
      gap: 1rem;
    }
    .admin-col-6 { grid-column: span 6; }
    .admin-col-12 { grid-column: span 12; }
    @media (max-width: 767.98px) {
      .admin-col-6, .admin-col-12 { grid-column: span 12; }
    }
    .form-control, .form-select {
      min-height: 44px;
      border-radius: 12px;
      border-color: rgba(15,23,42,.12);
    }
    textarea.form-control { min-height: 120px; }
    .btn { border-radius: 12px; }
    .badge { border-radius: 999px; }
    .admin-kpi {
      border: 1px solid var(--admin-border);
      border-radius: 16px;
      padding: 1rem;
      background: linear-gradient(180deg, rgba(255,255,255,.9), rgba(248,250,252,.95));
    }
    .admin-kpi-label { color: var(--admin-muted); font-size: .82rem; text-transform: uppercase; letter-spacing: .04em; }
    .admin-kpi-value { font-size: 1.6rem; font-weight: 700; }

    body.sidebar-collapsed .admin-sidebar { width: var(--admin-sidebar-w-collapsed); }
    body.sidebar-collapsed .admin-content { margin-left: var(--admin-sidebar-w-collapsed); }
    body.sidebar-collapsed .admin-sidebar .nav-text,
    body.sidebar-collapsed .admin-sidebar .sidebar-heading,
    body.sidebar-collapsed .admin-sidebar .sidebar-user-text,
    body.sidebar-collapsed .admin-sidebar .menu-toggle::after,
    body.sidebar-collapsed .admin-sidebar .submenu { display: none !important; }
    body.sidebar-collapsed .admin-sidebar .nav-link,
    body.sidebar-collapsed .admin-sidebar .menu-toggle {
      justify-content: center;
      padding-left: .75rem;
      padding-right: .75rem;
    }

    @media (max-width: 991.98px) {
      .admin-sidebar {
        transform: translateX(-100%);
        width: var(--admin-sidebar-w);
      }
      body.sidebar-open .admin-sidebar { transform: translateX(0); }
      .admin-content {
        margin-left: 0 !important;
        padding: 1rem;
      }
      body.sidebar-collapsed .admin-sidebar {
        width: var(--admin-sidebar-w);
      }
      body.sidebar-collapsed .admin-sidebar .nav-text,
      body.sidebar-collapsed .admin-sidebar .sidebar-heading,
      body.sidebar-collapsed .admin-sidebar .sidebar-user-text,
      body.sidebar-collapsed .admin-sidebar .submenu,
      body.sidebar-collapsed .admin-sidebar .menu-toggle::after {
        display: initial !important;
      }
      body.sidebar-collapsed .admin-sidebar .nav-link,
      body.sidebar-collapsed .admin-sidebar .menu-toggle {
        justify-content: flex-start;
      }
      .admin-sidebar-backdrop {
        position: fixed;
        inset: var(--admin-topbar-h) 0 0 0;
        background: rgba(0,0,0,.35);
        z-index: 1025;
        display: none;
      }
      body.sidebar-open .admin-sidebar-backdrop { display: block; }
    }
  </style>
</head>
<body>
<nav class="navbar navbar-dark admin-topbar sticky-top shadow-sm">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-dark border-0 px-2" type="button" id="sidebarToggle" aria-label="Toggle menu">
        <i class="bi bi-list fs-3"></i>
      </button>
      <a class="navbar-brand mb-0" href="/admin/"><?= h(APP_NAME) ?></a>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="text-white-50 small d-none d-md-inline">Logged in as <?= h($me['username']) ?></span>
      <a class="btn btn-sm btn-outline-light" href="/logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="admin-shell">
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-user-card">
      <div class="fw-semibold text-white"><?= h($me['username']) ?></div>
      <div class="small text-white-50 sidebar-user-text">Administrator Panel</div>
    </div>

    <div class="sidebar-heading">Navigation</div>
    <nav class="nav flex-column pb-4">
      <?php if (can('codes.view') || can('code.generate')): ?>
        <?php $codePages = ['index.php', 'code_new.php', 'code_edit.php', 'code_delete.php', 'code_reset.php']; ?>
        <div class="submenu-wrap">
          <button class="menu-toggle <?= admin_nav_active($codePages) ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuCodes" aria-expanded="<?= admin_nav_expanded($codePages) ?>">
            <i class="bi bi-key-fill"></i>
            <span class="nav-text">Codes</span>
          </button>
          <div class="collapse submenu <?= admin_nav_show($codePages) ?>" id="menuCodes">
            <?php if (can('codes.view')): ?>
              <a class="submenu-link <?= admin_nav_active(['index.php']) ?>" href="/admin/"><span class="dot"></span><span class="nav-text">All Codes</span></a>
            <?php endif; ?>
            <?php if (can('code.generate')): ?>
              <a class="submenu-link <?= admin_nav_active(['code_new.php']) ?>" href="/admin/code_new.php"><span class="dot"></span><span class="nav-text">Generate Code</span></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (can('exams.manage') || can('quiz.manage')): ?>
        <?php $examPages = ['exams.php', 'exam_new_enbl.php', 'exam_edit_enbl.php', 'r2_manager.php']; ?>
        <div class="submenu-wrap">
          <button class="menu-toggle <?= admin_nav_active($examPages) ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuExams" aria-expanded="<?= admin_nav_expanded($examPages) ?>">
            <i class="bi bi-journal-text"></i>
            <span class="nav-text">Dumps</span>
          </button>
          <div class="collapse submenu <?= admin_nav_show($examPages) ?>" id="menuExams">
            <?php if (can('exams.manage')): ?>
              <a class="submenu-link <?= admin_nav_active(['exams.php']) ?>" href="/admin/exams.php"><span class="dot"></span><span class="nav-text">All Dumps</span></a>
              <a class="submenu-link <?= admin_nav_active(['exam_new_enbl.php', 'exam_edit_enbl.php']) ?>" href="/admin/exam_new_enbl.php"><span class="dot"></span><span class="nav-text">New Dumps</span></a>
              <a class="submenu-link <?= admin_nav_active(['r2_manager.php']) ?>" href="/admin/r2_manager.php"><span class="dot"></span><span class="nav-text">R2 Manager</span></a>
            <?php endif; ?>
          </div>
        </div>

        <a class="nav-link <?= admin_nav_active(['uploads.php', 'media_list.php', 'media_upload.php', 'media_delete.php', 'media_get.php', 'media_update.php', 'media_crop.php']) ?>" href="/admin/uploads.php">
          <i class="bi bi-images"></i>
          <span class="nav-text">Uploads</span>
        </a>
      <?php endif; ?>

      <?php if (can('audit.view')): ?>
        <a class="nav-link <?= admin_nav_active(['audit.php']) ?>" href="/admin/audit.php">
          <i class="bi bi-clock-history"></i>
          <span class="nav-text">Audit Log</span>
        </a>
      <?php endif; ?>

      <?php if (can('users.manage')): ?>
        <a class="nav-link <?= admin_nav_active(['users.php', 'user_new.php', 'user_edit.php']) ?>" href="/admin/users.php">
          <i class="bi bi-people-fill"></i>
          <span class="nav-text">Users</span>
        </a>
      <?php endif; ?>

      <?php if (can('roles.manage')): ?>
        <a class="nav-link <?= admin_nav_active(['roles.php']) ?>" href="/admin/roles.php">
          <i class="bi bi-shield-lock-fill"></i>
          <span class="nav-text">User Roles</span>
        </a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
  <main class="admin-content">
