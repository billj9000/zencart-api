<?php
/*
 * ERPNext Plugin for Zen Cart
 * 
 * Version 1.0
 *
 * Copyright (c) 2022 SAABits Ltd
 *
 *
 * Released under the GNU General Public License v2
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; under version 2 of the License
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA 02110-1301, USA.
 */
?>
<?php
require('includes/application_top.php');

header('Content-Type: application/json');

$VERSION = "1.0.0";

define('ZENCART_PRODUCT_TAX_CLASS_ID',1); // sets the record id for the default sales tax to use

/**
 * Authenticate the connection.
 */
function checkAccess($db = null)
{
    $user = $_GET['user'];
    $pass = $_GET['pass'];
    // Login with API Key

    if (isset($user) && isset($pass) && strlen($user) > 0 && strlen($pass) > 0) {
        $sql = "SELECT admin_id, admin_pass FROM " . TABLE_ADMIN . " WHERE admin_name = '" . $user . "'";
        $result = $db->Execute($sql);
        //validate the password with the zencart encrypted password
        if (!zen_validate_password($pass, $result->fields['admin_pass'])) {
            header('HTTP/1.0 401 Unauthorized');
            $out = array('message' => 'Unauthorized1');
            echo json_encode($out);
            exit;
        }
    } else {
        header('HTTP/1.0 401 Unauthorized');
        $out = array('message' => 'Unauthorized2');
        echo json_encode($out);
        exit;
    }
}


/**
 * Get the payment reference
 */
function getPaymentRef($db = null, $oID, $module)
{
    // There is no consistent API for this in Zen Cart so we have to treat each
    // payment method individually and if we install a new payment module we need
    // to add a new handler for it here
    $ref = '';

    if (!defined('TABLE_SQUARE_PAYMENTS')) define('TABLE_SQUARE_PAYMENTS', DB_PREFIX . 'square_payments');

    switch( $module )
    {
        case 'paypal':
        case 'paypalwpp':
            $query = 'SELECT txn_id from ' . TABLE_PAYPAL . ' where order_id = ' . $oID;
            $result = $db->Execute( $query );
            if( $result->fields['txn_id'] )
                $ref = $result->fields['txn_id'];
            break;

        case 'square':
            $query = 'SELECT sq_order from ' . TABLE_SQUARE_PAYMENTS . ' where order_id = ' . $oID;
            $result = $db->Execute( $query );
            if( $result->fields['sq_order'] )
                $ref = $result->fields['sq_order'];
            break;
    }

    return $ref;
}


/**
 * List orders
 */
