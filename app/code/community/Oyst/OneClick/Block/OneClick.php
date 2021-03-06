<?php
/**
 * This file is part of Oyst_OneClick for Magento.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @author Oyst <plugin@oyst.com> <@oyst>
 * @category Oyst
 * @package Oyst_OneClick
 * @copyright Copyright (c) 2017 Oyst (http://www.oyst.com)
 */

/**
 * OneClick Block
 */
class Oyst_OneClick_Block_OneClick extends Mage_Core_Block_Template
{
    /**
     * Check if the product is supported
     *
     * @return mixed
     */
    public function isSupportedProduct()
    {
        /** @var Oyst_OneClick_Model_Catalog $oystCatalog */
        $oystCatalog = Mage::getModel('oyst_oneclick/catalog');

        return $oystCatalog->isSupportedProduct($this->getProduct());
    }

    /**
     * Retrieve product
     *
     * @return Mage_Catalog_Model_Product
     */
    public function getProduct()
    {
        return Mage::registry('product');
    }

    /**
     * Return the shop payment url
     * Used to get in server-to-server payment url
     *
     * @return mixed
     */
    public function getOneClickUrl()
    {
        $store = Mage::getSingleton('adminhtml/session_quote')->getStore();

        return Zend_Json::encode($this->escapeUrl(Mage::getStoreConfig('oyst/oneclick/payment_url', $store->getId())));
    }

    /**
     * Is form validation enable
     *
     * @return mixed
     */
    public function isProductAddtocartFormValidate()
    {
        return Mage::getStoreConfig('oyst/oneclick/product_addtocart_form_validate');
    }

    /**
     * Return button customization
     *
     * @return mixed
     */
    public function getButtonCustomization()
    {
        $buttonCustomization = '';
        $customizationAttributes = array('theme', 'color', 'width', 'height', 'rounded', 'smart');
        foreach ($customizationAttributes as $customizationAttribute) {
            $config = Mage::getStoreConfig('oyst/oneclick/button_' . $customizationAttribute);
            if (empty($config)) {
                continue;
            }

            if (in_array($customizationAttribute, array('rounded', 'smart'))) {
                $config = filter_var($config, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            }

            $buttonCustomization .= sprintf(" data-" .  $customizationAttribute . "='%s'", $config);
        }

        return $buttonCustomization;
    }

    /**
     * Move OneClick button in first position in add to cart buttons list
     *
     * @return mixed
     */
    public function oneClickButtonPickToFirstAddToCartButtons()
    {
        if ('before' === Mage::getStoreConfig('oyst/oneclick/button_before_addtocart')) {
            $class = Mage::getStoreConfig('oyst/oneclick/product_page_buttons_wrapper_class');

            return 'oneClickButtonPickToFirstAddToCartButtons("' . $class . '");';
        }
    }

    /**
     * Change
     *
     * @return stringw
     */
    public function getButtonWrapperPosition()
    {
        $style = '';
        if ($buttonWidth = Mage::getStoreConfig('oyst/oneclick/button_width')) {
            $style .= sprintf('width: %s;', $buttonWidth);
        }

        $buttonLeftMargin = Mage::getStoreConfig('oyst/oneclick/button_left_margin');
        $style .= sprintf(' left: %s;', $buttonLeftMargin);

        return $style;
    }

    /**
     * Get the product type: simple, configurable, grouped, ...
     *
     * @return mixed
     */
    public function getProductType()
    {
        return Zend_Json::encode($this->escapeHtml($this->getProduct()->getTypeId()));
    }
}
