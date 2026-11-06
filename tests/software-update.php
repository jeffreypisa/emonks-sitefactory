<?php
// Isolated files and mocked WordPress installer; never load a customer database or HTTP transport.
namespace {
 $base=sys_get_temp_dir().'/emsf-software-test-'.bin2hex(random_bytes(5));mkdir($base);mkdir($base.'/wp');mkdir($base.'/private');mkdir($base.'/wp/themes');mkdir($base.'/wp/plugins');mkdir($base.'/wp/wp-admin',0777);mkdir($base.'/wp/wp-admin/includes');
 foreach(['file.php','plugin.php','class-wp-upgrader.php']as$file){file_put_contents($base.'/wp/wp-admin/includes/'.$file,'<?php');}
 define('ABSPATH',$base.'/wp/');define('WP_PLUGIN_DIR',$base.'/wp/plugins');define('EMSF_STATE_DIR',$base.'/private');
 $version='1.0.0';$fail=false;
 function get_site_option($key,$default=''){return $key==='active_sitewide_plugins'?($GLOBALS['networkPlugins']??[]):($key==='emsf_remote_theme_slug'?'skeletor-master':'skeletor');}
 function update_site_option($key,$value){$GLOBALS['networkPlugins']=$value;}
 function activate_plugin($file,$redirect,$network){if(!empty($GLOBALS['activationFail']))return new WP_Error();$GLOBALS['networkPlugins'][$file]=time();return null;}
 function get_plugins(){
  $found=[];foreach(glob(WP_PLUGIN_DIR.'/*/websitebeheer.php')?:[]as$file){$bytes=file_get_contents($file);preg_match('/Version:\s*(\S+)/',$bytes,$v);$found[basename(dirname($file)).'/websitebeheer.php']=['Name'=>'Emonks Dashboard','Version'=>$v[1]??''];}return $found;
 }
 function wp_get_theme($slug){global $version;return new class($slug){function __construct(private $slug){}function get($key){global $version;return $key==='Version'?$version:'Skeletor';}function get_stylesheet_directory(){global $base;return $base.'/source';}};}
 function current_user_can($cap){return true;}function wp_is_file_mod_allowed($context){return true;}function get_current_user_id(){return 1;}
 function get_theme_root(){return ABSPATH.'themes';}function get_filesystem_method(){return 'direct';}function WP_Filesystem(){return true;}
 function wp_clean_themes_cache($refresh){}function wp_clean_plugins_cache($refresh){}function is_wp_error($result){return $result instanceof WP_Error;}function wp_mkdir_p($dir){return is_dir($dir)||mkdir($dir,0777,true);}
 class WP_Error {function get_error_message(){return 'Synthetic failure';}}
 class WP_Theme {static function network_enable_theme($slug){$GLOBALS['enabled']=$slug;}}
 class Automatic_Upgrader_Skin {}
 class Theme_Upgrader {
  function __construct($skin){}function install($path,$options){global $fail,$wp_filesystem;$target=get_theme_root().'/skeletor-master';$wp_filesystem->delete($target,true);mkdir($target);file_put_contents($target.'/partial','partial');if($fail)return new WP_Error();unlink($target.'/partial');$zip=new ZipArchive();$zip->open($path);$zip->extractTo(get_theme_root());$zip->close();return true;}
 }
 class Plugin_Upgrader {function __construct($skin){}function install($path,$options){$zip=new ZipArchive();$zip->open($path);$zip->extractTo(WP_PLUGIN_DIR);$zip->close();return true;}}
 $wp_filesystem=new class {function delete($path,$recursive){if(!file_exists($path))return true;if(!is_dir($path))return unlink($path);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}return rmdir($path);}};
 require __DIR__.'/../includes/ThemeRelease.php';require __DIR__.'/../includes/Online/Store.php';require __DIR__.'/../includes/Online/Software.php';
 use Emonks\Factory\Online\Software;use Emonks\Factory\Online\Store;
 $n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}function rejected($fn,$label){$caught=false;try{$fn();}catch(RuntimeException $e){$caught=true;}check($caught,$label);}
 function makeZip($path,$extra=[],$version='1.1.0'){$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach(['skeletor-master/style.css'=>"/* Theme Name: Skeletor\nVersion: $version\n*/",'skeletor-master/functions.php'=>'<?php','skeletor-master/index.php'=>'<?php']+$extra as$name=>$bytes)$zip->addFromString($name,$bytes);$zip->close();}
 try{
  $path=$base.'/valid.zip';makeZip($path);$meta=['component'=>'theme','slug'=>'skeletor-master','version'=>'1.1.0','size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];Software::validate($path,$meta);check(true,'Valid theme');
  foreach(['skeletor-master/../outside.php','other-root/a.php','skeletor-master/a\\b.php','skeletor-master//a.php']as$name){$bad=$base.'/bad.zip';makeZip($bad,[$name=>'bad']);rejected(fn()=>Software::validate($bad,$meta),'Unsafe entry');}
  rejected(fn()=>Software::validate($path,array_replace($meta,['version'=>'9.0.0'])),'Wrong version');rejected(fn()=>Software::component('acf'),'Unknown component');
  mkdir($base.'/source');mkdir($base.'/source/src');mkdir($base.'/source/docs');mkdir($base.'/source/node_modules');file_put_contents($base.'/source/style.css',"/* Theme Name: Skeletor\nVersion: 1.0.0\n*/");file_put_contents($base.'/source/functions.php','<?php');file_put_contents($base.'/source/index.php','<?php');file_put_contents($base.'/source/src/Runtime.php','<?php');file_put_contents($base.'/source/docs/private.md','secret');file_put_contents($base.'/source/.env','credential');file_put_contents($base.'/source/node_modules/foo.js','development');
  mkdir($base.'/source/vendor');mkdir($base.'/source/vendor/bin');symlink('../../src/Runtime.php',$base.'/source/vendor/bin/dev-tool');
  $packed=Software::package('theme',$base.'/packed.zip');$zip=new ZipArchive();$zip->open($base.'/packed.zip');check($zip->locateName('skeletor-master/src/Runtime.php')!==false,'Runtime included');check($zip->locateName('skeletor-master/.env')===false&&$zip->locateName('skeletor-master/docs/private.md')===false&&$zip->locateName('skeletor-master/node_modules/foo.js')===false,'Local/private files excluded');check($zip->locateName('skeletor-master/vendor/bin/dev-tool')===false,'Composer development binaries excluded');$zip->close();Software::validate($base.'/packed.zip',$packed);
  symlink('Runtime.php',$base.'/source/src/unsafe.php');rejected(fn()=>Software::package('theme',$base.'/unsafe.zip'),'Runtime symlinks still rejected');unlink($base.'/source/src/unsafe.php');
  mkdir(get_theme_root().'/skeletor-master');file_put_contents(get_theme_root().'/skeletor-master/old.php','old');
  \Emonks\Factory\ThemeRelease::useRequestSlug('skeletor-master');
  $request=['source'=>'source-test','upload'=>'12345678-1234-1234-1234-123456789012','meta'=>$meta,'expected_version'=>'1.0.0'];Software::prepare($request);
  rejected(fn()=>Software::asset($request+['offset'=>3,'bytes'=>base64_encode('wrong')]),'Wrong offset');rejected(fn()=>Software::execute($request),'Incomplete upload');
  Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($path))]);
  $wrong=$request;$wrong['source']='other';rejected(fn()=>Software::execute($wrong),'Wrong source');
  $GLOBALS['version']='1.0.1';rejected(fn()=>Software::execute($request),'Changed remote version');$GLOBALS['version']='1.0.0';
  $result=Software::execute($request);check($result['updated']&&is_file(get_theme_root().'/skeletor-master/style.css'),'Installation');check(!is_file(get_theme_root().'/skeletor-master/old.php'),'Old file removed');check(file_get_contents(Store::root().'/software-'.$request['upload'].'-backup/old.php')==='old','Backup retained');check($GLOBALS['enabled']==='skeletor-master','Network theme enabled');check(!is_file(Store::root().'/software-'.$request['upload'].'.zip'),'Uploaded zip removed');
  $request['upload']='22345678-1234-1234-1234-123456789012';Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($path))]);$GLOBALS['fail']=true;rejected(fn()=>Software::execute($request),'Installer error');check(is_file(get_theme_root().'/skeletor-master/style.css')&&!is_file(get_theme_root().'/skeletor-master/partial'),'Rollback restores previous files');check(Store::read('software-'.$request['upload'])['status']==='restored','Rollback journal');
  $GLOBALS['fail']=false;$plugin=Software::package('plugin',$base.'/plugin.zip');Software::validate($base.'/plugin.zip',$plugin);preg_match('/^ \* Version: (.+)$/m',file_get_contents(dirname(__DIR__).'/emonks-site-factory.php'),$version);check($plugin['version']===trim($version[1]),'Plugin version in package');
  $request=['source'=>'source-test','upload'=>'32345678-1234-1234-1234-123456789012','meta'=>$plugin,'expected_version'=>$plugin['version']];Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($base.'/plugin.zip'))]);$result=Software::execute($request);check($result['updated']&&is_file(WP_PLUGIN_DIR.'/emonks-site-factory/includes/Online/Software.php'),'Plugin installed at fixed root');
  // Real filesystem fixtures exercise missing installs, network activation and rollback.
  $dashboardRoot=WP_PLUGIN_DIR.'/emonks-dashboard';mkdir($dashboardRoot);mkdir($dashboardRoot.'/includes');mkdir($dashboardRoot.'/assets');
  file_put_contents($dashboardRoot.'/websitebeheer.php',"<?php\n/**\n * Plugin Name: Emonks Dashboard\n * Version: 1.1.2\n */");
  file_put_contents($dashboardRoot.'/includes/Agency.php','<?php');file_put_contents($dashboardRoot.'/includes/Workspace.php','<?php');file_put_contents($dashboardRoot.'/assets/workspace.css','body{}');file_put_contents($dashboardRoot.'/.env','secret');
  $dashboard=Software::package('dashboard',$base.'/dashboard.zip');Software::validate($base.'/dashboard.zip',$dashboard);
  $zip=new ZipArchive();$zip->open($base.'/dashboard.zip');check($zip->locateName('emonks-dashboard/websitebeheer.php')!==false&&$zip->locateName('emonks-dashboard/.env')===false,'Dashboard runtime package excludes secrets');$zip->close();
  $wp_filesystem->delete($dashboardRoot,true);
  $request=['source'=>'source-test','upload'=>'42345678-1234-1234-1234-123456789012','meta'=>$dashboard,'expected_version'=>''];Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($base.'/dashboard.zip'))]);Software::execute($request);
  check(\Emonks\Factory\ThemeRelease::dashboard()['version']==='1.1.2'&&\Emonks\Factory\ThemeRelease::dashboard()['network_active'],'Missing dashboard installed and network activated');
  $request['upload']='52345678-1234-1234-1234-123456789012';$request['expected_version']='1.1.2';Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($base.'/dashboard.zip'))]);$GLOBALS['activationFail']=true;
  rejected(fn()=>Software::execute($request),'Activation error rolls back');check(is_file($dashboardRoot.'/websitebeheer.php')&&\Emonks\Factory\ThemeRelease::dashboard()['network_active'],'Rollback restores existing dashboard and activation');$GLOBALS['activationFail']=false;
  $wp_filesystem->delete($dashboardRoot,true);$GLOBALS['networkPlugins']=[];
  $request['upload']='62345678-1234-1234-1234-123456789012';$request['expected_version']='';Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($base.'/dashboard.zip'))]);$GLOBALS['activationFail']=true;rejected(fn()=>Software::execute($request),'Failed first activation removes new install');check(!is_dir($dashboardRoot)&&!\Emonks\Factory\ThemeRelease::dashboard()['network_active'],'No broken fresh install remains');$GLOBALS['activationFail']=false;
  mkdir(WP_PLUGIN_DIR.'/websitebeheer');file_put_contents(WP_PLUGIN_DIR.'/websitebeheer/websitebeheer.php',"<?php\n/* Plugin Name: Emonks Dashboard\nVersion: 1.1.0\n*/");
  check(\Emonks\Factory\ThemeRelease::dashboard()['slug']==='websitebeheer','Legacy folder discovered');
  $legacy=$dashboard;$legacy['slug']='websitebeheer';$zip=new ZipArchive();$zip->open($base.'/legacy.zip',ZipArchive::CREATE);$sourceZip=new ZipArchive();$sourceZip->open($base.'/dashboard.zip');for($i=0;$i<$sourceZip->numFiles;$i++){$name=$sourceZip->getNameIndex($i);$zip->addFromString(str_replace('emonks-dashboard/','websitebeheer/',$name),$sourceZip->getFromIndex($i));}$sourceZip->close();$zip->close();$legacy['size']=filesize($base.'/legacy.zip');$legacy['sha256']=hash_file('sha256',$base.'/legacy.zip');
  $request=['source'=>'source-test','upload'=>'72345678-1234-1234-1234-123456789012','meta'=>$legacy,'expected_version'=>'1.1.0'];Software::prepare($request);Software::asset($request+['offset'=>0,'bytes'=>base64_encode(file_get_contents($base.'/legacy.zip'))]);Software::execute($request);check(\Emonks\Factory\ThemeRelease::dashboard()['version']==='1.1.2'&&!is_dir($dashboardRoot),'Legacy install upgraded without duplicate');
  mkdir($dashboardRoot);file_put_contents($dashboardRoot.'/websitebeheer.php',file_get_contents(WP_PLUGIN_DIR.'/websitebeheer/websitebeheer.php'));rejected(fn()=>\Emonks\Factory\ThemeRelease::dashboard(),'Duplicate dashboards rejected');
  echo "$n software checks passed.\n";
 }finally{$wp_filesystem->delete($base,true);}
}
