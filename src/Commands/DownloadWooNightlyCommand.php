<?php

namespace QitTests\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

/**
 * Command to download the latest WooCommerce nightly zip file.
 */
class DownloadWooNightlyCommand extends Command {
    protected static $defaultName = 'download:woo-nightly';
    protected static $defaultDescription = 'Download the latest WooCommerce nightly zip file';
    
    private const GITHUB_API_URL = 'https://api.github.com/repos/woocommerce/woocommerce/releases?per_page=10';
    private const OUTPUT_FILENAME = 'woocommerce.zip';

    protected function configure(): void {
        $this
            ->setName( self::$defaultName )
            ->setDescription( self::$defaultDescription )
            ->setHelp('This command fetches the latest WooCommerce nightly release from GitHub and downloads the zip file to the project root.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('WooCommerce Nightly Downloader');
        $io->text('Fetching latest nightly release from GitHub...');

        try {
            // Fetch releases from GitHub API
            $releases = $this->fetch_github_releases();
            
            // Find the nightly release
            $nightly_release = $this->find_nightly_release( $releases );
            
            if ( ! $nightly_release ) {
                $io->error( 'No nightly release found in the GitHub API response.' );
                return Command::FAILURE;
            }

            $io->success( 'Found nightly release: ' . $nightly_release[ 'name' ] );
            $io->text( 'Created: ' . $nightly_release[ 'created_at' ] );
            $io->text( 'Published: ' . $nightly_release[ 'published_at' ] );

            // Find the download URL
            $download_url = $this->get_download_url( $nightly_release );
            
            if ( ! $download_url ) {
                $io->error( 'No download URL found in the nightly release assets.' );
                return Command::FAILURE;
            }

            $io->text( 'Download URL: ' . $download_url );

            // Download the file
            $this->download_file( $download_url, self::OUTPUT_FILENAME, $io );

            $io->success( 'WooCommerce nightly zip downloaded successfully as: ' . self::OUTPUT_FILENAME );
            
            return Command::SUCCESS;

        } catch ( Exception $e ) {
            $io->error( 'An error occurred: ' . $e->getMessage() );
            return Command::FAILURE;
        }
    }

    /**
     * Fetch releases from GitHub API.
     *
     * @return array
     * @throws Exception
     */
    private function fetch_github_releases(): array {
        $context = stream_context_create( [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: QIT-Tests-Bot/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30
            ]
        ] );

        $response = file_get_contents( self::GITHUB_API_URL, false, $context );
        
        if ( $response === false ) {
            throw new Exception('Failed to fetch releases from GitHub API');
        }

        $releases = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception('Failed to parse GitHub API response: ' . json_last_error_msg());
        }

        if ( ! is_array( $releases ) ) {
            throw new Exception('Unexpected GitHub API response format');
        }

        return $releases;
    }

    /**
     * Find the nightly release from the releases array.
     *
     * @param array $releases
     * @return array|null
     */
    private function find_nightly_release(array $releases): ?array {
        foreach ( $releases as $release ) {
            if (
                isset( $release[ 'tag_name' ] ) && 
                isset( $release[ 'prerelease' ] ) && 
                $release[ 'tag_name' ] === 'nightly' && 
                $release[ 'prerelease' ] === true
            ) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Get the download URL from the release assets.
     *
     * @param array $release
     * @return string|null
     */
    private function get_download_url( array $release ): ?string {
        if ( 
            ! isset( $release[ 'assets' ] ) || 
            ! is_array( $release[ 'assets'] ) 
        ) {
            return null;
        }

        foreach ( $release[ 'assets' ] as $asset ) {
            if ( 
                isset( $asset[ 'browser_download_url' ] ) && 
                isset( $asset[ 'content_type' ] ) && 
                $asset[ 'content_type' ] === 'application/zip'
            ) {
                return $asset[ 'browser_download_url' ];
            }
        }

        return null;
    }

    /**
     * Download a file from URL.
     *
     * @param string $url
     * @param string $filename
     * @param SymfonyStyle $io
     * @throws Exception
     */
    private function download_file( string $url, string $filename, SymfonyStyle $io ): void {
        $io->text( 'Downloading ' . $filename . '...' );
        
        $content = file_get_contents( $url );
        if ( $content === false ) {
            throw new Exception( 'Failed to download file from URL' );
        }
        
        if ( file_put_contents( $filename, $content ) === false ) {
            throw new Exception( 'Failed to write file to disk' );
        }
        
        $io->text( sprintf( 'Downloaded %s bytes', number_format( strlen( $content ) ) ) );
    }
}
