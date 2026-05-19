<?php
/**
 * Cloudflare R2 helpers (S3-compatible) for:
 * - Listing objects (admin dropdown)
 * - Creating presigned GET URLs (download from EXE/web)
 *
 * Required config constants (or env vars with same names):
 * - R2_BUCKET
 * - R2_ENDPOINT      e.g. https://<accountid>.r2.cloudflarestorage.com
 * - R2_ACCESS_KEY
 * - R2_SECRET_KEY
 * Optional:
 * - R2_REGION        default: auto
 * - R2_PUBLIC_BASE   e.g. https://thetruecerts.com
 */

require_once __DIR__ . '/../config.php';

function r2_config(string $key, $default = null) {
  if (defined($key)) return constant($key);
  $env = getenv($key);
  if ($env !== false && $env !== '') return $env;
  return $default;
}

function r2_required(string $key): string {
  $v = (string)r2_config($key, '');
  if ($v === '') throw new Exception("Missing {$key} in config/env");
  return $v;
}

function r2_normalize_prefix(string $prefix): string {
  $prefix = trim($prefix);
  $prefix = preg_replace('#^/+|/+$#', '', $prefix);
  return $prefix;
}

function r2_public_url(string $key): string {
  $base = (string)r2_config('R2_PUBLIC_BASE', '');
  if ($base === '') return '';
  return rtrim($base, '/') . '/' . ltrim($key, '/');
}

// -------------------------
// SigV4 helpers
// -------------------------

function r2_hmac(string $key, string $msg, bool $raw = true) {
  return hash_hmac('sha256', $msg, $key, $raw);
}

function r2_sig_key(string $secretKey, string $date, string $region, string $service): string {
  $kDate = r2_hmac('AWS4' . $secretKey, $date);
  $kRegion = r2_hmac($kDate, $region);
  $kService = r2_hmac($kRegion, $service);
  return r2_hmac($kService, 'aws4_request');
}

function r2_canonical_query(array $query): string {
  ksort($query);
  return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

// -------------------------
// R2: List Objects
// -------------------------

/**
 * List objects (ListObjectsV2).
 * @return array<int, array{key:string,size:int,last_modified:string}>
 */
function r2_list_objects(string $prefix = '', int $maxKeys = 500): array {
  $bucket    = r2_required('R2_BUCKET');
  $endpoint  = r2_required('R2_ENDPOINT');
  $accessKey = r2_required('R2_ACCESS_KEY');
  $secretKey = r2_required('R2_SECRET_KEY');
  $region    = (string)r2_config('R2_REGION', 'auto');

  $endpoint = rtrim($endpoint, '/');
  $host = parse_url($endpoint, PHP_URL_HOST);
  if (!$host) throw new Exception('Invalid R2_ENDPOINT');

  $method  = 'GET';
  $service = 's3';
  $amzDate = gmdate('Ymd\THis\Z');
  $date    = gmdate('Ymd');

  // Path-style: /<bucket>
  $uriPath = '/' . rawurlencode($bucket);

  $query = [
    'list-type' => '2',
    'max-keys'  => (string)max(1, min(1000, $maxKeys)),
  ];
  $prefix = r2_normalize_prefix($prefix);
  if ($prefix !== '') $query['prefix'] = $prefix . '/';

  $payloadHash = hash('sha256', '');
  $signedHeaders = 'accept;host;x-amz-content-sha256;x-amz-date';
  $canonicalHeaders =
    'accept:application/xml' . "\n" .
    'host:' . $host . "\n" .
    'x-amz-content-sha256:' . $payloadHash . "\n" .
    'x-amz-date:' . $amzDate . "\n";

  $canonicalRequest = implode("\n", [
    $method,
    $uriPath,
    r2_canonical_query($query),
    $canonicalHeaders,
    $signedHeaders,
    $payloadHash
  ]);

  $credentialScope = "$date/$region/$service/aws4_request";
  $stringToSign = implode("\n", [
    'AWS4-HMAC-SHA256',
    $amzDate,
    $credentialScope,
    hash('sha256', $canonicalRequest)
  ]);

  $signingKey = r2_sig_key($secretKey, $date, $region, $service);
  $signature = hash_hmac('sha256', $stringToSign, $signingKey);
  $auth = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

  $url = $endpoint . $uriPath . '?' . r2_canonical_query($query);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Accept: application/xml',
      'Host: ' . $host,
      'x-amz-content-sha256: ' . $payloadHash,
      'x-amz-date: ' . $amzDate,
      'Authorization: ' . $auth,
    ],
    CURLOPT_TIMEOUT => 25,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) throw new Exception('R2 list failed: ' . $err);
  if ($code < 200 || $code >= 300) throw new Exception('R2 list HTTP ' . $code . ': ' . substr($resp, 0, 300));

  $xml = @simplexml_load_string($resp);
  if (!$xml) throw new Exception('Invalid XML from R2 list');

  $items = [];
  if (isset($xml->Contents)) {
    foreach ($xml->Contents as $c) {
      $key  = (string)$c->Key;
      $size = (int)$c->Size;
      $last = (string)$c->LastModified;
      if ($key !== '' && substr($key, -1) !== '/') {
        $items[] = ['key' => $key, 'size' => $size, 'last_modified' => $last];
      }
    }
  }

  usort($items, fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));
  return $items;
}

