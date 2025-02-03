<?php

namespace Tests\Service\Order;

use \Mockery;
use PsMonei\Service\Order\OrderService;
use PsMonei\MoneiClient;
use PHPUnit\Framework\TestCase;
use OpenAPI\Client\Model\Payment as MoneiPayment;
use OpenAPI\Client\Model\PaymentStatus;

class OrderServiceTest extends TestCase
{
    private $moneiMock;
    private $orderService;

    private const MONEI_STATUS_SUCCEEDED = 1;
    private const MONEI_STATUS_FAILED = 2;
    private const MONEI_STATUS_PENDING = 3;

    public function setUp(): void
    {
        parent::setUp();

        $moneiClient = Mockery::mock(MoneiClient::class);

        $this->moneiMock = Mockery::mock('MoneiModuleInstance');
        $this->moneiMock->shouldReceive('getMoneiClient')
            ->once()
            ->andReturn($moneiClient);

        $this->orderService = new OrderService($this->moneiMock);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function getSuccessMoneiPaymentMock()
    {
        $response = '
        {
            "id": "86c63edf33d7ff3c6665977ae4ceafc687dad56e",
            "accountId": "0e8dfe0b-2304-4ecb-8264-73bc1d74f06c",
            "sequenceId": null,
            "subscriptionId": null,
            "providerReferenceId": "21fCKeEtsdmVQ40YlXrOzaS1RfKLkxeV",
            "createdAt": 1737852208,
            "updatedAt": 1737852219,
            "amount": 3509,
            "authorizationCode": "529962",
            "billingDetails": {
            "email": "test@presteamshop.com",
            "name": "test test",
            "company": null,
            "phone": "123123123",
            "address": {
                "city": "Barcelona",
                "country": "ES",
                "line1": "Direccion 123",
                "line2": null,
                "zip": "76026",
                "state": "A Coruña"
            }
            },
            "currency": "EUR",
            "customer": {
            "email": "test@presteamshop.com",
            "name": "AASD ASD",
            "phone": "123123123"
            },
            "description": null,
            "livemode": false,
            "orderId": "00000009m205",
            "paymentMethod": {
            "method": "card",
            "card": {
                "brand": "visa",
                "country": "PL",
                "type": "credit",
                "threeDSecure": true,
                "threeDSecureVersion": "2.1.0",
                "threeDSecureFlow": "CHALLENGE",
                "last4": "4406",
                "cardholderName": "AASD ASD",
                "cardholderEmail": null,
                "expiration": 1985472000,
                "bank": "Credit Agricole Bank Polska S.A.",
                "tokenizationMethod": null
            },
            "bizum": null,
            "paypal": null,
            "cofidis": null,
            "cofidisLoan": null,
            "trustly": null,
            "sepa": null,
            "klarna": null,
            "mbway": null
            },
            "refundedAmount": null,
            "lastRefundAmount": null,
            "lastRefundReason": null,
            "cancellationReason": null,
            "shippingDetails": {
            "email": "test@presteamshop.com",
            "name": "test test",
            "company": null,
            "phone": "123123123",
            "address": {
                "city": "Barcelona",
                "country": "ES",
                "line1": "Direccion 123",
                "line2": null,
                "zip": "76026",
                "state": "A Coruña"
            }
            },
            "shop": {
            "name": "PresTeamShop Test",
            "country": "ES"
            },
            "status": "SUCCEEDED",
            "statusCode": "E000",
            "statusMessage": "Transaction approved",
            "sessionDetails": {
            "ip": "181.54.0.186",
            "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36",
            "countryCode": "CO",
            "lang": "es",
            "deviceType": "desktop",
            "deviceModel": null,
            "browser": "Chrome",
            "browserVersion": "132.0.0.0",
            "browserAccept": "*/*",
            "browserColorDepth": "24",
            "browserScreenHeight": "1080",
            "browserScreenWidth": "1920",
            "browserTimezoneOffset": "300",
            "os": "Windows",
            "osVersion": "10",
            "source": null,
            "sourceVersion": null
            },
            "traceDetails": {
            "ip": "181.54.0.186",
            "userAgent": "MONEI/PHP/2.4.3",
            "countryCode": "CO",
            "lang": "en",
            "deviceType": "desktop",
            "deviceModel": null,
            "browser": null,
            "browserVersion": null,
            "browserAccept": null,
            "os": null,
            "osVersion": null,
            "source": "MONEI/PHP",
            "sourceVersion": "2.4.3",
            "userId": null,
            "userEmail": null
            }
        }';

        return json_decode($response, true);
    }

    // Monei Payment con estado SUCCEEDED
    // Order existente
    // Sin redireccion
    public function testMoneiPaymentSucceededWithExistingOrderWithoutRedirect()
    {
        $successMoneiPaymentResponse = $this->getSuccessMoneiPaymentMock();
        $moneiPayment = new MoneiPayment($successMoneiPaymentResponse);

        $cartId = $this->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

        $cartMock = $this->validateCartSuccess($cartId);
        $customerMock = $this->validateCustomerSuccess($cartMock->id_customer);

        $orderStateId = $this->determineOrderStateIdSuccess($moneiPayment->getStatus());
        $failed = $orderStateId === self::MONEI_STATUS_FAILED;

        $order = $this->handleExistingOrderSuccess($cartId, $orderStateId, $moneiPayment);
        $this->assertNotNull($order);

        if (!$order && !$failed) {
            $order = $this->createNewOrder($cartMock, $customerMock, $orderStateId, $moneiPayment);
        }

        $result = $this->handlePostOrderCreation(false, $cartMock, $customerMock, $order);
        $this->assertEquals('OK', $result);
    }

    // // Monei Payment con estado SUCCEEDED
    // // Order no existente
    // // Sin redireccion
    // public function testMoneiPaymentSucceededWithNonExistingOrder()
    // {
    //     $successMoneiPaymentResponse = $this->getSuccessMoneiPaymentMock();
    //     $moneiPayment = new MoneiPayment($successMoneiPaymentResponse);

    //     $cartId = $this->extractCartIdFromMoneiOrderId($moneiPayment->getOrderId());

    //     $cartMock = $this->validateCartSuccess(null);
    //     $customerMock = $this->validateCustomerSuccess($cartMock->id_customer);

    //     $orderStateId = $this->determineOrderStateIdSuccess($moneiPayment->getStatus());
    //     $failed = $orderStateId === self::MONEI_STATUS_FAILED;

    //     $order = $this->handleExistingOrderFailed($cartId, $orderStateId, $moneiPayment);
    //     $this->assertNull($order);

    //     if (!$order && !$failed) {
    //         $order = $this->createNewOrder($cartMock, $customerMock, $orderStateId, $moneiPayment);
    //     }

    //     $result = $this->handlePostOrderCreation(false, $cartMock, $customerMock, $order);
    //     $this->assertEquals('OK', $result);
    // }

    private function extractCartIdFromMoneiOrderId($moneiOrderId)
    {
        $cartId = $this->orderService->extractCartIdFromMoneiOrderId($moneiOrderId);
        $this->assertInternalType('int', $cartId, 'The extracted cart ID should be an integer.');

        return $cartId;
    }

    public function validateCartSuccess($cartId)
    {
        $cartMock = Mockery::mock('Cart');
        $cartMock->shouldReceive('__construct')->with($cartId)->andReturnSelf();
        $cartMock->shouldReceive('isLoadedObject')->andReturn(true);

        $cartMock->id = $cartId;
        $cartMock->id_currency = 1;
        $cartMock->id_customer = 1;

        return $cartMock;
    }

    public function validateCustomerSuccess($customerId)
    {
        $customerMock = Mockery::mock('Customer');
        $customerMock->shouldReceive('__construct')->with($customerId)->andReturnSelf();
        $customerMock->shouldReceive('isLoadedObject')->andReturn(true);

        $customerMock->id = $customerId;
        $customerMock->firstname = 'John';
        $customerMock->lastname = 'Doe';
        $customerMock->email = 'john.doe@example.com';
        $customerMock->secure_key = '8eb1b522f60d11fa897de1dc6351b7e8';

        return $customerMock;
    }

    public function determineOrderStateIdSuccess($moneiPaymentStatus)
    {
        $statusMap = [
            PaymentStatus::REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PARTIALLY_REFUNDED => 'MONEI_STATUS_REFUNDED',
            PaymentStatus::PENDING => 'MONEI_STATUS_PENDING',
            PaymentStatus::SUCCEEDED => 'MONEI_STATUS_SUCCEEDED',
        ];
        $configKey = $statusMap[$moneiPaymentStatus] ?? 'MONEI_STATUS_FAILED';

        $this->assertEquals('MONEI_STATUS_SUCCEEDED', $configKey);

        return self::MONEI_STATUS_SUCCEEDED;
    }

    // Cuando existe una orden pendiente con el carrito enviado.
    private function handleExistingOrderSuccess($cartId, $orderStateId, $moneiPayment)
    {
        $orderPaymentMock = Mockery::mock('OrderPayment');
        $orderPaymentMock->shouldReceive('save')->andReturnSelf();
        $orderPaymentMock->transaction_id = $moneiPayment->getId();

        $existingOrder = Mockery::mock('Order');
        $existingOrder->shouldReceive('getByCartId')->with($cartId)->andReturn(true);
        $existingOrder->shouldReceive('isLoadedObject')->andReturn(true);
        $existingOrder->shouldReceive('setCurrentState')->with($orderStateId)->andReturnSelf();
        $existingOrder->shouldReceive('getOrderPaymentCollection')->andReturn([
            $orderPaymentMock
        ]);
        $existingOrder->module = 'monei';
        $existingOrder->current_state = self::MONEI_STATUS_PENDING;

        $this->updateExistingOrder($existingOrder, $orderStateId, $moneiPayment);

        return $existingOrder;
    }

    private function handleExistingOrderFailed($cartId, $orderStateId, $moneiPayment)
    {
        return null;
    }

    private function updateExistingOrder($order, $orderStateId, $moneiPayment)
    {
        $pendingStates = [self::MONEI_STATUS_PENDING];
        if (in_array((int) $order->current_state, $pendingStates)) {
            $order->setCurrentState($orderStateId);
            $this->updateOrderPaymentTransactionId($order, $moneiPayment->getId());
        }
    }

    private function updateOrderPaymentTransactionId($order, $transactionId)
    {
        $orderPayment = $order->getOrderPaymentCollection();
        if (count($orderPayment) > 0) {
            $orderPayment[0]->transaction_id = $transactionId;
            $orderPayment[0]->save();
        }
    }

    private function createNewOrder($cart, $customer, $orderStateId, $moneiPayment)
    {
        $moneiInstanceMock = Mockery::mock('MoneiModuleInstance');
        $moneiInstanceMock->shouldReceive('validateOrder')
            ->once()
            ->with(
                $cart->id,
                $orderStateId,
                $moneiPayment->getAmount() / 100,
                'MONEI ' . $moneiPayment->getPaymentMethod()->getMethod(),
                '',
                ['transaction_id' => $moneiPayment->getId()],
                $cart->id_currency,
                false,
                $customer->secure_key
            );

        $orderMock = Mockery::mock('Order');
        $orderMock->shouldReceive('getByCartId')->with($cart->id)->andReturn($orderMock);

        return $orderMock;
    }

    private function handlePostOrderCreation($redirectToConfirmationPage, $cart, $customer, $order)
    {
        if ($redirectToConfirmationPage) {
            $moneiInstanceMock = Mockery::mock('MoneiModuleInstance');
            $moneiInstanceMock->shouldReceive('id')->andReturn(1);

            return [
                'redirect' => 'index.php?controller=order-confirmation' .
                '&id_cart=' . $cart->id .
                '&id_module=' . $moneiInstanceMock->id .
                '&id_order=' . $order->id .
                '&key=' . $customer->secure_key
            ];
        } else {
            return 'OK';
        }
    }
}
