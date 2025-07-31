<?php

namespace PsMonei\Service\Monei;

use Monei;

/**
 * Service for handling MONEI status codes and messages
 */
class StatusCodeHandler
{
    /**
     * @var Monei
     */
    private $module;

    /**
     * StatusCodeHandler constructor.
     *
     * @param Monei $module
     */
    public function __construct(Monei $module)
    {
        $this->module = $module;
    }

    /**
     * Get a localized status message for a status code
     *
     * @param string $statusCode
     * @return string
     */
    public function getStatusMessage(string $statusCode): string
    {
        // Use individual l() calls for each status code so PrestaShop can extract them
        switch ($statusCode) {
            case 'E000':
                return $this->module->l('Transaction approved', 'statusCodeHandler');
            case 'E999':
                return $this->module->l('Service internal error. Please contact support', 'statusCodeHandler');
            case 'E101':
                return $this->module->l('Error with payment processor configuration. Check this in your dashboard or contact MONEI for support', 'statusCodeHandler');
            case 'E102':
                return $this->module->l('Invalid or inactive MID. Please contact the acquiring entity', 'statusCodeHandler');
            case 'E103':
                return $this->module->l('Operation not allowed/configured for this merchant. Please contact the acquiring entity or MONEI for support', 'statusCodeHandler');
            case 'E104':
                return $this->module->l('Partial captures are not enabled in your account, please contact MONEI support', 'statusCodeHandler');
            case 'E105':
                return $this->module->l('MOTO Payment are not enabled in your account, please contact MONEI support', 'statusCodeHandler');
            case 'E150':
                return $this->module->l('Invalid or malformed request. Please check the message format', 'statusCodeHandler');
            case 'E151':
                return $this->module->l('Missing or malformed signature/auth', 'statusCodeHandler');
            case 'E152':
                return $this->module->l('Error while decrypting request', 'statusCodeHandler');
            case 'E153':
                return $this->module->l('Pre-authorization is expired and cannot be canceled or captured', 'statusCodeHandler');
            case 'E154':
                return $this->module->l('The payment date cannot be less than the cancellation or capture date', 'statusCodeHandler');
            case 'E155':
                return $this->module->l('The cancellation date exceeded the date allowed for pre-authorized operations', 'statusCodeHandler');
            case 'E200':
                return $this->module->l('Transaction failed during payment processing', 'statusCodeHandler');
            case 'E201':
                return $this->module->l('Transaction declined by the card-issuing bank', 'statusCodeHandler');
            case 'E202':
                return $this->module->l('Transaction declined by the issuing bank', 'statusCodeHandler');
            case 'E203':
                return $this->module->l('Payment method not allowed', 'statusCodeHandler');
            case 'E204':
                return $this->module->l('Wrong or not allowed currency', 'statusCodeHandler');
            case 'E205':
                return $this->module->l('Incorrect reference / transaction does not exist', 'statusCodeHandler');
            case 'E207':
                return $this->module->l('Transaction failed: process time exceeded', 'statusCodeHandler');
            case 'E208':
                return $this->module->l('Transaction is currently being processed', 'statusCodeHandler');
            case 'E209':
                return $this->module->l('Duplicated operation', 'statusCodeHandler');
            case 'E210':
                return $this->module->l('Wrong or not allowed payment amount', 'statusCodeHandler');
            case 'E211':
                return $this->module->l('Refund declined by processor', 'statusCodeHandler');
            case 'E212':
                return $this->module->l('Transaction has already been captured', 'statusCodeHandler');
            case 'E213':
                return $this->module->l('Transaction has already been canceled', 'statusCodeHandler');
            case 'E214':
                return $this->module->l('The amount to be captured cannot exceed the pre-authorized amount', 'statusCodeHandler');
            case 'E215':
                return $this->module->l('The transaction to be captured has not been pre-authorized yet', 'statusCodeHandler');
            case 'E216':
                return $this->module->l('The transaction to be canceled has not been pre-authorized yet', 'statusCodeHandler');
            case 'E217':
                return $this->module->l('Transaction denied by processor to avoid duplicated operations', 'statusCodeHandler');
            case 'E218':
                return $this->module->l('Error during payment request validation', 'statusCodeHandler');
            case 'E219':
                return $this->module->l('Refund declined due to exceeded amount', 'statusCodeHandler');
            case 'E220':
                return $this->module->l('Transaction has already been fully refunded', 'statusCodeHandler');
            case 'E221':
                return $this->module->l('Transaction declined due to insufficient funds', 'statusCodeHandler');
            case 'E222':
                return $this->module->l('The user has canceled the payment', 'statusCodeHandler');
            case 'E223':
                return $this->module->l('Waiting for the transaction to be completed', 'statusCodeHandler');
            case 'E224':
                return $this->module->l('No reason to decline', 'statusCodeHandler');
            case 'E225':
                return $this->module->l('Refund not allowed', 'statusCodeHandler');
            case 'E226':
                return $this->module->l('Transaction cannot be completed, violation of law', 'statusCodeHandler');
            case 'E227':
                return $this->module->l('Stop Payment Order', 'statusCodeHandler');
            case 'E228':
                return $this->module->l('Strong Customer Authentication required', 'statusCodeHandler');
            case 'E300':
                return $this->module->l('Transaction declined due to security restrictions', 'statusCodeHandler');
            case 'E301':
                return $this->module->l('3D Secure authentication failed', 'statusCodeHandler');
            case 'E302':
                return $this->module->l('Authentication process timed out. Please try again', 'statusCodeHandler');
            case 'E303':
                return $this->module->l('An error occurred during the 3D Secure process', 'statusCodeHandler');
            case 'E304':
                return $this->module->l('Invalid or malformed 3D Secure request', 'statusCodeHandler');
            case 'E305':
                return $this->module->l('Exemption not allowed', 'statusCodeHandler');
            case 'E306':
                return $this->module->l('Exemption error', 'statusCodeHandler');
            case 'E307':
                return $this->module->l('Fraud control error', 'statusCodeHandler');
            case 'E308':
                return $this->module->l('External MPI received wrong. Please check the data', 'statusCodeHandler');
            case 'E309':
                return $this->module->l('External MPI not enabled. Please contact support', 'statusCodeHandler');
            case 'E310':
                return $this->module->l('Transaction confirmation rejected by the merchant', 'statusCodeHandler');
            case 'E500':
                return $this->module->l('Transaction declined during card payment process', 'statusCodeHandler');
            case 'E501':
                return $this->module->l('Card rejected: invalid card number', 'statusCodeHandler');
            case 'E502':
                return $this->module->l('Card rejected: wrong expiration date', 'statusCodeHandler');
            case 'E503':
                return $this->module->l('Card rejected: wrong CVC/CVV2 number', 'statusCodeHandler');
            case 'E504':
                return $this->module->l('Card number not registered', 'statusCodeHandler');
            case 'E505':
                return $this->module->l('Card is expired', 'statusCodeHandler');
            case 'E506':
                return $this->module->l('Error during payment authorization. Please try again', 'statusCodeHandler');
            case 'E507':
                return $this->module->l('Cardholder has canceled the payment', 'statusCodeHandler');
            case 'E508':
                return $this->module->l('Transaction declined: AMEX cards not accepted by payment processor', 'statusCodeHandler');
            case 'E509':
                return $this->module->l('Card blocked temporarily or under suspicion of fraud', 'statusCodeHandler');
            case 'E510':
                return $this->module->l('Card does not allow pre-authorization operations', 'statusCodeHandler');
            case 'E511':
                return $this->module->l('CVC/CVV2 number is required', 'statusCodeHandler');
            case 'E512':
                return $this->module->l('Unsupported card type', 'statusCodeHandler');
            case 'E513':
                return $this->module->l('Transaction type not allowed for this type of card', 'statusCodeHandler');
            case 'E514':
                return $this->module->l('Transaction declined by card issuer', 'statusCodeHandler');
            case 'E515':
                return $this->module->l('Implausible card data', 'statusCodeHandler');
            case 'E516':
                return $this->module->l('Incorrect PIN', 'statusCodeHandler');
            case 'E517':
                return $this->module->l('Transaction not allowed for cardholder', 'statusCodeHandler');
            case 'E518':
                return $this->module->l('The amount exceeds the card limit', 'statusCodeHandler');
            case 'E600':
                return $this->module->l('Transaction declined during ApplePay/GooglePay payment process', 'statusCodeHandler');
            case 'E601':
                return $this->module->l('Incorrect ApplePay or GooglePay configuration', 'statusCodeHandler');
            case 'E620':
                return $this->module->l('Transaction declined during PayPal payment process', 'statusCodeHandler');
            case 'E621':
                return $this->module->l('Transaction declined during PayPal payment process: invalid currency', 'statusCodeHandler');
            case 'E640':
                return $this->module->l('Bizum transaction declined after three authentication attempts', 'statusCodeHandler');
            case 'E641':
                return $this->module->l('Bizum transaction declined due to failed authorization', 'statusCodeHandler');
            case 'E642':
                return $this->module->l('Bizum transaction declined due to insufficient funds', 'statusCodeHandler');
            case 'E643':
                return $this->module->l('Bizum transaction canceled: the user does not want to continue', 'statusCodeHandler');
            case 'E644':
                return $this->module->l('Bizum transaction rejected by destination bank', 'statusCodeHandler');
            case 'E645':
                return $this->module->l('Bizum transaction rejected by origin bank', 'statusCodeHandler');
            case 'E646':
                return $this->module->l('Bizum transaction rejected by processor', 'statusCodeHandler');
            case 'E647':
                return $this->module->l('Bizum transaction failed while connecting with processor. Please try again', 'statusCodeHandler');
            case 'E648':
                return $this->module->l('Bizum transaction failed, payee is not found', 'statusCodeHandler');
            case 'E649':
                return $this->module->l('Bizum transaction failed, payer is not found', 'statusCodeHandler');
            case 'E650':
                return $this->module->l('Bizum REST not implemented', 'statusCodeHandler');
            case 'E651':
                return $this->module->l('Bizum transaction declined due to failed authentication', 'statusCodeHandler');
            case 'E652':
                return $this->module->l('The customer has disabled Bizum, please use another payment method', 'statusCodeHandler');
            case 'E680':
                return $this->module->l('Transaction declined during ClickToPay payment process', 'statusCodeHandler');
            case 'E681':
                return $this->module->l('Incorrect ClickToPay configuration', 'statusCodeHandler');
            case 'E700':
                return $this->module->l('Transaction declined during Cofidis payment process', 'statusCodeHandler');
            default:
                return sprintf($this->module->l('Unknown status code: %s', 'statusCodeHandler'), $statusCode);
        }
    }

