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

    /** @var bool Whether the request carried a valid token */
    private $authorized = false;

    public function postProcess()
    {
        header('Content-Type: application/json');

        $this->input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        if (!$this->isAuthorizedRequest()) {
            $this->fail('forbidden', 'Invalid or missing security token', 403);
        }

        $this->authorized = true;

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
                case 'restoreCart':
                    // The client calls this when a failure lands after createOrder
                    // has already adopted the express cart — confirmPayment being
                    // rejected, for one. The server saw no failure, so nothing
                    // else would return the shopper to their own basket.
                    $this->cartService()->restore($this->context);
                    $this->respond([]);

                    break;
                case 'createOrder':
                    $this->respond($this->actionCreateOrder());

                    break;
                default:
                    $this->fail('unknown_action', 'Unknown express action: ' . $action, 400);
            }
        } catch (Throwable $e) {
            // Never leave the shopper's cart borrowed by a failed express payment.
            // Throwable, not Exception: a TypeError from a typed service must
            // reach here too, or the cart stays borrowed and the browser gets an
            // HTML error page instead of the JSON this endpoint promises.
            $this->cartService()->restore($this->context);

            Monei::logError('[MONEI] Express ' . $action . ' failed: ' . $e->getMessage());

            // Only the messages this controller wrote for the shopper go back to
            // the browser. Anything else — a core exception, an SQL error, a
            // type error — carries internal detail and gets the generic text.
            $shopperFacing = $e instanceof PrestaShopException;
            $this->fail(
                'express_failed',
                $shopperFacing ? $e->getMessage() : $this->module->l('The payment could not be completed. Please try again.', 'express'),
                400
            );
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
        $expressCartId = (int) $this->value('expressCartId');

        return $this->cartPayload($expressCartId ? $this->cartService()->owned($this->context, $expressCartId) : null);
    }

    /**
     * Price the product a shopper is looking at, without a cart.
     *
     * ⚠️ This prices the button at mount. A cart and a guest are created only when
     * the shopper opens a wallet: a cart here would add a ps_cart row, and for a
     * new visitor a ps_guest row, on every page view that runs JavaScript.
     *
     * Shipping is not included. The wallet reports an address as it opens, and the
     * shipping quote reprices the sheet then; createOrder refuses a total that
     * still differs from the one the shopper approved.
     */
    private function actionSelectedProductData()
    {
        $productId = (int) $this->value('productId');
        $attributeId = $this->selectedCombination($productId);
        $quantity = max(1, (int) $this->value('quantity', 1));

        $product = $this->cartService()->assertPurchasable($this->context, $productId, $attributeId, $quantity);

        $unit = Product::getPriceStatic($productId, true, $attributeId ?: null, 6, null, false, true, $quantity);
        $precision = $this->context->getComputingPrecision();
        $total = (int) Configuration::get('PS_ROUND_TYPE') === Order::ROUND_ITEM
            ? Tools::ps_round($unit, $precision) * $quantity
            : Tools::ps_round($unit * $quantity, $precision);

        return [
            'productAttributeId' => $attributeId,
            'amount' => $this->toMinorUnits($total),
            'currency' => $this->context->currency->iso_code,
            'shippingRequired' => !$product->is_virtual,
            'items' => [['label' => $product->name, 'amount' => $this->toMinorUnits($total)]],
        ];
    }

    /**
     * Put a single product into an express cart of its own.
     */
    private function actionAddToCart()
    {
        $productId = (int) $this->value('productId');
        $attributeId = $this->selectedCombination($productId);
        $quantity = max(1, (int) $this->value('quantity', 1));

        // A stale express cart for the same container is replaced, not left
        // behind: the shopper changing quantity or combination would otherwise
        // litter one abandoned cart per change.
        $replaces = (int) $this->value('replacesCartId');

        if ($replaces) {
            $this->cartService()->discard($this->context, $replaces);
        }

        // Created but NOT adopted. The session keeps the shopper's own basket
        // until the wallet approves; see ExpressCartService.
        $cart = $this->cartService()->create($this->context, $productId, $attributeId, $quantity);

        return ['expressCartId' => (int) $cart->id] + $this->cartPayload($cart);
    }

    /**
     * Carriers for the address a wallet reports, and the total with one chosen.
     *
     * ⚠️ Writes nothing. While the sheet is open the wallet reports a partial
     * address (no street, no name), so no customer or address can be created yet.
     * The cart is priced the way PrestaShop prices a cart without a delivery
     * address: in a country, here the wallet's, in the zone of its state when the
     * shop has one. createOrder prices it again from the full address before any
     * money moves.
     */
    private function actionShippingOptions()
    {
        $expressCartId = (int) $this->value('expressCartId');
        $cart = $expressCartId ? $this->cartService()->owned($this->context, $expressCartId) : $this->context->cart;

        if ($cart->isVirtualCart()) {
            return ['shippingOptions' => []] + $this->cartPayload($cart);
        }

        $country = $this->walletCountry((array) $this->value('address', []));

        if (!$country) {
            // An empty list is how the wallet is told it cannot ship there.
            return ['shippingOptions' => []] + $this->cartPayload($cart);
        }

        // Core reads the zone from the context country when the cart has no
        // delivery address, and caches delivery options per country across carts.
        Cart::resetStaticCache();
        $this->context->country = $country;
        $cart->id_address_delivery = 0;

        $options = $this->shippingOptions($cart, $country);

        if (!$options) {
            return ['shippingOptions' => []] + $this->cartPayload($cart);
        }

        $chosen = $this->chooseCarrier($options, (int) $this->value('carrierId'));
        $cart->delivery_option = json_encode([0 => $chosen['key']]);
        $cart->id_carrier = $chosen['id'];

        $shippingOptions = [];

        foreach ($options as $option) {
            $shippingOptions[] = [
                'id' => (string) $option['id'],
                'label' => $option['label'],
                'amount' => $option['amount'],
                'selected' => $option['id'] === $chosen['id'],
            ];
        }

        return ['shippingOptions' => $shippingOptions] + $this->cartPayload($cart);
    }

    /**
     * The country to price shipping in, from a wallet's partial address.
     *
     * @param array $address Wallet address: country, and state when it has one
     *
     * @return Country|null Null when express cannot deliver there
     */
    private function walletCountry(array $address)
    {
        $normalized = ExpressAddressNormalizer::normalize($address);
        $countryId = (int) Country::getByIso($normalized['countryIso'], true);

        if (!$countryId) {
            return null;
        }

        $country = new Country($countryId, (int) $this->context->language->id);

        // createOrder refuses these; quoting them would promise a delivery that
        // cannot be ordered.
        if ($country->need_identification_number) {
            return null;
        }

        // createOrder refuses a state it cannot resolve, so none is quoted.
        if (Country::containsStates($countryId)) {
            $stateId = ExpressOrderBuilder::resolveState($countryId, $normalized['state']);

            if (!$stateId) {
                return null;
            }

            $country->id_zone = (int) State::getIdZone($stateId);
        }

        return $country;
    }

    /**
     * The carrier the wallet chose, or the cheapest when it chose none we offer.
     *
     * @param array<int, array{id: int, key: string, label: string, amount: int}> $options
     * @param int $carrierId Carrier the wallet reported, 0 when none
     *
     * @return array{id: int, key: string, label: string, amount: int}
     */
    private function chooseCarrier(array $options, $carrierId)
    {
        foreach ($options as $option) {
            if ($carrierId && $option['id'] === $carrierId) {
                return $option;
            }
        }

        usort($options, static function ($a, $b) {
            return $a['amount'] <=> $b['amount'];
        });

        return $options[0];
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

        // ⚠️ Browser supplied. The resolver only decides which buttons render; it
        // does not guard this endpoint, so without this a visitor could pay by a
        // method the merchant disabled, or one excluded from express.
        if (!in_array($method, $this->module->getExpressMethods(), true)) {
            throw new PrestaShopException($this->module->l('This payment method is not available.', 'express'));
        }

        // The normal payment hooks hide MONEI for a currency the merchant turned
        // off; this endpoint has to honour the same restriction.
        if (!$this->module->isMoneiAvailable($this->context->cart)) {
            throw new PrestaShopException($this->module->l('This payment method is not available.', 'express'));
        }

        // Product page express pays for a cart of its own. It becomes the session
        // cart only now, once the wallet has approved — never at mount.
        $expressCartId = (int) $this->value('expressCartId');

        if ($expressCartId) {
            $this->cartService()->adopt($this->context, $expressCartId);
        }

        $address = $this->applyAddressFromInput();

        // ⚠️ The amount charged is always the one computed from the cart here. The
        // client never supplies it, so there is nothing to validate and nothing to
        // spoof: a wallet cannot talk the shop into a smaller total.
        //
        // The carrier the shopper picked in the sheet, when it can deliver to the
        // full address; otherwise the cheapest that can.
        $this->selectCarrier((int) $this->value('carrierId'));

        // A physical cart with no carrier serving the wallet's address would be
        // charged with no delivery option at all, and the order could not be
        // built afterwards. Refuse before any money moves.
        if (!$this->context->cart->isVirtualCart() && !(int) $this->context->cart->id_carrier) {
            throw new PrestaShopException(
                $this->module->l('No shipping method is available for this address. Please continue with the standard checkout.', 'express')
            );
        }

        // ⚠️ The total the sheet showed is a quote: priced from the product at mount,
        // or from a partial address while the sheet was open. Charging a different
        // amount than the shopper approved is refused here, before any money moves;
        // the client then prices the button from this cart. A client that sends no
        // amount predates the quote and is not checked.
        $approved = (int) $this->value('amount');
        $amount = (int) $this->cartAmount();

        if ($approved && $approved !== $amount) {
            $this->cartService()->restore($this->context);
            $this->fail(
                'amount_changed',
                $this->module->l('The total changed while the wallet was open. Please check it and try again.', 'express'),
                409
            );
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
     * Pick a carrier so a physical cart can be priced and shipped.
     *
     * @param int $carrierId Carrier the shopper picked in the wallet, 0 when none
     */
    private function selectCarrier($carrierId)
    {
        $cart = $this->context->cart;

        if ($cart->isVirtualCart()) {
            return;
        }

        $options = $this->shippingOptions($cart);

        if (!$options) {
            return;
        }

        $cart->setDeliveryOption([
            (int) $cart->id_address_delivery => $this->chooseCarrier($options, $carrierId)['key'],
        ]);
        $cart->update();
    }

    /**
     * Build a customer and address from the wallet payload and attach them.
     *
     * @return array The normalized address, so callers can report gaps
     */
    /**
     * Refuse a country whose addresses PrestaShop will not accept from a wallet.
     *
     * ⚠️ Spain is the case that matters, and it is stock PrestaShop data: ES has
     * `need_identification_number = 1`, so Address::validateFields rejects any
     * address without a DNI. No wallet supplies a national identification number,
     * and one cannot be invented — it is a tax identifier with a validated
     * checksum, so writing a placeholder would put fabricated tax data on a real
     * order.
     *
     * Without this the shopper approves in the wallet and then meets a raw
     * "Property Address->dni is empty", which tells them nothing. Nothing is
     * charged either way: this runs before the payment is confirmed.
     *
     * @param string $countryIso Two letter country code from the wallet
     *
     * @throws PrestaShopException when express cannot complete for that country
     */
    private function assertCountryIsExpressable($countryIso)
    {
        $countryId = (int) Country::getByIso($countryIso);

        if (!$countryId) {
            return;
        }

        $country = new Country($countryId);

        if (!$country->need_identification_number) {
            return;
        }

        // Merchant-facing detail goes to the log; the shopper cannot act on it.
        Monei::logError(
            '[MONEI] Express checkout refused for ' . $countryIso . ': PrestaShop requires an'
            . ' identification number for this country and no wallet supplies one. Clear'
            . ' "Do you need an identification number?" for this country under International >'
            . ' Locations > Countries to allow express checkout there.'
        );

        throw new PrestaShopException(
            $this->module->l('Express checkout is not available for delivery to this country. Please continue with the standard checkout.', 'express')
        );
    }

    /**
     * Refuse an address whose state the shop cannot match.
     *
     * ExpressOrderBuilder refuses too; this gives the shopper a translated reason.
     * The alternative — assigning some state — is what this used to do, and it
     * stored every US express order in the first state alphabetically, which in
     * PrestaShop's data is "AA", Armed Forces Americas.
     *
     * @param array $normalized Output of ExpressAddressNormalizer::normalize()
     *
     * @throws PrestaShopException when a required state cannot be resolved
     */
    private function assertStateIsResolvable(array $normalized)
    {
        $countryId = (int) Country::getByIso($normalized['countryIso']);

        if (!$countryId || !Country::containsStates($countryId)) {
            return;
        }

        if (ExpressOrderBuilder::resolveState($countryId, (string) ($normalized['state'] ?? ''))) {
            return;
        }

        throw new PrestaShopException(
            $this->module->l('The delivery address is missing a state or region this store recognises. Please continue with the standard checkout.', 'express')
        );
    }

    /**
     * Refuse an address with no postcode.
     *
     * The normaliser fills other missing parts with a visible placeholder so an
     * approved payment is not lost to validation, but a postcode is what a carrier
     * delivers to. PayPal's documented partial address omits it; that shopper is
     * better served by the standard checkout than by an undeliverable order.
     *
     * @param array $normalized Output of ExpressAddressNormalizer::normalize()
     *
     * @throws PrestaShopException when the postcode is missing
     */
    private function assertPostcodeIsPresent(array $normalized)
    {
        $countryId = (int) Country::getByIso($normalized['countryIso']);

        if ($countryId && !(new Country($countryId))->need_zip_code) {
            return;
        }

        if (trim((string) $normalized['postcode']) !== '') {
            return;
        }

        throw new PrestaShopException(
            $this->module->l('The delivery address is missing a postcode. Please continue with the standard checkout.', 'express')
        );
    }

    /**
     * Refuse an anonymous express order for an email that belongs to an account.
     *
     * ⚠️ The email is wallet supplied, so browser controlled. ExpressOrderBuilder
     * refuses to reuse a registered customer for exactly that reason; this gives
     * the shopper a translated explanation instead of the builder's exception.
     * A customer who is actually signed in never reaches here — ensureCustomer
     * returns the session's own account first.
     *
     * @param string $email Email from the wallet payload
     *
     * @throws PrestaShopException when a registered account owns the email
     */
    private function assertEmailIsNotARegisteredAccount($email)
    {
        if (Validate::isLoadedObject($this->context->customer)
            && $this->context->customer->id
            && !$this->context->customer->is_guest) {
            return;
        }

        if (!ExpressOrderBuilder::registeredCustomerExists((string) $email)) {
            return;
        }

        throw new PrestaShopException(
            $this->module->l('An account already exists for this email address. Please sign in first, or continue with the standard checkout.', 'express')
        );
    }

    private function applyAddressFromInput()
    {
        // shippingDetails / billingDetails are monei.js v3's own shapes, passed
        // through by express.js. shippingAddress is what a cached copy of the
        // previous client still sends.
        $shippingPayload = (array) $this->value('shippingDetails', $this->value('shippingAddress', []));
        $billingPayload = (array) $this->value('billingDetails', []);
        $email = (string) $this->value('email');
        $builder = new ExpressOrderBuilder();

        // A signed-in customer already has an email and an address on file. Some
        // wallets return neither for them — a virtual cart asks for no shipping
        // and no payer email — and that must not read as a missing wallet email.
        $signedIn = Validate::isLoadedObject($this->context->customer)
            && $this->context->customer->id
            && !$this->context->customer->is_guest;

        if ($signedIn && $email === '') {
            $email = (string) $this->context->customer->email;
        }

        // A virtual cart needs no delivery address at all. Normalising an empty
        // payload here produced an empty country and a refusal after approval.
        if ($this->context->cart->isVirtualCart()) {
            if (!$signedIn) {
                $this->assertEmailIsNotARegisteredAccount($email);
                $builder->ensureCustomer($this->context, $email, ExpressAddressNormalizer::PLACEHOLDER, ExpressAddressNormalizer::PLACEHOLDER);
            }

            return ['incomplete' => false];
        }

        if (!$shippingPayload && !$email) {
            return ['incomplete' => true];
        }

        $normalized = ExpressAddressNormalizer::normalize($shippingPayload);

        // PayPal's shipping details arrive empty when monei.js could not read the
        // order; the payer's address is then the only one there is.
        if ($normalized['countryIso'] === '' && $billingPayload) {
            $shippingPayload = $billingPayload;
            $normalized = ExpressAddressNormalizer::normalize($shippingPayload);
        }

        $this->assertCountryIsExpressable($normalized['countryIso']);
        $this->assertStateIsResolvable($normalized);
        $this->assertPostcodeIsPresent($normalized);
        $this->assertEmailIsNotARegisteredAccount($email);

        $customer = $builder->ensureCustomer(
            $this->context,
            $email,
            $normalized['firstName'],
            $normalized['lastName']
        );

        $delivery = $builder->applyAddress($this->context, $this->context->cart, $customer, $normalized);

        // A wallet may return a billing address that differs from the delivery
        // one. It used to be discarded, so the invoice carried the delivery
        // address. Only a complete one is stored; a partial billing address is
        // not worth failing an approved payment over.
        if ($billingPayload) {
            $billing = ExpressAddressNormalizer::normalize($billingPayload);

            if (!$billing['incomplete'] && $billing['countryIso'] !== '' && $billing != $normalized) {
                $builder->applyBillingAddress($this->context, $this->context->cart, $customer, $billing, $delivery);
            }
        }

        return $normalized;
    }

    /**
     * Amount, currency, whether shipping applies, and the line item breakdown.
     */
    private function cartPayload(?Cart $cart = null)
    {
        $cart = $cart ?: $this->context->cart;
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
            'amount' => $this->cartAmount($cart),
            'currency' => $currency,
            'shippingRequired' => !$cart->isVirtualCart(),
            'items' => $items,
        ];
    }

    /**
     * @param Cart $cart Cart to deliver
     * @param Country|null $country Country to price in when the cart has no address
     *
     * @return array<int, array{id: int, key: string, label: string, amount: int}>
     */
    private function shippingOptions(Cart $cart, ?Country $country = null)
    {
        $options = [];

        foreach ($cart->getDeliveryOptionList($country, true) as $addressOptions) {
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

    private function cartAmount(?Cart $cart = null)
    {
        $cart = $cart ?: $this->context->cart;

        return Monei::getService('service.monei')->getCartAmount(
            $cart->getSummaryDetails(null, true),
            (int) $cart->id_currency
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
     * The combination the shopper picked, resolved server side.
     *
     * @param int $productId Product
     *
     * @return int Combination id, 0 for a product without combinations
     */
    private function selectedCombination($productId)
    {
        $groups = $this->value('groups', []);

        return $this->cartService()->resolveCombination(
            (int) $productId,
            (int) $this->value('productAttributeId'),
            is_array($groups) ? $groups : []
        );
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
        echo json_encode(['ok' => true] + $payload + $this->freshToken());
        exit;
    }

    private function fail($code, $message, $status)
    {
        http_response_code($status);
        echo json_encode(['ok' => false, 'code' => $code, 'message' => $message] + $this->freshToken());
        exit;
    }

    /**
     * The token the next request must carry.
     *
     * ⚠️ The token hashes the customer, and createOrder signs an anonymous
     * shopper in as the wallet's guest customer. Every request after that, even
     * when createOrder failed — the restore, the remount after a changed total,
     * a second attempt — would be refused with the token the page was rendered
     * with. Never handed to a request that failed the token check.
     *
     * @return array{token?: string}
     */
    private function freshToken()
    {
        return $this->authorized ? ['token' => Tools::getToken(false)] : [];
    }
}
