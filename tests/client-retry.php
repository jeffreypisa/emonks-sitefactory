<?php
/** Run after online-integration.php in the same disposable network; no real HTTP. */
use Emonks\Factory\{Factory,Package};
use Emonks\Factory\Online\{Client,Store,Admin,Accounts};
if (getenv('EMSF_DISPOSABLE_TEST') !== 'yes' || !str_contains(ABSPATH,'emsf-integration')) { throw new RuntimeException('Disposable test only.'); }
define('EMSF_LOCAL',true);
define('EMSF_REMOTE_URL','https://receiver.example.invalid');
define('EMSF_REMOTE_USER','test-user');
define('EMSF_REMOTE_PASSWORD','test-application-password');
wp_set_current_user(1);register_post_type(Factory::TYPE,['public'=>false,'supports'=>['title','editor']]);
$p=Factory::save(['slug'=>'client-fixture','name'=>'Client fixture']);
$site=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/client-fixture/','user_id'=>1,'title'=>'Client fixture']);
$data=get_post_meta($p['id'],'_emsf',true);$data['site_id']=$site;update_post_meta($p['id'],'_emsf',$data);
switch_to_blog($site);switch_theme('skeletor');update_option('emsf_slug',$p['slug']);update_option('emsf_uuid',$p['uuid']);$page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'First version','post_name'=>'first']);restore_current_blog();
$attempt=0;$digest=null;$checks=0;
$transport=static function($pre,$args,$url) use (&$attempt,&$digest,&$checks,$p) {
    if (!str_starts_with($url,'https://receiver.example.invalid/wp-json/emsf/v1/')) { throw new RuntimeException('Unexpected destination.'); }
    if (($args['redirection']??null)!==0 || ($args['sslverify']??null)!==true || empty($args['headers']['Authorization'])) { throw new RuntimeException('Transport security settings missing.'); }
    $checks++;
    $route=basename((string)parse_url($url,PHP_URL_PATH));$body=json_decode($args['body']??'{}',true);
    $result=[];
    if ($route==='status') { $result=['protocol'=>2,'optional_preview'=>true,'publication_check'=>true,'link'=>[],'operation'=>[]]; }
    elseif ($route==='check') { $result=['ok'=>true,'issues'=>[],'changes'=>[]]; }
    elseif ($route==='prepare') {
        $current=hash('sha256',wp_json_encode($body['manifest']));
        if ($digest!==null && $digest!==$current) { throw new RuntimeException('Retry changed manifest.'); }
        $expected=['name'=>'Test Customer','username'=>'test_customer','email'=>'test-customer@example.invalid','confirmed'=>true,'layout_access'=>false];if(Accounts::validate($body['customer']??[])!==$expected){throw new RuntimeException('Form-to-client-to-receiver customer mapping changed');}
        $digest=$current;$result=['status'=>$attempt?'complete':'receiving'];
    } elseif ($route==='execute') {
        $attempt++;
        if ($attempt===1) { return new WP_Error('timeout','Simulated lost response after remote commit'); }
        $result=['link'=>['site'=>123,'source'=>$p['uuid'],'url'=>'https://receiver.example.invalid/client-fixture/','name'=>'Client fixture'],'operation'=>['status'=>'complete','id'=>$body['operation']]];
    } elseif ($route==='asset') { $result=['stored'=>true]; }
    else { throw new RuntimeException('Unexpected route.'); }
    return ['headers'=>[],'body'=>wp_json_encode($result),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
};
add_filter('pre_http_request',$transport,PHP_INT_MAX,3);
try { Admin::run((int)$site,['site_action'=>'create','scope'=>'online','customer_name'=>'Test Customer','customer_username'=>'Test_Customer','customer_email'=>'test-customer@example.invalid','customer_confirmed'=>'1']);throw new RuntimeException('Expected simulated timeout'); } catch(RuntimeException $e) { if(!str_contains($e->getMessage(),'Verbinding onderbroken')) { throw $e; } }
$pending=Store::read('pending-'.$p['uuid']);if(empty($pending['operation'])){throw new RuntimeException('Lost response did not preserve operation');}
switch_to_blog($site);wp_update_post(['ID'=>$page,'post_title'=>'Changed while connection lost']);restore_current_blog();
$result=Admin::run((int)$site,['site_action'=>'create','scope'=>'online','customer_name'=>'Test Customer','customer_username'=>'Test_Customer','customer_email'=>'test-customer@example.invalid','customer_confirmed'=>'1']);
if ($result['link']['site']!==123 || Store::read('pending-'.$p['uuid'])!==[] || $attempt!==2) { throw new RuntimeException('Retry did not reconcile committed operation'); }
$bound=Store::read('remote-'.$p['uuid']);$bound['endpoint']='https://another.example.invalid';Store::write('remote-'.$p['uuid'],$bound);
try { Client::status($p['slug']);throw new RuntimeException('Expected endpoint guard'); } catch(RuntimeException $e) { if(!str_contains($e->getMessage(),'ander online netwerk')){throw $e;} }
echo "PASS: lost-response retry reuses the original operation and manifest, confirms the same site, clears pending journal, and guards endpoint binding.\n";
echo "PASS: $checks intercepted requests enforce fixed HTTPS destination, redirect refusal, TLS verification and credentials. No real HTTP requests.\n";

$bound['endpoint']=EMSF_REMOTE_URL;Store::write('remote-'.$p['uuid'],$bound);
try{Client::manage($p['slug'],'preview_generate','Client fixture');throw new RuntimeException('Expected upgrade guard');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'0.5.7')){throw $e;}}
remove_filter('pre_http_request',$transport,PHP_INT_MAX);
add_filter('pre_http_request',static function($pre,$args,$url)use($bound){
 $status=['protocol'=>4,'persistent_preview'=>true,'link'=>$bound];
 if(basename(parse_url($url,PHP_URL_PATH))==='manage'){$status['preview']=['enabled'=>true,'code'=>'TEST','url'=>$bound['url']];}
 return ['headers'=>[],'body'=>wp_json_encode($status),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
},PHP_INT_MAX,3);
$access=Client::manage($p['slug'],'preview_generate','Client fixture');
if(($access['preview']['code']??'')!=='TEST'||isset(Store::read('remote-'.$p['uuid'])['preview'])){throw new RuntimeException('Preview response/cache handling failed');}
foreach(glob(Store::root().'/*.json')as$file){if(str_contains(file_get_contents($file),'"code": "TEST"')){throw new RuntimeException('Preview code leaked to journal');}}
echo "PASS: old receiver upgrade guard and response-only preview code, absent from local journals.\n";

// An old pending package is never silently rewritten or uploaded after a path-policy failure.
$oldDir=Store::root().'/out-'.$pending['operation'];$oldManifest=json_decode(file_get_contents($oldDir.'/manifest.json'),true);$badPath='media/Scherm'.chr(194).chr(173).'afbeelding.jpg';wp_mkdir_p($oldDir.'/files/media');file_put_contents($oldDir.'/files/'.$badPath,'synthetic-image');$oldManifest['files'][$badPath]=['size'=>15,'sha256'=>hash('sha256','synthetic-image')];Package::json($oldDir.'/manifest.json',$oldManifest);Store::write('pending-'.$p['uuid'],$pending);$before=hash_file('sha256',$oldDir.'/manifest.json');
try{Admin::run((int)$site,['site_action'=>'create','scope'=>'online','customer_name'=>'Test Customer','customer_username'=>'Test_Customer','customer_email'=>'test-customer@example.invalid','customer_confirmed'=>'1']);throw new LogicException('Invalid pending path accepted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'openstaande pakket')){throw $e;}}
if(hash_file('sha256',$oldDir.'/manifest.json')!==$before||Store::read('pending-'.$p['uuid'])!==$pending){throw new RuntimeException('Pending state changed during path rejection');}
echo "PASS: old Unicode pending package rejected before upload with explicit recovery guidance; manifest and operation unchanged.\n";
