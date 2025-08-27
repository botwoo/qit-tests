<?php

namespace QitTests\Services;

use QitTests\ResultsParser;
use QitTests\App;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

/**
 * Service for handling QIT requests and notifications.
 */
class QitRequest {
    private const API_ENDPOINT = 'https://qit.woo.com/wp-json/cd/v1/environment';
    private const TIMEOUT = 10;

    /**
     * Fetch environment data from QIT API.
     *
     * @return array
     * @throws Exception
     */
    public function fetch_environment_data(): array {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => self::API_ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: QIT-Tests/1.0'
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        if ($response === false || !empty($curl_error)) {
            $error_details = [
                'endpoint' => self::API_ENDPOINT,
                'curl_error' => $curl_error,
                'http_code' => $http_code
            ];
            
            throw new Exception('Failed to fetch environment data from QIT API: ' . json_encode($error_details, JSON_PRETTY_PRINT));
        }
        
        if ($http_code >= 400) {
            $error_details = [
                'endpoint' => self::API_ENDPOINT,
                'http_code' => $http_code,
                'response_body' => $response
            ];
            
            throw new Exception('QIT API request failed with HTTP ' . $http_code . ': ' . json_encode($error_details, JSON_PRETTY_PRINT));
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from QIT API: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Get available PHP versions.
     *
     * @return array
     * @throws Exception
     */
    public function get_php_versions(): array {
        $data = $this->fetch_environment_data();
        
        if (!isset($data['php_versions']) || !is_array($data['php_versions'])) {
            throw new Exception('PHP versions not found in QIT API response');
        }

        return array_keys($data['php_versions']);
    }

    /**
     * Get two random PHP versions for testing.
     *
     * @return array Array with 'primary' and 'secondary' PHP versions
     * @throws Exception
     */
    public function get_random_php_versions(): array {
        $php_versions = $this->get_php_versions();
        
        if (count($php_versions) < 2) {
            throw new Exception('At least 2 PHP versions are required for testing');
        }

        // Shuffle the array to randomize
        shuffle($php_versions);
        
        return [
            'primary' => $php_versions[0],
            'secondary' => $php_versions[1]
        ];
    }

    /**
     * Get available WooCommerce versions.
     *
     * @return array
     * @throws Exception
     */
    public function get_woocommerce_versions(): array {
        $data = $this->fetch_environment_data();
        
        if (!isset($data['woocommerce_versions']) || !is_array($data['woocommerce_versions'])) {
            throw new Exception('WooCommerce versions not found in QIT API response');
        }

        return $data['woocommerce_versions'];
    }

    /**
     * Get available WordPress versions.
     *
     * @return array
     * @throws Exception
     */
    public function get_wordpress_versions(): array {
        $data = $this->fetch_environment_data();
        
        if (!isset($data['wordpress_versions']) || !is_array($data['wordpress_versions'])) {
            throw new Exception('WordPress versions not found in QIT API response');
        }

        return $data['wordpress_versions'];
    }

    /**
     * Get available features.
     *
     * @return array
     * @throws Exception
     */
    public function get_features(): array {
        $data = $this->fetch_environment_data();
        
        if (!isset($data['features']) || !is_array($data['features'])) {
            return [];
        }

        return $data['features'];
    }

    /**
     * Get QIT webhook URL from environment variable.
     *
     * @return string|null
     */
    public function get_qit_webhook_url(): ?string {
        return getenv('QIT_WEBHOOK_URL') ?: null;
    }

    /**
     * Send QIT notification with test results.
     *
     * @param string $webhook_url
     * @param array $test_results
     * @param array $failures
     * @param SymfonyStyle $io
     * @throws Exception
     */
    public function send_qit_notification(string $webhook_url, array $test_results, array $failures, SymfonyStyle $io): void {
        $group_id = $test_results['group_identifier'] ?? 'Unknown';
        $total_tests = count($test_results['test_runs']);
        $failed_count = count($failures);

        // Extract all plugins from failed tests
        $all_plugins = [];
        $parser = App::make(ResultsParser::class);
        
        foreach ($failures as $failure) {
            $plugin_info = $parser->extract_plugin_info_from_failed_test($failure);
            if ($plugin_info && !empty($plugin_info['plugin_slugs'])) {
                $all_plugins = array_merge($all_plugins, $plugin_info['plugin_slugs']);
            }
        }
        
        $unique_plugins = array_values(array_unique($all_plugins));

        // Build message blocks without plugin information
        $test_details = $this->build_test_details($group_id, $total_tests, $failed_count, $failures);

        // Get CI secret for authentication
        $ci_secret = getenv('CI_SECRET');
        if (!$ci_secret) {
            throw new Exception('CI_SECRET environment variable is required for QIT authentication');
        }

        $payload = json_encode([
            'test_details' => [$test_details],
            'plugins'      => $unique_plugins,
            'ci_secret'    => $ci_secret
        ]);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $webhook_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        if ($response === false || !empty($curl_error)) {
            $error_details = [
                'webhook_url' => $webhook_url,
                'payload_size' => strlen($payload),
                'curl_error' => $curl_error,
                'http_code' => $http_code
            ];
            
            throw new Exception('Failed to send QIT notification: ' . json_encode($error_details, JSON_PRETTY_PRINT));
        }
        
        if ($http_code >= 400) {
            $error_details = [
                'webhook_url' => $webhook_url,
                'http_code' => $http_code,
                'response_body' => $response
            ];
            
            throw new Exception('QIT notification failed with HTTP ' . $http_code . ': ' . json_encode($error_details, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Build QIT message blocks for test failures.
     *
     * @param string $group_id
     * @param int $total_tests
     * @param int $failed_count
     * @param array $failures
     * @return array
     */
    private function build_test_details(string $group_id, int $total_tests, int $failed_count, array $failures): array {
        // Get basic info from first failure for environment details
        $first_failure = $failures[0] ?? [];
        $parser = App::make(ResultsParser::class);
        $plugin_info = $parser->extract_plugin_info_from_failed_test($first_failure);
        
        if ($plugin_info) {
            $wordpress_version = $plugin_info['wordpress_version'];
            $woocommerce_version = $plugin_info['woocommerce_version'];
            $php_version = $plugin_info['php_version'];
            $status = $plugin_info['status'];
            $manager_url = $plugin_info['test_results_manager_url'];
        } else {
            $wordpress_version = $first_failure['wordpress_version'] ?? 'Unknown';
            $woocommerce_version = $first_failure['woocommerce_version'] ?? 'Unknown';
            $php_version = $first_failure['php_version'] ?? 'Unknown';
            $status = $first_failure['status'] ?? 'failed';
            $manager_url = $first_failure['test_results_manager_url'] ?? '';
        }

        $message = sprintf(
            "QIT test failures detected: %d of %d tests failed\n*Status:* %s\n*Environment:* WP %s, WC %s, PHP %s",
            $failed_count,
            $total_tests,
            $status,
            $wordpress_version,
            $woocommerce_version,
            $php_version
        );

        return  [
            'failed_count'        => $failed_count,
            'total_tests'         => $total_tests,
            'status'              => $status,
            'wordpress_version'   => $wordpress_version,
            'woocommerce_version' => $woocommerce_version,
            'php_version'         => $php_version,
            'manager_url'         => empty($manager_url) ? '' : $manager_url
        ];
    }
}