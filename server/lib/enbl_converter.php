<?php
require_once __DIR__ . '/../config.php';

if (!defined('TTC_CONTENT_PASSWORD')) define('TTC_CONTENT_PASSWORD', (string) enbl_env('TTC_CONTENT_PASSWORD', '-ddG^|cE;8+Yp8&&'));
if (!defined('TTC_PBKDF2_ITERATIONS')) define('TTC_PBKDF2_ITERATIONS', (int) enbl_env('TTC_PBKDF2_ITERATIONS', 100000));
if (!defined('TTC_MAX_METADATA_BYTES')) define('TTC_MAX_METADATA_BYTES', (int) enbl_env('TTC_MAX_METADATA_BYTES', 65536));
if (!defined('TTC_MAX_PAYLOAD_BYTES')) define('TTC_MAX_PAYLOAD_BYTES', (int) enbl_env('TTC_MAX_PAYLOAD_BYTES', 524288000));
if (!defined('TTC_FILE_MAGIC')) define('TTC_FILE_MAGIC', (string) enbl_env('TTC_FILE_MAGIC', 'TTCF')); // must be exactly 4 bytes

function enbl_random_hex(int $bytes = 16): string {
  return bin2hex(random_bytes($bytes));
}

function enbl_json_flags(): int {
  return JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
}

function enbl_pack_u64le(int $value): string {
  $low = $value & 0xFFFFFFFF;
  $high = ($value >> 32) & 0xFFFFFFFF;
  return pack('V2', $low, $high);
}

function enbl_build_metadata(array $input): string {
  $meta = [
    'schema' => 2,
    'examId' => (string)($input['examId'] ?? ''),
    'fileId' => (string)($input['fileId'] ?? enbl_random_hex(16)),
    'user' => (string)($input['user'] ?? ''),
    'expiryAbs' => (string)($input['expiryAbs'] ?? ''),
    'expiryRelHours' => max(0, (int)($input['expiryRelHours'] ?? 0)),
    'watermark' => array_values(array_filter(array_map('strval', (array)($input['watermark'] ?? [])), static fn($v) => trim($v) !== '')),
  ];
  $json = json_encode($meta, enbl_json_flags());
  if (!is_string($json) || $json === '') {
    throw new Exception('Failed to encode TTC metadata JSON.');
  }
  if (strlen($json) > TTC_MAX_METADATA_BYTES) {
    throw new Exception('Metadata is too large for TTC format.');
  }
  return $json;
}

function enbl_create_from_pdf_bytes(string $pdfBytes, array $metaInput): array {
  if ($pdfBytes === '') {
    throw new Exception('PDF data is empty.');
  }
  if (strlen($pdfBytes) > TTC_MAX_PAYLOAD_BYTES) {
    throw new Exception('PDF exceeds TTC payload size limit.');
  }

  $metadataJson = enbl_build_metadata($metaInput);
  $metadataSize = strlen($metadataJson);
  $payloadSize = strlen($pdfBytes);

  $salt = random_bytes(16);
  $nonce = random_bytes(12);
  $magic = substr((string)TTC_FILE_MAGIC, 0, 4);
  if (strlen($magic) !== 4) {
    throw new Exception('TTC_FILE_MAGIC must be exactly 4 bytes.');
  }

  $header = $magic
    . pack('v', 1)
    . pack('v', 0)
    . $salt
    . $nonce
    . pack('V', $metadataSize)
    . enbl_pack_u64le($payloadSize);

  $aad = $header . $metadataJson;
  $key = hash_pbkdf2('sha256', TTC_CONTENT_PASSWORD, $salt, TTC_PBKDF2_ITERATIONS, 32, true);
  if (!is_string($key) || strlen($key) !== 32) {
    throw new Exception('Failed to derive TTC encryption key.');
  }

  $tag = '';
  $ciphertext = openssl_encrypt($pdfBytes, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
  if (!is_string($ciphertext) || strlen($tag) !== 16) {
    throw new Exception('Failed to encrypt TTC payload.');
  }

  return [
    'file_id' => json_decode($metadataJson, true)['fileId'] ?? '',
    'metadata_json' => $metadataJson,
    'binary' => $header . $metadataJson . $ciphertext . $tag,
    'size' => strlen($header) + $metadataSize + strlen($ciphertext) + strlen($tag),
  ];
}
