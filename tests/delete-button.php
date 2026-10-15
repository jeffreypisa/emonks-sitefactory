<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only');}
$local=getenv('EMSF_DELETE_MODE')==='local';if($local){define('EMSF_LOCAL',true);}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Factory;
use Emonks\Factory\Online\{Sites,Admin,Store};
wp_set_current_user(1);register_post_type(Factory::TYPE,['public'=>false]);$checks=0;
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}++$checks;echo "PASS: $name\n";};
$make=static function()use($local){$uuid=wp_generate_uuid4();if($local){return Sites::createLocal(['name'=>'Delete fixture','slug'=>'delete-'.substr($uuid,0,8)]);}$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/delete-'.substr($uuid,0,8).'/','network_id'=>get_current_network_id(),'user_id'=>1,'title'=>'Delete fixture','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>$uuid]]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}Store::write('link-'.$uuid,['site'=>(int)$id,'source'=>$uuid]);return ['site_id'=>(int)$id,'uuid'=>$uuid];};
$p=$make();$other=$make();$id=(int)$p['site_id'];$scope=$local?'local':'online';
$_GET=['id'=>$id,'tab'=>'beheer'];ob_start();Admin::page();$html=ob_get_clean();preg_match_all('~<form\b[^>]*>.*?</form>~s',$html,$forms);$delete='';foreach($forms[0]as$f){if(str_contains($f,'name="site_action" value="delete"')){$delete=$f;break;}}
$check($delete!==''&&str_contains($delete,'emsf-delete'),'Red deletion button is rendered');
$check(!preg_match('~<input[^>]+type="(?:text|email)"~',$delete),'Deletion form has no typing fields');
$check(str_contains($delete,'name="confirm" value="Delete fixture"')&&str_contains($delete,'_wpnonce'),'Site identity and nonce retained');
$data=['scope'=>$scope,'site_action'=>'delete','confirm'=>'Delete fixture','delete_confirm'=>$local?'DEFINITIEF LOKAAL VERWIJDEREN':'DEFINITIEF VERWIJDEREN'];
foreach([['confirm'=>'Wrong'],['delete_confirm'=>'Wrong']]as$bad){try{Admin::run($id,array_replace($data,$bad));throw new Exception('Invalid deletion accepted');}catch(RuntimeException $e){$check((bool)get_site($id),'Wrong identity/scope confirmation blocked');}}
wp_set_current_user(0);try{Admin::run($id,$data);throw new Exception('Anonymous deletion accepted');}catch(RuntimeException $e){$check((bool)get_site($id),'Non-admin cannot delete');}wp_set_current_user(1);
if(!$local){update_site_meta($id,'emsf_state','importing');try{Admin::run($id,$data);throw new Exception('In-progress deletion accepted');}catch(RuntimeException $e){$check((bool)get_site($id),'Importing site cannot be deleted');}update_site_meta($id,'emsf_state','ready');}
$check(!get_site($id)->archived,'Fixture is not archived');$r=Admin::run($id,$data);
$check(!get_site($id)&&get_site((int)$other['site_id']),'Only selected unarchived fixture deleted');
$journal=Store::read(($local?'local-deleted-':'deleted-').$p['uuid']);$check(!empty($journal['backup']),'Recovery backup retained');
$check((bool)get_user_by('id',1),'Network user preserved');
echo "TOTAL: $checks $scope deletion checks passed\n";
