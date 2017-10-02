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
 * Adminhtml System Config Form Field Config Block
 */
class Oyst_OneClick_Block_Adminhtml_System_Config_Form_Field_Config extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $trad = array(
            'postShipmentsSuccess' => $this->__('Delivery mode synchronization was successful.'),
            'postShipmentsError' => $this->__('The synchronization of delivery modes failed.'),
            'saveBeforePost' => $this->__('Save the configuration before sending.'),
            'jsonBadFormat' => $this->__('Bad Json formatting. Use JSON Validator to fix it.'),
        );

        $shipmentsConfig = Mage::getStoreConfig("oyst/oneclick/shipments_config");

        $output = '<style type="text/css">
                #oyst_oneclick_oneclick_shipments_config { font-family: courier, courier new, serif; height: 30em; }
            </style>
            <script type="text/javascript">
            //<![CDATA[
                function showMessage(txt, type) {
                    var html = \'<ul class="messages"><li class="\' + type + \'-msg"><ul><li>\' + txt + \'</li></ul></li></ul>\';
                    $("messages").update(html);
                    var url = location.href;
                    var n = url.indexOf("#");
                    url = url.substring(0, n != -1 ? n : url.length);
                    location.replace(url + "#html-body");
                }
                postShipments = (function() {
                    // one way to clean data to be able to compare
                    try {
                        dataFromTextarea = Object.toJSON(JSON.parse($("oyst_oneclick_oneclick_shipments_config").value));
                        dataFromConfig = Object.toJSON(JSON.parse(\'' . str_replace(array("\r", "\n"), '', $shipmentsConfig) . '\'));
                    } catch (e) {
                        showMessage("' . $trad['jsonBadFormat'] . '", "error");
                    }

                    if (dataFromTextarea !== dataFromConfig) {
                        Element.show("loading-mask");
                        showMessage("' . $trad['saveBeforePost'] . '", "error");
                        Element.hide("loading-mask");
                        return;
                    }

                    var url = "' . $this->getUrl('adminhtml/oneclick_catalog/postShipments') . '";
                    var myAjax = new Ajax.Request(url, {
                        method: "post",
                        onComplete: function (xhr) {
                            Element.hide("loading-mask");
                            if (200 === xhr.status && "" === xhr.responseText) {
                                showMessage("' . $trad['postShipmentsSuccess'] . '", "success");
                            } else {
                                showMessage("' . $trad['postShipmentsError'] . '", "error");
                            }
                        }
                    });
                });
            //]]>
            </script>';

        $buttonBlock = $this->getLayout()->createBlock('adminhtml/widget_button');
        $data = array(
            'label' => $this->__('Send configuration'),
            'onclick' => 'postShipments()',
            'class' => '',
            'style' => 'float: right;',
        );
        $postShipmentsButtonBlock = $buttonBlock->setData($data)->toHtml();

        return <<<EOD
{$element->getElementHtml()}<br/>
<div style="margin-bottom: 1px;">
    {$postShipmentsButtonBlock}
</div>
<a href="https://www.json.fr/" target="_blank">{$this->__('JSON Validator')}</a>
{$output}
EOD;
    }
}