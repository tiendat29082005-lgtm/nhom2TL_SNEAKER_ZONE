<?php
// Local maintenance only. Opening this URL NEVER changes an account.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Công cụ bảo trì chỉ chạy trong cửa sổ lệnh trên máy chủ.');}
require __DIR__.'/db.php';
function ask($label){echo $label;return trim(fgets(STDIN)?:'');}
$username=ask('Tên tài khoản nhân sự cần tạo/đặt lại: ');
$role=ask('Vai trò (manager hoặc admin): ');
$name=ask('Họ và tên: ');
$password=ask('Mật khẩu mới (6–72 byte; ký tự nhập sẽ hiện trên màn hình): ');
if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username)||!in_array($role,['manager','admin'],true)||mb_strlen($name)<2||mb_strlen($name)>100||strlen($password)<6||strlen($password)>72){fwrite(STDERR,"Thông tin không hợp lệ. Không thay đổi dữ liệu.\n");exit(1);}
if(ask('Nhập XACNHAN để tạo/đặt lại tài khoản này: ')!=='XACNHAN')exit("Đã hủy.\n");
$hash=password_hash($password,PASSWORD_DEFAULT);
$st=$pdo->prepare('INSERT INTO admins(username,password,full_name,role,status) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE password=VALUES(password),full_name=VALUES(full_name),role=VALUES(role),status=1');$st->execute([$username,$hash,$name,$role]);echo "Đã cập nhật tài khoản. Phiên sử dụng mật khẩu cũ sẽ bị từ chối.\n";
