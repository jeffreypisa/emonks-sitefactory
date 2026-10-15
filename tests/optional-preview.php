<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Package;
use Emonks\Factory\Online\{Preview,Privacy,Receiver,Store,Admin,MailDraft,ContentChanges};
wp_set_current_user(1);$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
$req=static function($data){$r=new WP_REST_Request('POST');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return $r;};
$make=static function(?string $mode)use($req){
 $source=wp_generate_uuid4();$short=substr($source,0,8);
 $manifest=['format'=>Package::FORMAT,'version'=>1,'theme'=>'skeletor','prospect'=>['uuid'=>$source,'name'=>'Access '.$short],'dependencies'=>['theme_version'=>wp_get_theme('skeletor')->get('Version'),'theme_digest'=>Package::theme_digest()],'unsupported'=>[],'models'=>[],'posts'=>[['ref'=>'post:page:home','post'=>['post_type'=>'page','post_status'=>'publish','post_name'=>'home','post_title'=>'Home','post_content'=>'Read-only proposal','post_excerpt'=>''],'parent'=>0,'meta'=>[],'terms'=>[]]],'terms'=>[],'menus'=>[],'forms'=>[],'menu_locations'=>[],'files'=>[],'options'=>['blogname'=>'Access '.$short,'show_on_front'=>'page','page_on_front'=>['$ref'=>'post:page:home'],'page_for_posts'=>['$literal'=>0],'permalink_structure'=>'/%postname%/']];
 $payload=['source'=>$source,'operation'=>wp_generate_uuid4(),'action'=>'create','slug'=>'access-'.$short,'customer'=>[],'manifest'=>$manifest];if($mode!==null){$payload['preview_mode']=$mode;}
 Receiver::prepare($req($payload));$result=Receiver::execute($req($payload));return [$result,$payload];
};
[$result,$payload]=$make('public');$id=(int)$result['link']['site'];
$check($result['link']['preview_mode']==='public'&&!Preview::enabled($id),'New publication explicitly defaults to public without generating code');
$originalServer=$_SERVER;$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/';
switch_to_blog($id);wp_set_current_user(0);
$check(Privacy::visitorAllowed(),'Anonymous may view public frontend');
foreach(['/emsf-media/test.jpg'=>true,'/wp-admin/'=>false,'/wp-json/wp/v2/posts'=>false,'/feed/'=>false,'/xmlrpc.php'=>false]as$path=>$allowed){$_SERVER['REQUEST_URI']=$path;$check(Privacy::visitorAllowed()===$allowed,'Public access boundary '.$path);}
$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='POST';$check(!Privacy::visitorAllowed(),'Anonymous write requests remain blocked');$_SERVER['REQUEST_METHOD']='GET';
foreach(['importing','failed','archived','restoring']as$state){update_site_meta($id,'emsf_state',$state);$check(!Privacy::visitorAllowed(),'Unavailable state stays closed: '.$state);}update_site_meta($id,'emsf_state','ready');
$check(is_wp_error(apply_filters('rest_authentication_errors',null)),'Anonymous REST remains blocked');
$check(get_option('blog_public')===0,'Search indexing remains disabled');
wp_set_current_user(1);$code=Preview::manage($id);wp_set_current_user(0);$check(!Privacy::visitorAllowed(),'Turning code on immediately blocks anonymous frontend');
$_COOKIE[Preview::cookieName()]=Preview::token();$check(Privacy::visitorAllowed(),'Valid preview cookie grants access');$old=$_COOKIE[Preview::cookieName()];
wp_set_current_user(1);Preview::makePublic($id);$check(!Preview::valid($old),'Turning code off invalidates old session');Preview::manage($id);wp_set_current_user(0);$check(!Privacy::visitorAllowed(),'Old session remains invalid after re-enabling protection');unset($_COOKIE[Preview::cookieName()]);
wp_set_current_user(1);Preview::makePublic($id);restore_current_blog();
$fields=['draft_step'=>'follow','draft_first_name'=>'Test','draft_company'=>'Fixture','draft_email'=>'test@example.invalid','draft_price'=>'100','draft_monthly'=>'10'];
$draft=MailDraft::prepare($id,$fields)['mail_draft'];
$check($draft['unprotected']&&!str_contains($draft['body'],'Code:')&&!Preview::enabled($id),'Mail without code includes no code line and changes no protection');
$check(str_contains($draft['body'],get_home_url($id)),'Public mail contains actual site link');
$_GET=['id'=>$id,'tab'=>'toegang'];ob_start();Admin::page();$html=ob_get_clean();$check(str_contains($html,'Deze website is toegankelijk zonder code')&&str_contains($html,'Kopieer sitelink')&&str_contains($html,'Codebeveiliging aanzetten'),'Sharing UI reminder and optional protection action');
$_GET=['id'=>$id,'tab'=>'email'];ob_start();Admin::page();$html=ob_get_clean();$check(str_contains($html,'Deze website is toegankelijk zonder code'),'Mail UI reminds without blocking');
$code=Preview::manage($id);$draft=MailDraft::prepare($id,$fields)['mail_draft'];$check(!$draft['unprotected']&&str_contains($draft['body'],$code['code']),'Protected mail retains working code');
$replacement=array_replace($payload,['action'=>'replace','operation'=>wp_generate_uuid4(),'confirm'=>get_blog_option($id,'blogname'),'expected_revision'=>ContentChanges::status($id)['token'],'replace_changes'=>true]);Receiver::prepare($req($replacement));Receiver::execute($req($replacement));$check(Preview::details($id)['code']===$code['code'],'Repush cannot switch protected site to public');
Preview::makePublic($id);$replacement['operation']=wp_generate_uuid4();$replacement['expected_revision']=ContentChanges::status($id)['token'];Receiver::prepare($req($replacement));Receiver::execute($req($replacement));$check(Preview::mode($id)==='public','Repush preserves public choice');
Receiver::manage($req(['source'=>$payload['source'],'operation'=>$replacement['operation'],'action'=>'restore','confirm'=>get_blog_option($id,'blogname')]));$check(Preview::mode($id)==='public','Restoring content preserves access choice');
[$legacy]=$make(null);$legacyId=(int)$legacy['link']['site'];$check(Preview::mode($legacyId)==='closed','Legacy client or pending operation remains closed');
[$protected]=$make('code');$protectedId=(int)$protected['link']['site'];$check(Preview::enabled($protectedId)&&$protected['link']['preview_mode']==='code','Opt-in protection is ready on first publication');
$changed=$payload;$changed['preview_mode']='code';try{Receiver::prepare($req($changed));throw new LogicException('Mode mutation accepted');}catch(RuntimeException $e){$check(true,'Pending operation binds access choice');}
delete_site_meta($protectedId,'emsf_preview_mode');$check(Preview::mode($protectedId)==='code','Existing code remains protected without a new mode setting');
Preview::manage($protectedId,true);$check(Preview::mode($protectedId)==='closed','Legacy revoke remains private, not public');
// Fixture for separate real HTTP gate tests; no production content or credentials.
file_put_contents(EMSF_STATE_DIR.'/optional-preview-fixture.json',wp_json_encode(['public'=>get_site($id)->path,'protected'=>get_site($legacyId)->path,'public_id'=>$id]));
$_SERVER=$originalServer;echo "TOTAL $n optional preview checks\n";
