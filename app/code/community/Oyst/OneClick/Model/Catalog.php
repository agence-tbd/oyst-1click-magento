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
    protected $supportedProductTypes = array(
        Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
        Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
        Mage_Catalog_Model_Product_Type::TYPE_GROUPED,
        //Mage_Catalog_Model_Product_Type::TYPE_BUNDLE,
        Mage_Catalog_Model_Product_Type::TYPE_VIRTUAL,
        //Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE,
    );

    /**
     * Translate Product attribute for Oyst <-> Magento
     *
     * @var array
     */
    protected $productAttrTranslate = array(
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
    protected $customAttributesCode = array('color', 'size');

    /**
     * Selected system attribute code
     *
     * @var array
     */
    protected $systemSelectedAttributesCode = array();

    /**
     * Variations attribute code
     *
     * @var array
     */
    protected $variationAttributesCode = array('price', 'final_price');

    /**
     * User defined attribute code
     *
     * @var array
     */
    protected $userDefinedAttributeCode = array();

    /**
     * @var array
     */
    protected $productsIds = null;

    /**
     * @var array
     */
    protected $productsQuantities = null;

    /**
     * @var array
     */
    protected $products = null;

    /**
     * @var int
     */
    protected $configurableProductChildId = null;

    /**
     * @var null|Mage_CatalogInventory_Model_Stock_Item
     */
    private $stockItem = null;

    /**
     * Object construct
     */
    public function __construct()
    {
        if (!$this->getConfig('enable')) {
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
    protected function getConfig($code)
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
        /** @var Oyst_OneClick_Model_Notification $notification */
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
            case 'order.shipments.get':
                $response = $this->retrieveShippingMethods($apiData);
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
                $response = '';
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
     * Return OystProduct array
     *
     * @param array $dataFormated
     *
     * @return OystProduct[]|array Array of OystProduct or array with errors
     */
    public function getOystProducts($dataFormated)
    {
        $isPreload = filter_var($dataFormated['preload'], FILTER_VALIDATE_BOOLEAN);

        $products = Zend_Json::decode($dataFormated['products']);
        $productsCount = count($products);
        $this->userDefinedAttributeCode = $this->getUserDefinedAttributeCode();
        $this->systemSelectedAttributesCode = $this->getSystemSelectedAttributeCode();

        //$this->productsIds = array_column($products, 'productId');
        // Alternative way for PHP array_column method which is available only on (PHP 5 >= 5.5.0, PHP 7)
        $this->productsIds = array_map(function ($element) {
            return $element['productId'];
        }, $products);

        $productsError = 0;
        $checkQuoteItemQty = '';
        $productsFormated = array();
        foreach ($products as $product) {
            // @codingStandardsIgnoreLine
            $currentProduct = Mage::getModel('catalog/product')->load($product['productId']);

            if ($isPreload) {
                $product['quantity'] = 1;
            }

            // Validate Qty
            if (array_key_exists('configurableProductChildId', $product)) {
                $this->configurableProductChildId = $product['configurableProductChildId'];

                /** @var Mage_Catalog_Model_Product $configurableProductChild */
                // @codingStandardsIgnoreLine
                $configurableProductChild = Mage::getModel('catalog/product')->load($this->configurableProductChildId);

                /** @var Mage_CatalogInventory_Model_Stock_Item $stock */
                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($configurableProductChild);
                $checkQuoteItemQty = $stock->checkQuoteItemQty($product['quantity'], $configurableProductChild->getQty());
            } else {
                /** @var Mage_CatalogInventory_Model_Stock_Item $stock */
                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($currentProduct);
                $checkQuoteItemQty = $stock->checkQuoteItemQty($product['quantity'], $currentProduct->getQty());
            }

            if ($checkQuoteItemQty->getData('has_error')) {
                $productsError++;
            }

            // Manage quantity error
            if ($productsCount == $productsError) {
                $message = $checkQuoteItemQty->getData('message');
                $checkQuoteItemQty->setData(
                    'message',
                    str_replace('""', '"' . $currentProduct->getName() . '"', $message)
                );

                return array(
                    'has_error' => $checkQuoteItemQty->getData('has_error'),
                    'message' => $checkQuoteItemQty->getData('message')
                );
            }

            if (!$isPreload && 0 === $product['quantity']) {
                continue;
            }

            // Make OystProduct
            $productsFormated[] = $this->format(array($currentProduct), $product['quantity']);

            // Book initial quantity
            if (!$isPreload && $this->getConfig('should_ask_stock') && 0 !== $product['quantity']) {
                if (array_key_exists('configurableProductChildId', $product)) {
                    $realPid = $this->configurableProductChildId;
                } else {
                    $realPid = $product['productId'];
                }

                $this->stockItemToBook($realPid, $product['quantity']);
            }
        }

        return $productsFormated;
    }

    /**
     * Transform Database Data to formatted array
     *
     * @param Mage_Catalog_Model_Product[] $products
     * @param int $qty
     *
     * @return OystProduct
     */
    protected function format($products, $qty = null)
    {
        foreach ($products as $product) {
            if (!$product->isConfigurable() && !is_null($this->configurableProductChildId) && $product->getId() != $this->configurableProductChildId) {
                continue;
            }

            $oystProduct = new OystProduct();

/*            if (is_array($products)) {
                // @TODO This need to be improved
                // @codingStandardsIgnoreLine
                $product = Mage::getModel('catalog/product')->load($product->getId());
            }
*/
            // Get product attributes
            $this->getAttributes($product, $this->productAttrTranslate, $oystProduct);
            if ($product->isConfigurable()) {
                $this->addVariations($product, $oystProduct);
            }

            // Add others attributes
            // Don't get price from child product
            if (in_array($product->getId(), $this->productsIds)) {
                $this->addAmount($product, $oystProduct);
            }

            $this->addComplexAttributes($product, $oystProduct);
            $this->addCategories($product, $oystProduct);
            $this->addImages($product, $oystProduct);
            $this->addRelatedProducts($product, $oystProduct);
            $this->addCustomAttributesToInformation($product, $oystProduct);

            // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
            // in release stock event
            if ($product->isConfigurable()) {
                $oystProduct->__set('reference', $product->getId() . ';' . $this->configurableProductChildId);
                $oystProduct->__set('variation_reference', $this->configurableProductChildId);
            }

            if (!is_null($qty)) {
                $oystProduct->__set('quantity', $qty);
            }
        }

        return $oystProduct;
    }

    /**
     * Return the user defined attributes code
     *
     * @return array
     */
    protected function getUserDefinedAttributeCode()
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
    protected function getSystemSelectedAttributeCode()
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
    protected function getProductAttributeCodeDefinedByUser(Mage_Catalog_Model_Product $product, $userDefinedAttributeCode)
    {
        $attributes = $product->getAttributes();
        $productAttributeCode = array();
        foreach ($attributes as $attribute) {
            $productAttributeCode[] = $attribute->getAttributeCode();
        }

        $attributeCodes = array_unique(
            array_merge(
                array_intersect($userDefinedAttributeCode, $productAttributeCode),
                $this->customAttributesCode,
                $this->systemSelectedAttributesCode
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
    protected function getAttributes(Mage_Catalog_Model_Product $product, Array $translateAttribute, OystProduct &$oystProduct)
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
    protected function addVariations(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        /** @var  $productAttributeCodeDefinedByUser */
        $productAttributeCodeDefinedByUser = $this->getProductAttributeCodeDefinedByUser($product, $this->userDefinedAttributeCode);

        $requiredAttributesCode = array_unique(
            array_merge(
                array_keys($this->productAttrTranslate),
                $this->customAttributesCode,
                $this->systemSelectedAttributesCode,
                $productAttributeCodeDefinedByUser,
                $this->variationAttributesCode
            )
        );

        $requiredAttributesIds = array();
        foreach ($requiredAttributesCode as $requiredAttributeCode) {
            $requiredAttributesIds[] = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', $requiredAttributeCode);
        }

        /** @var Mage_Catalog_Model_Product_Type_Configurable $childProducts */
        $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts($requiredAttributesIds, $product);

        $variationProductsFormated = $this->format($childProducts);

        $oystProduct->__set('variations', array($variationProductsFormated));
    }

    /**
     * Add price to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addAmount(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $prices = $this->getPrices($product);
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
    protected function getPrices(Mage_Catalog_Model_Product $product, $storeId = null)
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
                    $configurableProductChild = Mage::getModel('catalog/product')->load($this->configurableProductChildId);
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
    protected function addComplexAttributes(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
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
    protected function addCategories(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
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
    protected function addImages(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attribute = Mage::getSingleton('catalog/product')->getResource()->getAttribute('media_gallery');
        $media = Mage::getResourceSingleton('catalog/product_attribute_backend_media');
        $gallery = $media->loadGallery($product, new Varien_Object(array('attribute' => $attribute)));

        $images = array();
        foreach ($gallery as $image) {
            $images[] = $product->getMediaConfig()->getMediaUrl($image['file']);
        }

        $catalogPlaceholderImage = Mage::getStoreConfig('catalog/placeholder/image_placeholder');
        if (empty($images) && !empty($catalogPlaceholderImage)) {
            $images[] = sprintf('%s/placeholder/%s',
                Mage::getSingleton('catalog/product_media_config')->getBaseMediaUrl(),
                Mage::getStoreConfig('catalog/placeholder/image_placeholder')
            );
        }

        if (!empty($images)) {
            $oystProduct->__set('images', $images);
        }
    }

    /**
     * Add related product of parent product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addRelatedProducts(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
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
    protected function addCustomAttributesToInformation(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attributeCodes = $this->getProductAttributeCodeDefinedByUser($product, $this->userDefinedAttributeCode);

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

        if (in_array($product->getTypeId(), $this->supportedProductTypes)) {
            $supported = true;
        }

        return $supported;
    }

    /**
     * Get the shipping methods
     *
     * @param $data
     *
     * @return string
     */
    public function retrieveShippingMethods($apiData)
    {
        // @TODO EndpointShipment: remove these bad hack for currency
        $apiData['order_amount']['currency'] = 'EUR';
        $apiData['created_at'] = Mage::getModel('core/date')->gmtDate();
        $apiData['id'] = null;

        /** @var Mage_Core_Model_Store $store */
        $store = Mage::getModel('core/store')->load($apiData['context']['store_id']);

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
                        trim($this->getConfigMappingName($rate->getCode())),
                        $rate->getCode(),
                        $price
                    )
                );

                // This mean it's disable for 1-Click
                if ("0" === ($carrierMapping = $this->getConfigMappingDelay($rate->getCode()))) {
                    continue;
                }

                $oystPrice = new OystPrice($price, Mage::app()->getStore()->getCurrentCurrencyCode());

                $oystCarrier = new OystCarrier(
                    $rate->getCode(),
                    trim($this->getConfigMappingName($rate->getCode())),
                    $carrierMapping
                );

                $shipment = new OneClickShipmentCatalogLess(
                    $oystPrice,
                    $this->getConfigCarrierDelay($rate->getCode()),
                    $oystCarrier
                );

                if ($rate->getCode() === $this->getConfig('carrier_default')) {
                    $shipment->setPrimary(true);
                    $isPrimarySet = true;
                }

                $oneClickShipmentCalculation->addShipment($shipment);
            } catch (Exception $e) {
                Mage::logException($e);
                continue;
            }
        }

        if (!$isPrimarySet) {
            $oneClickShipmentCalculation->setDefaultPrimaryShipmentByType();
        }

        $magentoQuoteBuilder->getQuote()->setIsActive(false)->save();

        return $oneClickShipmentCalculation->toJson();
    }

    /**
     * Get config for carrier delay from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getConfigCarrierDelay($code)
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
    protected function getConfigMappingDelay($code)
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
    protected function getConfigMappingName($code)
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

                if (0 === $qty) {
                    continue;
                }

                $productId = $product['reference'];

                // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                // in release stock event
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
                    // in release stock event
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
                // in release stock event
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
