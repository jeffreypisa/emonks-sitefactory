<?php
$_SERVER['HTTPS']='on';
$_SERVER['SERVER_PORT']=443;
$root='/private/tmp/emsf-integration/wordpress';
if(str_starts_with($_SERVER['HTTP_HOST']??'','127.0.0.1')){$_SERVER['HTTP_HOST']='online.example.test';ob_start(static fn($html)=>str_replace('https://online.example.test','http://127.0.0.1:18765',$html));}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/customer-form.html'){header('Content-Type: text/html');readfile('/private/tmp/emsf-integration/customer-form.html');return;}
if(in_array($path,['/assets/admin.css','/assets/admin.js'],true)){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'application/javascript'));readfile($root.'/wp-content/plugins/emonks-site-factory'.$path);return;}
if(str_ends_with($path,'/wp-content/plugins/emonks-site-factory/assets/preview.css')){header('Content-Type: text/css');readfile($root.'/wp-content/plugins/emonks-site-factory/assets/preview.css');return;}
if (str_ends_with($path,'wp-login.php')) { $_SERVER['SCRIPT_NAME']='/wp-login.php'; require $root.'/wp-login.php'; }
else { $_SERVER['SCRIPT_NAME']='/index.php'; require $root.'/index.php'; }
