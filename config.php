<?php
// XAMPP local defaults. Environment overrides are optional.
return ['host'=>getenv('SNEAKER_DB_HOST')?:'localhost','port'=>getenv('SNEAKER_DB_PORT')?:'3306','database'=>'SNEAKER_ZONE','user'=>getenv('SNEAKER_DB_USER')?:'root','password'=>getenv('SNEAKER_DB_PASSWORD')?:''];
