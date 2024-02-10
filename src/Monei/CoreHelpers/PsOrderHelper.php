<?php


namespace Monei\CoreHelpers;

use Context;
use Db;
use Monei\ApiException;
use Monei\CoreClasses\Monei;
use Monei\Model\MoneiPayment;
use Monei\Model\MoneiPaymentStatus;
use Order;

class PsOrderHelper
{
    /**
     * Save Payment object information into log
     * @param MoneiPayment $payment
     * @param bool $save_amount
     * @param bool $is_refund
     * @return mixed
     */
    public static function saveTransaction(
        MoneiPayment $payment,
        bool         $save_amount = false,
        bool         $is_refund = false,
        bool         $is_callback = false,
        bool         $failed = false
    ): bool
    {
        try {
            $id_cart = false;
            $id_cart_array = explode('m', $payment->getOrderId());
            if (is_array($id_cart_array)) {
                $id_cart = (int)$id_cart_array[0];
            }

            if (!$id_cart) { // We do nothing if theres no cart information
                return false;
            }

            $monei = null;
            $id_monei = Monei::getIdByInternalOrder($payment->getOrderId());

            if (self::sameState($id_monei, $payment) && !$is_callback && !$is_refund) {
                return false;
            }

            if ($id_monei) {
                $monei = new Monei($id_monei);
                if (self::hasMoneiCallback($id_monei) && !$is_refund) {
                    return true;
                }
            } else {
                $monei = new Monei();
            }

            $monei->id_cart = (int)$id_cart;
            $monei->status = in_array($payment->getStatus(), MoneiPaymentStatus::getAllowableEnumValues())
                ? pSQL($payment->getStatus()) : 'UNKNOWN';

            $id_order = \Order::getIdByCartId($id_cart);
            $monei->id_order = (int)$id_order > 0 ? (int)$id_order : null;
            $monei->id_order_internal = pSQL($payment->getOrderId());
            if ($save_amount) {
                $monei->amount = (int)$payment->getAmount();
            }
            $monei->currency = pSQL($payment->getCurrency());

            if ($monei->save()) { // We can save additional information
                $status_code = $payment->getStatusCode();
                $id_status_code = Db::getInstance()->getValue(
                    'SELECT id_monei_codes FROM ' . _DB_PREFIX_ . 'monei_codes WHERE code = "'
                    . pSQL($status_code) . '"'
                );

                $id_status_code = $id_status_code ? (int)$id_status_code : null;
                self::saveHistory($id_status_code, $is_refund, $monei, $payment, $is_callback);

                if ($is_refund) {
                    self::saveRefund(Db::getInstance()->Insert_ID(), $monei, $payment);
                }

                if ($payment->getPaymentToken() && !$failed) {
                    self::savePaymentToken($monei, $payment);
                }
            }
            return true;
        } catch (\Exception $ex) {
            throw new ApiException(
                $ex->getMessage(),
                $ex->getCode()
            );
        }
    }

