<?php

namespace QitTests\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use QitTests\Services\QitRequest;
use QitTests\App;
use Exception;

/**
 * Command to generate QIT test configuration files for new versions.
 */
class GenerateConfigCommand extends Command {
    protected static $defaultName = 'generate:config';
    protected static $defaultDescription = 'Generate QIT test configuration file for a new version';

    private QitRequest $qit_request;

    public function __construct() {
        parent::__construct();
        $this->qit_request = App::make(QitRequest::class);
    }

    protected function configure(): void {
        $this
            ->setName( self::$defaultName )
            ->setDescription( self::$defaultDescription )
            ->setHelp('This command generates a QIT test configuration file based on the platform, version, and channel provided.')
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Platform (WordPress or WooCommerce)')
            ->addOption('target-version', 't', InputOption::VALUE_REQUIRED, 'Version number to test')
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Release channel', 'stable')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path', './config/generated-config.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        
        $platform = $input->getOption('platform');
        $version = $input->getOption('target-version');
        $channel = $input->getOption('channel');
        $output_file = $input->getOption('output');

        if (!$platform || !$version) {
            $io->error('Both --platform and --target-version options are required.');
            return Command::FAILURE;
        }

        $io->title('QIT Config Generator');
        $io->text("Generating config for {$platform} {$version} ({$channel})");

        // Get PHP versions and show them to user
        try {
            $php_versions = $this->qit_request->get_random_php_versions();
            $io->text("PHP versions (from QIT API): {$php_versions['primary']}, {$php_versions['secondary']}");
        } catch (Exception $e) {
            $io->text("PHP versions (fallback): 8.4, 7.4 (API unavailable: {$e->getMessage()})");
        }

        try {
            // Generate both canonical and legacy configs
            $canonical_config = $this->generate_config($platform, $version, $channel, 'canonical');
            $legacy_config = $this->generate_config($platform, $version, $channel, 'legacy');
            
            // Generate output file paths
            $base_path = pathinfo($output_file, PATHINFO_DIRNAME);
            $base_name = pathinfo($output_file, PATHINFO_FILENAME);
            $extension = pathinfo($output_file, PATHINFO_EXTENSION);
            
            $canonical_file = $base_path . '/' . $base_name . '-canonical.' . $extension;
            $legacy_file = $base_path . '/' . $base_name . '-legacy.' . $extension;
            
            // Save both configs
            $this->save_config($canonical_config, $canonical_file);
            $this->save_config($legacy_config, $legacy_file);
            
            $io->success("Configuration files generated successfully:");
            $io->text("Canonical config: {$canonical_file}");
            $io->text("Legacy config: {$legacy_file}");
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
     * Generate the configuration array based on platform, version, channel, and type.
     *
     * @param string $platform The platform (WordPress or WooCommerce)
     * @param string $version The version number
     * @param string $channel The release channel
     * @param string $type The config type ('canonical' or 'legacy')
     * @return array
     * @throws Exception
     */
    private function generate_config(string $platform, string $version, string $channel, string $type = 'canonical'): array {
        $base_config = [
            "activation" => []
        ];

        if (strtolower($platform) === 'woocommerce') {
            $base_config['activation'] = $this->get_woocommerce_config($version, $channel, $type);
        } elseif (strtolower($platform) === 'wordpress') {
            $base_config['activation'] = $this->get_wordpress_config($version, $channel, $type);
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
     * @param string $type The config type ('canonical' or 'legacy')
     * @return array
     */
    private function get_woocommerce_config(string $version, string $channel, string $type = 'canonical'): array {
        // Use the actual version if it's not nightly, otherwise use 'nightly'
        $woo_version = ($channel === 'nightly') ? 'nightly' : $version;
        
        // Get randomized PHP versions, fall back to defaults if API fails
        try {
            $php_versions = $this->qit_request->get_random_php_versions();
            $primary_php = $php_versions['primary'];
            $secondary_php = $php_versions['secondary'];
        } catch (Exception $e) {
            // Fall back to hardcoded versions if API fails
            $primary_php = '8.4';
            $secondary_php = '7.4';
        }
        
        $basic_config = [
            'php_version' => $primary_php,
            'woocommerce_version' => $woo_version
        ];
        
        $plugin_config = [
            'php_version' => $secondary_php,
            'woocommerce_version' => $woo_version
        ];
        
        if ($type === 'canonical') {
            return $this->generate_canonical_test_matrix($basic_config, $plugin_config);
        } else {
            return $this->generate_legacy_test_matrix($basic_config, $plugin_config);
        }
    }

    /**
     * Get WordPress-specific configuration.
     *
     * @param string $version The WordPress version
     * @param string $channel The release channel
     * @param string $type The config type ('canonical' or 'legacy')
     * @return array
     */
    private function get_wordpress_config(string $version, string $channel, string $type = 'canonical'): array {
        // Get randomized PHP versions, fall back to defaults if API fails
        try {
            $php_versions = $this->qit_request->get_random_php_versions();
            $primary_php = $php_versions['primary'];
            $secondary_php = $php_versions['secondary'];
        } catch (Exception $e) {
            // Fall back to hardcoded versions if API fails
            $primary_php = '8.4';
            $secondary_php = '7.4';
        }
        
        // For WordPress, we still test with WooCommerce nightly but use the specific WordPress version
        $basic_config = [
            'php_version' => $primary_php,
            'wordpress_version' => $version
        ];
        
        $plugin_config = [
            'php_version' => $secondary_php,
            'wordpress_version' => $version
        ];
        
        if ($type === 'canonical') {
            return $this->generate_canonical_test_matrix($basic_config, $plugin_config);
        } else {
            return $this->generate_legacy_test_matrix($basic_config, $plugin_config);
        }
    }

    /**
     * Generate canonical test matrix with basic test + canonical plugin groups.
     *
     * @param array $basic_config Basic configuration without plugins
     * @param array $plugin_config Base configuration for plugin tests
     * @return array
     * @throws Exception
     */
    private function generate_canonical_test_matrix(array $basic_config, array $plugin_config): array {
        $plugin_methods = [
            'get_canonical_payments_shipping_group',
            'get_canonical_products_customization_group',
            'get_canonical_subscriptions_automation_group',
            'get_canonical_shipping_tax_group',
            'get_canonical_marketing_analytics_group'
        ];

        $test_matrix = [ $basic_config ];

        foreach ($plugin_methods as $method) {
            if (!method_exists($this, $method)) {
                throw new Exception("Plugin method '{$method}' does not exist. Please ensure the method is implemented.");
            }

            if (!is_callable([$this, $method])) {
                throw new Exception("Plugin method '{$method}' is not callable. Please check method visibility.");
            }

            $plugins = $this->$method();
            
            if (!is_array($plugins)) {
                throw new Exception("Plugin method '{$method}' must return an array, " . gettype($plugins) . " returned.");
            }

            $test_matrix[] = array_merge($plugin_config, [
                'additional_plugins' => $plugins
            ]);
        }

        return $test_matrix;
    }

    /**
     * Generate legacy test matrix with basic test + legacy plugin groups.
     *
     * @param array $basic_config Basic configuration without plugins
     * @param array $plugin_config Base configuration for plugin tests
     * @return array
     * @throws Exception
     */
    private function generate_legacy_test_matrix(array $basic_config, array $plugin_config): array {
        $plugin_methods = [
            'get_essentials_revshare_plugins',
            'get_sales_marketing_plugins',
            'get_subscriptions_memberships_plugins',
            'get_lms_commerce_plugins',
            'get_complex_shipping_plugins',
            'get_fullstack_business_plugins'
        ];

        $test_matrix = [ $basic_config ];

        foreach ($plugin_methods as $method) {
            if (!method_exists($this, $method)) {
                throw new Exception("Plugin method '{$method}' does not exist. Please ensure the method is implemented.");
            }

            if (!is_callable([$this, $method])) {
                throw new Exception("Plugin method '{$method}' is not callable. Please check method visibility.");
            }

            $plugins = $this->$method();
            
            if (!is_array($plugins)) {
                throw new Exception("Plugin method '{$method}' must return an array, " . gettype($plugins) . " returned.");
            }

            $test_matrix[] = array_merge($plugin_config, [
                'additional_plugins' => $plugins
            ]);
        }

        return $test_matrix;
    }

    /**
     * Get Canonical Payments & Shipping group (Group 1 from nightly.json).
     *
     * @return array
     */
    private function get_canonical_payments_shipping_group(): array {
        return [
            "woocommerce-payments",
            "woocommerce-gateway-stripe",
            "google-listings-and-ads",
            "woocommerce-shipping",
            "woocommerce-shipment-tracking",
            "woocommerce-table-rate-shipping",
            "woocommerce-gift-cards",
            "woocommerce-back-in-stock-notifications",
            "woocommerce-google-analytics-integration"
        ];
    }

    /**
     * Get Canonical Products & Customization group (Group 2 from nightly.json).
     *
     * @return array
     */
    private function get_canonical_products_customization_group(): array {
        return [
            "addify-product-options-and-addons",
            "woocommerce-product-bundles",
            "woocommerce-composite-products",
            "product-brands-for-woocommerce",
            "woocommerce-min-max-quantities",
            "woocommerce-product-recommendations",
            "woocommerce-additional-variation-images",
            "woocommerce-payments",
            "woocommerce-paypal-payments"
        ];
    }

    /**
     * Get Canonical Subscriptions & Automation group (Group 3 from nightly.json).
     *
     * @return array
     */
    private function get_canonical_subscriptions_automation_group(): array {
        return [
            "woocommerce-subscriptions",
            "woocommerce-all-products-for-subscriptions",
            "woocommerce-subscriptions-gifting",
            "woocommerce-subscription-downloads",
            "woocommerce-payments",
            "automatewoo",
            "automatewoo-birthdays",
            "automatewoo-referrals",
            "woocommerce-conditional-shipping-and-payments"
        ];
    }

    /**
     * Get Canonical Shipping & Tax group (Group 4 from nightly.json).
     *
     * @return array
     */
    private function get_canonical_shipping_tax_group(): array {
        return [
            "woocommerce-shipping",
            "woocommerce-shipment-tracking",
            "woocommerce-shipping-usps",
            "woocommerce-shipping-canada-post",
            "woocommerce-shipping-royalmail",
            "woocommerce-shipping-fedex",
            "woocommerce-shipping-ups",
            "woocommerce-avatax",
            "woocommerce-eu-vat-number"
        ];
    }

    /**
     * Get Canonical Marketing & Analytics group (Group 5 from nightly.json).
     *
     * @return array
     */
    private function get_canonical_marketing_analytics_group(): array {
        return [
            "google-listings-and-ads",
            "tiktok-for-woocommerce",
            "pinterest-for-woocommerce",
            "woocommerce-points-and-rewards",
            "woocommerce-gift-cards",
            "automatewoo",
            "automatewoo-referrals",
            "mailpoet",
            "woocommerce-analytics"
        ];
    }

    /**
     * Get Essentials + Rev Share plugins.
     *
     * @return array
     */
    private function get_essentials_revshare_plugins(): array {
        return [
            "woocommerce-payments",
            "google-listings-and-ads",
            "facebook-for-woocommerce",
            "woocommerce-gateway-stripe",
            "woocommerce-paypal-payments",
            "woocommerce-services", // WooCommerce Shipping & Tax
            "elementor",
            "wp-mail-smtp",
            "wordpress-seo"
        ];
    }

    /**
     * Get Sales and Marketing plugins.
     *
     * @return array
     */
    private function get_sales_marketing_plugins(): array {
        return [
            "woocommerce-payments",
            "google-listings-and-ads",
            "facebook-for-woocommerce",
            "pinterest-for-woocommerce",
            "tiktok-for-woocommerce",
            "mailpoet",
            "automatewoo",
            "woocommerce-smart-coupons",
            "woocommerce-services" // WooCommerce Shipping & Tax
        ];
    }

    /**
     * Get Subscriptions and Memberships Site plugins.
     *
     * @return array
     */
    private function get_subscriptions_memberships_plugins(): array {
        return [
            "woocommerce-subscriptions",
            "woocommerce-memberships",
            "woocommerce-payments",
            "woocommerce-smart-coupons",
            "google-listings-and-ads",
            "automatewoo",
            "woocommerce-zapier",
            "woocommerce-gateway-stripe",
            "elementor"
        ];
    }

    /**
     * Get LMS + Commerce Hybrid plugins.
     *
     * @return array
     */
    private function get_lms_commerce_plugins(): array {
        return [
            "sensei-lms",
            "woocommerce-subscriptions",
            "woocommerce-payments",
            "google-listings-and-ads",
            "mailchimp-for-woocommerce",
            "automatewoo",
            "elementor",
            "woocommerce-gift-cards",
            "woocommerce-zapier"
        ];
    }

    /**
     * Get Complex Shipping plugins.
     *
     * @return array
     */
    private function get_complex_shipping_plugins(): array {
        return [
            "woocommerce-payments",
            "google-listings-and-ads",
            "woocommerce-shipstation-integration",
            "woocommerce-shipping-usps",
            "woocommerce-shipment-tracking",
            "woocommerce-conditional-shipping-and-payments",
            "woocommerce-gateway-stripe",
            "woocommerce-paypal-payments",
            "woocommerce-table-rate-shipping"
        ];
    }

    /**
     * Get Full-Stack Business Store plugins.
     *
     * @return array
     */
    private function get_fullstack_business_plugins(): array {
        return [
            "woocommerce-subscriptions",
            "woocommerce-payments",
            "google-listings-and-ads",
            "facebook-for-woocommerce",
            "woocommerce-zapier",
            "automatewoo",
            "woocommerce-gateway-stripe",
            "mailpoet",
            "elementor"
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