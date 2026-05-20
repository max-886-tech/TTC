<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
$me = require_admin();
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
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="/admin/"><?= h(APP_NAME) ?></a>

    <div class="navbar-nav me-auto">
      <a class="nav-link" href="/admin/">Codes</a>
      <a class="nav-link" href="/admin/audit.php">Audit Log</a>
      <a class="nav-link" href="/admin/users.php">Users</a>
    </div>

    <div class="d-flex align-items-center gap-2">
      <span class="text-white-50 small">Logged in as <?= h($me['username']) ?></span>
      <a class="btn btn-sm btn-outline-light" href="/logout.php">Logout</a>
    </div>
  </div>
</nav>
<main class="container-full p-5">
