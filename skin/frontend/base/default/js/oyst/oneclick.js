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
 * OneClick Customs JS
 */
"use strict";

/**
 * Main function to init 1-Click
 *
 * @param {String} productTypeId Product Type Id
 * @param {String} oneClickUrl  Backend conf oneclick payment url
 * @param {Boolean} isProductAddtocartFormValidate  Backend conf to validate or not form data
 */
function oystOneClick(productTypeId, oneClickUrl, isProductAddtocartFormValidate, addtocartButtonsClass) {
    smartButtonData(addtocartButtonsClass);

    window.__OYST__ = window.__OYST__ || {};
    window.__OYST__.getOneClickURL = function (cb, opts) {
        opts = opts || {};

        ready(function () {
            var form = new FormData();
            form.append("preload", opts.preload);

            var products = [];
            var productId, quantity, configurableProductChildId, superGroupEl, productsEl;
            switch (productTypeId) {
                case "simple":
                case "virtual":
                    // [Hook] Custom function allow anyone to use custom function to retrieve product id
                    productId = "function" === typeof customGetProductId ?
                        customGetProductId() :
                        document.forms.product_addtocart_form.elements.product.value;

                    // [Hook] Custom function allow anyone to use custom function to retrieve quantity
                    quantity = "function" === typeof customGetQuantity ?
                        customGetQuantity :
                        document.forms.product_addtocart_form.elements.qty.value;

                    products.push({
                        productId: Number(productId),
                        quantity: Number(quantity)
                    });
                    break;
                case "configurable":
                    // [Hook] Custom function allow anyone to use custom function to retrieve product id
                    productId = "function" === typeof customGetProductId ?
                        customGetProductId :
                        document.forms.product_addtocart_form.elements.product.value;

                    // [Hook] Custom function allow anyone to use custom function to retrieve quantity
                    quantity = "function" === typeof customGetQuantity ?
                        customGetQuantity :
                        document.forms.product_addtocart_form.elements.qty.value;

                    // [Hook] Custom function allow anyone to retrieve configurable product child id
                    configurableProductChildId = "function" === typeof customGetConfigurableProductChildId ?
                        customGetConfigurableProductChildId() :
                        getConfigurableProductChildId();

                    products.push({
                        productId: Number(productId),
                        quantity: Number(quantity),
                        configurableProductChildId: configurableProductChildId
                    });
                    break;
                case "grouped":
                    // [Hook] Custom function allow anyone to retrieve all super group
                    productsEl = "function" === typeof customGetSuperGroup ?
                        customGetSuperGroup():
                        document.querySelectorAll("*[id^='super_group_']");

                    productsEl.forEach(function (el, i) {
                        products.push({
                            productId: Number(el.name.match(/\[(.*?)\]/)[1]),
                            quantity: Number(el.value)
                        });
                    });
                    break;
                default:
                    return false;
            }

            form.append("products", JSON.stringify(products));

            var settings = {
                async: true,
                cacheControl: "no-cache",
                data: form,
                method: "POST",
                mimeType: "multipart/form-data",
                url: oneClickUrl
            };

            var xhr = new XMLHttpRequest();
            xhr.open(settings.method, settings.url, settings.async);
            xhr.setRequestHeader("cache-control", settings.cacheControl);
            xhr.onload = function () {
                if (200 === xhr.status) {
                    var data = JSON.parse(xhr.responseText);
                    var isErrorsInForm = null;
                    var messageProductViewElem = document.getElementById("messages_product_view");
                    // If not the preload of button run form validation to know if they are errors
                    if (!opts.preload) {
                        if (isProductAddtocartFormValidate) {
                            // @TODO: Add backend conf to change form name
                            isErrorsInForm = !isOystOneClickButtonFormValid("product_addtocart_form");
                        }

                        if ("function" === typeof isCustomProductAddtocartFormValid) {
                            // [Hook] This function allow anyone to use custom form validator, return as to be a boolean
                            isErrorsInForm = !isCustomProductAddtocartFormValid();
                        }

                        if (data.has_error && data.message) {
                            isErrorsInForm = true;
                            if ("function" === typeof customMessagesProductView) {
                                // This function allow anyone to display custom message
                                customMessagesProductView(data);
                            } else {
                                if (messageProductViewElem) {
                                    messageProductViewElem.parentNode.removeChild(messageProductViewElem);
                                }

                                appendNotificationMsg(data.message, "error-msg", "product-view");
                            }
                        }
                    }
                    cb(isErrorsInForm, data.url);
                    if (messageProductViewElem) {
                        messageProductViewElem.parentNode.removeChild(messageProductViewElem);
                    }
                }
            };
            xhr.send(form);
        });
    };
}

