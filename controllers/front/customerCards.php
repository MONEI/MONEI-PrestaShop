<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PsMonei\Entity\Monei2CustomerCard;

class MoneiCustomerCardsModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&back=my-account');
        }

        parent::initContent();

        $url_shop = Configuration::get('PS_SSL_ENABLED')
            ? Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            : Tools::getShopDomain(true) . __PS_BASE_URI__;

        $customerCardsList = $this->getCustomerCards((int) $this->context->customer->id);

        $this->context->smarty->assign([
            'base_dir' => $url_shop,
            'modules_dir' => $url_shop . 'modules/',
            'isWarehouseInstalled' => Module::isEnabled('iqitelementor'),
            'customerCardsList' => $customerCardsList,
        ]);

        $this->setTemplate('module:monei/views/templates/hook/_partials/customerCards.tpl');
    }

    private function getCustomerCards(int $customerId): array
    {
        $customerCards = $this->module->getRepository(Monei2CustomerCard::class)->findBy(['id_customer' => $customerId]);
        $paymentMethodFormatter = $this->module->getService('helper.payment_method_formatter');

        return array_map(function ($card) use ($paymentMethodFormatter) {
            $cardData = $card->toArray();
            $cardBrand = strtolower($card->getBrand());

            // Add formatted display and icon
            $cardData['displayName'] = $paymentMethodFormatter->formatPaymentDisplay('card', $cardBrand, $card->getLastFour());
            $cardData['iconHtml'] = $paymentMethodFormatter->renderPaymentMethodIcon('card', $cardBrand, [
                'width' => 48,
                'height' => 32,
                'class' => 'img img-responsive',
            ]);

            return $cardData;
        }, $customerCards);
    }

    public function displayAjaxDeleteCustomerCard()
    {
        try {
            $customerCardId = (int) Tools::getValue('customerCardId');
            $customerCard = $this->module->getRepository(Monei2CustomerCard::class)->findOneBy([
                'id' => $customerCardId,
                'id_customer' => (int) $this->context->customer->id,
            ]);

            if ($customerCard) {
                $this->module->getRepository(Monei2CustomerCard::class)->remove($customerCard);
            }

            $this->ajaxResponse(true);
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    private function ajaxResponse(bool $success, string $errorMessage = ''): void
    {
        die(json_encode([
            'success' => $success,
            'error' => $success ? null : $errorMessage,
        ]));
    }

    protected function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->module->l('My Credit Cards', basename(__FILE__, '.php')),
            'url' => $this->context->link->getModuleLink('monei', 'customerCards'),
        ];

        return $breadcrumb;
    }
}
