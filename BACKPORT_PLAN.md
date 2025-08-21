# MONEI Module Backport Plan: PrestaShop 8 → PrestaShop 1.7

## Overview
This document outlines the comprehensive plan to backport the MONEI payment module from PrestaShop 8+ to PrestaShop 1.7 with PHP 7.4 compatibility.

## 1. Core Module Configuration

### Version Compatibility
- **File:** `monei.php`
- **Line 34:** Change `'min' => '8'` to `'min' => '1.7'`
- **PHP Support:** Already compatible with PHP 7.4 (composer.json platform config is set correctly)

## 2. Replace Doctrine ORM with ObjectModel

### Current State
The module uses Doctrine ORM entities with annotations, which are not available in PrestaShop 1.7.

### Required Changes

#### Entity Conversion List
1. `src/Entity/Monei2Payment.php`
2. `src/Entity/Monei2CustomerCard.php`
3. `src/Entity/Monei2History.php`
4. `src/Entity/Monei2Refund.php`

#### ObjectModel Implementation Requirements
Each entity needs to be converted to extend `ObjectModel` with:

```php
class Monei2Payment extends ObjectModel
{
    public static $definition = [
        'table' => 'monei2_payment',
        'primary' => 'id_payment',
        'fields' => [
            'id_payment' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'required' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_order_monei' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50],
            'amount' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true],
            'refunded_amount' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'currency' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 3],
            'authorization_code' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 20],
            'is_captured' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'status_code' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 10],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
```

#### Conversion Tasks
- Remove all Doctrine annotations (`@ORM\*`)
- Remove Doctrine imports
- Remove ArrayCollection usage
- Convert getters/setters to use ObjectModel's data array
- Add static finder methods to replace repository patterns

## 3. Replace Service Container with Direct Instantiation

### Current Dependencies
- Uses `prestashop/module-lib-service-container` (PS8-specific)
- Service definitions in YAML files under `/config/`

### Required Changes

#### Remove Service Container Infrastructure
1. Delete entire `/config/` directory
2. Remove from `composer.json`:
   ```json
   "prestashop/module-lib-service-container": "^2.0"
   ```
3. Remove `getService()` method from `monei.php`
4. Remove service container initialization in constructor

#### Implement Simple Service Locator Pattern
```php
class MoneiServiceLocator
{
    private static $instances = [];
    
    public static function getMoneiService()
    {
        if (!isset(self::$instances['monei_service'])) {
            self::$instances['monei_service'] = new MoneiService(
                Context::getContext(),
                new Monei2Payment(),
                new Monei2CustomerCard(),
                new Monei2Refund(),
                new Monei2History()
            );
        }
        return self::$instances['monei_service'];
    }
    
    public static function getOrderService()
    {
        // Similar pattern for other services
    }
}
```

#### Update Service Access Throughout Module
Replace all occurrences:
- `Monei::getService('service.monei')` → `MoneiServiceLocator::getMoneiService()`
- `Monei::getService('service.order')` → `MoneiServiceLocator::getOrderService()`
- `Monei::getService('service.payment.option')` → `MoneiServiceLocator::getPaymentOptionService()`
- `Monei::getService('helper.payment_method_formatter')` → `new PaymentMethodFormatter()`

## 4. Update Controller Implementations

### Crypto Service Replacement
**File:** `controllers/front/redirect.php`
**Line 21:** Replace PS8 ServiceLocator usage

```php
// PS8 version:
$crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');

// PS1.7 version:
$crypto_hash = Tools::encrypt((int)$cart->id . (int)$cart->id_customer);
$check_encrypt = ($crypto_hash === $transactionId);
```

### Update All Controllers
- Remove PS8-specific imports
- Update service access patterns
- Ensure compatibility with PS1.7 controller structure

## 5. Hook Compatibility

### Remove PS8-Specific Hooks

#### hookActionGetAdminOrderButtons (lines 2453-2493)
- This hook doesn't exist in PS 1.7
- Replace functionality using `hookDisplayAdminOrder`
- Remove `PrestaShop\PrestaShop\Core\Action\ActionsBarButton` usage

### Adapt Existing Hooks
Verify compatibility of:
- `hookPaymentOptions` - Check payment option structure
- `hookDisplayPaymentByBinaries` - May need adjustments
- `hookDisplayPaymentReturn` - Verify template variables
- `hookDisplayAdminOrder` - Enhance to include capture button
- `hookActionFrontControllerSetMedia` - Check asset management
- `hookDisplayCustomerAccount` - Verify customer context
- `hookActionDeleteGDPRCustomer` - GDPR compliance
- `hookActionExportGDPRData` - GDPR compliance
- `hookDisplayBackOfficeHeader` - Admin assets
- `hookActionCustomerLogoutAfter` - Session management
- `hookModuleRoutes` - Custom routing
- `hookActionOrderSlipAdd` - Refund handling

## 6. Repository Pattern Replacement

### Convert Repository Classes to ObjectModel Static Methods

