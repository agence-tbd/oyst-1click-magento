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

use Oyst\Classes\OneClickItem;
use Oyst\Classes\OneClickShipmentCalculation;
use Oyst\Classes\OneClickShipmentCatalogLess;
use Oyst\Classes\OneClickStock;
use Oyst\Classes\OystCarrier;
use Oyst\Classes\OystCategory;
use Oyst\Classes\OystPrice;
use Oyst\Classes\OystProduct;

/**
 * Catalog Model
 */
class Oyst_OneClick_Model_Catalog extends Mage_Core_Model_Abstract
{
    /**
     * Supported type of product
     *
     * @var array
     */
    protected $_supportedProductTypes = array(
        Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
        Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
        //Mage_Catalog_Model_Product_Type::TYPE_GROUPED,
        //Mage_Catalog_Model_Product_Type::TYPE_BUNDLE,
        //Mage_Catalog_Model_Product_Type::TYPE_VIRTUAL,
        //Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE,
    );

    /**
     * Translate Product attribute for Oyst <-> Magento
     *
     * @var array
     */
    protected $_productAttrTranslate = array(
        'entity_id' => array(
            'lib_property' => 'reference',
            'type' => 'string',
            'required' => true,
        ),
        'status' => array(
            'lib_property' => 'active',
            'type' => 'bool',
        ),
        'name' => array(
            'lib_property' => 'title',
            'type' => 'string',
            'required' => true,
        ),
        'short_description' => array(
            'lib_property' => 'shortDescription',
            'type' => 'string',
        ),
        'description' => array(
            'lib_property' => 'description',
            'type' => 'string',
        ),
        'manufacturer' => array(
            'lib_property' => 'manufacturer',
            'type' => 'string',
            'required' => true,
        ),
        'weight' => array(
            'lib_property' => 'weight',
            'type' => 'string',
        ),
        'ean' => array(
            'lib_property' => 'ean',
            'type' => 'string',
        ),
        'isbn' => array(
            'lib_property' => 'isbn',
            'type' => 'string',
        ),
        'upc' => array(
            'lib_property' => 'upc',
            'type' => 'string',
        ),
    );

    /**
     * Custom attribute code
     *
     * @var array
     */
    protected $_customAttributesCode = array('color', 'size');

    /**
     * Selected system attribute code
     *
     * @var array
     */
    protected $_systemSelectedAttributesCode = array();

    /**
     * Variations attribute code
     *
     * @var array
     */
    protected $_variationAttributesCode = array('price', 'final_price');

    /**
     * User defined attribute code
     *
     * @var array
     */
    protected $_userDefinedAttributeCode = array();

    /**
     * @var int
     */
    protected $_productId = null;

    /**
     * @var int
     */
    protected $_configurableProductChildId = null;

    /**
     * @var null|Mage_CatalogInventory_Model_Stock_Item
     */
    private $stockItem = null;

