<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Online\{Preview,Admin,Store,Receiver,Privacy};
wp_set_current_user(1);$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REMOTE_ADDR']='192.0.2.43';$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$label);}++$checks;echo "PASS: $label\n";};
$source=wp_generate_uuid4();$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/fixed-'.substr($source,0,8).'/','network_id'=>get_current_network_id(),'user_id'=>1,'title'=>'Preview fixture','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>$source,'emsf_mail_mode'=>'off']]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$id=(int)$id;Store::write('link-'.$source,['site'=>$id,'source'=>$source]);
$run=static fn($action)=>Admin::run($id,['scope'=>'online','site_action'=>$action]);
$check(empty($run('preview_get')['preview']['enabled']),'Reading inactive access does not create a code');
$r=$run('preview_generate')['preview'];$record=get_site_meta($id,'emsf_preview',true);
$check((bool)preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/D',$r['code']),'One four-character code generated without typing a name');
$check($r['url']===get_home_url($id,'/')&&!str_contains($r['url'],'?'),'Ordinary site URL without secret parameters');
$check(!isset($record['code'])&&!str_contains(json_encode($record),$r['code'])&&!empty($record['sealed_code']),'Recoverable code stored encrypted');
$check($run('preview_get')['preview']['code']===$r['code']&&$run('preview_generate')['preview']['code']===$r['code'],'Repeated reading and creation preserve code');
$check(get_site_meta($id,'emsf_preview',true)===$record,'Repeated actions do not alter generation or sessions');
switch_to_blog($id);$_SERVER['REQUEST_URI']=parse_url(home_url('/'),PHP_URL_PATH);$session=Preview::token();$check(Preview::attempt(strtolower($r['code']))['ok'],'Customer enters lowercase code on normal URL');$_COOKIE[Preview::cookieName()]=$session;wp_set_current_user(0);$check(Preview::allowed()&&!is_user_logged_in(),'Code allows frontend viewing without WordPress login');
foreach(['/wp-admin/','/wp-json/wp/v2/posts','/feed/'] as $path){$_SERVER['REQUEST_URI']=$path;$check(!Preview::allowed(),'Code does not grant '.$path);}$_SERVER['REQUEST_URI']=parse_url(home_url('/'),PHP_URL_PATH);
try{Preview::details($id);throw new Exception('Secret returned to visitor');}catch(RuntimeException $e){$check(true,'Visitor cannot retrieve code');}
restore_current_blog();wp_set_current_user(1);$run('preview_get');switch_to_blog($id);$check(Preview::valid($session),'Viewing code preserves customer session');restore_current_blog();
$_GET=['id'=>$id,'tab'=>'toegang'];ob_start();Admin::page();$html=ob_get_clean();preg_match('~<section id="toegang".*?(?=<section id="activiteit")~s',$html,$m);$panel=$m[0]??'';
$check(str_contains($panel,'data-preview-copy')&&str_contains($panel,esc_attr($r['code'])),'Access tab shows stable code and copy link-and-code button');
$check(!str_contains($panel,'Exacte online sitenaam')&&!str_contains($panel,'name="confirm"'),'Preview and editor forms need no name confirmation');
$check(str_contains($panel,'name="_wpnonce"')&&str_contains($panel,'name="site_id"'),'Forms retain nonce and selected site');
file_put_contents('/private/tmp/emsf-simple-preview/preview-ui.html','<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Site Factory test</title><style>body{font:16px system-ui;background:#f0f0f1;color:#1d2327;margin:30px}input,button,textarea{font:inherit;padding:10px;box-sizing:border-box}.large-text{width:100%}.button{border:1px solid #243165;border-radius:4px;background:white;cursor:pointer}.button-primary{background:#243165;color:white}.emsf-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}@media(max-width:700px){.emsf-grid{grid-template-columns:1fr}}'.file_get_contents(dirname(__DIR__).'/assets/admin.css').'</style>'.$html.'<script>'.file_get_contents(dirname(__DIR__).'/assets/admin.js').'</script></html>');
$req=new WP_REST_Request('GET');$req['source']=$source;$status=Receiver::status($req);$check(!isset($status['preview'])&&!str_contains(json_encode($status),$record['sealed_code']),'Normal status does not return recoverable secrets');
update_site_meta($id,'emsf_state','archived');$check(Preview::details($id)['code']===$r['code'],'Archive retains the same recoverable code');update_site_meta($id,'emsf_state','ready');
// Legacy hash-only records and old share links continue to work until explicit replacement.
$legacy=$record;unset($legacy['sealed_code']);$share=bin2hex(random_bytes(24));$legacy['share_hash']=hash('sha256',$share);update_site_meta($id,'emsf_preview',$legacy);
$check(!empty($run('preview_get')['preview']['legacy'])&&!empty($run('preview_generate')['preview']['legacy'])&&get_site_meta($id,'emsf_preview',true)===$legacy,'Legacy code never changes silently');switch_to_blog($id);$check(Preview::attempt($r['code'])['ok']&&Preview::shareValid($share),'Existing legacy code and share link still work');restore_current_blog();
$next=$run('preview_rotate')['preview'];switch_to_blog($id);$check($next['code']!==$r['code']&&!Preview::valid($session)&&!Preview::shareValid($share)&&!Preview::attempt($r['code'])['ok'],'Explicit change revokes old code, link and session');$token=Preview::token();restore_current_blog();
$run('preview_revoke');switch_to_blog($id);$check(!Preview::enabled($id)&&!Preview::valid($token),'Explicit disable revokes access');restore_current_blog();
$check(get_site_meta($id,'emsf_mail_mode',true)==='off','Sharing leaves mail policy unchanged');
echo "TOTAL: $checks persistent-preview checks passed\n";
