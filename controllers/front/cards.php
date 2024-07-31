<?php

use Monei\CoreClasses\MoneiCard;

// Load libraries
require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiCardsModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        // Check if customer is logged in
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&back=my-account');
        }

        parent::initContent();

        if (Configuration::get('PS_SSL_ENABLED') == 1) {
            $url_shop = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
        } else {
            $url_shop = Tools::getShopDomain(true) . __PS_BASE_URI__;
        }

        $this->context->smarty->assign(array(
            'base_dir' => $url_shop,
            'modules_dir' => $url_shop . 'modules/',
            'is_warehouse' => Module::isEnabled('iqitelementor'),
            'monei_cards' => MoneiCard::getStaticCustomerCards($this->context->customer->id),
        ));

        $this->setTemplate('module:monei/views/templates/front/cards.tpl');
    }

    /**
     * Deletes a customer card (if it exists)
     * @return json
     */
    public function displayAjaxDeletecard()
    {
        $id_customer = (int)$this->context->customer->id;
        $id_monei_tokens = (int)Tools::getValue('id_monei_card');

        $res = Db::getInstance()->delete(
            'monei_tokens',
            'id_customer = ' . (int)$id_customer . ' AND id_monei_tokens = ' . (int)$id_monei_tokens
        );

        die(json_encode([
            'success' => $res
        ]));
    }

    protected function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] =
            [
                'title' => $this->module->l('My Credit Cards', 'cards'),
                'url' => $this->context->link->getModuleLink('monei', 'cards')
            ];

        return $breadcrumb;
    }
}
