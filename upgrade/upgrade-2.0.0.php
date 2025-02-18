<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0()
{
    $db = Db::getInstance();

    // Create the new tables
    include_once(dirname(__FILE__) . '/install.php');

    // Migration monei to monei2_payment
    // ----------------------------------------------
    $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'monei2_payment` (`id_payment`, `id_cart`, `id_order`, `id_order_monei`, `amount`, `refunded_amount`, `currency`, `authorization_code`, `status`, `date_add`, `date_upd`)
              SELECT `id_order_monei`, `id_cart`, `id_order`, `id_order_monei`, `amount`, NULL AS `refunded_amount`, `currency`, `authorization_code`, `status`, `date_add`, `date_upd`
              FROM `' . _DB_PREFIX_ . 'monei`;';
    $db->execute($sql);
    // ----------------------------------------------

    // Migration monei_tokens to monei2_customer_card
    // ----------------------------------------------
    $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'monei2_customer_card` (`id_customer_card`, `id_customer`, `brand`, `country`, `last_four`, `expiration`, `tokenized`, `date_add`)
              SELECT `id_monei_tokens`, `id_customer`, `brand`, `country`, `last_four`, `expiration`, `tokenized`, `date_add`
              FROM `' . _DB_PREFIX_ . 'monei_tokens`;';
    $db->execute($sql);
    // ----------------------------------------------

    // Migration monei_history to monei2_history
    // ----------------------------------------------
    $query = new DbQuery();
    $query->select('*')
          ->from('monei_history')
          ->where('id_monei_history > 0');
    $moneiHistoryResult = $db->executeS($query);

    if ($moneiHistoryResult) {
        foreach ($moneiHistoryResult as $history) {
            $paymentResponse = json_decode($history['response'], true);

            $query = new DbQuery();
            $query->select('code')
                  ->from('monei_codes')
                  ->where('id_monei_code = ' . $history['id_monei_code']);
            $statusCode = $db->getValue($query);
            if (!$statusCode) {
                $statusCode = 'UNKNOWN';
            }

            $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'monei2_history` (`id_history`, `id_payment`, `status`, `status_code`, `response`, `date_add`)
                    VALUES (' . $history['id_monei_history'] . ', "' . $paymentResponse['id'] . '", "' . $history['status'] . '", "' . $statusCode . '", "' . $history['response'] . '", "' . $history['date_add'] . '");';
            $db->execute($sql);
        }
    }
    // ----------------------------------------------

    // Migration monei_refund to monei2_refund
    // ----------------------------------------------
    $query = new DbQuery();
    $query->select('*')
          ->from('monei_refund')
          ->where('id_monei_refund > 0');
    $moneiRefundResult = $db->executeS($query);
    if ($moneiRefundResult) {
        foreach ($moneiRefundResult as $refund) {
            $query = new DbQuery();
            $query->select('id_order_monei')
                  ->from('monei')
                  ->where('id_monei = ' . $refund['id_monei']);
            $paymentId = $db->getValue($query);
            if (!$paymentId) {
                continue;
            }

            $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'monei2_refund` (`id_payment`, `id_history`, `id_employee`, `reason`, `amount`, `date_add`)
                    VALUES (' . $paymentId . ', ' . $refund['id_monei_history'] . ', ' . $refund['id_employee'] . ', "' . $refund['reason'] . '", ' . $refund['amount'] . ', "' . $refund['date_add'] . '");';
            $db->execute($sql);
        }
    }
    // ----------------------------------------------

    return true;
}
