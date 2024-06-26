<?php
use Monei\ApiException;
use Monei\Model\MoneiPayment;
use Monei\Traits\ValidationHelpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiValidationModuleFrontController extends ModuleFrontController
{
    use ValidationHelpers;

    public function postProcess()
    {
        // If the module is not active anymore, no need to process anything.
        if ($this->module->active == false) {
            die();
        }

        $data = Tools::file_get_contents('php://input');
//         $data = '{
//     "id": "2dd531ab7b1ef837d7c3b034dd39803ae158eafe",
//     "accountId": "0e8dfe0b-2304-4ecb-8264-73bc1d74f06c",
//     "sequenceId": null,
//     "subscriptionId": null,
//     "providerReferenceId": "b3PIuwWqlndTLoXuSuV7U4nR10luTQMI",
//     "createdAt": 1719431526,
//     "updatedAt": 1719431573,
//     "amount": 3509,
//     "authorizationCode": "214744",
//     "billingDetails": {
//       "email": "test@presteamshop.com",
//       "name": "test, test",
//       "company": null,
//       "phone": null,
//       "address": {
//         "city": "Barcelona",
//         "country": "PT",
//         "line1": "Direccion 123, 12",
//         "line2": null,
//         "zip": "0891-451",
//         "state": null
//       }
//     },
//     "currency": "EUR",
//     "customer": {
//       "email": "test@presteamshop.com",
//       "name": "JASON RENDON",
//       "phone": null
//     },
//     "description": null,
//     "livemode": false,
//     "orderId": "00000056m526",
//     "paymentMethod": {
//       "method": "card",
//       "card": {
//         "brand": "visa",
//         "country": "PL",
//         "type": "credit",
//         "threeDSecure": true,
//         "threeDSecureVersion": "2.1.0",
//         "threeDSecureFlow": "CHALLENGE",
//         "last4": "4406",
//         "cardholderName": "JASON RENDON",
//         "cardholderEmail": null,
//         "expiration": 1777593600,
//         "bank": "Credit Agricole Bank Polska S.A.",
//         "tokenizationMethod": null
//       },
//       "bizum": null,
//       "paypal": null,
//       "cofidis": null,
//       "cofidisLoan": null,
//       "trustly": null,
//       "sepa": null,
//       "klarna": null,
//       "mbway": null
//     },
//     "refundedAmount": null,
//     "lastRefundAmount": null,
//     "lastRefundReason": null,
//     "cancellationReason": null,
//     "shippingDetails": {
//       "email": "test@presteamshop.com",
//       "name": "test, test",
//       "company": null,
//       "phone": null,
//       "address": {
//         "city": "Barcelona",
//         "country": "PT",
//         "line1": "Direccion 123, 12",
//         "line2": null,
//         "zip": "0891-451",
//         "state": null
//       }
//     },
//     "shop": {
//       "name": "PresTeamShop Test",
//       "country": null
//     },
//     "status": "SUCCEEDED",
//     "statusCode": "E000",
//     "statusMessage": "Transaction approved",
//     "sessionDetails": {
//       "ip": "181.54.0.132",
//       "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
//       "countryCode": "CO",
//       "lang": "es",
//       "deviceType": "desktop",
//       "deviceModel": null,
//       "browser": "Chrome",
//       "browserVersion": "126.0.0.0",
//       "browserAccept": "*/*",
//       "browserColorDepth": "24",
//       "browserScreenHeight": "1080",
//       "browserScreenWidth": "1920",
//       "browserTimezoneOffset": "300",
//       "os": "Windows",
//       "osVersion": "10",
//       "source": null,
//       "sourceVersion": null
//     },
//     "traceDetails": {
//       "ip": "206.81.18.68",
//       "userAgent": "MONEI/PrestaShop/1.0.0",
//       "countryCode": "DE",
//       "lang": "en",
//       "deviceType": "desktop",
//       "deviceModel": null,
//       "browser": null,
//       "browserVersion": null,
//       "browserAccept": null,
//       "os": null,
//       "osVersion": null,
//       "source": "MONEI/PrestaShop",
//       "sourceVersion": "1.0.0",
//       "userId": null,
//       "userEmail": null
//     }
//   }';

        try {
            // Check if the data is a valid JSON
            $json_array = $this->vJSON($data);
            if (!$json_array) {
                throw new ApiException('Invalid JSON');
            }

            // Parse the JSON to a MoneiPayment object
            $moneiPayment = new MoneiPayment($json_array);

            $this->module->createOrUpdateOrder($moneiPayment->getId());

            echo 'OK';
        } catch (ApiException $ex) {
            PrestaShopLogger::addLog(
                'MONEI - validation:postProcess - ' . $ex->getMessage(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
        } catch (Exception $ex) {
            PrestaShopLogger::addLog($ex->getMessage());
        }

        exit;
    }
}
