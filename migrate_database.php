<?php
// Copy six project tables to a NEW SNEAKER_ZONE. Never overwrite an existing target.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Công cụ chuyển dữ liệu chỉ chạy trong cửa sổ lệnh trên máy chủ.');}
$c=require __DIR__.'/config.php';
$pdo=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo "Tên database nguồn (2TL hoặc 2tl_usecase): ";$source=trim(fgets(STDIN)?:'');
if(!in_array($source,['2TL','2tl_usecase'],true))exit("Tên nguồn không hợp lệ. Database đã tên SNEAKER_ZONE thì dùng upgrade_existing_database.sql.\n");
$st=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE BINARY SCHEMA_NAME=?');$st->execute([$source]);if(!$st->fetch())exit("Không tìm thấy database nguồn.\n");
$st->execute(['SNEAKER_ZONE']);if($st->fetch())exit("SNEAKER_ZONE đã tồn tại. Không ghi đè. Hãy xuất bản sao và kiểm tra database đích trước.\n");
echo "Dừng thao tác website cũ và xuất bản sao SQL trước khi tiếp tục.\nNhập SAOLUU để xác nhận đã sao lưu: ";if(trim(fgets(STDIN)?:'')!=='SAOLUU')exit("Đã hủy.\n");
$tables=['admins','users','products','coupons','orders','order_items'];$sourceCols=[];
foreach($tables as $table){$st=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=? AND table_name=?');$st->execute([$source,$table]);$sourceCols[$table]=$st->fetchAll(PDO::FETCH_COLUMN);if(!$sourceCols[$table])exit("Nguồn thiếu bảng $table. Không tạo database đích; cần đối chiếu bản SQL cũ trước.\n");}
try{
 $sql=preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/schema.sql'));
 foreach(explode(';',$sql) as $statement){if(trim($statement)!=='')$pdo->exec($statement);}
 $pdo->beginTransaction();$counts=[];
 foreach($tables as $table){
  $cols=$pdo->query("SHOW COLUMNS FROM `SNEAKER_ZONE`.`$table`")->fetchAll();$names=[];$expressions=[];
  foreach($cols as $col){$name=$col['Field'];
   if($name==='role'&&$table==='admins'){$names[]='`role`';$expressions[]=in_array('role',$sourceCols[$table],true)?"CASE WHEN `role` IN ('admin','superadmin') THEN 'admin' ELSE 'manager' END":"CASE WHEN username='admin' THEN 'admin' ELSE 'manager' END";}
   elseif(in_array($name,$sourceCols[$table],true)){$names[]="`$name`";$expressions[]="`$name`";}
   elseif($name==='subtotal'&&$table==='orders'){$names[]='`subtotal`';$expressions[]='`total`';}
   elseif($col['Null']!=='YES'&&$col['Default']===null&&$col['Extra']!=='auto_increment')throw new RuntimeException("Nguồn thiếu trường bắt buộc $table.$name");
  }
  $pdo->exec("INSERT INTO `SNEAKER_ZONE`.`$table` (".implode(',',$names).') SELECT '.implode(',',$expressions)." FROM `$source`.`$table`");$counts[$table]=(int)$pdo->query("SELECT COUNT(*) FROM `SNEAKER_ZONE`.`$table`")->fetchColumn();
 }
 $pdo->exec("UPDATE SNEAKER_ZONE.products SET image=CONCAT('assets/images/product/product-',id,'.3.jpg') WHERE id BETWEEN 1 AND 8 AND image=CONCAT('assets/images/product-',id,'.jpg')");
 $pdo->commit();echo "Đã sao chép; nguồn giữ nguyên.\n";foreach($counts as $table=>$n)echo "$table: $n dòng\n";echo "Không tự tạo tài khoản mẫu. Dùng tài khoản cũ hoặc reset_admin.php trong CLI.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Chuyển thất bại: ".$e->getMessage()."\nDatabase nguồn không thay đổi. Database đích có thể đã tạo cấu trúc nhưng chưa có dữ liệu; kiểm tra trước khi thử lại.\n");exit(1);}
