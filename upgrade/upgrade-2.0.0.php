<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0()
{
    $db = Db::getInstance();

    // Start transaction for data integrity
    $db->execute('START TRANSACTION');

    try {
        // Create the new tables
        include_once dirname(__FILE__) . '/../sql/install.php';

        // Migration monei to monei2_payment
        // ----------------------------------------------
        $db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'monei2_payment`;');
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'monei2_payment` (`id_payment`, `id_cart`, `id_order`, `id_order_monei`, `amount`, `refunded_amount`, `currency`, `authorization_code`, `status`, `date_add`, `date_upd`)
              SELECT `id_order_monei`, `id_cart`, `id_order`, `id_order_monei`, `amount`, NULL AS `refunded_amount`, `currency`, `authorization_code`, `status`, `date_add`, `date_upd`
              FROM `' . _DB_PREFIX_ . 'monei`
              WHERE `id_order_monei` IS NOT NULL AND `id_order_monei` != "";';
        $db->execute($sql);
        // ----------------------------------------------

        // Migration monei_tokens to monei2_customer_card
        // ----------------------------------------------
        $db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'monei2_customer_card`;');
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
            $db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'monei2_history`;');

            foreach ($moneiHistoryResult as $history) {
                $query = new DbQuery();
                $query->select('code')
                    ->from('monei_codes')
                    ->where('id_monei_codes = ' . $history['id_monei_code']);
                $statusCode = $db->getValue($query);
                if (!$statusCode) {
                    $statusCode = 'UNKNOWN';
                }

                // Handle potential double JSON encoding from legacy system
                $response = $history['response'];
                $moneiPayment = json_decode($response, true);

                // If first decode didn't work or result is still a string, try second decode
                if (!is_array($moneiPayment) && is_string($moneiPayment)) {
                    $moneiPayment = json_decode($moneiPayment, true);
                }

                // If still not an array, log and use empty array
                if (!is_array($moneiPayment)) {
                    PrestaShopLogger::addLog(
                        'MONEI - upgrade-2.0.0 - Failed to decode payment response for history ID: ' . $history['id_monei_history'],
                        PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                    );
                    $moneiPayment = [];
                }

                $db->insert('monei2_history', [
                    'id_history' => (int) $history['id_monei_history'],
                    'id_payment' => isset($moneiPayment['id']) ? pSQL($moneiPayment['id']) : '',
                    'status' => pSQL($history['status']),
                    'status_code' => pSQL($statusCode),
                    'response' => pSQL(json_encode($moneiPayment)),
                    'date_add' => pSQL($history['date_add']),
                ]);
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
            $db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'monei2_refund`;');

            foreach ($moneiRefundResult as $refund) {
                $query = new DbQuery();
                $query->select('id_order_monei')
                    ->from('monei')
                    ->where('id_monei = ' . $refund['id_monei']);
                $paymentId = $db->getValue($query);
                if (!$paymentId) {
                    PrestaShopLogger::addLog(
                        'MONEI - upgrade-2.0.0 - Skipping refund ID ' . $refund['id_monei_refund'] . ' - no matching payment found',
                        PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                    );

                    continue;
                }

                $db->insert('monei2_refund', [
                    'id_payment' => $paymentId,
                    'id_history' => (int) $refund['id_monei_history'],
                    'id_employee' => (int) $refund['id_employee'],
                    'reason' => pSQL($refund['reason']),
                    'amount' => (float) $refund['amount'],
                    'date_add' => pSQL($refund['date_add']),
                ]);
            }
        }
        // ----------------------------------------------

        Configuration::deleteByName('MONEI_ALLOW_COFIDIS');

        // Commit transaction
        $db->execute('COMMIT');

        PrestaShopLogger::addLog(
            'MONEI - upgrade-2.0.0 - Migration completed successfully',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        return true;
    } catch (Exception $e) {
        // Rollback on error
        $db->execute('ROLLBACK');

        PrestaShopLogger::addLog(
            'MONEI - upgrade-2.0.0 - Migration failed: ' . $e->getMessage(),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
        );

        return false;
    }
}