#### MoneiPaymentRepository → Monei2Payment Static Methods
```php
class Monei2Payment extends ObjectModel
{
    // ... definition array ...
    
    public static function getByIdOrder($id_order)
    {
        $sql = 'SELECT id_payment FROM ' . _DB_PREFIX_ . 'monei2_payment 
                WHERE id_order = ' . (int)$id_order;
        $id = Db::getInstance()->getValue($sql);
        return $id ? new self($id) : null;
    }
    
    public static function getByIdCart($id_cart)
    {
        $sql = 'SELECT id_payment FROM ' . _DB_PREFIX_ . 'monei2_payment 
                WHERE id_cart = ' . (int)$id_cart;
        $id = Db::getInstance()->getValue($sql);
        return $id ? new self($id) : null;
    }
}
```

### Update Repository Calls Throughout Module
Replace:
- `$this->getRepository(Monei2Payment::class)->findOneBy(['id_order' => $orderId])` 
  → `Monei2Payment::getByIdOrder($orderId)`
- `$this->getRepository(Monei2CustomerCard::class)->findBy(['id_customer' => $customerId])`
  → `Monei2CustomerCard::getByCustomer($customerId)`

## 7. Database Access Layer

### Replace Doctrine DBAL
- Remove `getDbalConnection()` method from `monei.php`
- Use `Db::getInstance()` for all database operations
- Convert any DBAL-specific queries to PrestaShop's database abstraction

### Example Conversion
```php
// PS8 with Doctrine:
$connection = $this->getDbalConnection();
$result = $connection->fetchAssoc($query);

// PS1.7:
$result = Db::getInstance()->getRow($query);
```

## 8. Admin Controllers

### Update Controller Structure
Ensure compatibility with PS1.7's `ModuleAdminController`:
- `AdminMoneiController.php`
- `AdminMoneiCapturePaymentController.php`

### Remove PS8-Specific Features
- Remove any usage of PS8 admin components
- Adapt to PS1.7 admin template system
- Update form builders if necessary

## 9. Template and Asset Updates

### Check Smarty Template Compatibility
- Review all `.tpl` files for PS8-specific variables
- Update any modern Smarty syntax to PS1.7 compatible version

### JavaScript Compatibility
- Ensure jQuery version compatibility
- Check for any PS8-specific JavaScript APIs

## 10. Testing Checklist

### Functional Testing
- [ ] Module installation
- [ ] Module configuration
- [ ] Payment method display in checkout
- [ ] Card payment flow
- [ ] Apple Pay integration
- [ ] Google Pay integration
- [ ] Bizum payment
- [ ] PayPal payment
- [ ] Payment confirmation
- [ ] Order creation
- [ ] Refund processing
- [ ] Customer card tokenization
- [ ] Saved cards management
- [ ] Admin order view
- [ ] Payment capture from admin
- [ ] Webhook handling

### Technical Testing
- [ ] PHP 7.4 compatibility
- [ ] Database operations
- [ ] Error handling
- [ ] Logging
- [ ] Cache clearing
- [ ] Multi-store compatibility
- [ ] Multi-language support

## Implementation Phases

### Phase 1: Core Infrastructure (Day 1-2)
1. Update module compatibility declaration
2. Create ObjectModel classes
3. Implement service locator pattern
4. Remove Doctrine dependencies

### Phase 2: Service Layer (Day 2-3)
1. Replace all service container usage
2. Update service instantiation
3. Convert repositories to static methods
4. Test database operations

### Phase 3: Controllers & Hooks (Day 3-4)
1. Update controller implementations
2. Replace PS8-specific hooks
3. Adapt admin functionality
4. Update routing if needed

### Phase 4: Testing & Debugging (Day 4-5)
1. Complete functional testing
2. Fix compatibility issues
3. Performance optimization
4. Documentation updates

## Risk Mitigation

### High Risk Areas
1. **Service Container Removal**: Requires extensive refactoring
   - Mitigation: Implement gradual replacement with thorough testing
   
2. **Doctrine to ObjectModel**: Complex data relationships
   - Mitigation: Preserve existing database structure, only change access layer
   
3. **Admin Features**: Some PS8 features may not have PS1.7 equivalents
   - Mitigation: Provide alternative implementations or graceful degradation

### Medium Risk Areas
1. **Hook System**: Different hook parameters between versions
2. **Asset Management**: Different handling of CSS/JS files
3. **API Changes**: PrestaShop core API differences

### Low Risk Areas
1. **Payment Gateway Integration**: MONEI SDK remains compatible
2. **Database Schema**: No changes required
3. **Frontend Templates**: Minor adjustments expected

## Success Criteria
- Module installs without errors on PS 1.7.8.x
- All payment methods function correctly
- Admin features work as expected
- No PHP errors with PHP 7.4
- Maintains backward compatibility with existing data

## Notes and Considerations
- Consider maintaining two branches: one for PS8+ and one for PS1.7
- Document any features that cannot be backported
- Update module documentation to reflect version-specific features
- Consider using feature flags for version-specific functionality

## Estimated Timeline
- **Total Duration**: 3-5 days development + 1-2 days testing
- **Developer Requirements**: PHP/PrestaShop expertise
- **Testing Requirements**: PS 1.7 test environment with various payment configurations