    /**
     * Avoid saving same information
     * @param int $id_monei
     * @param MoneiPayment $payment
     * @return bool
     */
    private static function sameState(
        int          $id_monei,
        MoneiPayment $payment
    )
    {
        $res = Db::getInstance()->getRow('
            SELECT status, id_monei_code FROM `' . _DB_PREFIX_ . 'monei_history` WHERE id_monei = '
            . (int)$id_monei . ' ORDER BY id_monei_history DESC
        ');

        $last_code = null;
        $last_state = null;

        if ($res) {
            $last_code = $res['id_monei_code'];
            $last_state = $res['status'];
        }

        $new_state = $payment->getStatus();
        $new_code_monei = $payment->getStatusCode();
        $new_code = Db::getInstance()->getValue(
            '
            SELECT id_monei_codes FROM `' . _DB_PREFIX_ . 'monei_codes`  WHERE `code` = "'
            . pSQL($new_code_monei) . '"'
        );

        if ($new_state == $last_state && $last_code == $new_code && !is_null($last_code) && !is_null($last_state)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if exists a callback history
     * @param int $id_monei
     * @return bool
     */
    public static function hasMoneiCallback(
        int $id_monei
    ): bool
    {
        $has_callback = Db::getInstance()->getValue(
            'SELECT id_monei_history FROM `' . _DB_PREFIX_ . 'monei_history` WHERE id_monei = ' .
            (int)$id_monei . ' AND is_callback = 1'
        );

        return $has_callback ? true : false;
    }

    /**
     * Saves transaction history
     * @param int $id_status_code
     * @param bool $is_refund
     * @param Monei $monei
     * @param MoneiPayment $payment
     * @return bool
     */
    private static function saveHistory(
        ?int         $id_status_code,
        bool         $is_refund,
        Monei        $monei,
        MoneiPayment $payment,
                     $is_callback = false
    )
    {
        return Db::getInstance()->insert('monei_history', [
            'id_monei' => (int)$monei->id,
            'status' => pSQL($monei->status),
            'id_monei_code' => $id_status_code,
            'is_refund' => (int)$is_refund,
            'is_Callback' => (int)$is_callback,
            'response' => pSQL(json_encode($payment->toJSON())),
            'date_add' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Save Refund Payment object information into log
     * @param MoneiPayment $refund
     * @return bool
     */
    private static function saveRefund(
        int          $id_monei_history,
        Monei        $monei,
        MoneiPayment $refund
    ): bool
    {
        return Db::getInstance()->insert('monei_refund', [
            'id_monei' => (int)$monei->id,
            'id_monei_history' => (int)$id_monei_history,
            'amount' => (int)$refund->getLastRefundAmount(),
            'reason' => $refund->getLastRefundReason(),
            'id_employee' => (int)Context::getContext()->employee->id,
            'date_add' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Saves Credit Card token
     * @param MoneiPayment $payment
     * @return bool
     */
    private static function savePaymentToken(
        Monei        $monei,
        MoneiPayment $payment
    ): bool
    {
        $id_order = $monei->id_order;
        $order = new Order($id_order);
        $id_customer = $order->id_customer;

        $token = $payment->getPaymentToken();
        $credit_card = $payment->getPaymentMethod() ? $payment->getPaymentMethod()->getCard() : null;

        if ($credit_card) {
            $credit_card_brand = $credit_card->getBrand();
            $credit_card_country = $credit_card->getCountry();
            $credit_card_is_3ds = $credit_card->getThreeDSecure();
            $credit_card_3ds_version = $credit_card->getThreeDSecureVersion();
            $credit_card_expiration = $credit_card->getExpiration();
            $credit_last_four = $credit_card->getLast4();

            // First, check if the card already exists
            $id_monei_tokens = (int)Db::getInstance()->getValue(
                'SELECT id_monei_tokens FROM `' . _DB_PREFIX_ . 'monei_tokens` WHERE id_customer = ' .
                (int)$id_customer . ' AND tokenized = "' . pSQL($token) . '" AND expiration = '
                . (int)$credit_card_expiration . ' AND last_four = "' . pSQL($credit_last_four) . '"'
            );

            if ($id_monei_tokens === 0 && $id_customer > 0) {
                return Db::getInstance()->insert('monei_tokens', [
                    'id_customer' => (int)$id_customer,
                    'brand' => pSQL($credit_card_brand),
                    'country' => pSQL($credit_card_country),
                    'last_four' => pSQL($credit_last_four),
                    'threeDS' => (bool)$credit_card_is_3ds,
                    'threeDS_version' => pSQL($credit_card_3ds_version),
                    'expiration' => (int)$credit_card_expiration,
                    'tokenized' => pSQL($token),
                    'date_add' => date('Y-m-d H:i:s')
                ]);
            }
        }
        return false;
    }

    /**
     * Check if order has already been placed.
     * @link https://github.com/PrestaShop/PrestaShop/issues/16490 (duplicate order bug)
     * @param int $id_cart
     * @return bool Indicates if the Order exists
     */
    public static function orderExists(int $id_cart)
    {
        $id_order = Db::getInstance()->getValue(
            'SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int)$id_cart,
            false
        );

        return (int)$id_order > 0 ? true : false;
    }
}
