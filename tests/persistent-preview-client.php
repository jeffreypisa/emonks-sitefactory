<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only');}
define('EMSF_LOCAL',true);define('EMSF_REMOTE_URL','https://receiver.example.invalid');define('EMSF_REMOTE_USER','test');define('EMSF_REMOTE_PASSWORD','test');require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Factory;
use Emonks\Factory\Online\{Client,Store};
wp_set_current_user(1);register_post_type(Factory::TYPE,['public'=>false]);$p=Factory::save(['slug'=>'preview-client-'.substr(wp_generate_uuid4(),0,8),'name'=>'Preview client fixture']);$supported=false;$requests=0;
add_filter('pre_http_request',static function($pre,$args,$url)use(&$supported,&$requests,$p){
 if(!str_starts_with($url,EMSF_REMOTE_URL.'/wp-json/emsf/v1/')){throw new RuntimeException('Unexpected transport');}
 $result=['protocol'=>4,'persistent_preview'=>$supported,'link'=>['site'=>123,'source'=>$p['uuid'],'name'=>'Fixture','url'=>EMSF_REMOTE_URL.'/fixture/','state'=>'ready']];
 if(basename(parse_url($url,PHP_URL_PATH))==='manage'){$requests++;$body=json_decode($args['body'],true);if(($body['source']??'')!==$p['uuid']||($body['action']??'')!=='preview_get'||($body['confirm']??'')!==''){throw new RuntimeException('Incorrect retrieval payload');}$result['preview']=['enabled'=>true,'code'=>'T2ST','url'=>$result['link']['url']];}
 return ['headers'=>[],'body'=>wp_json_encode($result),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
},PHP_INT_MAX,3);
try{Client::manage($p['slug'],'preview_get','');throw new LogicException('Old server accepted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'0.5.7')||$requests!==0){throw $e;}}echo "PASS: Old server requires upgrade before any code action\n";
$supported=true;for($i=0;$i<2;$i++){$result=Client::manage($p['slug'],'preview_get','');if($result['preview']['code']!=='T2ST'){throw new RuntimeException('Retrieval failed');}}
if($requests!==2||str_contains(wp_json_encode(Store::read('remote-'.$p['uuid'])),'T2ST')||str_contains(wp_json_encode(Store::read('status-'.$p['uuid'])),'T2ST')){throw new RuntimeException('Code leaked into status cache');}echo "PASS: Repeated retrieval bound to selected site, no typing, no code in journals\n";