    /**
     * Object construct
     *
     * @return null
     */
    public function __construct()
    {
        if (!$this->_getConfig('enable')) {
            Mage::throwException(Mage::helper('oyst_oneclick')->__('1-Click module is not enabled.'));
        }
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
     * Catalog process from notification controller
     *
     * @param array $event
     * @param array $apiData
     *
     * @return string
     */
    public function processNotification($event, $apiData)
    {
        // Create new notification in db with status 'start'
        $notification = Mage::getModel('oyst_oneclick/notification');
        $notification->setData(array(
            'event' => $event,
            'oyst_data' => Zend_Json::encode($apiData),
            'status' => 'start',
            'created_at' => Mage::getModel('core/date')->gmtDate(),
            'executed_at' => Mage::getModel('core/date')->gmtDate(),
        ));
        $notification->save();
        Mage::helper('oyst_oneclick')->log('Start processing notification: ' . $notification->getNotificationId());

        // Do action for each event type
        switch ($event) {
            case 'order.cart.estimate':
                $response = $this->cartEstimate($apiData);
                break;

            // Reduce qty in order or cancel booking
            case 'order.stock.released':
                $response = $this->stockReleased($apiData);
                break;

            // Increase qty in order
            case 'order.stock.book':
                $response = $this->stockBook($apiData);
                break;

            default:
                Mage::helper('oyst_oneclick')->log('No action defined for event ' . $event);
                break;
        }

        // Save new status and result in db
        $notification->setStatus('finished')
            ->setMageResponse($response)
            ->setExecutedAt(Mage::getSingleton('core/date')->gmtDate())
            ->save();
        Mage::helper('oyst_oneclick')->log('End processing notification: ' . $notification->getNotificationId());

        return $response;
    }

    /**
     * Return a OystProduct
     *
     * @param int $productId
     * @param int $configurableProductChildId
     *
     * @return OystProduct
     */
    public function getOystProduct($productId, $configurableProductChildId = null)
    {
        $this->_productId = $productId;

        if (!(null === $configurableProductChildId)) {
            $this->_configurableProductChildId = $configurableProductChildId;
        }

        // Params used to pass data in prepare collection ; allow to pass multiple product
        $params['product_id_include_filter'] = array($this->_productId);

        // Get list of product from params
        $collection = $this->_prepareCollection($params);

        /** @var Oyst_OneClick_Helper_Data $oystHelper */
        $oystHelper = Mage::helper('oyst_oneclick');
        $oystHelper->log('Catalog less product collection Sql : ' . $collection->getSelect()->__toString());

        $this->_userDefinedAttributeCode = $this->_getUserDefinedAttributeCode();
        $this->_systemSelectedAttributesCode = $this->_getSystemSelectedAttributeCode();

        // Format list into OystProduct
        $productsFormated = $this->_format($collection);

        return $productsFormated[0];
    }

    /**
     * Prepare Database Request with filters
     *
     * @param array $params
     *
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    protected function _prepareCollection($params)
    {
        // Construct param for list in db request
        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getModel('catalog/product')->getCollection();
        $collection->addAttributeToFilter('type_id', array('in' => $this->_supportedProductTypes));
        $collection->addAttributeToSelect('*');

        if (!empty($params) && is_array($params)) {
            if (!empty($params['product_id_include_filter'])) {
                $collection->addAttributeToFilter('entity_id', array(
                    'in' => $params['product_id_include_filter'],
                ));
            }

            if (!empty($params['product_id_exclude_filter'])) {
                $collection->addAttributeToFilter('entity_id', array(
                    'nin' => $params['product_id_exclude_filter'],
                ));
            }

            if (!empty($params['num_per_page'])) {
                $collection->setPage(0, $params['num_per_page']);
            }
        }

        // do not use : that include 'catalog_product_index_price' with inner join
        // but this table is used only if product is active once.
        // we want ALL products so let's join manually
        Mage::getSingleton('cataloginventory/stock')->addItemsToProducts($collection);
        $collection->addPriceData();
        $collection->joinField('qty', 'cataloginventory/stock_item', 'qty', 'product_id=entity_id', '{{table}}.stock_id=1', 'left');
        $collection->joinField('backorders', 'cataloginventory/stock_item', 'backorders', 'product_id=entity_id', '{{table}}.stock_id=1', 'left');
        $collection->joinField('min_sale_qty', 'cataloginventory/stock_item', 'min_sale_qty', 'product_id=entity_id', '{{table}}.stock_id=1', 'left');
        $collection->getSelect()->order('FIELD(type_id, ' . ('"' . implode('","', $this->_supportedProductTypes) . '"') . ')');

        Mage::helper('oyst_oneclick')->log('The catalog product size is: ' . $collection->getSize());

        return $collection;
    }

    /**
     * Transform Database Data to formatted array
     *
     * @param Mage_Catalog_Model_Resource_Product_Collection $products
     *
     * @return array
     */
    protected function _format($products)
    {
        $productsFormated = array();
        foreach ($products as $product) {
            // filter on required product and child product
            if (!in_array($product->getId(), array($this->_productId, $this->_configurableProductChildId))) {
                continue;
            }

            $oystProduct = new OystProduct();

            if (is_array($products)) {
                // @TODO This need to be improved
                // @codingStandardsIgnoreLine
                $product = Mage::getModel('catalog/product')->load($product->getId());
            }

            // Get product attributes
            $this->_getAttributes($product, $this->_productAttrTranslate, $oystProduct);
            if ($product->isConfigurable()) {
                $this->_addVariations($product, $oystProduct);
            }

            // Add others attributes
            // Don't get price from child product
            if ($product->getId() === $this->_productId) {
                $this->_addAmount($product, $oystProduct);
            }
            $this->_addComplexAttributes($product, $oystProduct);
            $this->_addCategories($product, $oystProduct);
            $this->_addImages($product, $oystProduct);
            $this->_addRelatedProducts($product, $oystProduct);
            $this->_addCustomAttributesToInformation($product, $oystProduct);

            $productsFormated[] = $oystProduct;
        }

        return $productsFormated;
    }

    /**
     * Return the user defined attributes code
     *
     * @return array
     */
    protected function _getUserDefinedAttributeCode()
    {
        /** @var Mage_Eav_Model_Entity_Type $type */
        $type = Mage::getModel('eav/entity_type');
        $type->loadByCode('catalog_product');

        /** @var $attrs Mage_Eav_Model_Resource_Entity_Attribute_Collection */
        $attrs = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($type)
            ->addFieldToFilter('is_user_defined', true)
            ->addFieldToFilter('frontend_input', 'select');

        $userDefinedAttributeCode = array();
        /** @var $attribute Mage_Eav_Model_Entity_Attribute */
        foreach ($attrs as $attribute) {
            $userDefinedAttributeCode[] = $attribute->getAttributeCode();
        }

        return $userDefinedAttributeCode;
    }

    /**
     * Return the selected system attributes code
     *
     * @return array
     */
    protected function _getSystemSelectedAttributeCode()
    {
        $code = 'systemattributes';
        $attrsIds = explode(',', Mage::getStoreConfig("oyst/oneclick/$code"));

        $systempSelectedAttributeCode = array();
        foreach ($attrsIds as $attributeId) {
            // @codingStandardsIgnoreLine
            $attribute = Mage::getModel('eav/entity_attribute')->load($attributeId);
            $systempSelectedAttributeCode[] = $attribute->getAttributeCode();
        }

        return $systempSelectedAttributeCode;
    }

    /**
     * Return the product attributes code defined by user
     *
     * @param Mage_Catalog_Model_Product $product
     * @param $userDefinedAttributeCode
     *
     * @return array
     */
    protected function _getProductAttributeCodeDefinedByUser(Mage_Catalog_Model_Product $product, $userDefinedAttributeCode)
    {
        $attributes = $product->getAttributes();
        $productAttributeCode = array();
        foreach ($attributes as $attribute) {
            $productAttributeCode[] = $attribute->getAttributeCode();
        }

        $attributeCodes = array_unique(
            array_merge(
                array_intersect($userDefinedAttributeCode, $productAttributeCode),
                $this->_customAttributesCode,
                $this->_systemSelectedAttributesCode
            )
        );

        return $attributeCodes;
    }

    /**
     * Get product attributes
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $translateAttribute
     * @param OystProduct $oystProduct
     */
    protected function _getAttributes(Mage_Catalog_Model_Product $product, Array $translateAttribute, OystProduct &$oystProduct)
    {
        foreach ($translateAttribute as $attributeCode => $simpleAttribute) {
            if ($data = $product->getData($attributeCode)) {
                if ($simpleAttribute['type'] == 'jsonb') {
                    $data = Zend_Json::encode(array(
                        'meta' => $data,
                    ));
                } else {
                    settype($data, $simpleAttribute['type']);
                }

                if ($data !== null) {
                    $oystProduct->__set($simpleAttribute['lib_property'], ($data));
                }
            } elseif (array_key_exists('required', $simpleAttribute) && $simpleAttribute['required'] == true) {
                if ('jsonb' == $simpleAttribute['type']) {
                    $data = '{}';
                } else {
                    $data = 'Empty';
                    settype($data, $simpleAttribute['type']);
                }
            }
        }
    }

    /**
     * Add variations attributes to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     *
     * @return array
     */
    protected function _addVariations(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $productAttributeCodeDefinedByUser = $this->_getProductAttributeCodeDefinedByUser($product, $this->_userDefinedAttributeCode);

        $requiredAttributesCode = array_unique(
            array_merge(
                array_keys($this->_productAttrTranslate),
                $this->_customAttributesCode,
                $this->_systemSelectedAttributesCode,
                $productAttributeCodeDefinedByUser,
                $this->_variationAttributesCode
            )
        );

        $requiredAttributesIds = array();
        foreach ($requiredAttributesCode as $requiredAttributeCode) {
            $requiredAttributesIds[] = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', $requiredAttributeCode);
        }

        /** @var Mage_Catalog_Model_Product_Type_Configurable $childProducts */
        $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts($requiredAttributesIds, $product);

        $variationProductsFormated = $this->_format($childProducts);

        $oystProduct->__set('variations', $variationProductsFormated);
    }

    /**
     * Add price to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function _addAmount(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $prices = $this->_getPrices($product);
        $oystPriceIncludingTaxes = new OystPrice($prices['price-including-tax'], 'EUR');
        $oystProduct->__set('amountIncludingTax', $oystPriceIncludingTaxes);

        if (isset($prices['price-excluding-tax'])) {
            $oystPriceExcludingTaxes = new OystPrice($prices['price-excluding-tax'], 'EUR');
            $oystProduct->__set('amountExcludingTax', $oystPriceExcludingTaxes);
        }
    }

    /**
     * Get prices
     *
     * @param Mage_Catalog_Model_Product $product
     * @param Mage_Catalog_Model_Product $configurable
     * @param integer $storeId
     *
     * @return array
     */
    protected function _getPrices(Mage_Catalog_Model_Product $product, $storeId = null)
    {
        $store = Mage::app()->getStore($storeId);
        $priceIncludesTax = Mage::helper('tax')->priceIncludesTax($store);
        $shippingPriceIncludesTax = Mage::helper('tax')->shippingPriceIncludesTax($store);
        $calculator = Mage::getSingleton('tax/calculation');
        $taxClassId = $product->getTaxClassId();
        $request = $calculator->getRateRequest(null, null, null, $store);
        $taxPercent = $calculator->getRate($request->setProductClassId($taxClassId));

        $price = $product->getPrice();
        $finalPrice = $product->getFinalPrice();

        if ($product->isConfigurable()) {
            $price = $product->getPrice();
            $finalPrice = $product->getFinalPrice();
            $configurablePrice = 0;
            $configurableOldPrice = 0;

            $attributes = $product->getTypeInstance(true)->getConfigurableAttributes($product);
            $attributes = Mage::helper('core')->decorateArray($attributes);
            if ($attributes) {
                foreach ($attributes as $attribute) {
                    $productAttribute = $attribute->getProductAttribute();
                    $productAttributeId = $productAttribute->getId();
                    // @codingStandardsIgnoreLine
                    $configurableProductChild = Mage::getModel('catalog/product')->load($this->_configurableProductChildId);
                    $attributeValue = $configurableProductChild->getData($productAttribute->getAttributeCode());
                    // @codingStandardsIgnoreLine
                    if (count($attribute->getPrices()) > 0) {
                        foreach ($attribute->getPrices() as $priceChange) {
                            if (is_array($priceChange) && array_key_exists('value_index',
                                    $priceChange) && $priceChange['value_index'] == $attributeValue
                            ) {
                                $configurableOldPrice += (float)($priceChange['is_percent'] ? (((float)$priceChange['pricing_value']) * $price / 100) : $priceChange['pricing_value']);
                                $configurablePrice += (float)($priceChange['is_percent'] ? (((float)$priceChange['pricing_value']) * $finalPrice / 100) : $priceChange['pricing_value']);
                            }
                        }
                    }
                }
            }
            $product->setConfigurablePrice($configurablePrice);
            $product->setParentId(true);
            Mage::dispatchEvent(
                'catalog_product_type_configurable_price',
                array('product' => $product)
            );
            $configurablePrice = $product->getConfigurablePrice();
            $price = $product->getPrice() + $configurableOldPrice;
            $finalPrice = $product->getFinalPrice() + $configurablePrice;
        }

        if ($product->isGrouped()) {
            $price = 0;
            $finalPrice = 0;
            $childs = Mage::getModel('catalog/product_type_grouped')->getChildrenIds($product->getId());
            $childs = $childs[Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED];
            foreach ($childs as $value) {
                // @codingStandardsIgnoreLine
                $product = Mage::getModel('catalog/product')->load($value);
                $price += $product->getPrice();
                $finalPrice += $product->getFinalPrice();
            }
            $priceIncludingTax = Mage::helper('tax')->getPrice(
                $product->setTaxPercent(null),
                $price,
                true
            );
            $finalPriceIncludingTax = Mage::helper('tax')->getPrice(
                $product->setTaxPercent(null),
                $finalPrice,
                true
            );
        }

        $priceIncludingTax = $price;
        $finalPriceIncludingTax = $finalPrice;
        if (!$priceIncludesTax) {
            $data['price-excluding-tax'] = round($finalPrice, 2);
            $priceIncludingTax = $price + $calculator->calcTaxAmount($price, $taxPercent, false);
            $finalPriceIncludingTax = $finalPrice + $calculator->calcTaxAmount($finalPrice, $taxPercent, false);
        }

        // Get prices
        $data['price-including-tax'] = round($finalPriceIncludingTax, 2);
        $data['price-before-discount'] = round($priceIncludingTax, 2);
        $discountAmount = $priceIncludingTax - $finalPriceIncludingTax;
        $data['discount-amount'] = $discountAmount > 0 ? round($discountAmount, 2) : '0';
        $data['discount-percent'] = $discountAmount > 0 ? round(($discountAmount * 100) / $priceIncludingTax,
            0) : '0';
        $data['start-date-discount'] = $product->getSpecialFromDate();
        $data['end-date-discount'] = $product->getSpecialToDate();

        // Retrieving promotions
        $dateTs = Mage::app()->getLocale()->storeTimeStamp($product->getStoreId());
        if (method_exists(Mage::getResourceModel('catalogrule/rule'), 'getRulesFromProduct')) {
            $promo = Mage::getResourceModel('catalogrule/rule')->getRulesFromProduct($dateTs, $product->getStoreId(), 1, $product->getId());
        } elseif (method_exists(Mage::getResourceModel('catalogrule/rule'), 'getRulesForProduct')) {
            $promo = Mage::getResourceModel('catalogrule/rule')->getRulesForProduct($dateTs, $product->getStoreId(), $product->getId());
        }
        if (count($promo)) {
            $promo = $promo[0];

            $from = isset($promo['from_time']) ? $promo['from_time'] : $promo['from_date'];
            $to = isset($promo['to_time']) ? $promo['to_time'] : $promo['to_date'];

            $data['start-date-discount'] = date('Y-m-d H:i:s', strtotime($from));
            $data['end-date-discount'] = null === $to ? '' : date('Y-m-d H:i:s', strtotime($to));
        }

        return $data;
    }

    /**
     * Add complex attribute to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function _addComplexAttributes(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $oystProduct->__set('url', $product->getUrlModel()->getProductUrl($product));
        $product->unsRequestPath();
        $oystProduct->__set('url', $product->getUrlInStore(array('_ignore_category' => true)));
        $oystProduct->__set('materialized', !($product->isVirtual()) ? true : false);

        $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        $oystProduct->__set('availableQuantity', (int)$stock->getQty());

        $productActive = ('1' === $product->getStatus()) ? true : false;
        $oystProduct->__set('active', $productActive);

        $oystProduct->__set('condition', 'new');

        // @TODO add verification for discount price
        $isDiscounted = false;
        $oystProduct->__set('discounted', $isDiscounted);
    }

    /**
     * Add categories to product array
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function _addCategories(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        /** @var Mage_Catalog_Model_Resource_Category_Collection $categoryCollection */
        $categoryCollection = $product->getCategoryCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('url_key');

        $oystCategory = array();

        /** @var Mage_Catalog_Model_Category $category */
        foreach ($categoryCollection as $category) {
            // Count slash to determine if it's a main category
            $isMain = substr_count($category->getPath(), '/') == 2;
            $oystCategory[] = new OystCategory($category->getId(), $category->getName(), $isMain);
        }

        if (empty($oystCategory)) {
            // Category is mandatory
            $oystCategory[] = new OystCategory('none', 'none');
        }

        $oystProduct->__set('categories', $oystCategory);
    }

