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
 * Notifications Controller
 */
class Oyst_OneClick_NotificationsController extends Mage_Core_Controller_Front_Action
{
    /**
     * Get notification for order
     *
     * @return void
     */
    public function indexAction()
    {
        $event = $this->getRequest()->getPost('event');
        $data = $this->getRequest()->getPost('data');

        // @codingStandardsIgnoreLine
        $post = (array)Zend_Json::decode(str_replace("\n", '', file_get_contents('php://input')));

        // Set the type and data from notification url
        if (empty($event) && empty($data) && !empty($post)) {
            if (array_key_exists('event', $post)) {
                $event = $post['event'];
            }

            if (array_key_exists('data', $post)) {
                $data = (array)$post['data'];
            }

            if (array_key_exists('notification', $post)) {
                $event = 'payment';
                $data = (array)$post['notification'];
                $data['order_increment_id'] = Mage::app()->getRequest()->getParam('order_increment_id');
            }
        }

        if (empty($post) || empty($data) || empty($event)) {
            return $this->badRequest('No event or data');
        }

        switch ($event) {
            // OneClick
            case 'order.v2.new':
                $modelName = 'oyst_oneclick/order';
                break;
            // OneClick
            case 'order.cart.estimate':
            case 'order.stock.book':
            case 'order.stock.released':
                $modelName = 'oyst_oneclick/catalog';
                break;
            // FreePay
            case 'notification.newOrder':
                $modelName = 'oyst_oneclick/order_data';
                break;
            // FreePay
            case 'payment':
                $modelName = 'oyst_oneclick/payment_data';
                break;
            default:
                return $this->badRequest('Event name ' . $event . ' is not allow.');
                break;
        }

        try {
            /** @var Oyst_OneClick_Model_Catalog|Oyst_OneClick_Model_Order $model */
            $model = Mage::getModel($modelName);
        } catch (\Exception $e) {
            return $this->badRequest('Model name ' . $modelName . ' is missing. ' . $e->getMessage());
        }

        /** @var Oyst_OneClick_Model_Catalog|Oyst_OneClick_Model_Order $model */
        $response = $model->processNotification($event, $data);

        if ('cgi-fcgi' === php_sapi_name()) {
            $this->getResponse()->setHeader('Content-type', 'application/json');
        }
        $this->getResponse()->setBody($response);
    }

    /**
     * @param string $message
     */
    public function badRequest($message = null)
    {
        if (isset($message)) {
            $message = ': ' . (string)$message;
        }

        $this->getResponse()
            ->clearHeaders()
            ->setHeader('HTTP/1.1', '400 Bad Request')
            ->setBody('400 Bad Request' . $message);
    }
}
