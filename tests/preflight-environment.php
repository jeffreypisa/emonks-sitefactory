<?php
declare(strict_types=1);
namespace Emonks\Factory {
 final class Factory {static function local(){}static function find($slug){return ['uuid'=>'source-test','site_id'=>2];}}
 final class Package {static function export($dir){$GLOBALS['exports']++;mkdir($dir);}static function read($dir){return ['posts'=>[],'files'=>[],'unsupported'=>[]];}}
}
namespace Emonks\Factory\Online {
 final class Store {static function root(){return $GLOBALS['root'];}static function key($key){return $key;}static function read($key){return [];}static function write($key,$value){$GLOBALS['stored'][$key]=$value;}}
 final class Client {static function status($slug){if($GLOBALS['offline'])throw new \RuntimeException('Offline');return $GLOBALS['status'];}static function checkPackage($source,$manifest){$GLOBALS['checks']++;return ['ok'=>true,'issues'=>[]];}}
}
namespace {
 const ACF_PRO=true;const ACF_VERSION='6.8.1';const EMSF_PRIVATE_HOSTING_VERIFIED=true;
 function acf_get_field($key){return [];}
 function get_site_option($key,$default=null){return 'skeletor';}
 function wp_get_theme($slug){return new class {function get($key){return $key==='Version'?'1.1.8':'Skeletor';}function exists(){return true;}function is_allowed($scope){return true;}};}
 function wp_raise_memory_limit($context){$GLOBALS['memory']=$context;}
 function wp_generate_uuid4(){return '12345678-1234-1234-1234-123456789012';}function switch_to_blog($id){}function restore_current_blog(){}
 require __DIR__.'/../includes/ThemeRelease.php';require __DIR__.'/../includes/Online/Publication.php';
 use Emonks\Factory\ThemeRelease;use Emonks\Factory\Online\Publication;
 $GLOBALS['root']=sys_get_temp_dir().'/emsf-check-test-'.bin2hex(random_bytes(4));mkdir($GLOBALS['root']);$GLOBALS['exports']=0;$GLOBALS['checks']=0;$GLOBALS['offline']=false;$GLOBALS['status']=['reachable'=>true];
 $count=0;$assert=static function($ok,$message)use(&$count){if(!$ok)throw new \RuntimeException($message);$count++;};
 try{
  $report=Publication::inspect('fixture')['publication_check'];$assert(!$report['ok'],'Old receiver blocks');$assert($GLOBALS['exports']===0,'No export for legacy receiver');$assert($GLOBALS['memory']==='admin','Admin memory limit requested');$assert(isset($GLOBALS['stored']['check-source-test']),'Results persist');
  $GLOBALS['offline']=true;$report=Publication::inspect('fixture')['publication_check'];$assert($GLOBALS['exports']===0&&in_array('Offline',$report['issues'],true),'Offline check has no export');
  $GLOBALS['offline']=false;$GLOBALS['status']=['environment'=>ThemeRelease::environment(),'software_update'=>true];$report=Publication::inspect('fixture')['publication_check'];$assert($report['ok'],'Ready environment checks package');$assert($GLOBALS['exports']===1&&$GLOBALS['checks']===1,'Exactly one export after environment passes');$assert($report['software_update']===true,'Software capability retained');$assert(!is_dir($GLOBALS['root'].'/check-'.wp_generate_uuid4()),'Temporary export removed');
  echo "$count preflight checks passed.\n";
 }finally{rmdir($GLOBALS['root']);}
}