/**
 * Wait for DOM ready
 *
 * @param fn
 */
function ready(fn) {
    if (document.attachEvent ? "complete" === document.readyState : "loading" !== document.readyState) {
        fn();
    } else {
        document.addEventListener("DOMContentLoaded", fn);
    }
}

/**
 * Return the simple product id from a configurable product
 *
 * @returns {null}
 */
function getConfigurableProductChildId() {
    if ("undefined" === typeof(spConfig)) {
        return null;
    }

    var productCandidates = [];

    spConfig.settings.forEach(function (select, selectIndex) {
        var attributeId = select.id.replace("attribute", "");
        var selectedValue = select.options[select.selectedIndex].value;

        spConfig.config.attributes[attributeId].options.forEach(function (option, optionIndex) {
            if (option.id == selectedValue) {
                var optionProducts = option.products;

                if (0 === productCandidates.length) {
                    productCandidates = optionProducts;
                } else {
                    var productIntersection = [];
                    optionProducts.forEach(function (productId, productIndex) {
                        if (-1 < productCandidates.indexOf(productId)) {
                            productIntersection.push(productId);
                        }
                    });
                    productCandidates = productIntersection;
                }
            }
        });
    });

    return (1 === productCandidates.length) ? Number(productCandidates[0]) : null;
}

/**
 * Test if the form is valid
 *
 * return bool
 */
function isOystOneClickButtonFormValid(formName) {
    var form = new VarienForm(formName);

    return form.validator.validate();
}

/**
 * Notification based on Magento notification system
 *
 * @param message
 * @param notificationType
 */
function appendNotificationMsg(message, notificationType, target) {
    var child = document.createElement("span");
    child.innerHTML = message;

    var parent = document.createElement("li");
    parent.append(child);

    child = document.createElement("ul");
    child.append(parent);

    parent = document.createElement("li");
    parent.className = notificationType;
    parent.append(child);

    child = document.createElement("ul");
    child.className = "messages";
    child.append(parent);

    parent = document.createElement("div");
    parent.id = "messages_product_view";
    parent.append(child);

    document.getElementsByClassName(target)[0].before(parent);
}

/**
 * Move dom element as first child
 *
 * @param {HTMLElement} parentElement
 * @param {HTMLElement} newFirstChildElement
 */
function prependChild(parentElement, newFirstChildElement) {
    parentElement.insertBefore(newFirstChildElement, parentElement.firstChild);
}

/**
 * Move OneClick button in first position in add to cart buttons list
 */
function oneClickButtonPickToFirstAddToCartButtons(addtocartButtonsClass) {
    ready(function () {
        try {
            var addToCartButtons = document.getElementsByClassName(addtocartButtonsClass)[0];
            var oystOneClickButton = document.getElementById("oyst-1click-button-wrapper");
            prependChild(addToCartButtons, oystOneClickButton);
        } catch (e) {
            console.error(addtocartButtonsClass + " class not found.");
        }
    });
}

/**
 * Apply add to cart size on OneClick button
 */
function smartButtonData(addtocartButtonsClass) {
    if (document.getElementById("oyst-1click-button").getAttribute("data-smart")) {
        try {
            var addtocartButtons = document.getElementsByClassName(addtocartButtonsClass);
            var addtocartButton = addtocartButtons[0].getElementsByClassName("button btn-cart");

            document.getElementById("oyst-1click-button").setAttribute("data-height", addtocartButton[0].getHeight() + "px");
            document.getElementById("oyst-1click-button").setAttribute("data-width", addtocartButton[0].getWidth() + "px");
        } catch (e) {
            console.error(addtocartButtonsClass + " class not found.");
        }
    }
}
