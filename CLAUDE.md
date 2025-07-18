# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MONEI PrestaShop payment gateway module that enables merchants to accept various payment methods (Card, Apple Pay, Google Pay, Bizum, PayPal, etc.) in PrestaShop 8+ stores.

## Key Commands

### Development
```bash
# Install PHP dependencies
composer install

# Fix PHP code style
./vendor/bin/php-cs-fixer fix

# Build JavaScript assets (from /build directory)
cd build && yarn build

# Create release (from /build directory)
cd build && yarn release
```

### Code Quality
- PHP code style: Uses PHP-CS-Fixer with Symfony standards
- No linting command configured for JavaScript
- No test suite implemented (PHPUnit configured but no tests exist)

## Architecture

### Module Structure
- **Main Class**: `monei.php` extends PrestaShop's `PaymentModule`
- **Namespace**: `PsMonei` (PSR-4 autoloaded from `/src`)
- **Service Container**: Uses PrestaShop's dependency injection via YAML configs in `/config`

### Key Directories
- `/src`: Business logic organized by type
  - `Entity/`: Database models (Monei2Payment, Monei2CustomerCard, etc.)
  - `Repository/`: Data access layer
  - `Service/`: Core business services (PaymentService, TokenizationService, etc.)
  - `Exception/`: Custom exceptions
- `/controllers`: PrestaShop controllers (admin and front)
- `/views`: Frontend assets and Smarty templates
  - `/js/_dev/`: Development JavaScript files
  - `/js/`: Minified production JavaScript

### Database
- Custom tables prefixed with `monei2_` (payment, customer_card, admin_order_message)
- Installation/uninstallation SQL in `/sql` directory
- Entity classes use PrestaShop's ObjectModel pattern

### Payment Integration Flow
1. Customer initiates payment → `RedirectModuleFrontController`
2. Payment processed via MONEI API → `ValidationModuleFrontController`
3. Status updates handled via webhook → `CheckModuleFrontController`
4. Admin operations through `AdminMoneiPaymentsController`

### Configuration
- Module settings stored in PrestaShop configuration table
- Service definitions in `/config/services.yml`
- Hook subscriptions in `/config/hooks.yml`

## Important Patterns

### Service Usage
Services are accessed via static container methods:
```php
$paymentService = Monei::getService('monei2.payment_service');
```

### MONEI SDK Integration
- SDK client initialized with API key from configuration
- Payment methods fetched dynamically from MONEI account
- Supports tokenization for saved cards

### Frontend JavaScript
- Development files in `/views/js/_dev/`
- Production files generated via `uglifyjs-folder` to `/views/js/`
- Handles payment form interactions and saved card management

## Version Compatibility
- PHP: ≥7.4
- PrestaShop: ≥8.0
- MONEI PHP SDK: ^2.6