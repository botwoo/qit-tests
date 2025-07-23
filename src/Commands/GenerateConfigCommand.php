<?php

namespace QitTests\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

/**
 * Command to generate QIT test configuration files for new versions.
 */
class GenerateConfigCommand extends Command {
    protected static $defaultName = 'generate:config';
    protected static $defaultDescription = 'Generate QIT test configuration file for a new version';

    protected function configure(): void {
        $this
            ->setName( self::$defaultName )
            ->setDescription( self::$defaultDescription )
            ->setHelp('This command generates a QIT test configuration file based on the platform, version, and channel provided.')
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Platform (WordPress or WooCommerce)')
            ->addOption('version', 'v', InputOption::VALUE_REQUIRED, 'Version number to test')
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Release channel', 'stable')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path', './config/generated-config.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        
        $platform = $input->getOption('platform');
        $version = $input->getOption('version');
        $channel = $input->getOption('channel');
        $output_file = $input->getOption('output');

        if (!$platform || !$version) {
            $io->error('Both --platform and --version options are required.');
            return Command::FAILURE;
        }

        $io->title('QIT Config Generator');
        $io->text("Generating config for {$platform} {$version} ({$channel})");

        try {
            $config = $this->generate_config($platform, $version, $channel);
            $this->save_config($config, $output_file);
            
            $io->success("Configuration file generated successfully: {$output_file}");
            $io->text("Platform: {$platform}");
            $io->text("Version: {$version}");
            $io->text("Channel: {$channel}");
            
            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Generate the configuration array based on platform, version, and channel.
     *
     * @param string $platform The platform (WordPress or WooCommerce)
     * @param string $version The version number
     * @param string $channel The release channel
     * @return array
     * @throws Exception
     */
    private function generate_config(string $platform, string $version, string $channel): array {
        $base_config = [
            "activation" => []
        ];

        if (strtolower($platform) === 'woocommerce') {
            $base_config['activation'] = $this->get_woocommerce_config($version, $channel);
        } elseif (strtolower($platform) === 'wordpress') {
            $base_config['activation'] = $this->get_wordpress_config($version, $channel);
        } else {
            throw new Exception("Unsupported platform: {$platform}. Supported platforms are: WordPress, WooCommerce");
        }

        return $base_config;
    }

    /**
     * Get WooCommerce-specific configuration.
     *
     * @param string $version The WooCommerce version
     * @param string $channel The release channel
     * @return array
     */
    private function get_woocommerce_config(string $version, string $channel): array {
        // Use the actual version if it's not nightly, otherwise use 'nightly'
        $woo_version = ($channel === 'nightly') ? 'nightly' : $version;
        
        return [
            [
                "php_version" => "8.4",
                "woocommerce_version" => $woo_version
            ],
            [
                "php_version" => "7.4",
                "woocommerce_version" => $woo_version,
                "additional_plugins" => $this->get_additional_plugins()
            ]
        ];
    }

    /**
     * Get WordPress-specific configuration.
     *
     * @param string $version The WordPress version
     * @param string $channel The release channel
     * @return array
     */
    private function get_wordpress_config(string $version, string $channel): array {
        // For WordPress, we still test with WooCommerce nightly but use the specific WordPress version
        return [
            [
                "php_version" => "8.4",
                "wordpress_version" => $version,
                "woocommerce_version" => "nightly"
            ],
            [
                "php_version" => "7.4", 
                "wordpress_version" => $version,
                "woocommerce_version" => "nightly",
                "additional_plugins" => $this->get_additional_plugins()
            ]
        ];
    }

    /**
     * Get the list of additional plugins to test with.
     * Based on the nightly.json configuration.
     *
     * @return array
     */
    private function get_additional_plugins(): array {
        return [
            "woocommerce-gift-cards",
            "addify-product-options-and-addons",
            "product-brands-for-woocommerce",
            "woocommerce-back-in-stock-notifications",
            "woocommerce-min-max-quantities",
            "woocommerce-product-bundles",
            "woocommerce-product-recommendations",
            "woocommerce-payments",
            "woocommerce-avatax",
            "woocommerce-eu-vat-number",
            "automatewoo",
            "woocommerce-shipping",
            "woocommerce-shipment-tracking",
            "woocommerce-shipping-australia-post",
            "woocommerce-shipping-usps",
            "woocommerce-shipping-canada-post",
            "woocommerce-shipping-royalmail",
            "woocommerce-shipping-fedex",
            "woocommerce-shipping-ups",
            "woocommerce-google-analytics-integration",
            "tiktok-for-woocommerce",
            "pinterest-for-woocommerce",
            "google-listings-and-ads",
            "woocommerce-subscriptions",
            "woocommerce-all-products-for-subscriptions",
            "woocommerce-subscriptions-gifting",
            "woocommerce-subscription-downloads",
            "woocommerce-additional-variation-images",
            "automatewoo-birthdays",
            "automatewoo-referrals",
            "woocommerce-composite-products",
            "woocommerce-conditional-shipping-and-payments",
            "mailpoet",
            "woocommerce-product-csv-import-suite",
            "woocommerce-warranty",
            "woocommerce-table-rate-shipping",
            "woocommerce-analytics",
            "woocommerce-points-and-rewards",
            "woocommerce-services",
            "woocommerce-gateway-stripe",
            "woocommerce-square",
            "woocommerce-paypal-payments"
        ];
    }

    /**
     * Save the configuration to a JSON file.
     *
     * @param array $config The configuration array
     * @param string $output_file The output file path
     * @throws Exception
     */
    private function save_config(array $config, string $output_file): void {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            throw new Exception('Failed to encode configuration to JSON');
        }

        // Ensure the directory exists
        $dir = dirname($output_file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("Failed to create directory: {$dir}");
            }
        }

        if (file_put_contents($output_file, $json) === false) {
            throw new Exception("Failed to write configuration file: {$output_file}");
        }
    }
} 