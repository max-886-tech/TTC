<?php
require_once __DIR__ . '/../config.php';
json_out(['ok' => false, 'error' => 'disabled', 'message' => 'Browser-based quiz/exam is disabled. TrueCerts supports .ttc dumps files only.'], 410);
