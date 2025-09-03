# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MONEI PrestaShop payment gateway module that enables merchants to accept various payment methods (Card, Apple Pay, Google Pay, Bizum, PayPal, etc.) in PrestaShop 1.7.2+ and 8+ stores.

## Key Commands

### Development
```bash
# Install PHP dependencies
composer install

# Fix PHP code style
./vendor/bin/php-cs-fixer fix

# Create release (from /build directory) - bumps version in monei.php
cd build && yarn install && yarn release

# Note: No build step required for JavaScript assets
# JavaScript files in /views/js/ are production-ready (no minification needed)
```

### Code Quality
- PHP code style: Uses PHP-CS-Fixer with custom Symfony-based configuration (see `.php-cs-fixer.php`)
- IMPORTANT: Always run `./vendor/bin/php-cs-fixer fix` after modifying PHP files
- No JavaScript linting configured
- No test suite implemented (PHPUnit configured but `/tests` directory is empty)

### PrestaShop Admin Access
When using PrestaShop Flashlight Docker environment:

| URL      | {PS_DOMAIN}/admin-dev |
| -------- | --------------------- |
| Login    | admin@prestashop.com  |
| Password | prestashop            |

### Module Installation
Install MONEI module via CLI:
```bash
# For PrestaShop 1.7.2 (no console command available)
docker exec tunnel1-prestashop-1 bash -c "cd /var/www/html && php modules/monei/monei.php"

# For PrestaShop 8+
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console prestashop:module install monei"
```

### Cache Clearing (PrestaShop Flashlight)
When using PrestaShop Flashlight Docker environment, clear cache after module changes:
```bash
# Find the container name
docker ps | grep prestashop

# Clear all caches (replace 'tunnel1-prestashop-1' with your container name)
docker exec tunnel1-prestashop-1 bash -c "rm -rf /var/www/html/var/cache/*"

# For PrestaShop 1.7.2 (simpler cache structure)
docker exec tunnel1-prestashop-1 bash -c "rm -rf /var/www/html/cache/smarty/compile/* /var/www/html/cache/smarty/cache/* /var/www/html/cache/cachefs/* /var/www/html/cache/class_index.php"

# For PrestaShop 8+
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console cache:clear"

# Reset module to force configuration reload (PrestaShop 8+ only)
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console prestashop:module reset monei"
```
Then hard refresh browser (Ctrl+F5 or Cmd+Shift+R).

### Checking Logs (PrestaShop Flashlight)
When debugging errors in the Docker environment:
```bash
# View recent PrestaShop application logs
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"

# Search for MONEI-specific errors
docker exec tunnel1-prestashop-1 bash -c "grep -i 'monei' /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log | tail -50"

# Check PHP error logs
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/log/php/error.log"

# Live monitoring of logs
docker exec tunnel1-prestashop-1 bash -c "tail -f /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"
```

Log locations inside the container:
- PrestaShop app logs: `/var/www/html/var/logs/`
- PHP error logs: `/var/log/php/error.log`
- Cache logs: `/var/www/html/var/cache/dev/admin/AdminKernelDevDebugContainerDeprecations.log`

## Architecture

### Module Structure
- **Main Class**: `monei.php` extends PrestaShop's `PaymentModule` (v2.0.0)
- **Namespace**: `PsMonei` (PSR-4 autoloaded from `/src`)
- **Service Container**: Uses PrestaShop's dependency injection
  - Admin services: `/config/admin/services.yml`
  - Front services: `/config/front/services.yml`
  - Common services: `/config/common.yml`

### Key Directories
- `/src`: Business logic with PSR-4 autoloading
  - `Entity/`: Database models extending PrestaShop's ObjectModel
  - `Repository/`: Data access layer (e.g., MoneiPaymentRepository)
  - `Service/`: Core services (MoneiService, OrderService, PaymentOptionService)
  - `Exception/`: Custom exceptions
  - `Enum/`: Enumerations for statuses and types
- `/controllers`: PrestaShop controllers
  - `/admin`: Admin panel controllers
  - `/front`: Frontend controllers (redirect, validation, check, cards)
- `/views`: Frontend resources
  - `/templates`: Smarty templates
  - `/js/`: JavaScript files (production-ready)
  - `/css`: Stylesheets
- `/build`: Build tooling with Yarn 4.5.0 and release-it configuration
- `/sql`: Database schema (install.sql, uninstall.sql)
- `/translations`: Module translations

### Database Schema
Tables (prefixed with `monei2_`):
- `monei2_payment`: Payment records linked to orders
- `monei2_customer_card`: Tokenized customer cards
- `monei2_history`: Payment event history
- `monei2_refund`: Refund records
- `monei2_admin_order_message`: Admin messages

### Payment Flow
1. **Initiation**: Customer selects MONEI payment → `RedirectModuleFrontController`
2. **Processing**: Creates payment via MONEI API → redirects to MONEI hosted page
3. **Validation**: Return from MONEI → `ValidationModuleFrontController`
4. **Webhook**: Async status updates → `CheckModuleFrontController`
5. **Completion**: Order status update based on payment result