// -------------------------
// R2: Presigned GET (download)
// -------------------------

function r2_presign_get_url(string $key, int $expiresSeconds = 600): string {
  $bucket    = r2_required('R2_BUCKET');
  $endpoint  = r2_required('R2_ENDPOINT');
  $accessKey = r2_required('R2_ACCESS_KEY');
  $secretKey = r2_required('R2_SECRET_KEY');
  $region    = (string)r2_config('R2_REGION', 'auto');

  $expiresSeconds = max(60, min(86400, (int)$expiresSeconds));

  $endpoint = rtrim($endpoint, '/');
  $host = parse_url($endpoint, PHP_URL_HOST);
  if (!$host) throw new Exception('Invalid R2_ENDPOINT');

  $service = 's3';
  $method  = 'GET';
  $amzDate = gmdate('Ymd\THis\Z');
  $date    = gmdate('Ymd');

  // /bucket/key (path-style)
  $uriPath = '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/')));

  $credentialScope = "$date/$region/$service/aws4_request";
  $query = [
    'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
    'X-Amz-Credential' => $accessKey . '/' . $credentialScope,
    'X-Amz-Date' => $amzDate,
    'X-Amz-Expires' => (string)$expiresSeconds,
    'X-Amz-SignedHeaders' => 'host',
  ];

  $canonicalRequest = implode("\n", [
    $method,
    $uriPath,
    r2_canonical_query($query),
    'host:' . $host . "\n",
    'host',
    'UNSIGNED-PAYLOAD'
  ]);

  $stringToSign = implode("\n", [
    'AWS4-HMAC-SHA256',
    $amzDate,
    $credentialScope,
    hash('sha256', $canonicalRequest)
  ]);

  $signingKey = r2_sig_key($secretKey, $date, $region, $service);
  $signature = hash_hmac('sha256', $stringToSign, $signingKey);
  $query['X-Amz-Signature'] = $signature;

  return $endpoint . $uriPath . '?' . r2_canonical_query($query);
}


function r2_object_uri_path(string $bucket, string $key): string {
  return '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/')));
}

