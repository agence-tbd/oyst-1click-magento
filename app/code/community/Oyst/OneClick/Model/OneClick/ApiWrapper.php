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

use Oyst\Api\OystApiClientFactory;
use Oyst\Api\OystOneClickApi;
use Oyst\Classes\OneClickNotifications;
use Oyst\Classes\OneClickOrderParams;

/**
 * OneClick ApiWrapper Model
 */
class Oyst_OneClick_Model_OneClick_ApiWrapper extends Oyst_OneClick_Model_Api
{
    /** @var Oyst_OneClick_Model_Api $_oystClient */
    protected $_oystClient;

    /** @var OystOneClickApi $_oneClickApi */
    protected $_oneClickApi;

    protected $_type = OystApiClientFactory::ENTITY_ONECLICK;

    public function __construct()
    {
        $this->_oystClient = Mage::getModel('oyst_oneclick/api');
        $this->_oneClickApi = $this->_oystClient->getClient($this->_type);
    }

    /**
     * Get config from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function _getConfig($code)
    {
        return Mage::getStoreConfig("oyst/oneclick/$code");
    }

    /**
     * API send
     *
     * @param $dataFormated
     *
     * @return mixed
     *
     * @throws Mage_Core_Exception
     */
    public function authorizeOrder($dataFormated)
    {
        $dataFormated['version'] = Mage::getStoreConfig('oyst/oneclick/order_api_version');

        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($dataFormated['productId']);

        // Test if configurableProductChildId is set for configurable
        if ($product->isConfigurable()
            && null === filter_var($dataFormated['configurableProductChildId'], FILTER_NULL_ON_FAILURE)) {
            throw Mage::exception(
                'Oyst_OneClick',
                Mage::helper('oyst_onelick')->__(
                    sprintf(
                        "configurableProductChildId is null for configurable product id %s",
                        $dataFormated['productId']
                    )
                )
            );
        } elseif ($product->isConfigurable()) {
            /** @var Mage_Catalog_Model_Product $product */
            $configurableProductChild = Mage::getModel('catalog/product')
                ->load($dataFormated['configurableProductChildId']);
        }

        // Validate Qty
        if ($product->isConfigurable()) {
            /** @var Mage_CatalogInventory_Model_Stock_Item $stock */
            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($configurableProductChild);
            $result = $stock->checkQuoteItemQty($dataFormated['quantity'], $configurableProductChild->getQty());
        } else {
            /** @var Mage_CatalogInventory_Model_Stock_Item $stock */
            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
            $result = $stock->checkQuoteItemQty($dataFormated['quantity'], $product->getQty());
        }

        // Manage form error
        if ($result->getData('has_error')) {
            $message = $result->getData('message');
            $result->setData('message', str_replace('""', '"' . $product->getName() . '"', $message));

            return array('has_error' => $result->getData('has_error'), 'message' => $result->getData('message'));
        }

        /** @var Oyst_OneClick_Model_Catalog $oystCatalog */
        $oystCatalog = Mage::getModel('oyst_oneclick/catalog');
        $oystProduct = $oystCatalog->getOystProduct($dataFormated['productId'], $dataFormated['configurableProductChildId']);

        /** @var Oyst_OneClick_Helper_Data $oystHelper */
        $oystHelper = Mage::helper('oyst_oneclick');

        $oystHelper->defaultValue($dataFormated['productId'], null);
        $oystHelper->defaultValue($dataFormated['quantity'], 1);
        $oystHelper->defaultValue($dataFormated['configurableProductChildId'], null);
        $oystHelper->defaultValue($dataFormated['user'], null);
        $oystHelper->defaultValue($dataFormated['version'], 1);
        $dataFormated['preload'] = filter_var($dataFormated['preload'], FILTER_VALIDATE_BOOLEAN);

        $notifications = new OneClickNotifications();
        $notifications->setShouldAskShipments(true);
        $notifications->setShouldAskStock($this->_getConfig('should_ask_stock'));
        $notifications->setUrl($this->_getConfig('notification_url'));

        // Book initial quantity
        if (!$dataFormated['preload'] && $this->_getConfig('should_ask_stock')) {
            $realPid = $product->isConfigurable() ? $dataFormated['configurableProductChildId'] : $product->getId();
            $oystCatalog->stockItemToBook($realPid, $dataFormated['quantity']);
            $notifications->addEvent('order.stock.released');
        }

        Mage::helper('oyst_oneclick')->log('$dataFormated');
        Mage::helper('oyst_oneclick')->log($dataFormated);

        $orderParams = new OneClickOrderParams();
        $orderParams->setManageQuantity($this->_getConfig('allow_quantity_change'));

        $context = array(
            'id' => $this->generateId(),
            'remote_addr' => Mage::helper('core/http')->getRemoteAddr(),
            'store_id' => Mage::app()->getStore()->getStoreId(),
        );
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $context['user_id'] = (string)Mage::getSingleton('customer/session')->getCustomer()->getId();
        }

        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $context['user_id'] = Mage::getSingleton('customer/session')->getCustomerId();
        }

        try {
            // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
            if ($product->isConfigurable()) {
                $dataFormated['productId'] .= ';'. $dataFormated['configurableProductChildId'];
                $oystProduct->__set('reference', $dataFormated['productId']);
            }

            $response = $this->_oneClickApi->authorizeOrder(
                $dataFormated['productId'],
                $dataFormated['quantity'],
                $dataFormated['configurableProductChildId'],
                $dataFormated['user'],
                $dataFormated['version'],
                $oystProduct,
                $orderParams,
                $context,
                $notifications
            );
            $this->_oystClient->validateResult($this->_oneClickApi);

        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $response;
    }

    /**
     * Generate unique identifier.
     *
     * Identifier is built as [custom string][current datetime][random part]
     *
     * @return string
     */
    public function generateId($string = null)
    {
        $randomPart = rand(10, 99);

        list($usec, $sec) = explode(' ', microtime());
        unset($sec);

        $microtime = explode('.', $usec);
        $datetime = new DateTime();
        $datetime = $datetime->format('YmdHis');

        return $string . $datetime . $microtime[1] . $randomPart;
    }
}
