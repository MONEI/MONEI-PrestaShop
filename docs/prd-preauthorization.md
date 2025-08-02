# MONEI PrestaShop pre-authorization functionality

**Version:** 1.0  
**Date:** August 1, 2025  
**Project:** PrestaShop MONEI Payment Module - Pre-Authorization Implementation

## Product overview

This document outlines the requirements for implementing pre-authorization (AUTH) functionality in the MONEI PrestaShop payment module. Currently, the module only supports immediate capture (SALE) transactions. This enhancement will enable merchants to authorize payments without immediately capturing funds, providing greater flexibility for order fulfillment workflows, inventory management, and fraud prevention.

The implementation will add configuration options for payment actions, extend the admin interface for manual capture operations, and introduce new order status workflows while maintaining compatibility with existing functionality.

## Goals

### Business goals

- Enable merchants to authorize payments without immediate capture, improving cash flow management
- Reduce chargebacks and fraud by allowing order verification before fund capture
- Support complex fulfillment workflows where capture timing depends on inventory or shipping
- Increase competitive positioning by matching functionality available in other e-commerce platforms
- Maintain backwards compatibility with existing SALE transaction workflows

### User goals

- **Merchants**: Control when payments are captured to align with business processes
- **Store administrators**: Easily manage authorized payments through familiar admin interfaces  
- **Customers**: Experience seamless checkout regardless of authorization method selected
- **Developers**: Implement and maintain the feature using existing module architecture

### Non-goals

- Support pre-authorization for MBway and Multibanco payment methods (not supported by MONEI API)
- Automatic capture based on order status changes (manual capture only)
- Partial authorization functionality (full amount authorization only)
- Integration with external inventory management systems

## User personas

### Primary merchant owner
**Profile**: Small to medium business owner using PrestaShop for online sales  
**Needs**: Simple configuration options, clear payment status visibility, reliable capture process  
**Technical level**: Basic - relies on intuitive interfaces and clear documentation  
**Access level**: Full module configuration and order management

### Store administrator
**Profile**: Employee managing daily operations including order processing and customer service  
**Needs**: Efficient tools to review and capture authorized payments, clear status indicators  
**Technical level**: Intermediate - comfortable with admin interfaces and basic troubleshooting  
**Access level**: Order management and payment capture functions

### System integrator/developer
**Profile**: Technical professional implementing and customizing PrestaShop installations  
**Needs**: Well-documented APIs, consistent architecture patterns, extensible design  
**Technical level**: Advanced - full understanding of PrestaShop and payment processing  
**Access level**: Full system access including configuration, customization, and debugging

### Role-based access
- **Super Admin**: Full configuration access, all payment management functions
- **Order Manager**: View payment status, perform capture operations, access payment history
- **Customer Service**: Read-only access to payment status and transaction details

## Functional requirements

### High priority
- Configuration option to select payment action (Authorize vs Authorize and Capture)
- Support AUTH transaction type for compatible payment methods (Card, Apple Pay, Google Pay, PayPal, Bizum)
- Manual capture functionality accessible from admin order pages
- Order status progression: Pending → Authorized → Payment Accepted
- Integration with existing MONEI API for authorization and capture operations

### Medium priority
- Enhanced admin order page display showing authorization details and capture options
- Payment history tracking for authorization and capture events
- Email notifications for authorization status changes
- Partial capture support with amount specification
- Bulk capture operations for multiple authorized orders

### Low priority
- Automatic capture scheduling based on configurable time limits
- Advanced reporting for authorization vs capture metrics
- Integration with PrestaShop's advanced order management features
- API endpoints for third-party integrations

## User experience

### Entry points
- **Module configuration**: Payment action selection in MONEI settings
- **Admin order management**: Capture buttons and status displays on order pages
- **Customer checkout**: Transparent experience regardless of authorization method

### Core experience
The authorization workflow maintains familiar patterns while adding new capabilities. Merchants configure payment action once in module settings. When pre-authorization is enabled, customers complete checkout normally but orders remain in "Authorized" status until manually captured. Store administrators access capture functions directly from order pages using intuitive controls integrated with existing payment information displays.

### Advanced features
- **Partial capture**: Specify exact amount to capture (up to authorized amount)
- **Multiple captures**: Support for split captures over time
- **Capture validation**: Prevent over-capture and handle expired authorizations
- **Detailed logging**: Complete audit trail of authorization and capture events

