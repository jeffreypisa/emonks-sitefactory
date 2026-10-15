<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only');}
use Emonks\Factory\{Factory,Package};
use Emonks\Factory\Online\{Client,Store,Admin};
define('EMSF_LOCAL',true);define('EMSF_REMOTE_URL','https://receiver.example.invalid');define('EMSF_REMOTE_USER','test');define('EMSF_REMOTE_PASSWORD','test');
wp_set_current_user(1);register_post_type(Factory::TYPE,['public'=>false]);$slug='optional-client-'.substr(wp_generate_uuid4(),0,8);$p=Factory::save(['slug'=>$slug,'name'=>'Optional Client']);
$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/'.$slug.'/','user_id'=>1,'title'=>'Optional Client']);$record=get_post_meta($p['id'],'_emsf',true);$record['site_id']=$id;update_post_meta($p['id'],'_emsf',$record);switch_to_blog($id);switch_theme('skeletor');update_option('emsf_slug',$slug);update_option('emsf_uuid',$p['uuid']);restore_current_blog();
$new=false;$calls=[];$attempt=0;$operation='';$manifest='';$owner=['id'=>55,'name'=>'Current','email'=>'current@example.invalid'];
add_filter('pre_http_request',static function($pre,$args,$url)use(&$new,&$calls,&$attempt,&$operation,&$manifest,&$owner,$p){
 $route=basename(parse_url($url,PHP_URL_PATH));$calls[]=$route;$body=json_decode($args['body']??'{}',true);$result=[];
 if($route==='status'){$result=['protocol'=>4,'optional_preview'=>true,'publication_check'=>true,'optional_customer'=>$new,'customer_management'=>$new];}
 elseif($route==='check'){$result=['ok'=>true,'issues'=>[],'changes'=>[]];}
 elseif($route==='prepare'){
  if(($body['preview_mode']??'')!=='public'){throw new RuntimeException('New publication must default to public');}
  if(($body['customer']??null)!==[]){throw new RuntimeException('Unexpected customer data');}
  $hash=hash('sha256',wp_json_encode($body['manifest']));if($operation!==''&&($operation!==$body['operation']||$manifest!==$hash)){throw new RuntimeException('Retry changed package');}$operation=$body['operation'];$manifest=$hash;$result=['status'=>'receiving'];
 }elseif($route==='asset'){$result=['stored'=>true];}
 elseif($route==='execute'){if(++$attempt===1){return new WP_Error('timeout','Lost response');}$result=['link'=>['site'=>99,'name'=>'Optional Client','url'=>'https://receiver.example.invalid/demo/'],'customer'=>[],'operation'=>['status'=>'complete','id'=>$body['operation']]];}
 elseif($route==='manage'){
  if($body['action']==='customer_set'&&($body['customer_id']!==55||$body['expected_customer_id']!==0)){throw new RuntimeException('Missing assignment fields');}
  if($body['action']==='user_create'&&(($body['customer']['username']??'')!=='newuser'||empty($body['assign_customer']))){throw new RuntimeException('Missing creation fields');}
  $result=['link'=>['site'=>99,'name'=>'Optional Client','url'=>'https://receiver.example.invalid/demo/'],'customer'=>$owner,'users'=>[],'customer_id'=>55,'users_saved'=>true];
 }else{throw new RuntimeException('Unexpected route');}
 return ['headers'=>[],'body'=>wp_json_encode($result),'response'=>['code'=>200],'cookies'=>[]];
},PHP_INT_MAX,3);
$run=static fn($data)=>Admin::run((int)$id,['scope'=>'online']+$data);
try{$run(['site_action'=>'create','customer_mode'=>'none']);throw new LogicException('Old receiver accepted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'0.8.0')||$calls!==['status']){throw $e;}}echo "PASS: Old receiver blocked before account-free upload\n";
$new=true;try{$run(['site_action'=>'create','customer_mode'=>'none','customer_email'=>'ignored@example.invalid']);throw new LogicException('Timeout expected');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'Verbinding onderbroken')){throw $e;}}
$pending=Store::read('pending-'.$p['uuid']);if(($pending['customer']??null)!==[]){throw new RuntimeException('Optional choice not persisted');}
$_GET=['id'=>$id,'tab'=>'publicatie'];ob_start();Admin::page();$html=ob_get_clean();if(!preg_match('/value="none"[^>]*selected/',$html)){throw new RuntimeException('Retry lost no-customer selection');}echo "PASS: Lost response retains no-customer choice in retry form\n";
try{$run(['site_action'=>'create','customer_mode'=>'none','preview_protected'=>1]);throw new LogicException('Changed access accepted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'toegangskeuze')){throw $e;}}echo "PASS: Retry rejects a changed protection choice\n";
$run(['site_action'=>'create','customer_mode'=>'none']);if(Store::read('pending-'.$p['uuid'])!==[]||$attempt!==2){throw new RuntimeException('Retry failed');}echo "PASS: Account-free retry keeps identical operation and package\n";
$run(['site_action'=>'customer_set','customer_id'=>55,'expected_customer_id'=>0]);if(Store::read('remote-'.$p['uuid'])['customer']['id']!==55){throw new RuntimeException('Stale local owner cache');}echo "PASS: Remote assignment payload and local customer snapshot updated\n";
$run(['site_action'=>'user_create','customer_name'=>'New','customer_username'=>'newuser','customer_email'=>'new@example.invalid','customer_confirmed'=>1,'expected_customer_id'=>55,'assign_customer'=>1]);echo "PASS: Remote account creation forwards validated form fields\n";
