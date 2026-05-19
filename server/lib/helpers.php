<?php
require_once __DIR__ . '/../config.php';

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function gen_code(): string {
  // TTC-XXXX-XXXX (hex)
  $a = strtoupper(bin2hex(random_bytes(2)));
  $b = strtoupper(bin2hex(random_bytes(2)));
  return CODE_PREFIX . "-$a-$b";
}

function parse_days_to_expires_at(int $days): ?string {
  if ($days <= 0) return null;
  $dt = new DateTime('now', new DateTimeZone('UTC'));
  $dt->modify("+$days days");
  return $dt->format('Y-m-d H:i:s');
}

function parse_datetime_local_to_utc(?string $local): ?string {
  // Accepts: "YYYY-MM-DDTHH:MM" from HTML datetime-local
  $local = trim((string)$local);
  if ($local === '') return null;

  $tzLocal = new DateTimeZone(date_default_timezone_get());
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tzLocal);
  if (!$dt) return null;
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Y-m-d H:i:s');
}