function getOrders($db = null, $last_order)
{
    if( $last_order < 0 )
        return;

    // Get orders from database
    $sql = "SELECT * FROM " . TABLE_ORDERS . " WHERE orders_id >" . $last_order . ";";
    $result = $db->Execute($sql);

    $orders = array();
    if ($result->fields['orders_id']) {

        while (!$result->EOF) {
            $order = array();

            // order products
            $products_query = "SELECT * FROM " . TABLE_ORDERS_PRODUCTS . " WHERE orders_id = '" . $result->fields['orders_id'] . "'";
            $products_result = $db->Execute($products_query);


            $orders_id = $result->fields['orders_id'];
            $shipping_query = "SELECT * FROM " . TABLE_ORDERS_TOTAL . " WHERE orders_id = '$orders_id' and class = 'ot_shipping'";
            $shipping_result = $db->Execute($shipping_query);

            $orders_status = $db->Execute("select orders_status_id, orders_status_name
	                                 from " . TABLE_ORDERS_STATUS . "
	                                 where language_id = '1' and orders_status_id = '" . $result->fields['orders_status'] . "'");

            if ($orders_status->fields['orders_status_name']) {
                $order_status = $orders_status->fields['orders_status_name'];
                $order_status_id = $orders_status->fields['orders_status_id'];
            } else {
                $order_status = 'unknown';
                $order_status_id = '';
            }

            if ($order_status_id) {

                $orders_history_comments = $db->Execute("select comments
	                                      from " . TABLE_ORDERS_STATUS_HISTORY . "
	                                      where orders_id = '" . zen_db_input($result->fields['orders_id']) . "' and (comments is not null or comments != '')
	                                    order by date_added LIMIT 1");

                $shipping_comments = $orders_history_comments->fields['comments'];
            } else {
                $shipping_comments = '';
            }


            //order details
            $order["orderId"] = $result->fields['orders_id'];
            $order["orderDate"] =  $result->fields['date_purchased'];
            $order["status"] =  $order_status;
            $order["lastModified"] =  $result->fields['last_modified'];
            $order["paymentMethod"] =  $result->fields['payment_method'];
            $order["paymentModule"] = $result->fields['payment_module_code'];
            $order["paymentRef"] = getPaymentRef( $db, $order["orderId"], $order["paymentModule"] );
            $order["shippingMethod"] =  $result->fields['shipping_method'];
            $order["shippingModule"] =  $result->fields['shipping_module_code'];
            $order["currency"] =  $result->fields['currency'];
            $order["conversion_rate"] =  $result->fields['currency_value'];
            $order["totalAmount"] =  0.0 + $result->fields['order_total'];
            $order["taxAmount"] =  0.0 + $result->fields['order_tax'];
            $order["shippingAmount"] =  0.0 + $shipping_result->fields['value'];
            $order["comments"] =   $shipping_comments;
            $order["customerId"] =  $result->fields['customers_id'];
            $order["email"] =  $result->fields['customers_email_address'];
            $order["phone"] =  $result->fields['customers_telephone'];

            // Customer details
            $customerAddress = array();

            $customerAddress["name"] = $result->fields['customers_name'];
            $customerAddress["company"] = $result->fields['customers_company'];
            $customerAddress["addr1"] =  $result->fields['customers_street_address'];
            $customerAddress["addr2"] = $result->fields['customers_suburb'];
            $customerAddress["city"] =  $result->fields['customers_city'];
            $customerAddress["postalCode"] = $result->fields['customers_postcode'];
            $customerAddress["state"] = $result->fields['customers_state'];
            $customerAddress["country"] = $result->fields['customers_country'];

            $order["customerAddress"] = $customerAddress;


            // Billing details
            $billingAddress = array();

            $billingAddress["name"] = $result->fields['billing_name'];
            $billingAddress["company"] = $result->fields['billing_company'];
            $billingAddress["addr1"] =  $result->fields['billing_street_address'];
            $billingAddress["addr2"] = $result->fields['billing_suburb'];
            $billingAddress["city"] =  $result->fields['billing_city'];
            $billingAddress["postalCode"] = $result->fields['billing_postcode'];
            $billingAddress["state"] = $result->fields['billing_state'];
            $billingAddress["country"] = $result->fields['billing_country'];

            $order["billingAddress"] = $billingAddress;


            // Shipping details
            $shippingAddress = array();

            $shippingAddress["name"] = $result->fields['delivery_name'];
            $shippingAddress["company"] = $result->fields['delivery_company'];
            $shippingAddress["addr1"] =  $result->fields['delivery_street_address'];
            $shippingAddress["addr2"] = $result->fields['delivery_suburb'];
            $shippingAddress["city"] =  $result->fields['delivery_city'];
            $shippingAddress["postalCode"] = $result->fields['delivery_postcode'];
            $shippingAddress["state"] = $result->fields['delivery_state'];
            $shippingAddress["country"] = $result->fields['delivery_country'];

            $order["shippingAddress"] = $shippingAddress;


            $orderItems = array();
            $vat_rate=0;
            while (!$products_result->EOF) {

                $image_query = "SELECT products_image, products_weight FROM " . TABLE_PRODUCTS . " WHERE products_id = '" . $products_result->fields['products_id'] . "'";
                $image_result = $db->Execute($image_query);

                $item = array();
                $item["productID"] = $products_result->fields['products_id'];
                $item["sku"] = $products_result->fields['products_model'];
                $item["name"] = $products_result->fields['products_name'];
                $item["image"] = HTTP_SERVER . DIR_WS_CATALOG . DIR_WS_IMAGES . $image_result->fields['products_image'];
                $item["weight"] = $image_result->fields['products_weight'];
                $item["unitPrice"] = round($products_result->fields['final_price'], 2);
                $item["quantity"] = $products_result->fields['products_quantity'];
                $item["tax"] = $products_result->fields['products_tax'];
                if( $item['tax'] > $vat_rate )
                    $vat_rate = $item['tax'];
      
                $options_query = "SELECT orders_products_attributes_id, products_options, products_options_values, options_values_price, price_prefix  FROM " . TABLE_ORDERS_PRODUCTS_ATTRIBUTES .
                    " WHERE orders_id = '" . $result->fields['orders_id'] . "' and orders_products_id = '" . $products_result->fields['orders_products_id'] . "'";
                $options_result = $db->Execute($options_query);

                $attributes = '';
                $options = array();
                while (!$options_result->EOF) {
                    $option = array(
                        "name" => $options_result->fields['products_options'],
                        "value" => $options_result->fields['products_options_values'],
                        "price" => $options_result->fields['options_values_price'],
                        "prefix" => $options_result->fields['price_prefix']
                    );
                    array_push($options, $option);

                    // Append attributes to description for the moment until such time as we handle attributes properly
                    $attributes .= ' [' . $option['name'] . ': ' . nl2br(zen_output_string_protected($option['value']));
                    if( $option['price'] != '0' )
                    {
                        $attributes .= ' (' . $option['prefix'] . zen_round($option['price'] * $item['qty'], 2 /*$currencies->currencies[DEFAULT_CURRENCY]['decimal_places']*/) . ')';
                    }

                    if( $item['attributes'][$j]['product_attribute_is_free'] == '1' and $item['product_is_free'] == '1' )
                    {
                        $attributes .=  TEXT_INFO_ATTRIBUTE_FREE;
                    }
                    
                    $attributes .= ']';
                    // End append attributes to description

                    $options_result->MoveNext();
                }
                $item["options"] = $options;

                // Append attributes to description
                $item['name'] .= $attributes;
                // End append attributes to description

                array_push($orderItems, $item);
                $products_result->MoveNext();
            }

            // Deal with shipping and any coupons or Taxamo Assure
            // Any additional order_total modules installed may need to be added here

            // Get order_totals from database
            $sql = "SELECT * FROM " . TABLE_ORDERS_TOTAL . " WHERE orders_id =" . $orders_id . ";";
            $orders_total = $db->Execute($sql);

            $ot_items = [];
            while (!$orders_total->EOF)
            { 
                if( $shippingAddress["country"] == "United Kingdom" )
                    $ot_vat_rate = $vat_rate;
                else
                    $ot_vat_rate = 0;

                // TODO: Pass net discount separately
                $order['net_discount'] = 0.0;
                switch( $orders_total->fields['class'] )
                {
                    case 'ot_coupon':
                        // Add discount coupons as line items
                        $coupon_value = $orders_total->fields['value'] / (1 + $ot_vat_rate/100);

                        $coupon_item = array();
                        $coupon_item["sku"] = 'Discount';
                        $coupon_item["name"] = strip_tags($orders_total->fields['title']);
                        $coupon_item["quantity"] = 1;
                        $coupon_item["unitPrice"] = -$coupon_value;
                        $coupon_item["tax"] = $ot_vat_rate;

                        $ot_items['coupon'] = $coupon_item;

//                        $order['net_discount'] += $coupon_value;

                        break;

                    case 'ot_shipping':
                        // Add shipping as a line item
                        $shipping_cost = $orders_total->fields['value'] / (1 + $ot_vat_rate/100);

                        $shipping_item = array();
                        $shipping_item["sku"] = 'Shipping';
                        $shipping_item["name"] = strip_tags($orders_total->fields['title']);
                        $shipping_item["quantity"] = 1;
                        $shipping_item["unitPrice"] = $shipping_cost;
                        $shipping_item["tax"] = $ot_vat_rate;
                        // BillJ - Above works for UK VAT. A more general way is outlined below
//                        we must infer shipping-tax as it is not recorded anywhere
//                        $shipping_tax = $data->info['tax'] - $item_total_tax - ($coupon_value * $ot_vat_rate / 100);
//                        $shipping_gross = $this->clean_value($freight_totals['text'], $data->info['currency']);
                        
                        // BillJ Only includes tax if shipping to a UK destination
                        
//                        if( $shippingAddress["country"] == "United Kingdom" )
//                        {
//                            $shipping_cost = $shipping_gross / (1 + $vat_rate / 100);
//                        }
//                        else
//                        {
//                            $shipping_cost = $shipping_gross;
//                        }
                        
//                        $strXML .= $this->xmlEntry('UnitPrice',  $shipping_cost );

                        // BillJ - need to do something here to distinguish between different taxes of the same rate - "EU VAT" and "none"
                        // Otherwise Phreebooks selects "EU VAT" for every item with 0% tax in soap/classes/orders.php
                        // Tax rate sent from Zen Cart in <admin>/includes/classes/phreedom.php

//                        $shipping_tax_percent = $vat_rate;
                        // BillJ - UK no longer in the EU so for now, any orders shipped to the UK have UK VAT
                        // and others have no tax rate (but we may collect foreign tax simply as an additional line item)
//                        if( $shipping_tax_percent > 0 )
//                        {
//                            if( $codes['country'] == "GB" )
//                            {
//                                $strXML .= $this->xmlEntry( 'SalesTaxPercent', $shipping_tax_percent );
//                            }
//                            else
//                            {
//                                $strXML .= $this->xmlEntry( 'SalesTaxPercent', '0' );
//                            }
//                        }
                    
    //            $strXML .= $this->xmlEntry('TotalPrice', $shipping_cost);
    //            $strXML .= '</Item>' . chr(10);

                        $ot_items['shipping'] = $shipping_item;
                        break;

                    // TODO: If collecting non-UK VAT, add an item line for that
                    case 'ot_taxamo_assure':
                        if( $shippingAddress["country"] != "United Kingdom" && $vat_rate != 0 )
                        {
                            $taxamo_vat = $orders_total->fields['value'] /*/ (1 + $ot_vat_rate/100)*/;

                            $taxamo_item = array();
                            $taxamo_item["sku"] = 'VAT';
                            $taxamo_item["name"] = strip_tags($orders_total->fields['title']);
                            $taxamo_item["quantity"] = 1;
                            $taxamo_item["unitPrice"] = $taxamo_vat;
                            $taxamo_item["tax"] = 0;
                            $ot_items['taxamo_assure'] = $taxamo_item;
                        }     
                        break;
                }

                $orders_total->MoveNext();
            }

            if( array_key_exists( 'coupon', $ot_items ) )
                array_push($orderItems, $ot_items['coupon']);

            if( array_key_exists( 'shipping', $ot_items ) )
                array_push($orderItems, $ot_items['shipping']);

            if( array_key_exists( 'taxamo_assure', $ot_items ) )
                array_push($orderItems, $ot_items['taxamo_assure']);

            $order["items"] = $orderItems;
            array_push($orders, $order);
            $result->MoveNext();
        }
    }
    $out = array("orders" => $orders);
    echo json_encode($out);
}

/**
 * Update order comments
 */
function uploadTracking($db = null, $data)
{
    if ($data['orderId'] && $data['comment']) {
        $customer_notified = '1';
        $sql = "SELECT orders_id FROM " . TABLE_ORDERS . " WHERE orders_id = '" . $data['orderId'] . "'";
        $result = $db->Execute($sql);

        if (isset($result) && $result->fields['orders_id']) {
            $status = 4;

            $status_updated = zen_update_orders_history(
                $data['orderId'],
                $data['comment'],
                "SAABits system",
                $status,
                $customer_notified,
                true
            );
            if ($status_updated > 0) {
                $out = array('message' => 'Order status update was successful.', "status" => 0);
                echo json_encode($out);
            } else {
                $out = array('message' => 'Failed to update order status.', "status" => 1);
                echo json_encode($out);
            }
        } else {
            $out = array('message' => 'orderId ' . $data['orderId'] . ' does not exist.', "status" => 1);
            echo json_encode($out);
        }
    } else {
        $out = array('message' => 'orderId and comment must be specified', "status" => 1);
        echo json_encode($out);
    }
}

/**
 * Update/add product
 */
function updateProduct($db = null, $data)
{
    $products = $data["products"];
    $added = array();
    $updated = array();
    $notUpdated = array();
    foreach ($products as $product) {
        $products_model = $product["products_model"];
        $quantity = 0.0 + $product["quantity"];
        if( $quantity < 0 )
            $quantity = 0;
		
        $sql = "SELECT products_id FROM " . TABLE_PRODUCTS . " WHERE products_model = '" . $products_model . "'";
        $result = $db->Execute($sql);

        // set some preliminary information
        // verify the submitted language exists on the Zencart side
        // N.B. Hardcoded to 'en' for now
        $languages_code = strtolower(substr('en', 0, 2)); // Take the first two characters of the language iso code (e.g. en_us)
        $result = $db->Execute("select languages_id from " . TABLE_LANGUAGES . " where code = '" . $languages_code . "'");
        if ($result->RecordCount() <> 1) {
            $out = array('message' => 'Unrecognised language: ' . $languages_code, "status" => 1);
            echo json_encode($out);
            return;
        }
        $languages_id = $result->fields['languages_id'];
    

        // determine and verify the product_type
        $product_type_name = $product['productType'];
        $result = $db->Execute("select type_id from " . TABLE_PRODUCT_TYPES . " where type_name = '" . $product_type_name . "'");
        if ($result->RecordCount() <> 1) {
            $out = array('message' => 'Bad product type: ' . $product_type_name, "status" => 1);
            echo json_encode($out);
            return;
        }
        $product_type_id = $result->fields['type_id'];
        
        // manufacturer to id
        $manufacturer_name = $product['manufacturer'];
        $result = $db->Execute("select manufacturers_id from " . TABLE_MANUFACTURERS . " where manufacturers_name = '" . $manufacturer_name . "'");
        if ($result->RecordCount() <> 1) {
            $out = array('message' => 'Bad manufacturer: ' . $manufacturer_name . ' ' . $products_model, "status" => 1);
            echo json_encode($out);
            return;
        }
        $manufacturers_id = $result->fields['manufacturers_id'];

        // categories need to be verified to be highest level and fetch id
        $categories_name = $product['productCategory'];
        $result = $db->Execute("select categories_id from " . TABLE_CATEGORIES_DESCRIPTION . " 
            where categories_name = '" . $categories_name . "' and language_id = '" . $languages_id . "'");
        if ($result->RecordCount() <> 1) {
            $out = array('message' => 'Category not found: ' . $categories_name . ' ' . $products_model, "status" => 1);
            echo json_encode($out);
            return;
        }

        $categories_id = $result->fields['categories_id'];
        // Verify that it is the highest level of category tree (required by zencart)
        $result = $db->Execute("select categories_id from " . TABLE_CATEGORIES . " where parent_id = '" . $categories_id . "'");
        if ($result->RecordCount() <> 0) {
            $out = array('message' => 'Category not lowest level: ' . $categories_name . ' ' . $products_model, "status" => 1);
            echo json_encode($out);
            return;
        }

        // verify the image and storage location - save image
        $image_directory = $product['productImageDirectory'];
        if( !is_string($image_directory) )
            $image_directory = '';

        // directory must not be more than one level down
        if (strpos($image_directory, '/') !== false) {
        $image_directory = substr($image_directory, 0, strpos($image_directory, '/'));
        }
        $image_filename = $product['productImageFileName'];
        if( $image_filename == null )
            $image_filename = '';

        // Save the image
        $image_data = $product['productImageData'];
        if ($image_data != null)
        {
            // Image is encoded using URL-safe RFC 4648 scheme. Convert to original base64 encoding before we decode 
            $contents = base64_decode(str_replace(array('-', '_'), array('+', '/'), $image_data));
            
            if ($image_directory != '') {
                if (!is_dir(DIR_FS_CATALOG . '/images/' . $image_directory)) {
                    mkdir(DIR_FS_CATALOG . '/images/' . $image_directory);
                }
                $full_path = $image_directory . '/' . $image_filename;
            } else {
                $full_path = $image_filename;
            }
            if (!$handle = fopen(DIR_FS_CATALOG . '/images/' . $full_path, 'wb')) {
                $out = array('message' => 'Failed to open file: ' . '/images/' .$full_path, "status" => 1);
                echo json_encode($out);
                return;
            }
            if (fwrite($handle, $contents) === false) {
                $out = array('message' => 'Error writing image file', "status" => 1);
                echo json_encode($out);
                return;
            }
            fclose($handle);
        }

        // WORKAROUND for ERPNext HTML editor always producing ordered lists even if unordered
        if( isset($product['productZencartDescription']) )
            $product['productZencartDescription'] = str_ireplace( array('<ol>', '</ol>'), array( '<ul>', '</ul>'), $product['productZencartDescription']);

        // ************** prepare to write tables **************
        // build the products table data
        $sql_data_array = array(
        'phreebooks_sku'       => $product['products_model'],
        'products_type'        => $product_type_id,
        'manufacturers_id'     => $manufacturers_id,
        'master_categories_id' => $categories_id,
        );

        if (isset($product['quantity']))                $sql_data_array['products_quantity']       = $product['quantity'];
        if (isset($product['products_model']))          $sql_data_array['products_model']          = $product['products_model'];
        if (isset($full_path))                          $sql_data_array['products_image']          = $full_path;
        if (isset($product['product_virtual']))         $sql_data_array['products_virtual']        = $product['product_virtual'];
        // Don't use supplied "date added" as it might have been some time ago. If we end up creating a new product here, we
        // want it to have been "created" today
        if (isset($product['dateUpdated']))             $sql_data_array['products_last_modified']  = $product['dateUpdated'];
        if (isset($product['dateAvailable']))           $sql_data_array['products_date_available'] = $product['dateAvailable'];
        if (isset($product['productWeight']))           $sql_data_array['products_weight']         = $product['productWeight'];
        if (isset($product['productStatus']))           $sql_data_array['products_status']         = $product['productStatus'];
        if (isset($product['productHidePrice']))        $sql_data_array['product_is_call']         = $product['productHidePrice'];
        if (isset($product['productFreeShipping']))     $sql_data_array['product_is_always_free_shipping'] = $product['productFreeShipping'];
        if (isset($product['productSortOrder']))        $sql_data_array['products_sort_order']     = $product['productSortOrder'];
        if ($product['priceDiscountType'] <> 0) {
            $sql_data_array['products_discount_type'] = $product['priceDiscountType'];
            // set products price to level 1 price since zencart uses products_price for the first level.
            $sql_data_array['products_quantity_order_min'] = $product['price_levels'][1]['qty'];
            $sql_data_array['products_price'] = $product['price_levels'][1]['amount'];
        } else {
            $sql_data_array['products_discount_type'] = '0';
            if (isset($product['retailPrice'])) $sql_data_array['products_price'] = $product['retailPrice'];
        }

        // determine tax class
        $tax_class_id = $product['productTaxable'] ? ZENCART_PRODUCT_TAX_CLASS_ID : 0; // constant set at top of file
        if ($tax_class_id) $sql_data_array['products_tax_class_id'] = $tax_class_id;

        // prepare the products_description data
        $prod_desc_data_array = array();
        // Database column for product_name is max 64 chars
        if (isset($product['productName'])) $prod_desc_data_array['products_name'] = substr( $product['productName'], 0, 64 );
        if (isset($product['productDescription'])) $prod_desc_data_array['products_description'] = $product['productDescription'];
        if (isset($product['productURL']))
        {
            if( $prod_desc_data_array['products_url'] != null )
                    $prod_desc_data_array['products_url'] = str_replace('http://', '', $product['productURL']);
        }

        // Use the ERPNext sales description as the Zencart product title
        if( isset($product['productDescription']) )
            $prod_desc_data_array['products_name'] = $product['productDescription'];

        // Set the product model to the product SKU
        $sql_data_array['products_model'] = $product['products_model'];

        // Set the Zencart HTML description
        if( isset($product['productZencartDescription']) )
            $prod_desc_data_array['products_description'] = $product['productZencartDescription'];

        // Set the Zencart sort order
        if( isset($product['productZencartSortOrder']) )
            $sql_data_array['products_sort_order'] = $product['productZencartSortOrder'];

        // Set the Zencart OEM part numbers
        if( isset($product['productZencartOemPartnumbers']) && is_string($product['productZencartOemPartnumbers']) )
            $sql_data_array['additional_skus'] = $product['productZencartOemPartnumbers'];

        // Set the Zencart Condition field
        if( isset($product['productZencartCondition']) )
            $sql_data_array['products_condition'] = $product['productZencartCondition'];

        // Set the product dimensional units
        if( isset($product['productZencartDimUnits']) )
            $sql_data_array['products_dim_type'] = $product['productZencartDimUnits'];

        // Set the product length
        if( isset($product['productZencartLength']) )
            $sql_data_array['products_length'] = $product['productZencartLength'];

        // Set the product width
        if( isset($product['productZencartWidth']) )
            $sql_data_array['products_width'] = $product['productZencartWidth'];

        // Set the product height
        if( isset($product['productZencartHeight']) )
            $sql_data_array['products_height'] = $product['productZencartHeight'];

        // Set the product "ready to ship" field
        if( isset($product['productZencartReadyToShip']) )
            $sql_data_array['products_ready_to_ship'] = $product['productZencartReadyToShip'];

        // Set the product HS code
        if( isset($product['productHsCode']) )
            $sql_data_array['hs_code'] = $product['productHsCode'];

        // Set the product country of origin
        if( isset($product['productCountryOrigin']) )
            $sql_data_array['country_of_origin'] = $product['productCountryOrigin'];


        // write to the tables
        $upload_success = true;

        // determine if the SKU exists, if so update else insert the products table
        $result = $db->Execute("select products_id from " . TABLE_PRODUCTS . " where products_model = '" . $product['products_model'] . "'");
        if ($result->RecordCount() == 0) { // new product
            // Only ever set this once and set it to the date the product was first uploaded,
            // regardless of any supplied creation date
            $sql_data_array['products_date_added'] = date("Y-m-d H:i:s");
            zen_db_perform(TABLE_PRODUCTS, $sql_data_array);
            $products_id = zen_db_insert_id();
            $result = $db->Execute("insert into " . TABLE_PRODUCTS_TO_CATEGORIES . " set categories_id = " . $categories_id . ", products_id = " . $products_id);
            $prod_desc_data_array['products_id'] = $products_id;
            $prod_desc_data_array['language_id'] = $languages_id;
            zen_db_perform(TABLE_PRODUCTS_DESCRIPTION, $prod_desc_data_array);
            array_push($added, $products_model);
        } else { // update product
            $products_id = (int)$result->fields['products_id'];
            zen_db_perform(TABLE_PRODUCTS, $sql_data_array, 'update', "products_id = '" . $products_id . "'");
            // BillJ - don't update category since product might be linked to more than one,
            // which would make this update fail with "Duplicate entry"
            // To get around the category limitations, all our products go into
            // one category and we link them within Zencart to their correct categories.
            zen_db_perform(TABLE_PRODUCTS_DESCRIPTION, $prod_desc_data_array, 'update', "products_id = " . $products_id.' and language_id =' . $languages_id);
            array_push($updated, $products_model);
        }
    }

    $status = 1;
    if (empty($notUpdated)) {
        $status = 0;
    }

    $out = array('message' => 'Added: ' . implode(", ", $added) . "; Updated: " . implode(", ", $updated), "; Not updated: " . implode(", ", $notUpdated), "status" => $status);
    echo json_encode($out);
}

/**
 * Update product quantity
 */
function updateProductQuantity($db = null, $data)
{
    $products = $data["products"];
    $updated = array();
    $notUpdated = array();
    foreach ($products as $product) {
        $products_model = $product["products_model"];
        $quantity = 0.0 + $product["quantity"];
        if( $quantity < 0 )
            $quantity = 0;
		
        $sql = "SELECT products_id FROM " . TABLE_PRODUCTS . " WHERE products_model = '" . $products_model . "'";
        $result = $db->Execute($sql);

        if (isset($result) && $result->fields['products_id']) {

            $sql_data_array = array(
                'products_quantity' => $quantity
            );
            zen_db_perform(
                TABLE_PRODUCTS,
                $sql_data_array,
                'update',
                "products_id = " . (int) $result->fields['products_id']
            );
            array_push($updated, $products_model);
        } else {
            array_push($notUpdated, $products_model);
        }
    }
    $status = 1;
    if (empty($notUpdated)) {
        $status = 0;
    }
    $out = array('message' => 'Updated: ' . implode(", ", $updated) . "; Not updated: " . implode(", ", $notUpdated), "status" => $status);
    echo json_encode($out);
}

/**
 * Set configuration
 */
function configure($db = null, $data)
{
    $config = $data["config"];
    $store_id = $config["store_id"];
    $enabled = $config["enabled"];
    $url = $config['url'];

    // Todo: Get credentials and create config category/items

    $status = 0;
    $out = array('message' => 'Configured', "status" => $status);
    echo json_encode($out);
}

//
// The main entry point to the code
//
checkAccess($db);
if (isset($_REQUEST['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}
if (isset($_REQUEST['task'])) {
    if ($_REQUEST['task'] == 'orders') {
        if( $_REQUEST['last_order'] )
            $last_order = $_REQUEST['last_order'];
        else
            $last_order = -1;

        getOrders($db, $last_order);
    } else if ($_REQUEST['task'] == 'version') {
        $out = array('version' => $VERSION);
        echo json_encode($out);
    } else {
        // check POST
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if ($_REQUEST['task'] == 'uploadTracking') {
            uploadTracking($db, $data);
        } else if ($_REQUEST['task'] == 'updateProductsQty') {
            updateProductQuantity($db, $data);
        } else if ($_REQUEST['task'] == 'updateProducts') {
            updateProduct($db, $data);
        } else if ($_REQUEST['task'] == 'configure') {
            configure($db, $data);
        }
    }
}

?>
<?php require(DIR_WS_INCLUDES . 'application_bottom.php'); ?>