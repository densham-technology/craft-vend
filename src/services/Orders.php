<?php
/**
 * Vend plugin for Craft Commerce
 *
 * Connect your Craft Commerce store to Vend POS.
 *
 * @link      https://angell.io
 * @copyright Copyright (c) 2019 Angell & Co
 */

namespace angellco\vend\services;

use angellco\vend\models\Settings;
use angellco\vend\Vend;
use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\Json;
use DateTimeInterface;
use Exception;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;

/**
 * Orders service.
 *
 * @author    Angell & Co
 * @package   Vend
 * @since     2.0.0
 */
class Orders extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Sends the order to Vend.
     *
     * @param int $orderId
     *
     * @return bool|mixed
     * @throws InvalidConfigException
     * @throws Throwable
     * @throws \yii\base\Exception
     */
    public function registerSale(int $orderId) {
        // Get the order
        $order = CommercePlugin::getInstance()->getOrders()->getOrderById($orderId);
        if (!$order) {
            return false;
        }

        // Bail if incomplete
        if (!$order->isCompleted) {
            return false;
        }

        // If we already have an order ID, then also bail
        if (!empty($order->vendOrderId)) {
            return true;
        }

        // Cache the line items
        /** @var LineItem[] $lineItems */
        $lineItems = $order->getLineItems();

        // Bail if we don’t have any line items for some reason
        if (!$lineItems) {
            return false;
        }

        // Validate the line items - we won’t send any orders that don’t wholly
        // contain Variants with product IDs stored on them.
        $lineItemsValid = true;
        foreach ($lineItems as $lineItem) {
            $purchasable = $lineItem->getPurchasable();
            if (!$purchasable) {
                $lineItemsValid = false;
                break;
            }

            // First check the purchasable is a Variant
            if (!is_a($purchasable, Variant::class)) {
                $lineItemsValid = false;
                break;
            }

            // Secondly, check we have a product ID on the Variant
            /** @var Variant $purchasable */
            if (!$purchasable->vendProductId) {
                $lineItemsValid = false;
                break;
            }
        }

        // Bail before we go any further if the line items are invalid
        if (!$lineItemsValid) {
            return false;
        }

        // Prep the API variables
        $vendApi = Vend::$plugin->api;
        $vendCustomerId = null;
        /** @var Settings $settings */
        $settings = Vend::$plugin->getSettings();

        // Get the bits we need from the order
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        $customerUser = $order->getUser();
        $email = $order->getEmail();

        // Store Vend customer ID if there is one
        if ($customerUser && $customerUser->vendCustomerId) {
            $vendCustomerId = $customerUser->vendCustomerId;
        }

        /**
         * First, sort out the customer
         */
        try {

            // Make the Vend customer object
            $vendCustomerObject = null;
            if ($email && $billingAddress && $shippingAddress) {

                // Make the customer
                $vendCustomerObject = [
                    'customer_group_id' => $settings->vend_customerGroupId,
                    'email' => $email,
                    'first_name' => $billingAddress->firstName,
                    'last_name' => $billingAddress->lastName,
                    'phone' => $billingAddress->phone,
                    'company_name' => $billingAddress->businessName,

                    'physical_address_1' => $billingAddress->address1,
                    'physical_address_2' => $billingAddress->address2,
                    'physical_suburb' => $billingAddress->address3,
                    'physical_city' => $billingAddress->city,
                    'physical_postcode' => $billingAddress->zipCode,
                    'physical_state' => $billingAddress->getStateText(),
                    'physical_country_id' => $billingAddress->getCountry()->iso,

                    'postal_address_1' => $shippingAddress->address1,
                    'postal_address_2' => $shippingAddress->address2,
                    'postal_suburb' => $shippingAddress->address3,
                    'postal_city' => $shippingAddress->city,
                    'postal_postcode' => $shippingAddress->zipCode,
                    'postal_state' => $shippingAddress->getStateText(),
                    'postal_country_id' => $shippingAddress->getCountry()->iso
                ];

                // If there is currently no customer in Vend, then make a new one
                if (!$vendCustomerId) {
                    $customerResult = $vendApi->postRequest('2.0/customers', Json::encode($vendCustomerObject), [
                        'Content-Type' => 'application/json',
                    ]);

                    Craft::info(
                        'New customer created.',
                        __METHOD__
                    );

                    // Save the ID back onto our Craft User
                    $vendCustomerId = $customerResult['data']['id'];
                    if ($customerUser) {
                        $customerUser->setFieldValue('vendCustomerId', $vendCustomerId);
                        Craft::$app->getElements()->saveElement($customerUser);
                    }
                } else {
                    // There is a customer, but we could update it couldn’t we now
                    $vendApi->putRequest("2.0/customers/{$vendCustomerId}", Json::encode($vendCustomerObject), [
                        'Content-Type' => 'application/json',
                    ]);

                    Craft::info(
                        'Customer updated.',
                        __METHOD__
                    );
                }
            }

        } catch (Exception $e) {
            Craft::error(
                'Error creating customer for order: '.$orderId.' - '.$e->getMessage(),
                __METHOD__
            );
            Vend::$plugin->parkedSales->createFromOrder($order, $e);
        }


        /**
         * Second, make the data we need to register a sale
         */

        // Prep the basic minimum we need to register a sale
        $data = [
            'source_id' => $order->number,
            'register_id' => $settings->vend_registerId,
            'customer_id' => $vendCustomerId,
            'user_id' => $settings->vend_userId,
            'status' => 'CLOSED',
            'sale_date' => $order->dateOrdered->format(DateTimeInterface::RFC3339),
            'register_sale_products' => [],
            'register_sale_payments' => [
                [
                    'retailer_payment_type_id' => $settings->vend_retailerPaymentTypeId,
                    'payment_date' => $order->dateOrdered->format(DateTimeInterface::RFC3339),
                    'amount' => $order->getTotalPaid()
                ]
            ]
        ];

        // Process the line items
        $totalLineItemsDiscount = 0;
        foreach ($lineItems as $lineItem) {

            /** @var Variant $variant */
            $variant = $lineItem->getPurchasable();

            // Work out the sales tax ID
            $taxCategory = $lineItem->getTaxCategory();
            if (!$taxCategory || !isset($settings->taxMap[$taxCategory->id])) {
                continue;
            }
            $salesTaxId = $settings->taxMap[$taxCategory->id];

            // Find the amount of tax for one item
            $taxAmount = bcdiv($lineItem->getTaxIncluded(), $lineItem->qty, 5);

            // Get the item price without tax
            $itemPriceWithoutTax = bcsub($lineItem->salePrice, $taxAmount, 5);

            // Re-calculate price if line item discount is present - tax has already been taken into account but salePrice
            // still shows the pre-discounted price per item so we have to fiddle
            $perItemDiscount = null;
            if ($lineItem->getDiscount()) {
                // Get the per item discount amount - this will be negative, because getDiscount() returns a negative number
                $perItemDiscount = bcdiv($lineItem->getDiscount(), $lineItem->qty, 5);

                // Add it to our tracker, because its negative we just minus it here so we end up with a positive number
                $totalLineItemsDiscount -= $lineItem->getDiscount();

                // We can get the discounted item price (with tax) by adding the discount to the sale price
                $itemPriceWithTax = bcadd($lineItem->salePrice, $perItemDiscount, 5);

                // Now we can calculate the discounted item price without tax again, which is what Vend wants
                $itemPriceWithoutTax = bcsub($itemPriceWithTax, $taxAmount, 5);
            }

            // Prep the main product data array
            $productData = [
                'product_id' => $variant->vendProductId,
                'quantity' => $lineItem->qty,
                // Unit price, tax exclusive
                'price' => $itemPriceWithoutTax,
                // The amount of tax in the unit price - this will be already discounted if need be
                'tax' => $taxAmount,
                // The applicable Sales Tax ID
                'tax_id' => $salesTaxId
            ];

            // Add the discount for this line item if there is one
            if ($perItemDiscount) {
                // Send the discount as a positive number per item
                $productData['discount'] = abs($perItemDiscount);
                $productData['price_set'] = 1;
            }

            // Add the no†e if there is one
            if (!empty($lineItem->note)) {
                $productData['attributes'][] = [
                    'name' => 'line_note',
                    'value' => $lineItem->note
                ];
            }

            // Finally tack the product onto our main data stack
            $data['register_sale_products'][] = $productData;

        }

        // Work out if we need free shipping for the whole order because of a discount
        $orderLevelFreeShipping = false;
        foreach ($order->getAdjustmentsByType('discount') as $discountAdjustment) {
            $snapshot = $discountAdjustment->getSourceSnapshot();
            if (isset($snapshot['hasFreeShippingForOrder']) && $snapshot['hasFreeShippingForOrder'] === '1') {
                $orderLevelFreeShipping = true;
            }

        }

        // Process the active shipping rule if there is no order level free shipping applied
        if (!$orderLevelFreeShipping) {
            $shippingMethod = $order->getShippingMethod();
            if ($shippingMethod) {
                $shippingRule = $shippingMethod->getMatchingShippingRule($order);
                if ($shippingRule && isset($settings->shippingMap['rules'][$shippingRule->id])) {

                    $ruleSettings = $settings->shippingMap['rules'][$shippingRule->id];

                    $data['register_sale_products'][] = [
                        'product_id' => $ruleSettings['productId'],
                        'quantity' => 1,
                        'price' => $ruleSettings['productPrice']['excludingTax'],
                        'tax' => bcsub($ruleSettings['productPrice']['includingTax'], $ruleSettings['productPrice']['excludingTax'], 5),
                        'tax_id' => $ruleSettings['taxId']
                    ];
                }
            }
        }

        // Process order level discount adjustments - this does not include line item discounts
        $totalDiscount = abs($order->getTotalDiscount());
        $orderDiscount = $totalDiscount - $totalLineItemsDiscount;
        if ($orderDiscount > 0) {

            // Process the special discount product tax rate
            $orderDiscountTaxAmount = bcmul($orderDiscount, $settings->vend_discountTaxRate, 5);
            $orderDiscountWithoutTax = bcsub($orderDiscount, $orderDiscountTaxAmount, 5);

            $data['register_sale_products'][] = [
                'product_id' => $settings->vend_discountProductId,
                'quantity' => -1,
                'price' => $orderDiscountWithoutTax,
                'price_set' => 1,
                'tax' => $orderDiscountTaxAmount,
                'tax_id' => $settings->vend_discountTaxId
            ];
        }

        /**
         * Finally, send the sale to Vend
         */
        try {
            $response = $vendApi->postRequest('register_sales', Json::encode($data));

            if (isset($response['status']) && $response['status'] === 'error') {
                Craft::error(
                    'Error registering sale with Vend for order: '.$orderId.' - '.$response['details'],
                    __METHOD__
                );
                Vend::$plugin->parkedSales->createFromOrder($order);
                return false;
            }

            Craft::info(
                'Sale registered with Vend.',
                __METHOD__
            );

            // Update our copy of the order with the Vend ID
            if (isset($response['register_sale']['id'])) {
                $order->setFieldValue('vendOrderId', $response['register_sale']['id']);
                Craft::$app->getElements()->saveElement($order, false);
            }

            return $response;
        } catch (Exception $e) {
            Craft::error(
                'Error registering sale with Vend for order: '.$orderId.' - '.$e->getMessage(),
                __METHOD__
            );
            Vend::$plugin->parkedSales->createFromOrder($order, $e);
        }

        return false;
    }
}