    /**
     * Add picture link of product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function _addImages(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attribute = Mage::getSingleton('catalog/product')->getResource()->getAttribute('media_gallery');
        $media = Mage::getResourceSingleton('catalog/product_attribute_backend_media');
        $gallery = $media->loadGallery($product, new Varien_Object(array('attribute' => $attribute)));

        $images = array();
        foreach ($gallery as $image) {
            $images[] = $product->getMediaConfig()->getMediaUrl($image['file']);
        }

        if (empty($images)) {
            $images[] = sprintf('%s/placeholder/%s',
                Mage::getSingleton('catalog/product_media_config')->getBaseMediaUrl(),
                Mage::getStoreConfig('catalog/placeholder/image_placeholder')
            );
        }

        $oystProduct->__set('images', $images);
    }

    /**
     * Add related product of parent product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function _addRelatedProducts(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        if ($relatedProducts = $product->getRelatedProductIds()) {
            $oystProduct->__set('relatedProducts', $relatedProducts);
        }
    }

    /**
     * Add custom attributes to product information field
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     * @param array $userDefinedAttributeCode
     */
    protected function _addCustomAttributesToInformation(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attributeCodes = $this->_getProductAttributeCodeDefinedByUser($product, $this->_userDefinedAttributeCode);

        Mage::helper('oyst_oneclick')->log('$attributeCodes');
        Mage::helper('oyst_oneclick')->log($attributeCodes);

        $informations = array();

        foreach ($attributeCodes as $attributeCode) {
            $value = '';

            if (($attribute = $product->getResource()->getAttribute($attributeCode))
                && !(null === $product->getData($attributeCode))) {
                $value = $attribute->getFrontend()->getValue($product);
            }

            if (empty($value)) {
                continue;
            }
            Mage::helper('oyst_oneclick')->log('$attributeCode: ' . $attributeCode . '  -  value: ' . $value);

            $informations[$attributeCode] = $value;
        }
        $oystProduct->__set('information', $informations);
    }