### UI/UX highlights
- Clear visual distinction between authorized and captured payments
- One-click capture buttons with amount confirmation
- Real-time status updates without page refresh
- Consistent styling with existing PrestaShop admin interface
- Mobile-responsive design for tablet-based order management

## Narrative

As a merchant, I want to accept customer payments without immediately capturing funds so I can verify orders, check inventory, and prevent fraud before completing transactions. When a customer places an order, their payment method is authorized for the full amount, giving me confidence the funds are available while providing flexibility in my fulfillment process. From my admin dashboard, I can easily review authorized orders and capture payments with a simple click when I'm ready to ship products. This approach reduces my risk of chargebacks while improving cash flow management and customer satisfaction.

## Success metrics

### User-centric metrics
- Time to configure pre-authorization: Less than 2 minutes for typical merchant
- Admin capture completion rate: 95% success rate for manual capture operations
- Support ticket reduction: 50% decrease in payment-related inquiries
- User satisfaction: 8/10 or higher rating for authorization workflow usability

### Business metrics
- Feature adoption rate: 30% of merchants enable pre-authorization within 3 months
- Order value increase: 5% improvement in average order value due to reduced cart abandonment
- Chargeback reduction: 15% decrease in payment disputes for participating merchants
- Competitive positioning: Feature parity with major payment modules

### Technical metrics
- API response time: Authorization and capture operations complete in under 2 seconds
- System reliability: 99.9% uptime for authorization and capture functionality
- Error rate: Less than 0.1% of authorization or capture operations fail
- Performance impact: No measurable impact on existing checkout or admin performance

## Technical considerations

### Integration points
- **MONEI API**: Extend existing MoneiService to support AUTH transaction type and capture endpoint
- **PrestaShop Configuration**: Add new configuration fields using existing Configuration class patterns
- **Order Management**: Integrate with PrestaShop's order status system and hooks
- **Admin Interface**: Extend displayAdminOrder hook implementation for capture controls

### Data storage and privacy
- Leverage existing monei2_payment table with AUTHORIZED status support
- Store authorization details in monei2_history table for audit trail
- Maintain PCI compliance by avoiding storage of sensitive payment data
- Implement data retention policies aligned with existing module standards

### Scalability and performance
- Utilize existing service container architecture for new authorization services
- Implement database indexing for efficient authorized payment queries
- Design capture operations to handle high-volume merchant scenarios
- Maintain backwards compatibility with existing payment processing logic

### Potential challenges
- **API Integration**: Ensuring reliable communication with MONEI capture endpoints
- **Status Synchronization**: Maintaining consistent state between MONEI and PrestaShop
- **Error Handling**: Graceful degradation when capture operations fail
- **Testing Coverage**: Comprehensive testing across all supported payment methods

## Milestones and sequencing

### Project estimate
**Total duration**: 6-8 weeks  
**Team size**: 2-3 developers (1 senior, 1-2 junior)  
**Effort**: Approximately 120-160 development hours

### Suggested phases

**Phase 1: Foundation (Weeks 1-2)**
- Extend configuration system for payment action selection
- Update MoneiService for AUTH transaction support
- Implement basic authorization workflow
- Unit testing for core authorization logic

**Phase 2: Admin interface (Weeks 3-4)**
- Develop capture functionality in admin order pages
- Enhanced payment status displays
- Integration with existing displayAdminOrder hook
- Admin interface testing and refinement

**Phase 3: Advanced features (Weeks 5-6)**
- Partial capture support with amount validation
- Enhanced error handling and user feedback
- Payment history and audit trail improvements
- Comprehensive integration testing

**Phase 4: Polish and deployment (Weeks 7-8)**
- User acceptance testing with merchant feedback
- Documentation updates and translation support
- Performance optimization and security review
- Release preparation and rollout planning

## User stories

### US-001: Configure payment action
**Description**: As a merchant, I want to configure whether payments should be authorized only or authorized and captured immediately, so I can control when funds are collected from customers.

**Acceptance criteria**:
- Configuration option appears in MONEI module settings
- Two options available: "Authorize only" and "Authorize and Capture"
- Default setting is "Authorize and Capture" to maintain backwards compatibility
- Setting changes take effect immediately for new orders
- Configuration is validated and saved correctly

### US-002: Process authorization-only transactions
**Description**: As a customer, I want to complete checkout when the merchant has selected authorization-only mode, so I can place orders without noticing any difference in the payment process.

