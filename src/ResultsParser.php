<?php

namespace QitTests;

use Exception;

/**
 * Parser for QIT test results to extract plugin-specific information using regex patterns.
 */
class ResultsParser {
    
    /**
     * Process all test runs and create a map of failed tests with plugin information.
     * Returns one mapping per test run, regardless of number of errors.
     *
     * @param array $test_results The complete test results data
     * @return array Map of failed test runs with plugin information
     */
    public function map_failed_tests_with_plugins(array $test_results): array {
        $failed_test_map = [];
        
        if (!isset($test_results['test_runs']) || !is_array($test_results['test_runs'])) {
            return $failed_test_map;
        }

        foreach ($test_results['test_runs'] as $test_run) {
            // Only process failed test runs
            if (!isset($test_run['status']) || $test_run['status'] === 'success') {
                continue;
            }

            $plugin_info = $this->extract_plugin_info_from_failed_test($test_run);
            
            if ($plugin_info) {
                $test_run_id = $plugin_info['test_run_id'];
                $failed_test_map[$test_run_id] = $plugin_info;
            }
        }

        return $failed_test_map;
    }

    /**
     * Extract plugin information from a failed test run.
     * Returns comprehensive test run data with unique plugin slugs.
     * Omits query-monitor related entries.
     *
     * @param array $test_run
     * @return array|null Test run information with plugin slugs, or null if no plugins found
     */
    public function extract_plugin_info_from_failed_test(array $test_run): ?array {
        $plugin_slugs = $this->extract_plugin_slugs_from_test_run($test_run);
        
        if (empty($plugin_slugs)) {
            return null;
        }

        return [
            'test_run_id' => $test_run['test_run_id'] ?? 'unknown',
            'status' => $test_run['status'] ?? 'unknown',
            'test_results_manager_url' => $test_run['test_results_manager_url'] ?? '',
            'test_type' => $test_run['test_type'] ?? 'unknown',
            'test_type_display' => $test_run['test_type_display'] ?? $test_run['test_type'] ?? 'unknown',
            'wordpress_version' => $test_run['wordpress_version'] ?? 'unknown',
            'woocommerce_version' => $test_run['woocommerce_version'] ?? 'unknown',
            'php_version' => $test_run['php_version'] ?? 'unknown',
            'plugin_slugs' => array_values(array_unique($plugin_slugs)) // Ensure unique slugs only
        ];
    }

    /**
     * Extract unique plugin slugs from a test run (internal method).
     * Omits query-monitor related entries.
     *
     * @param array $test_run
     * @return array Array of unique plugin slugs
     */
    private function extract_plugin_slugs_from_test_run(array $test_run): array {
        $plugins = [];
        $log_sources = [];

        // Collect all log content from different sources
        if (!empty($test_run['debug_log'])) {
            $log_sources[] = $this->parse_json_log($test_run['debug_log']);
        }

        if (!empty($test_run['test_log'])) {
            $log_sources[] = $test_run['test_log'];
        }

        if (!empty($test_run['deprecation_warnings']) && is_array($test_run['deprecation_warnings'])) {
            $log_sources[] = implode("\n", $test_run['deprecation_warnings']);
        }

        if (!empty($test_run['attachments'])) {
            foreach ($test_run['attachments'] as $attachment) {
                if (isset($attachment['data']['PHP Debug Log']) && is_array($attachment['data']['PHP Debug Log'])) {
                    $log_sources[] = implode("\n", $attachment['data']['PHP Debug Log']);
                }
                if (isset($attachment['data']['JavaScript Console Log']) && is_array($attachment['data']['JavaScript Console Log'])) {
                    $log_sources[] = implode("\n", $attachment['data']['JavaScript Console Log']);
                }
            }
        }

        // Process each log source with regex patterns
        foreach ($log_sources as $log_content) {
            if (empty($log_content)) {
                continue;
            }

            $plugins = array_merge($plugins, $this->extract_plugins_with_regex($log_content));
        }

        // Remove query-monitor and return unique plugins
        return array_filter(array_unique($plugins), function($plugin) {
            return $plugin !== 'query-monitor';
        });
    }