    /**
     * Check if product is supported
     *
     * @param Mage_Catalog_Model_Product $product
     *
     * @return bool
     */
    public function isSupportedProduct($product)
    {
        $supported = false;

        if ($product->getIsOneclickActiveOnProduct()) {
            return $supported;
        }

        if (in_array($product->getTypeId(), $this->_supportedProductTypes)) {
            $supported = true;
        }

        return $supported;
    }

    /**
     * Count product collection
     *
     * @param array $param
     *
     * @return mixed
     */
    protected function _countProductCollection($param)
    {
        $productCollection = Mage::getModel('catalog/product')->getCollection();
        if (!empty($params['product_id_include_filter'])) {
            $productCollection->addAttributeToFilter('entity_id', array(
                'in' => $params['product_id_include_filter'],
            ));
        }

        if (!empty($params['product_id_exclude_filter'])) {
            $productCollection->addAttributeToFilter('entity_id', array(
                'nin' => $params['product_id_exclude_filter'],
            ));
        }

        $productCollection->addAttributeToFilter('type_id', array('in' => $this->_supportedProductTypes));

        return $productCollection->getSize();
    }

    /**
     * Get the shipping methods and apply cart rule
     *
     * @param $data
     *
     * @return string
     */
    public function cartEstimate($apiData)
    {
        /** @var Mage_Core_Model_Store $store */
        $store = Mage::getModel('core/store')->load($apiData['order']['context']['store_id']);

        /** @var Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder */
        $magentoQuoteBuilder = Mage::getModel('oyst_oneclick/magento_quote', $apiData);
        $magentoQuoteBuilder->buildQuote();

        // Object to format data of EndpointShipment
        $oneClickShipmentCalculation = new OneClickShipmentCalculation();

        /** @var Mage_Sales_Model_Quote_Address $address */
        $address = $magentoQuoteBuilder->getQuote()->getShippingAddress();

        $rates = $address
            ->collectShippingRates()
            ->getShippingRatesCollection();
        $isPrimarySet = false;

        $taxHelper = Mage::helper('tax');
        foreach ($rates as $rate) {
            try {
                $price = $rate->getPrice();
                if (!$taxHelper->shippingPriceIncludesTax()) {
                    $price = $taxHelper->getShippingPrice($price, true, $address);
                }
                Mage::helper('oyst_oneclick')->log(
                    sprintf('%s (%s): %s',
                        trim($this->_getConfigMappingName($rate->getCode())),
                        $rate->getCode(),
                        $price
                    )
                );

                // This mean it's disable for 1-Click
                if ("0" === ($carrierMapping = $this->_getConfigMappingDelay($rate->getCode()))) {
                    continue;
                }

                $oystPrice = new OystPrice($price, Mage::app()->getStore()->getCurrentCurrencyCode());

                $oystCarrier = new OystCarrier(
                    $rate->getCode(),
                    trim($this->_getConfigMappingName($rate->getCode())),
                    $carrierMapping
                );

                $shipment = new OneClickShipmentCatalogLess(
                    $oystPrice,
                    $this->_getConfigCarrierDelay($rate->getCode()),
                    $oystCarrier
                );

                if ($rate->getCode() === $this->_getConfig('carrier_default')) {
                    $shipment->setPrimary(true);
                    $isPrimarySet = true;
                }

                $oneClickShipmentCalculation->addShipment($shipment);
            } catch (Exception $e) {
                Mage::logException($e);
                continue;
            }

// DEV
            $item = new OneClickItem("551", 1, null, new OystPrice(100, 'EUR'), new OystPrice(132, 'EUR'));

            $oneClickShipmentCalculation->addItem($item);
        }

        if (!$isPrimarySet) {
            $oneClickShipmentCalculation->setDefaultPrimaryShipmentByType();
        }

        $magentoQuoteBuilder->getQuote()->setIsActive(false)->save();

        return $oneClickShipmentCalculation->toJson();

        $a = $oneClickShipmentCalculation->toArray();
        $response = Zend_Json::encode($a);

        return $response;
    }

