<?php

namespace Tests\Service\Order;

use PsMonei\Service\Order\OrderService;
use Monei;
use Mockery;
use Cart;
use Customer;
use Validate;
use Configuration;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use OpenAPI\Client\Model\PaymentStatus;
use Order;
use PsMonei\Exception\OrderException;
use Tools;

class OrderServiceTest extends MockeryTestCase
{
    private $orderService;
    private $moneiMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moneiMock = Mockery::mock(Monei::class);
        $this->orderService = new OrderService($this->moneiMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // Test getMoneiPayment
    public function testGetMoneiPaymentSuccess()
    {
        $moneiPaymentId = 'some-id';
        $moneiPayment = (object) ['id' => $moneiPaymentId];
        $this->moneiMock->shouldReceive('getMoneiClient')->andReturn((object) ['payments' => (object) ['get' => function ($id) use ($moneiPayment) { return $moneiPayment; }]]);

        $result = $this->orderService->getMoneiPayment($moneiPaymentId);
        $this->assertEquals($moneiPayment, $result);
    }

    public function testGetMoneiPaymentFails()
    {
        $this->expectException(OrderException::class);
        $this->expectExceptionCode(OrderException::MONEI_CLIENT_NOT_INITIALIZED);
        $this->moneiMock->shouldReceive('getMoneiClient')->andReturn(null);

        $this->orderService->getMoneiPayment('some-id');
    }

    // Test validateCart
    public function testValidateCartSuccess()
    {
        $cartId = 1;
        $cartMock = Mockery::mock(Cart::class);
        $cartMock->shouldReceive('__construct')->with($cartId)->andReturnSelf();
        $cartMock->shouldReceive('isLoadedObject')->andReturn(true);

        Validate::shouldReceive('isLoadedObject')->with($cartMock)->andReturn(true);

        $this->assertEquals($cartMock, $this->orderService->validateCart($cartId));
    }

    public function testValidateCartFails()
    {
        $this->expectException(OrderException::class);
        $this->expectExceptionCode(OrderException::CART_NOT_VALID);

        $cartId = 1;
        $cartMock = Mockery::mock(Cart::class);
        $cartMock->shouldReceive('__construct')->with($cartId)->andReturnSelf();
        Validate::shouldReceive('isLoadedObject')->with($cartMock)->andReturn(false);

        $this->orderService->validateCart($cartId);
    }

    // Test validateCustomer
    public function testValidateCustomerSuccess()
    {
        $customerId = 1;
        $customerMock = Mockery::mock(Customer::class);
        $customerMock->shouldReceive('__construct')->with($customerId)->andReturnSelf();
        $customerMock->shouldReceive('isLoadedObject')->andReturn(true);
    }

    public function testValidateCustomerFails()
    {
        $this->expectException(OrderException::class);
        $this->expectExceptionCode(OrderException::CUSTOMER_NOT_VALID);

        $customerId = 1;
        $customerMock = Mockery::mock(Customer::class);
        $customerMock->shouldReceive('__construct')->with($customerId)->andReturnSelf();
        Validate::shouldReceive('isLoadedObject')->with($customerMock)->andReturn(false);

        $this->orderService->validateCustomer($customerId);
    }

    // Test createOrUpdateOrder
    // Prueba cuando se crea una nueva orden.
    // Prueba cuando se actualiza una orden existente.
    // Prueba cuando falla la creación/actualización por diferentes excepciones.
    public function testCreateOrUpdateOrderNewOrder()
    {
        $moneiPaymentId = 'some-id';
        $moneiPayment = Mockery::mock(stdClass::class);
        $moneiPayment->shouldReceive('getOrderId')->andReturn('1m'); // Simulando que el ID del pedido es '1m'
        $moneiPayment->shouldReceive('getStatus')->andReturn(PaymentStatus::SUCCEEDED);
        $moneiPayment->shouldReceive('getAmount')->andReturn(10000); // 100.00 en centavos
        $moneiPayment->shouldReceive('getId')->andReturn('payment-id');
        $moneiPayment->shouldReceive('getPaymentMethod')->andReturn((object) ['getMethod' => function() { return 'card'; }]);

        $cart = Mockery::mock(Cart::class);
        $cart->shouldReceive('__construct')->with(1)->andReturnSelf();
        $cart->id = 1;
        $cart->id_currency = 1;
        Validate::shouldReceive('isLoadedObject')->with($cart)->andReturn(true);

        $customer = Mockery::mock(Customer::class);
        $customer->shouldReceive('__construct')->with(1)->andReturnSelf();
        $customer->id = 1;
        $customer->secure_key = 'key';
        Validate::shouldReceive('isLoadedObject')->with($customer)->andReturn(true);

        $this->moneiMock->shouldReceive('getMoneiClient')->andReturn((object) ['payments' => (object) ['get' => function ($id) use ($moneiPayment) { return $moneiPayment; }]]);
        Configuration::shouldReceive('get')->with('MONEI_STATUS_SUCCEEDED')->andReturn(1);
        Order::shouldReceive('getByCartId')->with(1)->andReturn(null);
        $this->moneiMock->shouldReceive('validateOrder')->once();
        $this->moneiMock->shouldReceive('removeMoneiPaymentCookie')->once();

        // Mock para la redirección
        Tools::shouldReceive('redirect')->once();

        $this->orderService->createOrUpdateOrder($moneiPaymentId, true);
    }
}