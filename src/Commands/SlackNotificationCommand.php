<?php

namespace QitTests\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

/**
 * Command to send Slack notifications for QIT test failures.
 */
class SlackNotificationCommand extends Command {
    protected static $defaultName = 'slack:notify';
    protected static $defaultDescription = 'Send Slack notification for QIT test failures';

    protected function configure(): void {
        $this
            ->setName( self::$defaultName )
            ->setDescription( self::$defaultDescription )
            ->setHelp('This command reads QIT test results from a JSON file and sends Slack notifications only when there are test failures.')
            ->addArgument('json-file', InputArgument::REQUIRED, 'Path to the JSON file containing test results');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $json_file = $input->getArgument('json-file');

        $io->title('QIT Slack Notifier');

        try {
            // Check if Slack webhook URL is available
            $webhook_url = $this->get_slack_webhook_url();
            if (!$webhook_url) {
                $io->warning('No Slack webhook URL provided. Set SLACK_WEBHOOK_URL environment variable.');
                return Command::SUCCESS;
            }

            // Read and parse the JSON file
            $test_results = $this->read_test_results($json_file);

            // Check for failures
            $failures = $this->check_for_failures($test_results);

            if (empty($failures)) {
                $io->success('All tests passed! No Slack notification needed.');
                return Command::SUCCESS;
            }

            $io->text(sprintf('Found %d failed test run(s). Sending Slack notification...', count($failures)));

            // Send Slack notification
            $this->send_slack_notification($webhook_url, $test_results, $failures, $io);

            $io->success('Slack notification sent successfully.');
            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get Slack webhook URL from environment variables.
     *
     * @return string|null
     */
    private function get_slack_webhook_url(): ?string {
        return getenv('SLACK_WEBHOOK_URL') ?: null;
    }

    /**
     * Read and parse the test results JSON file.
     *
     * @param string $json_file
     * @return array
     * @throws Exception
     */
    private function read_test_results(string $json_file): array {
        if (!file_exists($json_file)) {
            throw new Exception("JSON file not found: {$json_file}");
        }

        $content = file_get_contents($json_file);
        if ($content === false) {
            throw new Exception("Failed to read JSON file: {$json_file}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON: ' . json_last_error_msg());
        }

        if (!is_array($data) || !isset($data['test_runs'])) {
            throw new Exception('Invalid JSON structure: missing test_runs array');
        }

        return $data;
    }

    /**
     * Check for test failures.
     *
     * @param array $test_results
     * @return array Array of failed test runs
     */
    private function check_for_failures(array $test_results): array {
        $failures = [];

        foreach ($test_results['test_runs'] as $test_run) {
            if (isset($test_run['status']) && $test_run['status'] === 'failed') {
                $failures[] = $test_run;
            }
        }

        return $failures;
    }

    /**
     * Send Slack notification for test failures.
     *
     * @param string $webhook_url
     * @param array $test_results
     * @param array $failures
     * @param SymfonyStyle $io
     * @throws Exception
     */
    private function send_slack_notification(string $webhook_url, array $test_results, array $failures, SymfonyStyle $io): void {
        $group_id = $test_results['group_identifier'] ?? 'Unknown';
        $total_tests = count($test_results['test_runs']);
        $failed_count = count($failures);

        $message = $this->build_slack_message($group_id, $total_tests, $failed_count, $failures);

        $payload = json_encode([
            'text' => 'QIT Test Failures Detected',
            'blocks' => $message
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload)
                ],
                'content' => $payload,
                'timeout' => 30
            ]
        ]);

        $response = file_get_contents($webhook_url, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to send Slack notification');
        }

        if ($response !== 'ok') {
            throw new Exception('Slack API returned error: ' . $response);
        }
    }

    /**
     * Build Slack message blocks for test failures.
     *
     * @param string $group_id
     * @param int $total_tests
     * @param int $failed_count
     * @param array $failures
     * @return array
     */
    private function build_slack_message(string $group_id, int $total_tests, int $failed_count, array $failures): array {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '⚠️ QIT Test Failures Detected'
                ]
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*Group ID:* %s\n*Failed Tests:* %d of %d total tests",
                        $group_id,
                        $failed_count,
                        $total_tests
                    )
                ]
            ]
        ];

        // Add details for each failed test
        foreach ($failures as $index => $failure) {
            $test_type = $failure['test_type_display'] ?? $failure['test_type'] ?? 'Unknown';
            $wordpress_version = $failure['wordpress_version'] ?? 'Unknown';
            $woocommerce_version = $failure['woocommerce_version'] ?? 'Unknown';
            $php_version = $failure['php_version'] ?? 'Unknown';
            $status = $failure['status'] ?? 'Unknown';

            $block_text = sprintf(
                "*Test Type:* %s\n*Status:* %s\n*WordPress:* %s | *WooCommerce:* %s | *PHP:* %s",
                $test_type,
                $status,
                $wordpress_version,
                $woocommerce_version,
                $php_version
            );

            // Add manager URL link if available
            if (!empty($failure['test_results_manager_url'])) {
                $block_text .= sprintf("\n*View Results:* <%s|Open Test Results>", $failure['test_results_manager_url']);
            }

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $block_text
                ]
            ];

            // Add divider between failures (except after the last one)
            if ($index < count($failures) - 1) {
                $blocks[] = ['type' => 'divider'];
            }
        }

        return $blocks;
    }
} 