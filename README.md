# QIT Test Automation Repository

This repository contains automated QIT (Quality Intelligence Testing) configurations and workflows for proactive testing of WooCommerce and WordPress core updates.

## Repository Structure

```
qit-tests/
├── .github/
│   └── workflows/              # GitHub Actions workflows
├── src/
│   └── Commands/               # PHP CLI commands
│       └── DownloadWooNightlyCommand.php
├── bin/                        # CLI executables
│   └── download-woo-nightly
├── configs/                    # Configuration files
├── composer.json              # PHP dependencies
└── README.md                  # This file
```

## Prerequisites

- PHP 8.0 or higher
- Composer 2.0 or higher

## Installation

1. Clone the repository:
```bash
git clone https://github.com/botwoo/qit-tests.git
cd qit-tests
```

2. Install PHP dependencies:
```bash
composer install
```

## Available Commands

### Download WooCommerce Nightly

Downloads the latest WooCommerce nightly build from GitHub releases.

```bash
php bin/download-woo-nightly
```

**What it does:**
- Fetches the latest releases from the WooCommerce GitHub API
- Finds the first release with `tag_name: "nightly"` and `prerelease: true`
- Downloads the zip file from the release assets
- Saves it as `woocommerce.zip` in the project root
- Shows download progress and file size

**Example output:**
```
WooCommerce Nightly Downloader
==============================

Fetching latest nightly release from GitHub...

✅ Found nightly release: Nightly
Created: 2025-07-06T18:09:47Z
Published: 2020-04-28T02:15:56Z

Download URL: https://github.com/woocommerce/woocommerce/releases/download/nightly/woocommerce-trunk-nightly.zip
Downloading woocommerce.zip...
Downloaded 18,074,366 bytes

✅ WooCommerce nightly zip downloaded successfully as: woocommerce.zip
```

## Usage in Testing Workflows

This tool is designed to be used in automated testing pipelines where you need the latest development version of WooCommerce:

```bash
# Download latest nightly
php bin/download-woo-nightly

# Use the downloaded zip in your tests
# The file will be saved as woocommerce.zip in the current directory
```

## Error Handling

The command handles various error scenarios gracefully:

- **No nightly release found**: If no release with `tag_name: "nightly"` exists
- **No download URL**: If the release has no zip assets
- **Download failures**: Network issues or invalid URLs
- **File write errors**: Disk space or permission issues

## Technical Details

### GitHub API Usage

- **Endpoint**: `https://api.github.com/repos/woocommerce/woocommerce/releases`
- **Limit**: Fetches the 10 most recent releases for efficiency
- **Rate Limits**: Uses standard GitHub API rate limits (no auth token required)

### File Size Expectations

- Typical nightly builds are 15-20 MB
- Maximum expected size: 50 MB
- Uses `file_get_contents()` for simple, efficient downloads

### WordPress Coding Standards

The code follows WordPress coding standards:
- Snake_case method names for private methods
- Spaces around parentheses and brackets
- K&R brace style
- Comprehensive error handling

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes following WordPress coding standards
4. Test your changes
5. Submit a pull request

## License

This project is licensed under the GPL v2 or later.
