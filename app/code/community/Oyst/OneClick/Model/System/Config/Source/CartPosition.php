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
 * Cart Position
 */
class Oyst_OneClick_Model_System_Config_Source_CartPosition
{
    /**
     * Button position from cart.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 'disabled',
                'label' => Mage::helper('oyst_oneclick')->__('Disabled'),
            ),
            array(
                'value' => 'top',
                'label' => Mage::helper('oyst_oneclick')->__('Top'),
            ),
            array(
                'value' => 'bottom',
                'label' => Mage::helper('oyst_oneclick')->__('Bottom'),
            ),
        );
    }
}