    /**
     * Check if a status code indicates success
     *
     * @param string|null $statusCode
     * @return bool
     */
    public function isSuccessCode(?string $statusCode): bool
    {
        return $statusCode === 'E000';
    }

    /**
     * Check if a status code indicates an error
     *
     * @param string|null $statusCode
     * @return bool
     */
    public function isErrorCode(?string $statusCode): bool
    {
        return $statusCode !== null && $statusCode !== 'E000';
    }

    /**
     * Extract status code from payment data
     *
     * @param array $data
     * @return string|null
     */
    public function extractStatusCodeFromData(array $data): ?string
    {
        if (isset($data['statusCode'])) {
            return $data['statusCode'];
        }

        if (isset($data['status_code'])) {
            return $data['status_code'];
        }

        if (isset($data['response']) && is_array($data['response'])) {
            if (isset($data['response']['statusCode'])) {
                return $data['response']['statusCode'];
            }
        }

        return null;
    }

    /**
     * Extract status message from payment data
     *
     * @param array $data
     * @return string|null
     */
    public function extractStatusMessageFromData(array $data): ?string
    {
        if (isset($data['statusMessage'])) {
            return $data['statusMessage'];
        }

        if (isset($data['status_message'])) {
            return $data['status_message'];
        }

        if (isset($data['message'])) {
            return $data['message'];
        }

        if (isset($data['response']) && is_array($data['response'])) {
            if (isset($data['response']['statusMessage'])) {
                return $data['response']['statusMessage'];
            }
            if (isset($data['response']['message'])) {
                return $data['response']['message'];
            }
        }

        return null;
    }
}