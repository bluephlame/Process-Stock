<?php
    set_time_limit(1800);
    $_BASE_DIR = "/home/bbaftest/";
    echo "V1.03 timestamp: " . date('c',time()) . "\n\r"  ;
    //Note relative directory
    require_once 'app/Mage.php';
    require_once 'vend.php';

    //initiate MAgento
    Mage::app();
    umask(0);
    $database = Mage::getSingleton('core/resource')->getConnection('core_read');
    $result=$database->query("SELECT DATABASE()");
    $product = $result->fetchAll();
    print($product[0]['DATABASE()']."\n");

    //initiate Vend
    $processVend = new ProcessVend();

    $helper = Mage::helper('warehouse');
    $warehouses_real = $helper->getWarehouses();
    foreach ($warehouses_real as $house) {
        $warehouses[$house['warehouse_id']] = array('id' => $house['warehouse_id'], 'name' => $house['code']);
    }

    //process new sales in the last 5 days
    //this function ecides basedon warehosue what todo (vend ot TOWER)
    salesList(strtotime("-5 day"),$warehouses);


    //process Inventory into Magento
    foreach ($warehouses as $warehouse ) {
        if($warehouse['name'] == 'townsville')
        {
            //vend store
            echo "processing VEND Warehouse ". $warehouse['name']."\n";
            ProcessVendInventory($warehouse);
        }
        else{
            echo "processing warehouse ". $warehouse['name']."\n";
            ProcessTowerInventory($warehouse);
        }
    }

    function ProcessVendInventory($warehouse)
    {
        $processVend = new ProcessVend();        
        $data = $processVend->seedStock();
        foreach($data as $row)
        {   
            print("Processing ". $row->SKU."\n");
            ProductUpdate($row->SKU,$row->QTY,$warehouse); //send update
            ProductPriceUpdate($row->SKU,$row->Price,$warehouse);
        }
    }
    function ProcessTowerInventory($warehouse)
    {        
        $diffData = loadFile("STOCK",$warehouse);

        foreach($diffData as $row)
        {
            ProductUpdate($row[0],$row[1],$warehouse); //send update
            ProductPriceUpdate($row[0],$row[2],$warehouse);
        }
        $customerData = loadFile("CUSTOMER",$warehouse);
        foreach ($customerData as $customer) {
            
        }
    }

    function loadFile($prefix,$warehouse)
    {
        /// File process
///    find newest file in directory with PREFIX, 
///     Get PREFIX file from History folder
///     Compare files
///     generate diff data for return.
///     Move Newest File to History (overwrite old file)
///     Delete other PREFIX files in directory.

        global $_BASE_DIR;
        $StockFiles = array();
        $CustomerFiles = array();
        $iterator = new DirectoryIterator($_BASE_DIR.$warehouse['name']."/inbound");
        foreach($iterator as $fileInfo){
           if ($fileInfo->isFile()) {
                if(substr($fileInfo->getFilename(), 0, strlen($prefix)  ) === $prefix)
                    $StockFiles[$fileInfo->getMTime()] = $fileInfo->getFilename();    
            }
        }
        krsort($StockFiles);

        $latestFile = $_BASE_DIR.$warehouse['name']."/inbound/".reset($StockFiles);
        $previousFile = $_BASE_DIR.$warehouse['name']."/lastimport/".$prefix.".csv";
        if(count($StockFiles) > 1)
        {
            //need to get last updated file, and then do diff from that.
            $diffCommand = "diff --changed-group-format='%>' --unchanged-group-format='' --new-group-format='%>' {$latestFile} {$previousFile}";
            $result = shell_exec ($diffCommand);

            print(getTimeStamp().": moving file from {$latestFile} to {$previousFile}\n");
            rename($latestFile, $previousFile); //Overwrites OLD previous file
        }
        else{
            $result = file_get_contents($latestFile , true);
            if($result)
            {
                print(getTimeStamp().": moving file from {$latestFile} to {$previousFile}\n");
                rename($latestFile, $previousFile); //Overwrites OLD previous file        
            }
            else return;
        }

        //Processes the data into a useable format (look at naming the rows)
        $Data = str_getcsv($result, "\n"); //parse the rows 
        $retArray = [];
        foreach($Data as &$Row) 
        {
            $Row = str_getcsv($Row); //parse the items in rows 
            $retArray[] = $Row; 
        }

        //delete unessisary files
        foreach(glob($_BASE_DIR.$warehouse['name']."/inbound/".$prefix."*") as $f) {
            print(getTimeStamp().": unlinking file {$f}\n");          
            unlink($f);
        }
        return $retArray;
    }


    function ProductUpdate($productSku, $qty, $warehouse)
    {
        global $database;

        $product_id = Mage::getModel("catalog/product")->getIdBySku( $productSku );
        
        if($product_id)
        {
            $stock = ($qty > 0 ? 1 : 0);
            $qty = ($qty <= 0 ? 0 : $qty );
            $date = ($qty > 0 ? NULL : date());
            print ("DATE {$date}");
            $result = $database->query(
                "update cataloginventory_stock_item 
                    set qty= {$qty} ,is_in_stock = {$stock}, low_stock_date = '{$date}' 
                where product_id= '{$product_id}' and stock_id = {$warehouse['id']}");
            print(getTimeStamp().": Setting QTY :{$qty} for SKU {$productSku}({$product_id}) in Warehouse: {$warehouse['name']} \n");
            $rowCount = $result->rowCount();
            //print("Row Count: {$rowCount} \n");
            if( $rowCount == 0)
            {
                //check if row exists, insert if not
                $exec = $database->query("select count(*) as `exists` from cataloginventory_stock_item where product_id= '{$product_id}' and stock_id = {$warehouse['id']}");
                $exists = $exec->fetchAll()[0]['exists'];

                if (!$exists) {
                    //insert
                    $sql = "INSERT INTO `cataloginventory_stock_item` (`item_id`, `product_id`, `stock_id`, `qty`, `min_qty`, `use_config_min_qty`, `is_qty_decimal`, `backorders`, `use_config_backorders`, `min_sale_qty`, `use_config_min_sale_qty`, `max_sale_qty`, `use_config_max_sale_qty`, `is_in_stock`, `low_stock_date`, `notify_stock_qty`, `use_config_notify_stock_qty`, `manage_stock`, `use_config_manage_stock`, `stock_status_changed_auto`, `use_config_qty_increments`, `qty_increments`, `use_config_enable_qty_inc`, `enable_qty_increments`, `is_decimal_divided`) 
                    VALUES (NULL, '{$product_id}', '{$warehouse['id']}', '{$qty}', '0.0000', '1', '0', '0', '1', '1.0000', '1', '0.0000', '1', '1', NULL, NULL, '1', '0', '0', '0', '1', '0.0000', '1', '0', '0')";
                    $result = $database->exec($sql);
                    print(getTimeStamp().": UPDATING {$result} row with QTY :{$qty} for SKU {$productSku}({$product_id}) in Warehouse: {$warehouse['name']} \n");
                }
            }
        }
        else
        {
            print(getTimeStamp().": no product found for SKU:{$productSku} in Warehouse: {$warehouse['name']} \n");
        }
    
    }

    function ProductPriceUpdate($productSku, $price, $warehouse)
    {
        global $database;
        if($price >= 0)
        {
            $product_id = Mage::getModel("catalog/product")->getIdBySku( $productSku );
            
            if($product_id)
            {

                $select = "SELECT count(*) from catalog_product_batch_price WHERE product_id = '{$product_id}' and stock_id = {$warehouse['id']}";
                $selectResult = $database->query($select);
                $selectRowCount = $selectResult->fetchAll();

            //    print("rowCount {$selectRowCount[0]['count(*)']} \n");

                if($selectRowCount[0]['count(*)'] == 1)
                {
                    $result = $database->query(
                    "UPDATE catalog_product_batch_price 
                        set price = '{$price}'

                    where product_id = '{$product_id}' and stock_id = {$warehouse['id']}");

                    print(getTimeStamp().": UPDATING Price :{$price} for SKU {$productSku}({$product_id}) in Warehouse: {$warehouse['name']} \n");
                }
                else 
                {
                    $sql = "INSERT INTO `catalog_product_batch_price` (`product_id`, `stock_id`, `website_id`, `price` ) VALUES ('{$product_id}', '{$warehouse['id']}',0,'{$price}')";
                    $result = $database->exec($sql);
                    print(getTimeStamp().": INSERTING {$result} row with Price :{$prie} for SKU {$productSku}({$product_id}) in Warehouse: {$warehouse['name']} \n");
    
                }
            }            
            else
            {
                print(getTimeStamp().": no product found for SKU:{$productSku} in Warehouse: {$warehouse['name']}\n");
            }
            
        }
        else
        {
            print(getTimeStamp().": no negative price for SKU:{$productSku} in Warehouse: {$warehouse['name']}\n");
        }
    }


    //Test SKU 
    function ProductInfo($productSku, $warehouse)
    {
        global $database;
        $product_id = Mage::getModel("catalog/product")->getIdBySku( $productSku[0] );
        //print("ID ".$product_id. " SKU ".$productSku[0]."\n\r");

        $arrayString = implode("','",$productSku);
        $result=$database->query(
            "
            SELECT product_id,qty,stock_id, catalog_product_entity.sku 
            FROM cataloginventory_stock_item 
            JOIN catalog_product_entity on catalog_product_entity.entity_id = cataloginventory_stock_item.product_id
            WHERE catalog_product_entity.sku in ('{$arrayString}') ");
        $product = $result->fetchAll();
    }

    function ProductPriceInfo($productSku, $warehouse)
    {
        global $database;
        $product_id = Mage::getModel("catalog/product")->getIdBySku( $productSku[0] );
        print_r("ID ".$product_id. " SKU ".$productSku[0]."\n\r");

        $arrayString = implode("','",$productSku);
        $result=$database->query(
            "
            SELECT catalog_product_index_price.entity_id as product_id,stock_id, catalog_product_entity.sku,price,final_price,min_price,max_price,tier_price
            FROM catalog_product_index_price 
            JOIN catalog_product_entity on catalog_product_entity.entity_id = catalog_product_index_price.entity_id
            WHERE catalog_product_entity.sku in ('{$arrayString}') ");
        $product = $result->fetchAll();
       // print_r($product);

    }

    function salesList($startDate = null, $warehouse)
    {
        $format = 'Y-m-d H:i:s';
        $result = array();
        $orders = array();
        $customer = array();

        $order_collection = Mage::getModel('sales/order')
            ->getCollection();
            //      ->join(
            //     array('payment' => 'sales/order_payment'),
            //     'main_table.entity_id=payment.parent_id',
            //     array('payment_method' => 'payment.method')
            // );
        $order_collection
            ->addAttributeToSelect('*')
            // ->addFieldToFilter('payment.method',array(array('nlike'=>'checkmo'))); //filter out transactions that are check/ Money Order
            ->addAttributeToFilter('created_at', array('from' => date('c',$startDate), "to" => '3015-10-15 01:02:02'));
        $order_collection->load();

        $tfile='orderid_count.txt';
        $myfile=fopen("$tfile",'r');
        $orderCount= rtrim(fgets($myfile));
        $lastOrderId = rtrim(fgets($myfile));
        fclose($myfile);

        print("Last order details Counter {$orderCount} and Last order ID {$lastOrderId} working in ".getcwd().PHP_EOL);

        foreach ($order_collection as $key => $order) 
        {
            $warehouseId = $order->getStockId();
            $tmpOrder = array();
            $date = Mage::helper('core')->formatDate($order->getCreatedAt(), "short");
            $time = date("H:i:s",strtotime(Mage::helper('core')->formatTime($order->getCreatedAt(), "medium")));
            $orderId = $order->getIncrementId();
            

            if($orderId <= $lastOrderId )
            {
                print("Not processing order {$orderId} lastOrderId {$lastOrderId}\n");
                continue ;
            }
            print("processing order {$orderId} lastOrderId {$lastOrderId}\n ");
            $lastOrderId = $order->getIncrementId();

            if($warehouse[$warehouseId->name == "Townsville"])
            {
                VendOrder($order);
            }
            else
            {
                $tmpOrder = TowerOrders($order);
                orders2csv($tmpOrder,sizeof($tmpOrder),$warehouse[$warehouseId]);
            }
            //NOT SURE WHHY THE LINE BELOW HAVE COMMENTED OUT FOR TESTING
            //$result[$warehouseId] = array_merge($tmpOrder,$result[$warehouseId]);
        }

        $myfile=fopen('orderid_count.txt','w');
        fwrite($myfile,$orderCount.PHP_EOL);
        fwrite($myfile,$lastOrderId);
        fclose($myfile);

        customers2csv($customer,$warehouse);
    }
    function VendOrder($order)
    {
        $ordered_items = $order->getAllItems();
        $ItemNumber = 0;

        $internalId = $order->getIncrementId();
        $sale  = $ProcessVend->createSale($internalId,$date);

        foreach($ordered_items as $item)
        { 
            $sale->addProduct(
                $item->getSku(), 
                number_format($item->getQtyOrdered(),0,'',''),
                number_format($item->getPriceInclTax(), 2, '.', ''),
                number_format($item->getPriceInclTax()-$item->getPrice(), 2, '.', '') //tax
                );
        }
        //Shipping record
        $sale->addShipping(number_format($order->getShippingInclTax(), 2, '.', ''),
            number_format($order->getShippingTaxAmount(), 2, '.', '')); //tax
   
        $ProcessVend->finalizeSale($sale,$order->getGrandTotal());
    }

    function TowerOrders($order)
    {
        if($order->getCustomerId())
        {
            $customer[$order->getCustomerId()] = array(
                "firstname" => $order->getCustomerFirstname(),
                "lastname" => $order->getCustomerLastname(),
                "email" => $order->getCustomerEmail(),
                "mobile" => $order->getShippingAddress()->getTelephone(),
                "Id" => $order->getCustomerId()
                );
        }
             
        $ordered_items = $order->getAllItems();
        $ItemNumber = 0;
        foreach($ordered_items as $item)
        { 
            $tmpOrder[] =  array(
                    "Sale Id" =>$order->getIncrementId(),
                    "barcode" => $item->getSku(),
                    "ItemNumber" => $ItemNumber++,
                    "qty_ordered" => number_format($item->getQtyOrdered(),0,'',''),
                    "price" => number_format($item->getPriceInclTax(), 2, '.', ''),
                    "tax" => number_format($item->getPriceInclTax()-$item->getPrice(), 2, '.', ''),
                    "Transaction date" => $date,
                    "Transaction Time" => $time,
                    "Customer ID" => $order->getCustomerId()
            );     
        }
//Shipping record
        $tmpOrder[] =  array(
                    "Sale Id" =>$order->getIncrementId(),
                    "barcode" => "FREIGHTFREIGHT",
                    "ItemNumber" => $ItemNumber++,
                    "qty_ordered" => 1,
                    "price" => number_format($order->getShippingInclTax(), 2, '.', ''),
                    "tax" => number_format($order->getShippingTaxAmount(), 2, '.', ''),
                    "Transaction date" => $date,
                    "Transaction Time" => $time,
                    "Customer ID" => $order->getCustomerId(),
                    
            );     
        //$orderCount = $orderCount+1;
        return $tmpOrder;
    }

    function orders2csv(array &$array,$salesCount,$warehouse)
    {
       global $_BASE_DIR;
       if (count($array) == 0) {
         return null;
       }

       $dir = $_BASE_DIR.$warehouse['name']."/outbound/Import_Sales_{$salesCount}.csv";

       print("Creating order {$dir} \n");

       ob_start();
       $df = fopen($dir, 'w');
       
       fputcsv_eol($df, array_keys(reset($array)));
       foreach ($array as $row) {
          fputcsv_eol($df, $row);
       }
       fclose($df);
       return ob_get_clean();
    }

    function customers2csv(array &$array,$warehouse)
    {
        //print_r($array);
       global $_BASE_DIR;
       if (count($array) == 0) {
         return null;
       }
        $dir  = $_BASE_DIR.$warehouse['name']."/outbound/Import_Customers_1.csv";
       ob_start();
       $df = fopen($dir, 'w');
       
       fputcsv_eol($df, array_keys(reset($array)));
       foreach ($array as $row) {
          fputcsv_eol($df, $row);
       }
       fclose($df);
       return ob_get_clean();
    }

    function fputcsv_eol($handle, $array, $delimiter = ',', $enclosure = '"', $eol = "\r\n") {
        $return = fputcsv($handle, $array, $delimiter, $enclosure);
        if($return !== FALSE && "\n" != $eol && 0 === fseek($handle, -1, SEEK_CUR)) {
            fwrite($handle, $eol);
        }
        return $return;
    }

    function getTimeStamp()
    {
        return date('m-d-Y H:i:').(date('s')+fmod(microtime(true), 1));
    }
    exit();
?>