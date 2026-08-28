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
        if (!\Validate::isEmail($email)) {
            throw new \PrestaShopException('The wallet did not provide a usable email address');
        }

        if (\Validate::isLoadedObject($context->customer) && $context->customer->id && !$context->customer->is_guest) {
            return $context->customer;
        }

        $existing = new \Customer();
        $existing->getByEmail($email);

        if (\Validate::isLoadedObject($existing)) {
            $customer = $existing;
        } else {
            $customer = new \Customer();
            $customer->firstname = $firstName;
            $customer->lastname = $lastName;
            $customer->email = $email;
            $customer->is_guest = true;
            $customer->id_default_group = (int) \Configuration::get('PS_GUEST_GROUP');
            $customer->passwd = \Tools::hash(\Tools::passwdGen());
            $customer->add();
        }

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
        $address->city = $normalized['city'];
        $address->postcode = $normalized['postcode'];
        $address->phone = $normalized['phone'];

        // Some countries require a state; pick the first one rather than failing
        // validation on a field no wallet supplies.
        if (\Country::containsStates($countryId)) {
            $states = \State::getStatesByIdCountry($countryId);

            if ($states) {
                $address->id_state = (int) $states[0]['id_state'];
            }
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
