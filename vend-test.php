<?php
  require_once 'vend.php';
  $processVend = new ProcessVend();
  $data = $processVend->seedStock();
//print_r($data);

?>