**Acceptance criteria**:
- Checkout process remains identical from customer perspective
- Payment methods supporting AUTH work correctly (Card, Apple Pay, Google Pay, PayPal, Bizum)
- MBway and Multibanco automatically use SALE mode regardless of configuration
- Transaction is created with AUTH type in MONEI system
- Order is created with "Authorized" status in PrestaShop

### US-003: View authorized payments in admin
**Description**: As a store administrator, I want to see which orders have authorized but uncaptured payments, so I can identify orders ready for capture.

**Acceptance criteria**:
- Order list shows clear indicator for authorized payments
- Order detail page displays authorization amount and date
- Payment status distinguishes between authorized and captured
- Authorization expiration date is visible when available
- Interface matches existing PrestaShop admin styling

### US-004: Capture authorized payments manually
**Description**: As a store administrator, I want to capture authorized payments from the admin order page, so I can collect funds when I'm ready to fulfill the order.

**Acceptance criteria**:
- Capture button appears on order pages with authorized payments
- Capture process requires confirmation before execution
- Full amount capture is default behavior
- Success/failure feedback is immediate and clear
- Order status updates to "Payment Accepted" after successful capture

### US-005: Handle capture failures gracefully
**Description**: As a store administrator, I want to receive clear error messages when payment capture fails, so I can take appropriate action to resolve issues.

**Acceptance criteria**:
- Error messages are specific and actionable
- Failed capture attempts are logged for troubleshooting
- Order status remains "Authorized" after failed capture
- Administrator can retry capture operation
- Critical errors are escalated with appropriate notifications

### US-006: Support partial payment capture
**Description**: As a store administrator, I want to capture a partial amount of an authorized payment, so I can handle scenarios like partial shipments or order modifications.

**Acceptance criteria**:
- Capture form allows amount specification
- Amount validation prevents over-capture beyond authorized total
- Remaining authorized amount is calculated and displayed
- Multiple partial captures are supported up to total authorization
- Payment history tracks all capture events with amounts

### US-007: Track authorization and capture history
**Description**: As a store administrator, I want to see complete history of authorization and capture events, so I can audit payment processing and resolve customer inquiries.

**Acceptance criteria**:
- All authorization events are logged with timestamps
- Capture attempts (successful and failed) are recorded
- History includes amounts, status changes, and error details
- History is accessible from order detail pages
- Data is retained according to module's standard retention policies

### US-008: Secure access to capture functionality
**Description**: As a system administrator, I want to ensure only authorized personnel can capture payments, so payment operations remain secure and auditable.

**Acceptance criteria**:
- Capture functionality respects PrestaShop's existing permission system
- User roles can be configured to allow/deny capture operations
- All capture operations are logged with user identification
- Session validation prevents unauthorized access
- Failed authentication attempts are monitored and logged

### US-009: Handle authorization expiration
**Description**: As a store administrator, I want to be notified when payment authorizations are approaching expiration, so I can capture payments before they become invalid.

**Acceptance criteria**:
- Authorization expiration dates are tracked and displayed
- Visual indicators highlight payments near expiration
- Expired authorizations cannot be captured
- Clear messaging explains expiration status
- Alternative payment collection methods are suggested for expired authorizations

### US-010: Maintain backwards compatibility
**Description**: As a merchant with existing MONEI integration, I want the new authorization functionality to not interfere with my current payment processing, so I can continue operations without disruption.

**Acceptance criteria**:
- Existing configuration and payments continue working unchanged
- Default behavior remains "Authorize and Capture" for new installations
- Database migrations handle existing payment records correctly
- API integrations maintain existing functionality
- Module upgrades preserve merchant settings and preferences

### US-011: Process webhook notifications for authorizations
**Description**: As a system, I want to handle MONEI webhook notifications for authorization events, so payment status remains synchronized between systems.

**Acceptance criteria**:
- Webhook endpoint processes AUTH status notifications
- Order status updates correctly based on webhook data
- Failed webhook processing includes retry mechanisms
- Webhook validation ensures security and authenticity
- Status synchronization handles edge cases and race conditions

### US-012: Support multi-currency authorization
**Description**: As an international merchant, I want pre-authorization to work correctly with multiple currencies, so I can serve customers worldwide with consistent payment processing.

**Acceptance criteria**:
- Authorization amounts respect order currency settings
- Currency conversion is handled correctly during capture
- Multi-currency display is accurate in admin interfaces
- Exchange rate fluctuations are handled appropriately
- Currency validation prevents processing errors