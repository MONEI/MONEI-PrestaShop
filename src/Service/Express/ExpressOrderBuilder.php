<?php

declare(strict_types=1);

namespace PsMonei\Service\Express;

/**
 * Prepares a cart so an express payment can be taken against it.
 *
 * ⚠️ This deliberately stops at "the cart is ready to pay". It does not create the
 * PrestaShop order: that stays with OrderService::createOrUpdateOrder, driven by
 * the same confirmation and webhook path every other MONEI payment uses. Express
 * is a different way to reach a payment, not a second way to make an order.
 */
class ExpressOrderBuilder
{
    /**
     * Make sure the shopper is someone PrestaShop can attach an order to.
     *
     * Express starts from a product or cart page, where a shopper is very often
     * not logged in. PrestaShop still needs a customer, so a guest is created from
     * what the wallet gives us.
     *
     * @param \Context $context Shop context
     * @param string $email Email from the wallet
     * @param string $firstName First name
     * @param string $lastName Last name
     *
     * @return \Customer
     *
     * @throws \PrestaShopException when no usable email was supplied
     */
    public function ensureCustomer(\Context $context, string $email, string $firstName, string $lastName): \Customer
    {
        // A signed-in customer is returned before the wallet email is looked at.
        // Some wallets return no email for them — a virtual cart asks for none —
        // and the account already has one.
        if (\Validate::isLoadedObject($context->customer) && $context->customer->id && !$context->customer->is_guest) {
            return $context->customer;
        }

        if (!\Validate::isEmail($email)) {
            throw new \PrestaShopException('The wallet did not provide a usable email address');
        }

        // ⚠️ Never attach an existing account to an anonymous session. The email
        // comes from the wallet payload, which the browser controls, and the
        // cookie below is written with logged = 1 before any payment is confirmed.
        // Reusing a registered customer here would sign the visitor in as whoever
        // owns that email. Registered accounts are refused by the controller; an
        // anonymous express shopper always gets a fresh guest record of their own,
        // which is also what PrestaShop's guest checkout does.
        if (self::registeredCustomerExists($email)) {
            throw new \PrestaShopException('An account already exists for this email address');
        }

        $customer = new \Customer();
        $customer->firstname = $firstName;
        $customer->lastname = $lastName;
        $customer->email = $email;
        $customer->is_guest = true;
        $customer->id_default_group = (int) \Configuration::get('PS_GUEST_GROUP');
        $customer->passwd = \Tools::hash(\Tools::passwdGen());
        $customer->add();

        $context->customer = $customer;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->passwd = $customer->passwd;
        $context->cookie->email = $customer->email;
        $context->cookie->is_guest = (bool) $customer->is_guest;
        $context->cookie->logged = 1;
        $context->cookie->write();

        return $customer;
    }

    /**
     * Attach a delivery and invoice address built from the wallet payload.
     *
     * @param \Context $context Shop context
     * @param \Cart $cart Cart to attach to
     * @param \Customer $customer Owner of the address
     * @param array $normalized Output of ExpressAddressNormalizer::normalize
     *
     * @return \Address
     *
     * @throws \PrestaShopException when the country is unusable
     */
    /**
     * Whether a registered (non guest) account already uses this email.
     *
     * Customer::getByEmail ignores guests by default, so a loaded object here is
     * always a real account.
     */
    public static function registeredCustomerExists(string $email): bool
    {
        $existing = new \Customer();
        $existing->getByEmail($email);

        return \Validate::isLoadedObject($existing);
    }

    /**
     * Store a billing address that differs from the delivery one.
     *
     * applyAddress() points both cart addresses at the delivery address, which
     * is right whenever the wallet returned a single contact. When it returned
     * two, the invoice address must be the billing one, or the invoice carries
     * the wrong details.
     */
    public function applyBillingAddress(\Context $context, \Cart $cart, \Customer $customer, array $normalized, \Address $delivery): \Address
    {
        $countryId = (int) \Country::getByIso($normalized['countryIso']);

        if (!$countryId) {
            return $delivery;
        }

        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = $countryId;
        $address->alias = 'MONEI Express billing';
        $address->firstname = $normalized['firstName'];
        $address->lastname = $normalized['lastName'];
        $address->address1 = $normalized['address1'];
        $address->address2 = (string) ($normalized['address2'] ?? '');
        $address->city = $normalized['city'];
        $address->postcode = $normalized['postcode'];
        $address->phone = $normalized['phone'];

        if (\Country::containsStates($countryId)) {
            $stateId = self::resolveState($countryId, (string) ($normalized['state'] ?? ''));

            if (!$stateId) {
                // Not worth failing an approved payment over: the delivery
                // address is complete, so it stays the invoice address too.
                return $delivery;
            }

            $address->id_state = $stateId;
        }

        $address->add();

        $cart->id_address_invoice = (int) $address->id;
        $cart->update();

        return $address;
    }

    /**
     * Find the shop's state for what the wallet sent, by ISO code then by name.
     *
     * Wallets are not consistent: Apple Pay sends "CA", PayPal may send "CA" or
     * "California". Both are accepted. Matching is scoped to the country so that a
     * name shared across countries cannot resolve to the wrong one.
     *
     * @return int State id, 0 when nothing matches
     */
    public static function resolveState(int $countryId, string $given): int
    {
        $given = trim($given);

        if ($given === '') {
            return 0;
        }

        $byIso = (int) \State::getIdByIso(strtoupper($given), $countryId);

        if ($byIso) {
            return $byIso;
        }

        foreach (\State::getStatesByIdCountry($countryId) as $state) {
            if (strcasecmp((string) $state['name'], $given) === 0) {
                return (int) $state['id_state'];
            }
        }

        return 0;
    }

    public function applyAddress(\Context $context, \Cart $cart, \Customer $customer, array $normalized): \Address
    {
        $countryId = (int) \Country::getByIso($normalized['countryIso']);

        if (!$countryId) {
            throw new \PrestaShopException('The wallet supplied a country this shop does not know: ' . $normalized['countryIso']);
        }

        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = $countryId;
        $address->alias = 'MONEI Express';
        $address->firstname = $normalized['firstName'];
        $address->lastname = $normalized['lastName'];
        $address->address1 = $normalized['address1'];
        $address->address2 = (string) ($normalized['address2'] ?? '');
        $address->city = $normalized['city'];
        $address->postcode = $normalized['postcode'];
        $address->phone = $normalized['phone'];

        // ⚠️ The state is resolved from what the wallet sent, never guessed. This
        // used to assign the country's first state whenever one was required, so
        // a Texas address was stored — and taxed, and shipped — as Alabama. A
        // state the shop cannot match is refused, which is also what PrestaShop's
        // own checkout does when the field is left blank.
        if (\Country::containsStates($countryId)) {
            $stateId = self::resolveState($countryId, (string) ($normalized['state'] ?? ''));

            if (!$stateId) {
                throw new \PrestaShopException('The wallet did not supply a state this shop recognises for ' . $normalized['countryIso']);
            }

            $address->id_state = $stateId;
        }

        $address->add();

        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_customer = (int) $customer->id;
        $cart->secure_key = $customer->secure_key;
        $cart->update();

        $context->cart = $cart;

        return $address;
    }
}
