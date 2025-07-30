# Product Requirements Document: MONEI Payment Method Display Enhancement

## Product overview

**Document version**: 1.0  
**Product summary**: Enhancement to the MONEI PrestaShop payment module to correctly display payment method types and payment details in order management interfaces.

## Goals

### Business goals
- Improve payment transparency and traceability for merchants using the MONEI payment gateway
- Enhance operational efficiency by providing accurate payment information in order details
- Maintain consistency with industry-standard payment display practices
- Reduce support inquiries related to payment method identification

### User goals
- **Merchants**: Easily identify which payment method was used for each order (Card, Apple Pay, Google Pay)
- **Store administrators**: Access complete payment details (last 4 digits, card brand, expiration date, cardholder name) directly in PrestaShop admin
- **Customer service representatives**: Quickly resolve payment-related customer inquiries with accurate payment information

### Non-goals
- Storing full card numbers or sensitive payment data
- Modifying the customer-facing checkout experience
- Changing the MONEI API integration or payment flow
- Adding new payment methods or features beyond display improvements

## User personas

### Primary merchant administrator
- **Role**: E-commerce store owner or administrator
- **Technical level**: Basic to intermediate
- **Needs**: Clear visibility of payment methods used, accurate order records for accounting
- **Access**: Full admin access to PrestaShop backend

### Customer service representative
- **Role**: Handles customer inquiries and order issues
- **Technical level**: Basic
- **Needs**: Quick access to payment information to resolve customer questions
- **Access**: Limited admin access focused on order management

### Financial controller
- **Role**: Manages reconciliation and financial reporting
- **Technical level**: Basic technical skills, advanced financial knowledge
- **Needs**: Accurate payment method data for transaction reconciliation
- **Access**: Read-only access to order and payment data

## Functional requirements

### High priority
1. **FR-001**: Display correct payment method names
   - System must show "Apple Pay" when tokenizationMethod is 'applePay'
   - System must show "Google Pay" when tokenizationMethod is 'googlePay'
   - System must show "MONEI Card" for standard card payments
   - Display must be consistent across all admin interfaces

2. **FR-002**: Store payment details in OrderPayment
   - System must save card_number as "•••• XXXX" format using last4 digits
   - System must save card_brand with actual brand or wallet type
   - System must save card_expiration in MM/YY format
   - System must save card_holder with cardholder name

3. **FR-003**: Maintain data integrity
   - System must handle cases where payment details are unavailable
   - System must not break existing order displays
   - System must gracefully handle null or missing values

### Medium priority
4. **FR-004**: Update payment method formatter logic
   - Prioritize tokenizationMethod check before brand check
   - Follow the same logic pattern as the Magento implementation
   - Ensure backward compatibility with existing orders

5. **FR-005**: Display enhancement in order details
   - Payment details must appear in the standard PrestaShop payment information section
   - Format must match PrestaShop's native display patterns
   - All payment fields must show "Not defined" when data is unavailable

### Low priority
6. **FR-006**: Logging and debugging
   - System should log payment method detection for troubleshooting
   - Include relevant payment data in debug logs (excluding sensitive information)

## User experience

### Entry points
- **Order list view**: Merchants see the correct payment method in the order list
- **Order detail view**: Full payment information displayed in the payment section
- **Payment search/filter**: Ability to filter orders by payment method type

### Core experience
1. Merchant views order list and immediately identifies Apple Pay/Google Pay transactions
2. Clicking into order details reveals complete payment information
3. Payment details section shows:
   - Payment method: Apple Pay / Google Pay / MONEI Card
   - Card number: •••• 1234
   - Card brand: Visa / Mastercard / etc.
   - Expiration: 12/25
   - Cardholder: John Doe

### Advanced features
- Export functionality includes accurate payment method data
- Search and filter capabilities work with new payment method names
- API responses include enhanced payment details for third-party integrations

### UI/UX highlights
- Consistent iconography for different payment methods
- Clear visual distinction between wallet payments and card payments
- Standardized formatting for masked card numbers
- Responsive display that works across all device sizes

## Narrative

As a merchant managing my PrestaShop store, I receive an order notification and navigate to my admin panel. In the orders list, I immediately see that this order was paid with "Apple Pay" rather than a generic "MONEI Card" label. When I click into the order details, the payment information section clearly shows me the payment method along with the last four digits of the card (•••• 4242), the card brand (Visa), expiration date (12/25), and the customer's name (John Smith). This complete information helps me quickly verify the payment when the customer contacts support, and I can confidently process refunds knowing exactly which payment method was used. The clear distinction between Apple Pay, Google Pay, and regular card payments also helps me understand my customers' payment preferences for business analysis.

## Success metrics

### User-centric metrics
- Reduction in support tickets related to payment method identification (target: 50% reduction)
- Time to locate payment information decreased (target: from 30 seconds to 5 seconds)
- User satisfaction with payment information clarity (target: 90% positive feedback)

### Business metrics
- Improved order processing efficiency (target: 20% faster payment verification)
- Reduced payment reconciliation errors (target: 75% reduction)
- Increased merchant retention due to improved functionality

### Technical metrics
- Zero regression in existing payment functionality
- Payment detail storage success rate: 99.9%
- Performance impact: <50ms additional processing time per order
- Database storage increase: <100 bytes per order

## Technical considerations

