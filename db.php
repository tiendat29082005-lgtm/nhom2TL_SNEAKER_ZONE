<?php
$config=require __DIR__.'/config.php';
try{
 $pdo=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}catch(PDOException $e){
 error_log($e->getMessage());http_response_code(500);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'message'=>'Không kết nối được SNEAKER_ZONE. Kiểm tra MySQL, config.php và hướng dẫn cài đặt.'],JSON_UNESCAPED_UNICODE);exit;
}
