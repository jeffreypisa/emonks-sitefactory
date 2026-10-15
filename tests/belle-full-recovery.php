<?php
use Emonks\Factory\{Package};
use Emonks\Factory\Online\{Store,Receiver,Snapshot,Accounts};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
wp_set_current_user(1);$checks=0;
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}++$checks;echo "PASS: $name\n";};
$source=wp_generate_uuid4();$operation=wp_generate_uuid4();$key='op-'.$operation;$slug='recovery-'.substr($source,0,8);
$site=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/'.$slug.'/','network_id'=>get_current_network_id(),'user_id'=>1,'title'=>'Recovery fixture','options'=>['emsf_uuid'=>$source,'blog_public'=>0],'meta'=>['emsf_managed'=>1,'emsf_state'=>'failed','emsf_source'=>$source,'emsf_operation'=>$key,'emsf_mail_mode'=>'off']]);
if(is_wp_error($site)){throw new RuntimeException($site->get_error_message());}$site=(int)$site;
switch_to_blog($site);try{switch_theme('skeletor');$backup=Snapshot::create();update_option('blogdescription','Partial failed import');}finally{restore_current_blog();}
$customer=['name'=>'Recovery customer','username'=>'recovery'.substr($source,0,8),'email'=>'recovery'.substr($source,0,8).'@example.invalid','confirmed'=>true,'layout_access'=>false];
$sourceDir=rtrim((string)getenv('EMSF_TEST_PACKAGE'),'/');
if(!$sourceDir||!is_file($sourceDir.'/manifest.json')){throw new RuntimeException('Set EMSF_TEST_PACKAGE to a local test package.');}
$manifest=json_decode(file_get_contents($sourceDir.'/manifest.json'),true);$manifest['prospect']=['uuid'=>$source,'name'=>'Recovery fixture'];
$dir=Store::root().'/'.$key;wp_mkdir_p($dir);symlink($sourceDir.'/files',$dir.'/files');Package::json($dir.'/manifest.json',$manifest);
$op=['source'=>$source,'site'=>$site,'status'=>'failed','action'=>'create','backup'=>$backup,'error'=>'Ontbrekend inhoudstype: skeletor_teaser','customer'=>$customer,'digest'=>hash('sha256',json_encode([$manifest,'create',$slug,'',$customer]))];
Store::write($key,$op);Store::write('link-'.$source,['site'=>$site,'source'=>$source,'operation'=>$key]);
$run=static function(array $override=[])use($source,$operation,$site){$req=new WP_REST_Request('POST');$req->set_header('Content-Type','application/json');$req->set_body(wp_json_encode(array_replace(['source'=>$source,'operation'=>$operation,'action'=>'retry_models','confirm'=>get_blog_option($site,'blogname')],$override)));return Accounts::silent(static fn()=>Receiver::manage($req));};
foreach([['confirm'=>'Wrong site'],['operation'=>wp_generate_uuid4()]]as$bad){try{$run($bad);throw new Exception('Unsafe request accepted');}catch(RuntimeException $e){$check(Store::read($key)===$op,'Wrong confirmation or operation leaves journal untouched');}}
$raw=file_get_contents($dir.'/manifest.json');$check(Package::read($dir)===$manifest,'Package read preserves manifest identity');
$count=get_sites(['count'=>true]);$check(!post_type_exists('skeletor_teaser'),'Network request starts without teaser registration');$result=$run();$check(($result['operation']['status']??'')==='complete','Failed creation recovered to complete');
$check((int)$result['link']['site']===$site && get_sites(['count'=>true])===$count,'Recovery reuses existing site');
$check(Store::read($key)['backup']===$backup,'Original restore point retained');
$check(file_get_contents($dir.'/manifest.json')===$raw,'Stored manifest never rewritten');
$check(!empty($result['customer']['username']),'Customer account provisioned after recovery');
$check(get_site_meta($site,'emsf_state',true)==='ready','Site ready after complete import');
switch_to_blog($site);try{
 $map=get_option('emsf_import_map');
 foreach($manifest['posts'] as $row){$post=get_post($map[$row['ref']]??0);$check($post&&$post->post_type===$row['post']['post_type'],'Imported '.$row['ref']);}
 $check(post_type_exists('skeletor_teaser'),'Theme teaser registered using real theme class');
 $check(get_post_type_object('skeletor_teaser')->cap->edit_posts===ThemeOptionsAccess::CAPABILITY,'Teaser permissions match actual theme');
}finally{restore_current_blog();}
$again=$run();$check(($again['customer']['username']??'')===$result['customer']['username'],'Repeated recovery returns same completed account');
$check(($GLOBALS['test_mail_count']??0)===0,'No outgoing mail attempted');
echo "TOTAL: $checks recovery assertions passed\n";
