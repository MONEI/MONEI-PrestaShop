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

# Create release (from /build directory) - bumps version in monei.php
cd build && yarn release
```

### Code Quality
- PHP code style: Uses PHP-CS-Fixer with custom Symfony-based configuration (see `.php-cs-fixer.php`)
- No JavaScript linting configured
- No test suite implemented (PHPUnit configured but `/tests` directory is empty)

### Cache Clearing (PrestaShop Flashlight)
When using PrestaShop Flashlight Docker environment, clear cache after module changes:
```bash
# Find the container name
docker ps | grep prestashop

# Clear all caches (replace 'tunnel1-prestashop-1' with your container name)
docker exec tunnel1-prestashop-1 bash -c "rm -rf /var/www/html/var/cache/*"
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console cache:clear"

# Reset module to force configuration reload
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console prestashop:module reset monei"
```
Then hard refresh browser (Ctrl+F5 or Cmd+Shift+R).

### Checking Logs (PrestaShop Flashlight)

**IMPORTANT**: MONEI module logs are stored in the database (`ps_log` table), not in log files. Use these commands to check them:

```bash
# View recent MONEI logs from database (most useful for debugging)
docker exec tunnel1-prestashop-1 bash -c "mysql -h mysql -u root -pprestashop prestashop -e \"SELECT * FROM ps_log WHERE message LIKE '%MONEI%' ORDER BY id_log DESC LIMIT 20;\" 2>/dev/null"

# View MONEI logs from a specific time period
docker exec tunnel1-prestashop-1 bash -c "mysql -h mysql -u root -pprestashop prestashop -e \"SELECT * FROM ps_log WHERE message LIKE '%MONEI%' AND date_add >= '$(date +%Y-%m-%d) 00:00:00' ORDER BY id_log DESC;\" 2>/dev/null"

# Check for MONEI errors specifically (severity 3 = error, 2 = warning)
docker exec tunnel1-prestashop-1 bash -c "mysql -h mysql -u root -pprestashop prestashop -e \"SELECT * FROM ps_log WHERE message LIKE '%MONEI%' AND severity >= 2 ORDER BY id_log DESC LIMIT 20;\" 2>/dev/null"
```

For general PrestaShop and PHP errors:
```bash
# View recent PrestaShop application logs
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"

# Check dev environment logs (often more detailed)
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/www/html/var/logs/dev-$(date +%Y-%m-%d).log"

# Live monitoring of logs
docker exec tunnel1-prestashop-1 bash -c "tail -f /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"
```

Log locations:
- **MONEI module logs**: Database table `ps_log` (use MySQL queries above)
- PrestaShop app logs: `/var/www/html/var/logs/`
- PHP error logs: `/var/log/php/error.log` (if configured)
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
  - `/js/_dev/`: Development JavaScript (source files)
  - `/js/`: Minified production JavaScript (generated via uglifyjs-folder)
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
- Runtime uses JavaScript files in `/views/js/` directly (vanilla JavaScript, no build required)
- Key files:
  - `checkout.js`: Payment form handling, Apple/Google Pay detection
  - `saved-cards.js`: Tokenized card management
  - `admin/admin.js`: Admin panel functionality, refund handling
- **Development Note**: A legacy build pipeline exists (`/views/js/_dev/` → uglifyjs-folder → `/views/js/`) but is deprecated. The files in `/views/js/` are used directly in production without requiring any build step

## Version Compatibility
- PHP: ≥7.4 (composer platform configured)
- PrestaShop: ≥8.0 (minimum supported version)
- MONEI PHP SDK: ^2.6
- Build tools: Yarn 4.5.0 (packageManager field enforced)