### Integration points
- MONEI PHP SDK: Leverage existing payment response data structure
- PrestaShop OrderPayment class: Utilize native fields for payment storage
- PaymentMethodFormatter service: Modify logic to prioritize tokenizationMethod
- Database: No schema changes required, uses existing OrderPayment fields

### Data storage and privacy
- Only store masked card numbers (last 4 digits)
- No storage of sensitive payment data or full card numbers
- Comply with PCI DSS requirements for payment data handling
- Maintain data retention policies consistent with PrestaShop standards

### Scalability and performance
- Minimal performance impact: only additional database writes during order creation
- No impact on checkout performance or customer experience
- Efficient data retrieval using existing PrestaShop queries
- Support for high-volume stores with thousands of daily transactions

### Potential challenges
- Handling legacy orders without tokenizationMethod data
- Ensuring compatibility across different PrestaShop 8.x versions
- Managing edge cases where payment data is partially available
- Maintaining module upgrade path for existing installations

## Milestones & sequencing

### Project estimate
- Total duration: 2-3 weeks
- Development effort: 40-60 hours
- Testing and QA: 20 hours
- Documentation and deployment: 10 hours

### Recommended team
- 1 Senior PHP developer (PrestaShop experience required)
- 1 QA engineer
- 1 Technical writer (part-time)

### Phase 1: Core implementation (Week 1)
- Update PaymentMethodFormatter logic
- Implement OrderPayment data storage
- Basic testing and validation

### Phase 2: Edge cases and testing (Week 2)
- Handle null/missing data scenarios
- Comprehensive testing across PrestaShop versions
- Performance optimization

### Phase 3: Documentation and release (Week 3)
- Update module documentation
- Create migration guide for existing installations
- Prepare release notes and deployment package

## User stories

### US-001: View correct payment method in order list
**Description**: As a merchant, I want to see "Apple Pay" or "Google Pay" in my order list instead of "MONEI Card" so that I can quickly identify which payment method was used.  
**Acceptance criteria**:
- Order list displays "Apple Pay" when tokenizationMethod is 'applePay'
- Order list displays "Google Pay" when tokenizationMethod is 'googlePay'
- Order list displays "MONEI Card" for standard card transactions
- Payment method column is sortable and filterable

### US-002: View complete payment details in order view
**Description**: As a store administrator, I want to see all payment details (card number, brand, expiration, holder name) in the order details page so that I can verify payments and handle customer inquiries.  
**Acceptance criteria**:
- Payment section shows masked card number as •••• XXXX
- Card brand displays correctly (Visa, Mastercard, etc.)
- Expiration date shows in MM/YY format
- Cardholder name is displayed when available
- Missing data shows as "Not defined"

### US-003: Filter orders by payment method type
**Description**: As a financial controller, I want to filter orders by specific payment methods (Apple Pay, Google Pay, Card) so that I can analyze payment method usage.  
**Acceptance criteria**:
- Order filter includes options for Apple Pay, Google Pay, and MONEI Card
- Filters work correctly with the new payment method names
- Filter results are accurate and complete
- Export functionality includes correct payment method names

### US-004: Handle orders without payment details gracefully
**Description**: As a merchant, I want the system to handle orders that don't have complete payment information without breaking so that all my orders remain accessible.  
**Acceptance criteria**:
- Orders without payment details display "Not defined" for missing fields
- System doesn't throw errors when payment data is null
- Existing orders continue to display correctly
- New orders always attempt to capture available payment data

### US-005: Maintain backward compatibility
**Description**: As a merchant with existing orders, I want my historical order data to remain accessible and display correctly after the update so that I don't lose any information.  
**Acceptance criteria**:
- Existing orders display without errors
- Historical payment data is preserved
- Module update doesn't require data migration
- Performance is not degraded for stores with many orders

### US-006: Access payment details via API
**Description**: As a developer integrating with PrestaShop, I want to access the enhanced payment details through the API so that I can build custom reports and integrations.  
**Acceptance criteria**:
- API returns correct payment method type
- API includes all payment detail fields
- API response format is backward compatible
- Documentation is updated with new fields

### US-007: Debug payment method detection
**Description**: As a technical support specialist, I want to see logs of payment method detection so that I can troubleshoot issues when they occur.  
**Acceptance criteria**:
- System logs payment method detection process
- Logs include tokenizationMethod and final determination
- Sensitive data is excluded from logs
- Log level is configurable

### US-008: Secure access to payment information
**Description**: As a store owner, I want payment details to be accessible only to authorized users so that sensitive information remains protected.  
**Acceptance criteria**:
- Payment details respect PrestaShop permission system
- Only users with order view permissions can see payment data
- API access requires proper authentication
- No payment data is exposed in public areas

### US-009: Export orders with correct payment data
**Description**: As an accountant, I want to export order data with accurate payment method information so that I can prepare financial reports.  
**Acceptance criteria**:
- CSV export includes payment method column
- Payment method shows as Apple Pay, Google Pay, or MONEI Card
- Export includes available payment details
- Data format is consistent and parseable

### US-010: Quick payment verification
**Description**: As a customer service representative, I want to quickly verify a customer's payment method during support calls so that I can assist them efficiently.  
**Acceptance criteria**:
- Payment method is prominently displayed in order details
- Payment details are easy to read and understand
- Information loads quickly without delays
- All relevant payment data is in one location