    /**
     * Legacy method for backward compatibility - kept for existing usage
     * @deprecated Use extract_plugin_info_from_failed_test() instead
     */
    public function extract_plugin_slugs_from_failed_test(array $test_run): array {
        return $this->extract_plugin_slugs_from_test_run($test_run);
    }

    /**
     * Parse JSON-encoded log content to make it readable.
     *
     * @param string $json_log
     * @return string
     */
    private function parse_json_log(string $json_log): string {
        $decoded = json_decode($json_log, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json_log; // Return as-is if not valid JSON
        }

        $readable_content = '';
        
        // Handle nested debug_log structure
        if (isset($decoded['debug_log'])) {
            $debug_data = json_decode($decoded['debug_log'], true);
            if (is_array($debug_data)) {
                foreach ($debug_data as $entry) {
                    if (isset($entry['message'])) {
                        $readable_content .= $entry['message'] . "\n";
                    }
                }
            }
        }

        return $readable_content;
    }

    /**
     * Extract plugin slugs using sequential regex patterns.
     *
     * @param string $log_content
     * @return array
     */
    private function extract_plugins_with_regex(string $log_content): array {
        $plugins = [];

        // Pattern 1: File paths - wp-content/plugins/plugin-name/
        if (preg_match_all('/wp-content[\/\\\\]+plugins[\/\\\\]+([^\/\\\\]+)[\/\\\\]/', $log_content, $matches)) {
            $plugins = array_merge($plugins, $matches[1]);
        }

        // Pattern 2: Plugin installation URLs - downloads.wordpress.org/plugin/plugin-name.version.zip
        if (preg_match_all('/downloads\.wordpress\.org[\/\\\\]+plugin[\/\\\\]+([^\.\/\\\\]+)\.[\d\.]+\.zip/', $log_content, $matches)) {
            $plugins = array_merge($plugins, $matches[1]);
        }

        // Pattern 3: Plugin domains in text - Translation loading for the <code>plugin-name</code>
        if (preg_match_all('/<code>([^<]+)<\/code>/', $log_content, $matches)) {
            $plugins = array_merge($plugins, $matches[1]);
        }

        // Pattern 4: Plugin activation messages - Activating 'plugin-name'
        if (preg_match_all('/Activating \'([^\']+)\'/', $log_content, $matches)) {
            $plugins = array_merge($plugins, $matches[1]);
        }

        // Pattern 5: Plugin script dependencies - extension-plugin-name-script
        if (preg_match_all('/extension-([^-]+(?:-[^-]+)*)-(?:editor-)?script/', $log_content, $matches)) {
            $plugins = array_merge($plugins, $matches[1]);
        }

        // Pattern 6: Plugin file extensions - plugin-name.php
        if (preg_match_all('/([a-zA-Z0-9\-_]+)\.php/', $log_content, $matches)) {
            // Filter to only include likely plugin files (not WordPress core files)
            foreach ($matches[1] as $match) {
                if (!in_array($match, ['index', 'wp-config', 'functions', 'wp-settings', 'wp-load'])) {
                    $plugins[] = $match;
                }
            }
        }

        // Clean up plugin names (remove version numbers, normalize)
        return array_map(function($plugin) {
            // Remove version numbers and normalize
            $plugin = preg_replace('/\.\d+.*$/', '', $plugin);
            $plugin = trim($plugin);
            return $plugin;
        }, array_filter($plugins, function($plugin) {
            // Filter out empty strings and WordPress core references
            return !empty($plugin) && 
                   !in_array($plugin, ['wp', 'wordpress', 'wp-content', 'plugins', 'themes']);
        }));
    }
}