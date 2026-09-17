<?php
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__.'/db.php';
function out($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
function body(){ $raw=file_get_contents('php://input'); $d=json_decode($raw,true); return is_array($d)?$d:$_POST; }
function fail($message,$code=422){throw new RuntimeException($message,$code);}
function query($sql,$args=[]){global $pdo;$st=$pdo->prepare($sql);$st->execute($args);return $st;}
function textField($d,$key,$max,$min=0){$s=trim((string)($d[$key]??''));if(mb_strlen($s)<$min||mb_strlen($s)>$max)fail('Thông tin '.$key.' chưa hợp lệ.');return $s;}
function numberField($d,$key,$min,$max){$v=$d[$key]??null;if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)fail('Giá trị '.$key.' chưa hợp lệ.');return (int)$v;}
function clearAdmin(){unset($_SESSION['admin_id'],$_SESSION['admin_role'],$_SESSION['admin_name'],$_SESSION['admin_signature']);}
function clearUser(){unset($_SESSION['user_id'],$_SESSION['user_signature'],$_SESSION['order_requests']);}
function identity($kind,$lock=false){
 $key=$kind==='admin'?'admin':'user';$table=$key==='admin'?'admins':'users';
 if(empty($_SESSION[$key.'_id']))return null;
 $a=query("SELECT * FROM $table WHERE id=?".($lock?' FOR UPDATE':''),[$_SESSION[$key.'_id']])->fetch();
 if(!$a||(int)$a['status']!==1||!hash_equals($_SESSION[$key.'_signature']??'',hash('sha256',$a['password']))||($key==='admin'&&!in_array($a['role'],['manager','admin'],true))){if($key==='admin')clearAdmin();else clearUser();return null;}
 if($key==='admin'){$_SESSION['admin_role']=$a['role'];$_SESSION['admin_name']=$a['full_name'];}
 return $a;
}
function adminOnly($top=false){$a=identity('admin');if(!$a)fail('Phiên đăng nhập đã hết hạn hoặc tài khoản bị khóa.',401);if($top&&$a['role']!=='admin')fail('Chỉ Quản trị viên được quản lý khách hàng.',403);return $a;}
function userOnly($lock=false){$u=identity('user',$lock);if(!$u)fail('Vui lòng đăng nhập lại. Tài khoản có thể đã bị khóa.',401);return $u;}
function productQuote($items,$code){
 if(!is_array($items)||!count($items)||count($items)>200)fail('Giỏ hàng chưa hợp lệ.');
 $lines=[];$totals=[];
 foreach($items as $i){if(!is_array($i))fail('Dòng hàng chưa hợp lệ.');$id=numberField($i,'id',1,2147483647);$qty=numberField($i,'qty',1,10000);$size=textField($i,'size',10,1);if(!in_array($size,['38','39','40','41','42','43'],true))fail('Size phải từ 38 đến 43.');$key=$id.'-'.$size;if(!isset($lines[$key]))$lines[$key]=['id'=>$id,'size'=>$size,'qty'=>0];$lines[$key]['qty']+=$qty;$totals[$id]=($totals[$id]??0)+$qty;if($totals[$id]>10000)fail('Số lượng vượt giới hạn.');}
 ksort($totals,SORT_NUMERIC);$products=[];$subtotal=0;
 foreach($totals as $id=>$qty){$p=query('SELECT * FROM products WHERE id=? AND status=1 FOR UPDATE',[$id])->fetch();if(!$p)fail('Sản phẩm đã ngừng bán. Hãy cập nhật giỏ hàng.');if((int)$p['stock']<$qty)fail('Sản phẩm '.$p['name'].' không đủ tồn kho cho tổng số lượng các size.');$price=(int)$p['price'];if($price<0)fail('Giá sản phẩm chưa hợp lệ.');$subtotal+=$price*$qty;if($subtotal>999999999999)fail('Tổng tiền vượt giới hạn một đơn.');$products[$id]=$p;}
 $discount=0;$coupon=null;
 if($code!==''){$coupon=query('SELECT * FROM coupons WHERE code=? AND status=1 FOR UPDATE',[$code])->fetch();if(!$coupon)fail('Mã giảm giá không hợp lệ.');if((int)$coupon['max_uses']>0&&(int)$coupon['used_count']>=(int)$coupon['max_uses'])fail('Mã giảm giá đã hết lượt.');$pct=(float)$coupon['discount_percent'];if($pct<=0||$pct>100)fail('Phần trăm giảm chưa hợp lệ.');$discount=(int)round($subtotal*$pct/100);}
 $clean=[];foreach($lines as $i){$p=$products[$i['id']];$clean[]=['id'=>$i['id'],'name'=>$p['name'],'size'=>$i['size'],'qty'=>$i['qty'],'price'=>(int)$p['price']];}
 return ['items'=>$clean,'quantities'=>$totals,'subtotal'=>$subtotal,'discount'=>$discount,'total'=>$subtotal-$discount,'coupon_code'=>$coupon?$coupon['code']:null];
}
$action=$_GET['action']??'';
$_SESSION['csrf']=$_SESSION['csrf']??bin2hex(random_bytes(32));
$writes=['register','user_login','user_logout','login','logout','create_order','save_product','delete_product','restore_product','update_order','toggle_customer','save_coupon','toggle_coupon'];
try{
 if(in_array($action,$writes,true)){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')fail('Thao tác này yêu cầu POST.',405);
  if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Phiên thao tác hết hạn. Hãy tải lại trang.',403);
 }
 if($action==='csrf')out(['success'=>true,'token'=>$_SESSION['csrf']]);
 if($action==='products')out(['success'=>true,'products'=>query('SELECT id,name,category,price,old_price,stock,image,description,badge FROM products WHERE status=1 ORDER BY id')->fetchAll()]);
 if($action==='register'){
  $d=body();$name=textField($d,'name',120,2);$email=strtolower(textField($d,'email',150,3));$password=(string)($d['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<6||strlen($password)>72)fail('Email chưa hợp lệ hoặc mật khẩu không nằm trong 6–72 byte.');
  if(query('SELECT id FROM users WHERE email=?',[$email])->fetch())fail('Email đã được đăng ký.',409);
  $hash=password_hash($password,PASSWORD_DEFAULT);query('INSERT INTO users(full_name,email,password) VALUES(?,?,?)',[$name,$email,$hash]);$id=(int)$pdo->lastInsertId();clearUser();session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['user_signature']=hash('sha256',$hash);out(['success'=>true,'user'=>['id'=>$id,'name'=>$name,'email'=>$email]]);
 }
 if($action==='user_login'||$action==='login'){
  $d=body();$internal=$action==='login';$table=$internal?'admins':'users';$col=$internal?'username':'email';$login=trim((string)($d[$col]??''));if(!$internal)$login=strtolower($login);
  $a=query("SELECT * FROM $table WHERE $col=? AND status=1",[$login])->fetch();
  if(!$a||!password_verify((string)($d['password']??''),$a['password']))fail('Sai tài khoản hoặc mật khẩu.',401);
  if($internal&&!in_array($a['role'],['manager','admin'],true))fail('Vai trò không hợp lệ; cần cập nhật database.',403);
  session_regenerate_id(true);
  if($internal){clearAdmin();$_SESSION['admin_id']=(int)$a['id'];$_SESSION['admin_signature']=hash('sha256',$a['password']);$_SESSION['admin_role']=$a['role'];out(['success'=>true,'role'=>$a['role']]);}
  clearUser();$_SESSION['user_id']=(int)$a['id'];$_SESSION['user_signature']=hash('sha256',$a['password']);out(['success'=>true,'user'=>['id'=>$a['id'],'name'=>$a['full_name'],'email'=>$a['email']]]);
 }
 if($action==='logout'){clearAdmin();out(['success'=>true]);}
 if($action==='user_logout'){clearUser();out(['success'=>true]);}
 if($action==='me'){$a=identity('admin');out(['success'=>true,'loggedIn'=>(bool)$a,'name'=>$a?$a['full_name']:'','role'=>$a?$a['role']:'']);}
 if($action==='user_me'){$u=identity('user');if($u)unset($u['password']);out(['success'=>true,'loggedIn'=>(bool)$u,'user'=>$u?:null]);}
 if($action==='user_orders'){$u=userOnly();out(['success'=>true,'orders'=>query('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC',[$u['id']])->fetchAll()]);}
 if($action==='quote_order'||$action==='create_order'){
  $u=userOnly();$d=body();$code=strtoupper(textField($d,'coupon_code',30));
  $requestKey='';$fingerprint='';
  if($action==='create_order'){
   $name=textField($d,'name',120,2);$phone=preg_replace('/[^0-9+]/','',textField($d,'phone',30,9));if(!preg_match('/^\+?[0-9]{9,15}$/',$phone))fail('Số điện thoại chưa hợp lệ.');$address=textField($d,'address',255,5);
   $requestKey=textField($d,'request_id',80,16);$fingerprint=hash('sha256',json_encode($d));
   if(isset($_SESSION['order_requests'][$requestKey])){$old=$_SESSION['order_requests'][$requestKey];if($old['fingerprint']!==$fingerprint)fail('Mã yêu cầu đã dùng cho nội dung khác.',409);out($old['result']);}
  }
  $pdo->beginTransaction();$u=userOnly(true);$q=productQuote($d['items']??[], $code);
  if($action==='quote_order'){$pdo->commit();unset($q['quantities']);out(['success'=>true,'quote'=>$q]);}
  $expected=numberField($d,'expected_total',0,999999999999);if($expected!==$q['total'])fail('Giá hoặc mã giảm giá đã thay đổi. Hãy tính lại tổng tiền trước khi xác nhận.',409);
  query('INSERT INTO orders(user_id,customer_name,phone,address,subtotal,discount,coupon_code,total) VALUES(?,?,?,?,?,?,?,?)',[$u['id'],$name,$phone,$address,$q['subtotal'],$q['discount'],$q['coupon_code'],$q['total']]);$oid=(int)$pdo->lastInsertId();
  foreach($q['items'] as $i)query('INSERT INTO order_items(order_id,product_id,product_name,size,quantity,price) VALUES(?,?,?,?,?,?)',[$oid,$i['id'],$i['name'],$i['size'],$i['qty'],$i['price']]);
  foreach($q['quantities'] as $id=>$qty){$st=query('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?',[$qty,$id,$qty]);if($st->rowCount()!==1)fail('Tồn kho đã thay đổi.');}
  query('UPDATE users SET full_name=?,phone=?,address=? WHERE id=?',[$name,$phone,$address,$u['id']]);if($q['coupon_code'])query('UPDATE coupons SET used_count=used_count+1 WHERE code=?',[$q['coupon_code']]);
  $pdo->commit();$result=['success'=>true,'order_id'=>$oid,'total'=>$q['total'],'discount'=>$q['discount']];$_SESSION['order_requests'][$requestKey]=['fingerprint'=>$fingerprint,'result'=>$result];if(count($_SESSION['order_requests'])>100)array_shift($_SESSION['order_requests']);out($result);
 }
 // Every internal request revalidates current role, account status and password.
 $admin=adminOnly(in_array($action,['customers','toggle_customer'],true));
 if($action==='stats'){
  $s=query("SELECT (SELECT COUNT(*) FROM products WHERE status=1) products,(SELECT COALESCE(SUM(stock),0) FROM products WHERE status=1) stock,(SELECT COUNT(*) FROM orders) orders,(SELECT COUNT(*) FROM users WHERE status=1) customers,(SELECT COUNT(*) FROM orders WHERE status='pending') pending,(SELECT COUNT(*) FROM products WHERE status=1 AND stock<=5) low_stock,(SELECT COALESCE(SUM(total),0) FROM orders WHERE status='completed') revenue,(SELECT COALESCE(SUM(total),0) FROM orders WHERE status='completed' AND DATE(created_at)=CURDATE()) today_revenue")->fetch();out(['success'=>true,'stats'=>$s]);
 }
 if($action==='revenue_chart')out(['success'=>true,'data'=>query("SELECT DATE(created_at) day,COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE 0 END),0) revenue,COUNT(*) orders FROM orders WHERE created_at>=CURDATE()-INTERVAL 6 DAY GROUP BY DATE(created_at) ORDER BY day")->fetchAll()]);
 if($action==='orders')out(['success'=>true,'orders'=>query('SELECT o.*,u.email FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC')->fetchAll()]);
 if($action==='order_detail'){$id=(int)($_GET['id']??0);$o=query('SELECT o.*,u.email FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?',[$id])->fetch();if(!$o)fail('Không tìm thấy đơn.',404);out(['success'=>true,'order'=>$o,'items'=>query('SELECT * FROM order_items WHERE order_id=?',[$id])->fetchAll()]);}
 if($action==='products_admin')out(['success'=>true,'products'=>query('SELECT * FROM products ORDER BY id DESC')->fetchAll()]);
 if($action==='save_product'){
  $d=body();$id=(int)($d['id']??0);$name=textField($d,'name',150,1);$cat=textField($d,'category',80,1);$price=numberField($d,'price',0,999999999999);$old=numberField($d,'old_price',0,999999999999);$stock=numberField($d,'stock',0,2147483647);$img=textField($d,'image',255);$desc=textField($d,'description',16000);$badge=textField($d,'badge',30);
  if($img!==''&&!preg_match('~^(https?://|assets/images/)[^<>"\x27\s]+$~i',$img))fail('Ảnh phải là URL http(s) hoặc đường dẫn assets/images/.');
  if($id){if(!query('SELECT id FROM products WHERE id=?',[$id])->fetch())fail('Không tìm thấy sản phẩm.',404);query('UPDATE products SET name=?,category=?,price=?,old_price=?,stock=?,image=?,description=?,badge=? WHERE id=?',[$name,$cat,$price,$old,$stock,$img,$desc,$badge,$id]);}
  else{query('INSERT INTO products(name,category,price,old_price,stock,image,description,badge) VALUES(?,?,?,?,?,?,?,?)',[$name,$cat,$price,$old,$stock,$img,$desc,$badge]);$id=(int)$pdo->lastInsertId();}out(['success'=>true,'id'=>$id]);
 }
 if($action==='delete_product'||$action==='restore_product'){query('UPDATE products SET status=? WHERE id=?',[$action==='restore_product'?1:0,(int)($_GET['id']??0)]);out(['success'=>true]);}
 if($action==='update_order'){
  $d=body();$id=(int)($d['id']??0);$next=$d['status']??'';$transitions=['pending'=>['confirmed','cancelled'],'confirmed'=>['shipping','cancelled'],'shipping'=>['completed','cancelled'],'completed'=>[],'cancelled'=>[]];
  $pdo->beginTransaction();$o=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();if(!$o)fail('Không tìm thấy đơn.',404);
  if($next===$o['status']){$pdo->commit();out(['success'=>true]);}
  if(!in_array($next,$transitions[$o['status']]??[],true))fail('Không được chuyển trạng thái này. Đơn hoàn thành hoặc đã hủy không thể mở lại.',409);
  if($next==='cancelled'){
   $items=query('SELECT product_id,SUM(quantity) quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id',[$id])->fetchAll();
   foreach($items as $i)query('UPDATE products SET stock=stock+? WHERE id=?',[$i['quantity'],$i['product_id']]);
   if($o['coupon_code'])query('UPDATE coupons SET used_count=GREATEST(0,used_count-1) WHERE code=?',[$o['coupon_code']]);
  }
  query('UPDATE orders SET status=? WHERE id=?',[$next,$id]);$pdo->commit();out(['success'=>true]);
 }
 if($action==='customers')out(['success'=>true,'customers'=>query("SELECT u.id,u.full_name,u.email,u.phone,u.address,u.created_at,u.status,COALESCE(o.orders,0) orders,COALESCE(o.spent,0) spent FROM users u LEFT JOIN (SELECT user_id,COUNT(*) orders,SUM(CASE WHEN status='completed' THEN total ELSE 0 END) spent FROM orders GROUP BY user_id) o ON o.user_id=u.id ORDER BY u.id DESC")->fetchAll()]);
 if($action==='toggle_customer'){query('UPDATE users SET status=IF(status=1,0,1) WHERE id=?',[(int)($_GET['id']??0)]);out(['success'=>true]);}
 if($action==='coupons')out(['success'=>true,'coupons'=>query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll()]);
 if($action==='save_coupon'){
  $d=body();$id=(int)($d['id']??0);$code=strtoupper(textField($d,'code',30,3));$discount=$d['discount_percent']??0;$max=numberField($d,'max_uses',0,2147483647);
  if(!preg_match('/^[A-Z0-9_-]{3,30}$/',$code)||!is_numeric($discount)||(float)$discount<0.01||(float)$discount>100)fail('Mã hoặc phần trăm giảm chưa hợp lệ.');
  $pdo->beginTransaction();
  if($id){$c=query('SELECT * FROM coupons WHERE id=? FOR UPDATE',[$id])->fetch();if(!$c)fail('Không tìm thấy mã.',404);if($c['code']!==$code)fail('Không đổi chuỗi mã của voucher đã tạo; hãy tắt mã cũ và tạo mã mới.');if($max>0&&$max<(int)$c['used_count'])fail('Giới hạn không được nhỏ hơn lượt đã dùng.');query('UPDATE coupons SET discount_percent=?,max_uses=? WHERE id=?',[$discount,$max,$id]);}
  else query('INSERT INTO coupons(code,discount_percent,max_uses) VALUES(?,?,?)',[$code,$discount,$max]);$pdo->commit();out(['success'=>true]);
 }
 if($action==='toggle_coupon'){query('UPDATE coupons SET status=IF(status=1,0,1) WHERE id=?',[(int)($_GET['id']??0)]);out(['success'=>true]);}
 fail('Chức năng không tồn tại.',404);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 if($e instanceof PDOException){error_log((string)$e);out(['success'=>false,'message'=>$e->getCode()==='23000'?'Dữ liệu trùng hoặc vi phạm ràng buộc.':'Không thể xử lý dữ liệu. Hãy kiểm tra cấu trúc database và thử lại.'],422);}
 $code=(int)$e->getCode();out(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Không thể xử lý yêu cầu.'],in_array($code,[401,403,404,405,409,422],true)?$code:422);
}