    /**
     * Get config for carrier delay from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function _getConfigCarrierDelay($code)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_delay/$code");
    }

    /**
     * Get config for carrier mapping from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function _getConfigMappingDelay($code)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_mapping/$code");
    }

    /**
     * Get config for carrier name from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function _getConfigMappingName($code)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_name/$code");
    }

    /**
     *
     *
     * @return mixed
     */
    private function stockReleased($apiData)
    {
        try {
            if (!isset($apiData['products'])) {
                throw new \InvalidArgumentException(Mage::helper('oyst_oneclick')->__('Products info is missing'));
            }

            foreach ($apiData['products'] as $product) {
                $qty = $product['quantity'];

                $productId = $product['reference'];

                // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                if (false !== strpos($productId, ';')) {
                    $p = explode(';', $productId);
                    $product['reference'] = $p[0];
                    $product['variation_reference'] = $p[1];
                }

                if (isset($product['variation_reference'])) {
                    $productId = $product['variation_reference'];
                }

                /** @var Mage_CatalogInventory_Model_Stock_Item stockItem */
                $this->stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);

                if (!$this->stockItem->getId()) {
                    $this->stockItem->setProductId($productId);
                    $this->stockItem->setStockId(1);
                }

                if ($this->stockItem->getManageStock()) {
                    $this->stockItem->setQty($this->stockItem->getQty() + $qty);
                    $this->stockItem->setIsInStock((int)($qty > 0)); // Set the Product to InStock
                    // @codingStandardsIgnoreLine
                    $this->stockItem->save();
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Book stock item(s)
     *
     * @return string
     */
    private function stockBook($apiData)
    {
        try {
            if (isset($apiData['items'])) {
                foreach ($apiData['items'] as $item) {
                    $qty = $item['quantity'];
                    $productId = $item['reference'];

                    // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                    if (false  !== strpos($productId, ';')) {
                        $p = explode(';', $productId);
                        $productId['reference'] = $p[0];
                        $item['variation_reference'] = $p[1];
                    }

                    if (isset($item['variation_reference'])) {
                        $productId = $item['variation_reference'];
                    }

                    $this->stockItemToBook($productId, $qty);
                }
            } else {
                $qty = $apiData['quantity'];

                $productId = $apiData['product_reference'];

                // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                if (false  !== strpos($productId, ';')) {
                    $p = explode(';', $productId);
                    $productId = $p[0];
                    $apiData['variation_reference'] = $p[1];
                }

                if (isset($apiData['variation_reference'])) {
                    $productId = $apiData['variation_reference'];
                }

                $stockItemToBook = $this->stockItemToBook($productId, $qty);

                /** @var OneClickStock $stockBookResponse */
                $stockBookResponse = new OneClickStock($stockItemToBook, $apiData['product_reference']);

                return Zend_Json::encode($stockBookResponse->toArray());
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Book a stock unit
     *
     * @return string
     */
    public function stockItemToBook($productId, $qty)
    {
        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);

        /** @var Mage_CatalogInventory_Model_Stock_Item stockItem */
        $this->stockItem = $product->getStockItem();
        $stockItemToBook = $this->stockItem->getQty() >= $qty ? $qty : 0;

        if ($stockItemToBook) {
            $this->stockItem->setData('qty', $this->stockItem->getQty() - $stockItemToBook);
            $this->stockItem->save();
        }

        return $stockItemToBook;
    }
}
