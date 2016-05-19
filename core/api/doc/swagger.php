<?php

require_once dirname(__FILE__) . '/../../../vendor/autoload.php';
$swagger = \Swagger\scan(__DIR__.'/../');
header('Content-Type: application/json');
echo $swagger;exit;