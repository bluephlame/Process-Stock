<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/VendSaleDto.php';

// $myApp = new ProcessVend();
// $myApp->createSale("test_id",date(DATE_ATOM));
//     "prefix": "abcchristianbooks",

class ProcessVend {

    private $accessToken;
    private $vend;

    function __construct(){

       // $this->vend->debug(true);
        $tfile='token.txt';
        $myfile=fopen("$tfile",'r');
        $this->accessToken= rtrim(fgets($myfile));
        fclose($myfile);
        //print("Access Token :{$this->accessToken}\n");

        $this->vend = new VendAPI\VendAPI( 'https://abcchristianbooks.vendhq.com','Bearer',$this->accessToken);
    }

    public function getStock()
    {
        $date = new DateTime('now');

        $products = $this->vend->getProductsSince($date->modify('- 1 day')->format('Y-m-d H:i:s'));

        $magentoArray = [];
        foreach($products as $product)
        {
            $sku = $product->__get('sku');
            $inventory = $product->getInventory();
            $price = $product->__get('price');
            $magentoProduct = new MagentoProduct($sku,$inventory,$price);
            $magentoArray[] = $magentoProduct;
        };
        return $magentoArray;
    }

    public function seedStock()
    {
        $magentoArray = [];
        $products = $this->vend->getProducts();

        $pageination = $this->vend->getPages();
        $pages = $pageination->pages;
        //print("Pages ". $pageination->pages . "\n");
        $page = 1;
        while( $page<$pages)
        {
            foreach($products as $product)
            {
               // print("SKU ". $product->__get('sku') . "\n");
                $sku = $product->__get('sku');
                $inventory = $product->getInventory();
                $price = $product->__get('price');
                $magentoProduct = new MagentoProduct($sku,$inventory,$price);
                $magentoArray[] = $magentoProduct;
            };
            $page++;
            $products = $this->vend->getProducts(["page"=>$page]);
           // print("Page ". $page . "\n");
        }
        return $magentoArray;
    }

    public function createSale($magento_id,$sale_date)
    {
        $customer_id = "02dcd191-aeb7-11e9-f336-8cd80f97308f";// "name": "Web Customer ",
        // "customer_code": "Web Customer-C678",
        $register_id = "02dcd191-ae2b-11e6-f485-9f5af3e907e1"; //"name": "POS 1",
        // "outlet_id": "02dcd191-ae2b-11e6-f485-9f5af3e78d61",
        $user_id = "02dcd191-aeb7-11e9-ed44-6b9b4abb006b";
        $sale = new VendSaleDto($this->vend,$customer_id,$register_id,$magento_id,$user_id,$sale_date);

        return $sale;
    }

    public function finaliseSale($sale,$amount)
    {
        $payment_id = "02dcd191-aeb7-11e6-f485-9f65200c0f55"; // "name": "eWay online payment",
        $sale->addPayment($payment_id,$amount,$sale->sale_date);
        $json = json_encode($sale);
       $result = $this->vend->saveSale($sale);
    }
}
  class MagentoProduct{
    public $SKU ;
    public $QTY;
    public $Price;
    public function __construct($sku, $qty, $price)
    {
        $this->SKU = $sku;
        $this->QTY = $qty;
        $this->Price = $price;
    }
  }
//   $sales = $this->vend->saveSale();

?>
