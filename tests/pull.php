<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only');}
define('EMSF_LOCAL',true);define('EMSF_REMOTE_URL','https://receiver.example.invalid');define('EMSF_REMOTE_USER','test');define('EMSF_REMOTE_PASSWORD','test');
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\{Factory,Package};
use Emonks\Factory\Online\{Pull,Receiver,Store,Mail,Client,Admin};
wp_set_current_user(1);$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
$req=static function(array $data){$r=new WP_REST_Request('POST');$r->set_body(wp_json_encode($data));$r->set_header('Content-Type','application/json');return $r;};
register_post_type(Factory::TYPE,['public'=>false]);$slug='pull-test-'.substr(wp_generate_uuid4(),0,8);$p=Factory::save(['slug'=>$slug,'name'=>'Pull test']);
$ids=[];foreach(['local','remote']as$which){$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/'.$slug.'-'.$which.'/','title'=>'Pull test','user_id'=>1]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$ids[$which]=(int)$id;switch_to_blog((int)$id);switch_theme('skeletor');update_option('emsf_uuid',$p['uuid']);update_option('blogname','Pull test');wp_insert_post(['post_type'=>'page','post_title'=>$which.' title','post_status'=>'publish']);restore_current_blog();}
$record=get_post_meta($p['id'],'_emsf',true);$record['site_id']=$ids['local'];update_post_meta($p['id'],'_emsf',$record);
update_site_meta($ids['remote'],'emsf_managed',1);update_site_meta($ids['remote'],'emsf_source',$p['uuid']);update_site_meta($ids['remote'],'emsf_state','ready');
Store::write('link-'.$p['uuid'],['site'=>$ids['remote'],'source'=>$p['uuid'],'name'=>'Pull test','state'=>'ready']);
// Include actual bytes and an attachment to exercise the reverse media transfer.
switch_to_blog($ids['remote']);$upload=wp_upload_dir();wp_mkdir_p($upload['path']);$file=$upload['path'].'/fixture.png';file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));$attachment=wp_insert_attachment(['post_title'=>'Pixel','post_mime_type'=>'image/png','post_status'=>'inherit'],$file);update_attached_file($attachment,$file);restore_current_blog();
$mode='ok';$calls=[];
add_filter('pre_http_request',static function($pre,$args,$url)use($req,&$mode,&$calls){
    if(!str_starts_with($url,EMSF_REMOTE_URL.'/wp-json/emsf/v1/')){return $pre;}
    $route=basename(parse_url($url,PHP_URL_PATH));$calls[]=$route;
    if($mode==='offline'){return new WP_Error('offline','offline');}
    if($args['method']==='GET'){parse_str(parse_url($url,PHP_URL_QUERY),$data);}else{$data=json_decode($args['body'],true);}
    $result=Receiver::$route($req($data));
    if($mode==='corrupt'&&$route==='download_asset'){$result['bytes']=base64_encode('bad');}
    return ['headers'=>[],'body'=>wp_json_encode($result),'response'=>['code'=>200],'cookies'=>[]];
},PHP_INT_MAX,3);
try{Pull::run($slug,'wrong');$check(false,'wrong name');}catch(RuntimeException $e){$check(!$calls,'wrong confirmation sends no request');}
$mode='corrupt';try{Pull::run($slug,'Pull test');$check(false,'corrupt media');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Downloadcontrole'),'corrupt media rejected');}
switch_to_blog($ids['local']);$check((bool)get_page_by_path('local-title'),'failed download preserves local page');restore_current_blog();
$mode='ok';$result=Pull::run($slug,'Pull test');$check(!empty($result['local_backup']),'backup made before replacement');
switch_to_blog($ids['local']);$check((bool)get_page_by_path('remote-title')&&!get_page_by_path('local-title'),'online page replaces local page');$check(home_url()!==get_home_url($ids['remote']),'local URL retained');$check(Mail::off(),'local mail remains off');$media=get_posts(['post_type'=>'attachment','numberposts'=>1]);$check($media&&is_file(get_attached_file($media[0]->ID)),'media imported');restore_current_blog();
Pull::restore($slug,'Pull test',$result['local_backup']);switch_to_blog($ids['local']);$check((bool)get_page_by_path('local-title'),'local restore recovers original page');restore_current_blog();
$failure=static fn($empty,$post)=>get_current_blog_id()===$ids['local']&&($post['post_title']??'')==='remote title'?true:$empty;
add_filter('wp_insert_post_empty_content',$failure,10,2);
try{Pull::run($slug,'Pull test');$check(false,'failed import');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'herstelkopie is teruggezet'),'failed import automatically restores');}
remove_filter('wp_insert_post_empty_content',$failure,10);
switch_to_blog($ids['local']);$check((bool)get_page_by_path('local-title'),'original page survives failed application');restore_current_blog();
$download=Receiver::download($req(['source'=>$p['uuid']]));
try{Receiver::download_asset($req(['source'=>$p['uuid'],'download'=>wp_generate_uuid4(),'name'=>'media/x.png']));$check(false,'wrong download');}catch(RuntimeException $e){$check(true,'unrelated download rejected');}
Store::write('download-'.$p['uuid'],['id'=>$download['id'],'expires'=>time()-1]);
try{Receiver::download_asset($req(['source'=>$p['uuid'],'download'=>$download['id'],'name'=>'media/x.png']));$check(false,'expired download');}catch(RuntimeException $e){$check(true,'expired download rejected');}
$cached=Store::read('status-'.$p['uuid']);$mode='offline';try{Client::status($slug);}catch(RuntimeException $e){}$after=Store::read('status-'.$p['uuid']);$check($after['checked_at']===$cached['checked_at']&&!empty($after['error']),'offline preserves last successful check');
$check(Mail::cronRequest(['method'=>'POST'],site_url('wp-cron.php')),'own cron allowed');$check(!Mail::cronRequest(['method'=>'POST'],'https://elsewhere.invalid/wp-cron.php'),'external cron refused');$check(!Mail::cronRequest(['method'=>'POST'],site_url('wp-admin/admin-ajax.php')),'other integration path refused');
$_GET=[];ob_start();Admin::page();$html=ob_get_clean();$check(str_contains($html,'data-refresh-all')&&!str_contains($html,'<th>Aangemaakt</th>'),'compact overview and refresh control');
file_put_contents('/tmp/emsf-overview.html','<!doctype html><meta charset="utf-8"><style>body{font:14px system-ui;background:#f0f0f1;color:#222}.button{display:inline-block;padding:9px 14px;border:1px solid #bbc;text-decoration:none;border-radius:4px}table{width:100%;border-collapse:collapse}a{color:#2271b1}input,select{padding:8px}'.file_get_contents(dirname(__DIR__).'/assets/admin.css').'</style>'.$html);
echo "$n checks passed\n";
