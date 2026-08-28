<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PsMonei\Service\Express\ExpressAddressNormalizer;
use PsMonei\Service\Express\ExpressCartService;
use PsMonei\Service\Express\ExpressMethodResolver;
use PsMonei\Service\Express\ExpressOrderBuilder;

/**
 * Express checkout endpoints.
 *
 * One controller dispatching on `action`, rather than one controller per endpoint:
 * PrestaShop front controllers are a class and a file each, and nine of them would
 * be nine copies of the same token check and JSON plumbing.
 *
 * Every failure answers with a structured `{code, message}` body. The client has to
 * be able to tell the shopper what went wrong on the surface the payment started
 * from — a rejected express payment that fails silently leaves someone staring at a
 * page that has already taken their wallet approval.
 */
class MoneiExpressModuleFrontController extends ModuleFrontController
{
    /** @var array */
    private $input = [];

    public function postProcess()
    {
        header('Content-Type: application/json');

        $this->input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        if (!$this->isAuthorizedRequest()) {
            $this->fail('forbidden', 'Invalid or missing security token', 403);
        }

        if (!(bool) Configuration::get('MONEI_EXPRESS_ENABLED')) {
            $this->fail('express_disabled', 'Express checkout is not enabled', 403);
        }

        $action = (string) $this->value('action');

        try {
            switch ($action) {
                case 'bootstrap':
                    $this->respond($this->actionBootstrap());
                    break;
                case 'getCartDetails':
                    $this->respond($this->actionCartDetails());
                    break;
                case 'getSelectedProductData':
                    $this->respond($this->actionSelectedProductData());
                    break;
                case 'addToCart':
                    $this->respond($this->actionAddToCart());
                    break;
                case 'getShippingOptions':
                    $this->respond($this->actionShippingOptions());
                    break;
                case 'updateShippingMethod':
                    $this->respond($this->actionUpdateShippingMethod());
                    break;
                case 'createOrder':
                    $this->respond($this->actionCreateOrder());
                    break;
                default:
                    $this->fail('unknown_action', 'Unknown express action: ' . $action, 400);
            }
        } catch (Exception $e) {
            // Never leave the shopper's cart borrowed by a failed express payment.
            $this->cartService()->restore($this->context);

            Monei::logError('[MONEI] Express ' . $action . ' failed: ' . $e->getMessage());
            $this->fail('express_failed', $e->getMessage(), 400);
        }
    }

    /**
     * Force a cart and a session to exist, and report what the client needs to
     * build its components.
     *
     * ⚠️ An anonymous visitor on a product page has neither yet. Without this the
     * first thing express does is create them halfway through a wallet flow, which
     * is where a payment is hardest to recover.
     */
    private function actionBootstrap()
    {
        if (!Validate::isLoadedObject($this->context->cart)) {
            $cart = new Cart();
            $cart->id_currency = (int) $this->context->currency->id;
            $cart->id_lang = (int) $this->context->language->id;
            $cart->id_shop = (int) $this->context->shop->id;
            $cart->id_guest = (int) $this->context->cookie->id_guest;
            $cart->add();

            $this->context->cart = $cart;
            $this->context->cookie->id_cart = (int) $cart->id;
            $this->context->cookie->write();
        }

        return [
            'sessionId' => (string) $this->context->cart->id,
            'accountId' => $this->accountId(),
            'currency' => $this->context->currency->iso_code,
            'methods' => $this->resolvedMethods(),
        ];
    }

    /**
     * Amount, currency and line items for the current cart.
     *
     * The wallet sheet renders the breakdown, so this is not decoration: without it
     * a shopper sees a bare total and no idea what they are paying for.
     */
    private function actionCartDetails()
    {
        return $this->cartPayload();
    }

    /**
     * Price and availability of the product a shopper is looking at.
     */
    private function actionSelectedProductData()
    {
        $productId = (int) $this->value('productId');
        $attributeId = (int) $this->value('productAttributeId');
        $quantity = max(1, (int) $this->value('quantity', 1));

        $product = new Product($productId, false, (int) $this->context->language->id);

        if (!Validate::isLoadedObject($product) || !$product->active) {
            throw new PrestaShopException('Product not available');
        }

        $price = Product::getPriceStatic($productId, true, $attributeId ?: null, 2);

        return [
            'productId' => $productId,
            'productAttributeId' => $attributeId,
            'quantity' => $quantity,
            'name' => $product->name,
            'amount' => $this->toMinorUnits((float) $price * $quantity),
            'currency' => $this->context->currency->iso_code,
        ];
    }

    /**
     * Put a single product into an express cart of its own.
     */
    private function actionAddToCart()
    {
        $productId = (int) $this->value('productId');
        $attributeId = (int) $this->value('productAttributeId');
        $quantity = max(1, (int) $this->value('quantity', 1));

        $this->cartService()->start($this->context, $productId, $attributeId, $quantity);

        return $this->cartPayload();
    }

    /**
     * Carriers that can deliver this cart.
     */
    private function actionShippingOptions()
    {
        $this->applyAddressFromInput();

        return [
            'shippingOptions' => $this->shippingOptions(),
        ] + $this->cartPayload();
    }

    /**
     * Choose a carrier and report the recomputed totals.
     *
     * ⚠️ Returns the totals, and so do addToCart and getShippingOptions. The wallet
     * sheet shows what the shopper is about to pay, and a sheet left showing a
     * pre-shipping total is a sheet lying about the amount.
     */
    private function actionUpdateShippingMethod()
    {
        $carrierId = (int) $this->value('carrierId');

        if (!$carrierId) {
            throw new PrestaShopException('No carrier selected');
        }

        $this->context->cart->setDeliveryOption([
            (int) $this->context->cart->id_address_delivery => $carrierId . ',',
        ]);
        $this->context->cart->update();

        return $this->cartPayload();
    }

