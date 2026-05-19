<?php
require_once __DIR__ . '/db.php';

/**
 * RBAC (Role-Based Access Control)
 *
 * Supports:
 *  - Multiple roles per user (user_roles)
 *  - Many permissions per role (role_permissions)
 *
 * Backward compatibility:
 *  - If RBAC tables are missing, falls back to admin_users.role ("admin"/"viewer").
 */

function rbac_enabled(PDO $pdo): bool {
  return db_has_table($pdo, 'roles')
    && db_has_table($pdo, 'permissions')
    && db_has_table($pdo, 'user_roles')
    && db_has_table($pdo, 'role_permissions');
}

/**
 * Default permissions list used by UI + fallback mode.
 * Add more permission codes as your app grows.
 */
function rbac_all_permissions(): array {
  return [
    'codes.view',
    // Codes module (split by action)
    'code.generate',
    'code.edit',
    'code.reset',
    'code.delete',
    'exams.manage',
    'quiz.manage',
    'audit.view',
    'users.manage',
    'roles.manage',
    'attempts.view',
    'user.attempt_exam',
  ];
}

/**
 * In fallback mode (no RBAC tables), map legacy roles to permission codes.
 */
function rbac_fallback_role_perms(string $legacy_role): array {
  $legacy_role = strtolower(trim($legacy_role));
  if ($legacy_role === 'admin') return rbac_all_permissions();
  // "viewer" gets read-only codes.
  return ['codes.view'];
}

function rbac_user_roles(PDO $pdo, int $user_id, string $legacy_role = ''): array {
  if (!rbac_enabled($pdo)) {
    return [$legacy_role ?: 'viewer'];
  }

  $stmt = $pdo->prepare(
    "SELECT r.name
       FROM user_roles ur
       JOIN roles r ON r.id = ur.role_id
      WHERE ur.user_id = ?
      ORDER BY r.name"
  );
  $stmt->execute([$user_id]);
  $roles = array_values(array_filter(array_map(fn($x) => $x['name'] ?? '', $stmt->fetchAll())));
  return $roles ?: [$legacy_role ?: 'viewer'];
}

function rbac_user_permissions(PDO $pdo, int $user_id, string $legacy_role = ''): array {
  if (!rbac_enabled($pdo)) {
    return rbac_fallback_role_perms($legacy_role);
  }

  $stmt = $pdo->prepare(
    "SELECT DISTINCT p.code
       FROM user_roles ur
       JOIN role_permissions rp ON rp.role_id = ur.role_id
       JOIN permissions p ON p.id = rp.permission_id
      WHERE ur.user_id = ?
      ORDER BY p.code"
  );
  $stmt->execute([$user_id]);
  return array_values(array_filter(array_map(fn($x) => $x['code'] ?? '', $stmt->fetchAll())));
}

function rbac_list_roles(PDO $pdo): array {
  if (!rbac_enabled($pdo)) {
    return [
      ['name' => 'admin', 'description' => 'Legacy admin (all permissions)'],
      ['name' => 'viewer', 'description' => 'Legacy viewer (read-only)'],
    ];
  }
  $stmt = $pdo->query("SELECT id, name, description FROM roles ORDER BY name");
  return $stmt->fetchAll();
}

function rbac_has_permission(array $user, string $perm): bool {
  $perm = trim($perm);
  if ($perm === '') return false;
  $perms = $user['permissions'] ?? [];
  if (!is_array($perms)) return false;
  // Superuser shortcut: admins can do everything if you keep legacy role.
  if (($user['role'] ?? '') === 'admin') return true;
  return in_array($perm, $perms, true);
}
