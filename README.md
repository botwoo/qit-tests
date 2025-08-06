# QIT Test Automation Repository

This repository runs predefined [QIT](https://qit.woo.com) on the following events:
- New WooCommerce builds(nightly, release candidates and stable)
- New Builds of WordPress(release candidate and stable)
- Daily randomized environment tests (random PHP, WooCommerce, and WordPress versions)

## Test Types

### Event-Driven Tests
Tests are automatically triggered when new versions are released.

### Daily Randomized Tests  
Runs daily at 6:00 AM UTC with randomly selected environment combinations:
- Random PHP version (7.4, 8.0, 8.1, 8.2, 8.3, 8.4)
- Random WooCommerce version (stable releases, RCs, and specific versions)
- Random WordPress version (stable releases, RCs, and specific versions)

Both canonical and mixed plugin configurations are tested to ensure compatibility across different environment combinations.

## When Errors Are Detected
- This repository notifies our internal teams when errors are detected.

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
