<?php

class VendSaleDto
{
    var $source_id;
    var $register_id;
    var $customer_id;
    var $user_id;
    var $sale_date;
    var $note;
    var $status;
    var $short_code;
    var $invoice_number;
    private $vend;

    var $accounts_transaction_id;
    var $register_sale_products;
    var $register_sale_payments;
    function __construct($vend, $customer,$register,$source,$user,$sale_date)
    {
        $this->vend = $vend;
        $this->source_id = $source;
        $this->register_id = $register;
        $this->customer_id = $customer;
        $this->user_id = $user;
        $this->sale_date = $sale_date;
        $this->register_sale_products = [];
        $this->register_sale_payments = [];
    }

    function addProduct($sku,$qty,$price,$tax)
    {
        $product = $this->vend->getProductBySKU($sku);
//TODO: what if the product doesnt exist?
        $this->register_sale_products[] = new RegisterSaleProduct($this->register, $product,$qty,$price,$tax);
    }

    function addPayment($payment_id,$amount,$payment_date)
    {
        $this->register_sale_payments[] = new RegisterSalePayment($this->register, $payment_id,$amount, $this->sale_date);
    }

    function addShipping($price)
    {
        //SKU for postage in NA is 10000
        $this->addProduct(10000,1,$price);
    }
}

class RegisterSaleProduct 
{
    var $id; // this is used for updates.
    var $product_id; // need to be converted to SKU
    var $register_id;
    var $sequence;
    var $quantity;
    var $price;
     var $cost;
     var $price_set;
     var $discount;
     var $loyalty_value;
     var  $tax;
     var $tax_id;
     var $status;

    function __construct($register,$product,$quantity,$price,$tax)
    {
// print_r($product);
//         print($product->__get('id'));
        $this->register_id = $register;
        $this->product_id = $product->__get('id');
        $this->quantity = $quantity;
        $this->price = $price;
        $this->tax = $tax;
        $this->tax_id = $product->__get('tax_id');
    }
}

class RegisterSalePayment
{
    var $id;
    var $register_id;
    var $retailer_payment_type_id;
    var $payment_date;
    var $amount;

    function __construct($register_id, $payment_id,$amount,$date)
    {
        $this->payment_date = $date;
        $this->amount = $amount;
        $this->retailer_payment_type_id = $payment_id;
    }
}
?>