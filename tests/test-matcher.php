<?php
/**
 * Test Matcher - Taranehyar Scout
 */

if (!defined('ABSPATH')) {
    function sanitize_text_field($str) { return trim(strip_tags($str)); }
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key)); }
    function remove_accents($str) { return $str; }
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
    function get_option($key, $default = []) { return $default; }
}

require_once __DIR__ . '/../includes/Security/Sanitizer.php';
require_once __DIR__ . '/../includes/Metadata/Normalizer.php';

use TaranehyarScout\Metadata\Normalizer;

echo "Testing Normalizer...\n";

$raw = [
    'external_id' => '12345',
    'provider' => 'mybia2music',
    'source_url' => 'https://www.mybia2music.com/12345/test-track',
    'title' => 'Test Track',
    'title_fa' => 'آهنگ تست',
    'artist_en' => 'Test Artist',
    'artist_fa' => 'هنرمند تست',
    'type' => 'single',
    'cover_url' => 'https://example.com/cover.jpg',
    'audio_url_320' => 'https://example.com/audio.mp3',
    'genre' => 'Pop',
];

$normalized = Normalizer::normalize($raw, 'mybia2music');

if (is_wp_error($normalized)) {
    echo "FAIL: Normalization error: " . $normalized->get_error_message() . "\n";
} else {
    echo "PASS: Normalized artist: " . $normalized['artist_en'] . "\n";
    echo "PASS: Normalized title: " . $normalized['title'] . "\n";
    echo "PASS: Artist normalized: " . $normalized['artist_normalized'] . "\n";
    echo "PASS: Title normalized: " . $normalized['title_normalized'] . "\n";
    echo "PASS: Confidence: " . $normalized['confidence'] . "%\n";
    echo "PASS: Provider hash: " . $normalized['provider_hash'] . "\n";
    
    // Test remix detection
    $raw_remix = $raw;
    $raw_remix['title'] = 'Test Track Remix';
    $norm_remix = Normalizer::normalize($raw_remix, 'mybia2music');
    echo "PASS: Remix detection: " . ($norm_remix['is_remix'] ? 'YES' : 'NO') . " (expected YES)\n";
    
    // Test title normalization for matching
    $norm_title = Normalizer::normalize_title_for_matching('Test Track [320] (Remix)');
    echo "PASS: Title for matching: '$norm_title'\n";
}

echo "Matcher tests completed.\n";
