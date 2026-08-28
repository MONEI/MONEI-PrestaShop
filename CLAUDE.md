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
- ⚠️ `.php-cs-fixer.php` pins `trailing_comma_in_multiline` to arrays only. Trailing
  commas in *parameter lists* are PHP 8.0 syntax and this module runs on 7.4, so
  removing that pin makes the fixer emit code that fatals on the minimum supported
  version. CI parses every file under 7.4 to catch it.

```bash
npm install          # ESLint + Prettier + Playwright
npm run lint         # ESLint over views/js and tests
npm run format       # Prettier (JavaScript only)
composer test        # PHPUnit unit tests
npm run test:e2e     # Playwright, see tests/playwright/README.md
```

⚠️ Unit tests must not touch a class guarded by `if (!defined('_PS_VERSION_'))`.
Merely asking whether one exists loads the file and calls `exit`, which ends the
suite mid-run with no summary and exit code 0 — indistinguishable from a pass.

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

### Express Checkout

- Services in `/src/Service/Express/`, resolved through `MoneiServiceLocator`
- One front controller, `controllers/front/express.php`, dispatching on `action`
  read from the JSON body — not the query string
- Hooks, verified against the PrestaShop 1.7.8 classic theme:
  - product → `displayProductAdditionalInfo`
  - cart → `displayExpressCheckout`
  - checkout → `displayPaymentTop` (above the payment options)
- ⚠️ `page_name` is empty when `actionFrontControllerSetMedia` fires on a product or
  cart page. Key page detection off `php_self` instead, or nothing registers. That
  is what `getFrontPageName()` is for, and it also spells checkout `order`.

### Frontend JavaScript Architecture
- JavaScript files in `/views/js/` use vanilla JavaScript (no build/transpilation)
- `payment.js` holds the checkout payment components. It used to live inline in
  `views/templates/hook/displayPaymentByBinaries.tpl`; that template is now markup
  only
- ⚠️ `Media::addJsDef` values must be published from `hookActionFrontControllerSetMedia`.
  PrestaShop collects the `js_def` block before content hooks render, so publishing
  from a display hook reaches the page as nothing at all
- The five `initMonei*` functions are global on purpose — `front.js` calls them by
  name
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
- PHP: ≥7.4 — the floor is `monei/monei-php-sdk`'s (`php >=7.4`), not PrestaShop's.
  1.7.8 itself runs on 7.1+, but this module cannot; `checkPHPCompatibility()`
  enforces it on install
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

### Currency Restrictions Configuration (IMPORTANT)
- **need_instance** MUST be set to 1 for currency checkboxes to appear in Payment Preferences
- Without `need_instance = 1`, PrestaShop won't instantiate the module class when loading Module::getModulesOnDisk()
- This causes the module to appear without checkboxes (just dashes) in Payment > Preferences > Currency Restrictions
- The fix is compatible with PrestaShop 1.7.2.4 through 1.7.8 and PrestaShop 8.x
- Properties removed for compatibility: `limited_currencies`, explicit `currencies_mode` (uses parent default)

## Docker Development Environment

The stack lives in its own repository, on the branch matching this one:
**https://github.com/MONEI/monei-prestashop-dev-env/tree/prestashop-1.7**
(`main` there runs PrestaShop 9 for the module's `master` branch). It runs
PrestaShop 1.7.8.10 behind a Cloudflare tunnel — 3D Secure and webhooks both need
a public HTTPS origin — and mounts this module into the container.

- Base image: `prestashop/prestashop-flashlight:1.7.8.10` (PHP 7.4.33)
- MariaDB 10.3, project name `tunnel17`, store on port 8017
- ⚠️ Its Dockerfile carries three fixes that cannot be init scripts: this image
  drops to `www-data` before Flashlight runs them, so anything writing
  `/etc/nginx` or the php-fpm config fails with a permission error and leaves the
  store silently misconfigured. They map `X-Forwarded-Proto` through (PrestaShop
  otherwise builds `http://` links behind the tunnel), raise the five php-fpm
  workers (too few for order confirmation, which surfaces as a 502 on a payment
  that succeeded), and fix the nginx client_body permissions.

## Git Commit Guidelines
When creating commits:
- Use clear, concise commit messages
- Do NOT add any AI-generated footers or signatures
- Do NOT include "Generated with Claude Code" or similar messages
- Keep commit messages professional and focused on the changes made