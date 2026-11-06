<?php
/** Pure policy and ZIP validation; no WordPress, database or network required. */
namespace {
$fixture=sys_get_temp_dir().'/emsf-handover-policy-'.bin2hex(random_bytes(6));mkdir($fixture,0700);mkdir($fixture.'/web');mkdir($fixture.'/web/wp-content');
define('ABSPATH',$fixture.'/web/');define('WP_CONTENT_DIR',$fixture.'/web/wp-content');define('EMSF_HANDOVER_IMPORTER',true);
$GLOBALS['handover_allowed']=true;
function is_multisite(){return false;}function current_user_can($cap){return $GLOBALS['handover_allowed'];}function get_temp_dir(){return $GLOBALS['fixture'];}function wp_generate_uuid4(){return '12345678-1234-1234-1234-'.bin2hex(random_bytes(6));}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
foreach(['Factory','ThemeRelease','Package','Online/Receiver','Handover','Standalone'] as $file){require_once dirname(__DIR__).'/includes/'.$file.'.php';}
}
namespace Emonks\Factory {
$n=0;$assert=static function($v,$text)use(&$n){if(!$v){throw new \RuntimeException($text);}$n++;};$reject=static function($fn,$text)use($assert){try{$fn();}catch(\Throwable $e){$assert(str_contains($e->getMessage(),$text),'Expected '.$text.', got '.$e->getMessage());return;}$assert(false,'Expected '.$text);};
$assert(Handover::target('https://example.test/')==='https://example.test','Target normalization');
foreach(['http://example.test','https://user:pass@example.test','https://example.test?test=1','https://example.test#x','https://example.test:8080'] as $url){$reject(static fn()=>Handover::target($url),'HTTPS-adres');}
$a=['created_at'=>'old','options'=>['b'=>2,'a'=>1],'posts'=>[['id'=>1],['id'=>2]]];$b=['posts'=>$a['posts'],'options'=>['a'=>1,'b'=>2],'created_at'=>'new'];$assert(Handover::fingerprint($a)===Handover::fingerprint($b),'Canonical fingerprint');$b['posts']=array_reverse($b['posts']);$assert(Handover::fingerprint($a)!==Handover::fingerprint($b),'Ordered content binding');
$GLOBALS['handover_allowed']=false;$reject(static fn()=>Standalone::authorize(),'beheerder');$GLOBALS['handover_allowed']=true;
$root=Standalone::storage();$dir=$root.'/incoming-'.wp_generate_uuid4();wp_mkdir_p($dir.'/files');
$m=['format'=>Package::FORMAT,'version'=>1,'theme'=>'skeletor','source'=>'https://source.example.test','posts'=>[],'terms'=>[],'menus'=>[],'forms'=>[],'options'=>[],'files'=>[],'models'=>[],'unsupported'=>[]];wp_mkdir_p($dir.'/files/media');file_put_contents($dir.'/files/media/download.docx','Synthetic document transport fixture');$m['files']['media/download.docx']=['size'=>filesize($dir.'/files/media/download.docx'),'sha256'=>hash_file('sha256',$dir.'/files/media/download.docx')];Package::json($dir.'/manifest.json',$m);
$h=['format'=>Handover::FORMAT,'operation'=>wp_generate_uuid4(),'source'=>$m['source'],'target'=>'https://example.test','fingerprint'=>Handover::fingerprint($m),'manifest_sha256'=>hash_file('sha256',$dir.'/manifest.json'),'approved_by'=>1,'approved_at'=>'2026-10-27T12:00:00Z'];Package::json($dir.'/handover.json',$h);
$assert(Standalone::read($dir)['handover']['operation']===$h['operation'],'Legacy approved data accepted');
$zip=$root.'/test.zip';$z=new \ZipArchive();$z->open($zip,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);foreach(['manifest.json','handover.json','files/media/download.docx'] as $name){$z->addFile($dir.'/'.$name,$name);}$z->close();$unpacked=Standalone::unpack($zip);$assert(is_file($unpacked.'/manifest.json'),'Bounded extraction succeeds');$assert(is_file($unpacked.'/files/media/download.docx'),'DOCX preserved by standalone ZIP import');Standalone::removeTree($unpacked);
$new=$h;unset($new['approved_at'],$new['approved_by']);$new['ready_at']='2026-10-27T12:00:00Z';$new['ready_by']=1;Package::json($dir.'/handover.json',$new);$assert(Standalone::read($dir)['handover']['operation']===$h['operation'],'Ready package accepted without approval');
$h['approved_at']='';Package::json($dir.'/handover.json',$h);$reject(static fn()=>Standalone::read($dir),'niet klaargezet');$h['approved_at']='2026-10-27';$h['fingerprint']=str_repeat('0',64);Package::json($dir.'/handover.json',$h);$reject(static fn()=>Standalone::read($dir),'niet klaargezet');
foreach(['../escape.php','files/media/script.php','/manifest.json','files/media/../escape.png'] as $name){$z=new \ZipArchive();$z->open($zip,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);$z->addFromString($name,'data');$z->close();$reject(static fn()=>Standalone::unpack($zip),'Onveilig');}
$reject(static fn()=>Standalone::removeTree($GLOBALS['fixture'].'/web'),'Ongeldige');
Standalone::removeTree($dir);
echo "$n handover policy checks passed.\n";
}
namespace {
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($fixture);
}
