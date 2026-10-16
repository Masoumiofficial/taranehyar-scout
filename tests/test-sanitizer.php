<?php
/**
 * Test Sanitizer - Taranehyar Scout
 * توسعه‌دهنده: اتحاد وردپرس
 */

if (!defined('ABSPATH')) {
    // Mock WordPress functions for standalone testing
    function sanitize_text_field($str) { return trim(strip_tags($str)); }
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key)); }
    function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }
    function remove_accents($str) { return $str; }
}

require_once __DIR__ . '/../includes/Security/Sanitizer.php';

use TaranehyarScout\Security\Sanitizer;

echo "Testing Sanitizer...\n";

// Test normalize_string
$tests = [
    ['input' => 'Mohsen Yeganeh', 'expected' => 'mohsen yeganeh'],
    ['input' => 'Test - Track (Remix)', 'expected' => 'test track remix'],
    ['input' => '  Multiple   Spaces  ', 'expected' => 'multiple spaces'],
];

foreach ($tests as $test) {
    $result = Sanitizer::normalize_string($test['input']);
    $status = $result === $test['expected'] ? 'PASS' : 'FAIL';
    echo "[$status] normalize_string('{$test['input']}') = '$result' (expected: '{$test['expected']}')\n";
}

// Test sanitize_filename
$filename_tests = [
    ['input' => '../etc/passwd', 'should_not_contain' => '..'],
    ['input' => 'test/file\\name', 'should_not_contain' => '/'],
];

foreach ($filename_tests as $test) {
    $result = Sanitizer::sanitize_filename($test['input']);
    $status = strpos($result, $test['should_not_contain']) === false ? 'PASS' : 'FAIL';
    echo "[$status] sanitize_filename('{$test['input']}') = '$result' (should not contain '{$test['should_not_contain']}')\n";
}

echo "Sanitizer tests completed.\n";