function r2_signed_request(string $method, string $uriPath, array $query = [], string $body = '', array $extraHeaders = []): array {
  $endpoint  = r2_required('R2_ENDPOINT');
  $accessKey = r2_required('R2_ACCESS_KEY');
  $secretKey = r2_required('R2_SECRET_KEY');
  $region    = (string)r2_config('R2_REGION', 'auto');

  $endpoint = rtrim($endpoint, '/');
  $host = parse_url($endpoint, PHP_URL_HOST);
  if (!$host) throw new Exception('Invalid R2_ENDPOINT');

  $service = 's3';
  $amzDate = gmdate('Ymd\THis\Z');
  $date    = gmdate('Ymd');
  $payloadHash = hash('sha256', $body);

  $headers = [
    'host' => $host,
    'x-amz-content-sha256' => $payloadHash,
    'x-amz-date' => $amzDate,
  ];
  foreach ($extraHeaders as $name => $value) {
    $headers[strtolower($name)] = trim((string)$value);
  }
  ksort($headers);

  $signedHeaders = implode(';', array_keys($headers));
  $canonicalHeaders = '';
  foreach ($headers as $name => $value) {
    $canonicalHeaders .= $name . ':' . preg_replace('/\s+/', ' ', $value) . "\n";
  }

  $canonicalRequest = implode("\n", [
    strtoupper($method),
    $uriPath,
    r2_canonical_query($query),
    $canonicalHeaders,
    $signedHeaders,
    $payloadHash,
  ]);

  $credentialScope = "$date/$region/$service/aws4_request";
  $stringToSign = implode("\n", [
    'AWS4-HMAC-SHA256',
    $amzDate,
    $credentialScope,
    hash('sha256', $canonicalRequest),
  ]);

  $signingKey = r2_sig_key($secretKey, $date, $region, $service);
  $signature = hash_hmac('sha256', $stringToSign, $signingKey);
  $auth = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

  $url = $endpoint . $uriPath;
  if ($query) {
    $url .= '?' . r2_canonical_query($query);
  }

  $curlHeaders = [];
  foreach ($headers as $name => $value) {
    $curlHeaders[] = $name . ': ' . $value;
  }
  $curlHeaders[] = 'Authorization: ' . $auth;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => strtoupper($method),
    CURLOPT_HTTPHEADER => $curlHeaders,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HEADER => true,
    CURLOPT_POSTFIELDS => $body,   // always send, even if empty
  ]);

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($resp === false) {
    throw new Exception('R2 request failed: ' . $err);
  }

  return [
    'status' => $code,
    'headers' => substr($resp, 0, $headerSize),
    'body' => substr($resp, $headerSize),
    'url' => $url,
  ];
}

function r2_put_object(string $key, string $body = '', string $contentType = 'application/octet-stream'): void {
  $bucket = r2_required('R2_BUCKET');
  $key = ltrim(trim($key), '/');
  if ($key === '') throw new Exception('R2 key is required');

  $res = r2_signed_request('PUT', r2_object_uri_path($bucket, $key), [], $body, [
    'content-type'   => $contentType,
    'content-length' => (string) strlen($body),
  ]);

  if ($res['status'] < 200 || $res['status'] >= 300) {
    throw new Exception('R2 upload HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
  }
}

function r2_delete_object(string $key): void {
  $bucket = r2_required('R2_BUCKET');
  $key = ltrim(trim($key), '/');
  if ($key === '') throw new Exception('R2 key is required');

  $res = r2_signed_request('DELETE', r2_object_uri_path($bucket, $key));
  if ($res['status'] < 200 || $res['status'] >= 299) {
    throw new Exception('R2 delete HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
  }
}

function r2_create_prefix(string $prefix): string {
  $prefix = r2_normalize_prefix($prefix);
  if ($prefix === '') throw new Exception('Prefix name is required');
  $key = $prefix . '/.keep';
  r2_put_object($key, '', 'application/octet-stream');
  return $key;
}

function r2_group_objects_by_prefix(array $items): array {
  $grouped = [];
  foreach ($items as $f) {
    $k = (string)($f['key'] ?? $f['Key'] ?? '');
    if ($k === '') continue;

    $parts = explode('/', ltrim($k, '/'), 2);
    $prefix = count($parts) > 1 ? trim($parts[0]) : '(root)';
    $label = count($parts) > 1 ? $parts[1] : $parts[0];

    if (!isset($grouped[$prefix])) {
      $grouped[$prefix] = [];
    }
    if ($label === '.keep') {
      continue;
    }
    $grouped[$prefix][] = [
      'key' => $k,
      'label' => $label,
      'size' => (int)($f['size'] ?? 0),
      'last_modified' => (string)($f['last_modified'] ?? ''),
    ];
  }

  ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
  foreach ($grouped as &$group) {
    usort($group, static function ($a, $b) {
      return strcasecmp((string)$a['label'], (string)$b['label']);
    });
  }
  unset($group);

  return $grouped;
}

function r2_list_grouped_objects(string $prefix = '', int $maxKeys = 1000): array {
  return r2_group_objects_by_prefix(r2_list_objects($prefix, $maxKeys));
}

function r2_delete_prefix(string $prefix): int {
  $prefix = r2_normalize_prefix($prefix);
  if ($prefix === '') throw new Exception('Prefix name is required');

  $items = r2_list_objects($prefix, 1000);
  $count = 0;
  foreach ($items as $item) {
    $key = (string)($item['key'] ?? '');
    if ($key === '') continue;
    r2_delete_object($key);
    $count++;
  }
  return $count;
}