### Service Container Pattern
```php
// Access services via static helper
$paymentService = Monei::getService('monei.service.payment');

// Common services defined in config/common.yml:
// - monei.service.monei: Core MONEI API integration
// - monei.service.order: Order management
// - monei.service.payment.option: Payment method configuration
// - monei.repository.*: Data repositories
```

### Frontend JavaScript Architecture
- JavaScript files in `/views/js/` use vanilla JavaScript (no build/transpilation)
- Key files:
  - `checkout.js`: Payment form handling, Apple/Google Pay detection
  - `saved-cards.js`: Tokenized card management
  - `admin/admin.js`: Admin panel functionality (field toggling, refund handling)
- No bundler or build process required

### Testing MONEI Card Input (Playwright/Automated Testing)
The MONEI card input fields are rendered inside a cross-origin iframe from `js.monei.com`. To interact with these fields in automated tests:

#### Accessing Card Input Fields
```javascript
// The iframe contains input fields with data-testid attributes:
// - data-testid="card-number-input" - Card number field
// - data-testid="expiry-date-input" - Expiry date field (MM/YY format)
// - data-testid="cvc-input" - CVC/CVV field

// In Playwright, access the iframe content:
await page.locator('iframe[src*="monei"]').contentFrame().getByTestId('card-number-input').fill('4444444444444422');
await page.locator('iframe[src*="monei"]').contentFrame().getByTestId('expiry-date-input').fill('12/34');
await page.locator('iframe[src*="monei"]').contentFrame().getByTestId('cvc-input').fill('123');
```

#### Test Card Numbers (from https://docs.monei.com/testing/)
**Visa Test Cards:**
- `4444444444444406` - 3D Secure v2.1 Challenge (use for AUTH testing)
- `4444444444444414` - 3D Secure v2.1 Direct (no challenge)
- `4444444444444422` - 3D Secure v2.1 Frictionless
- `4444444444444430` - 3D Secure v2.1 Frictionless and Challenge

**Mastercard Test Cards:**
- `5555555555555524` - 3D Secure v2.1 Direct (no challenge)
- `5555555555555532` - 3D Secure v2.1 Frictionless
- `5555555555555565` - 3D Secure v2.1 Challenge
- `5555555555555573` - 3D Secure v2.1 Frictionless and Challenge

**Important:** Always use expiry date `12/34` and CVC `123` for test cards.

#### Authorization and Capture (AUTH Mode)
MONEI supports two payment action modes:
- **SALE** (default) - Funds are automatically captured when customer authorizes payment
- **AUTH** - Places a hold on funds but doesn't capture until later (up to 30 days)

To test AUTH mode:
1. Set `MONEI_PAYMENT_ACTION` configuration to 'auth' in database
2. Use test card `4444444444444406` (3D Secure Challenge) for reliable AUTH testing
3. After successful authorization, payment status will be "AUTHORIZED" (not "SUCCEEDED")
4. Capture can be performed later via API or admin interface (if implemented)

Note: If capture button is not visible in PrestaShop admin, check:
- Payment status is "AUTHORIZED" (not "SUCCEEDED")
- `is_captured` field in database is 0
- Module's capture functionality is properly implemented

#### Payment Flow Issues
- The `createPayment` controller must be registered in the module's `$this->controllers` array
- If getting 404 errors on payment submission, ensure the controller is listed in monei.php constructor

## Version Compatibility
- PHP: ≥7.4 (composer platform configured)
- PrestaShop: ≥1.7.2.4 (tested) and ≥8.0 (officially supported)
- MONEI PHP SDK: ^2.6
- Build tools: Yarn 4.5.0 (packageManager field enforced)

## Known Compatibility Issues

### PrestaShop 1.7.2.4 Specific Issues
- PrestaShopLogger constants don't exist (use numeric values: info=1, warning=2, error=3, major=4)
- hookDisplayBackOfficeHeader not triggered for module configuration pages
- JavaScript/CSS must be loaded in getContent() method for admin pages
- jQuery timing issues require waitForJQuery wrapper for reliable initialization

### Compatibility Solutions
- Added getLogLevel() method for PrestaShopLogger compatibility across versions
- Load admin assets in both getContent() and hookDisplayBackOfficeHeader
- Use waitForJQuery pattern for JavaScript initialization
- Always run php-cs-fixer after code modifications

## Docker Development Environment
Using PrestaShop Flashlight with custom PHP 7.4 upgrade for PrestaShop 1.7.2.4:
- Base image: `prestashop/prestashop-flashlight:1.7.2.4-7.1`
- Custom Dockerfile upgrades PHP from 7.1 to 7.4.33
- ngrok tunnel for HTTPS testing
- MariaDB 10.3 for database

## Git Commit Guidelines
When creating commits:
- Use clear, concise commit messages
- Do NOT add any AI-generated footers or signatures
- Do NOT include "Generated with Claude Code" or similar messages
- Keep commit messages professional and focused on the changes made