    /**
     * Prepare the cart and create the MONEI payment the client will confirm.
     *
     * ⚠️ Creates a payment, not an order. The PrestaShop order is created by
     * OrderService from the confirmation and webhook path that every other MONEI
     * payment already uses, so express does not become a second way to make orders.
     */
    private function actionCreateOrder()
    {
        $method = (string) $this->value('paymentMethod');
        $address = $this->applyAddressFromInput();

        // ⚠️ Never trust the amount the client reports. It comes back from the
        // wallet, and a mismatch means the sheet showed something other than what
        // the cart holds.
        $submitted = $this->value('amount');

        if ($submitted !== null && (int) $submitted !== $this->cartAmount()) {
            throw new PrestaShopException('The amount changed while the wallet was open');
        }

        $payment = Monei::getService('service.monei')->createMoneiPayment(
            $this->context->cart,
            false,
            0,
            $method
        );

        if (!$payment) {
            throw new PrestaShopException(
                Monei::getService('service.monei')->getLastError() ?: 'Payment creation failed'
            );
        }

        return [
            'paymentId' => (string) $payment->getId(),
            'addressIncomplete' => (bool) $address['incomplete'],
        ];
    }

    /**
     * Build a customer and address from the wallet payload and attach them.
     *
     * @return array The normalized address, so callers can report gaps
     */
    private function applyAddressFromInput()
    {
        $payload = (array) $this->value('shippingAddress', []);
        $email = (string) $this->value('email');

        if (!$payload && !$email) {
            return ['incomplete' => true];
        }

        $normalized = ExpressAddressNormalizer::normalize($payload);
        $builder = new ExpressOrderBuilder();

        $customer = $builder->ensureCustomer(
            $this->context,
            $email,
            $normalized['firstName'],
            $normalized['lastName']
        );

        $builder->applyAddress($this->context, $this->context->cart, $customer, $normalized);

        return $normalized;
    }

    /**
     * Amount, currency, whether shipping applies, and the line item breakdown.
     */
    private function cartPayload()
    {
        $cart = $this->context->cart;
        $summary = $cart->getSummaryDetails(null, true);
        $currency = $this->context->currency->iso_code;

        $items = [];

        foreach ($cart->getProducts() as $product) {
            $items[] = [
                'label' => $product['name'],
                'amount' => $this->toMinorUnits((float) $product['total_wt']),
            ];
        }

        $shipping = (float) $summary['total_shipping'];

        if ($shipping > 0) {
            $items[] = [
                'label' => $this->module->l('Shipping', 'express'),
                'amount' => $this->toMinorUnits($shipping),
            ];
        }

        return [
            'amount' => $this->cartAmount(),
            'currency' => $currency,
            'shippingRequired' => !$cart->isVirtualCart(),
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array{id: int, label: string, amount: int}>
     */
    private function shippingOptions()
    {
        $options = [];
        $cart = $this->context->cart;

        foreach ($cart->getDeliveryOptionList() as $addressOptions) {
            foreach ($addressOptions as $key => $option) {
                $carrier = reset($option['carrier_list']);

                if (!$carrier) {
                    continue;
                }

                $options[] = [
                    'id' => (int) $carrier['instance']->id,
                    'key' => $key,
                    'label' => $carrier['instance']->name,
                    'amount' => $this->toMinorUnits((float) $option['total_price_with_tax']),
                ];
            }
        }

        return $options;
    }

    private function cartAmount()
    {
        return Monei::getService('service.monei')->getCartAmount(
            $this->context->cart->getSummaryDetails(null, true),
            (int) $this->context->cart->id_currency
        );
    }

    private function resolvedMethods()
    {
        $allowed = [];

        foreach (['applePay' => 'MONEI_ALLOW_APPLE', 'googlePay' => 'MONEI_ALLOW_GOOGLE', 'paypal' => 'MONEI_ALLOW_PAYPAL'] as $method => $key) {
            if (Configuration::get($key)) {
                $allowed[] = $method;
            }
        }

        $offered = Monei::getService('service.monei')->getPaymentMethodsAllowed();

        return ExpressMethodResolver::resolve(
            (string) Configuration::get('MONEI_EXPRESS_METHODS'),
            $allowed,
            is_array($offered) ? $offered : []
        );
    }

    private function accountId()
    {
        return (bool) Configuration::get('MONEI_PRODUCTION_MODE')
            ? Configuration::get('MONEI_ACCOUNT_ID')
            : Configuration::get('MONEI_TEST_ACCOUNT_ID');
    }

    private function toMinorUnits($amount)
    {
        return (int) round($amount * 100);
    }

    private function cartService()
    {
        return new ExpressCartService();
    }

    /**
     * Reuses the token convention the module already uses for createPayment,
     * rather than inventing a second scheme.
     */
    private function isAuthorizedRequest()
    {
        return isset($this->input['token']) && $this->input['token'] === Tools::getToken(false);
    }

    private function value($key, $default = null)
    {
        return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
    }

    private function respond(array $payload)
    {
        echo json_encode(['ok' => true] + $payload);
        exit;
    }

    private function fail($code, $message, $status)
    {
        http_response_code($status);
        echo json_encode(['ok' => false, 'code' => $code, 'message' => $message]);
        exit;
    }
}
