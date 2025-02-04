<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PsMonei\Entity\MoCustomerCard;

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
        $customerCards = $this->module->getRepository(MoCustomerCard::class)->findBy(['id_customer' => $customerId]);
        return array_map(fn($card) => $card->toArray(), $customerCards);
    }

    public function displayAjaxDeleteCustomerCard()
    {
        try {
            $customerCardId = (int) Tools::getValue('customerCardId');
            $customerCard = $this->module->getRepository(MoCustomerCard::class)->findOneBy([
                'id' => $customerCardId,
                'id_customer' => (int) $this->context->customer->id
            ]);

            if ($customerCard) {
                $this->module->getRepository(MoCustomerCard::class)->removeMoneiCustomerCard($customerCard);
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
            'error' => $success ? null : $errorMessage
        ]));
    }

    protected function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->module->l('My Credit Cards', basename(__FILE__, '.php')),
            'url' => $this->context->link->getModuleLink('monei', 'customerCards')
        ];

        return $breadcrumb;
    }
}
