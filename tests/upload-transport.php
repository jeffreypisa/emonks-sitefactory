<?php
declare(strict_types=1);
namespace Emonks\Factory {
 final class Standalone {public static function authorize(): void {if(!$GLOBALS['allowed'])throw new \RuntimeException('Denied');}public static function storage(): string{return $GLOBALS['root'];}public static function stage(string $file,bool $private): array{$GLOBALS['stages']++;$GLOBALS['staged_hash']=hash_file('sha256',$file);return ['operation'=>'12345678-1234-1234-1234-123456789012'];}}
 final class FullSite {public const LIMIT=16*1024*1024*1024;}
}
namespace {
 $root=sys_get_temp_dir().'/emsf-upload-test-'.bin2hex(random_bytes(6));mkdir($root,0700);$allowed=true;$user=1;$site='https://fixture.example.test';$stages=0;
 function get_current_user_id(){return $GLOBALS['user'];}function site_url(){return $GLOBALS['site'];}function sanitize_file_name($n){return basename($n);}function wp_generate_uuid4(){$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
 function wp_raise_memory_limit($context){}function admin_url($p){return 'https://fixture.example.test/wp-admin/'.$p;}
 require dirname(__DIR__).'/includes/UploadTransport.php';
 $checks=0;function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}function rejects($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
 use Emonks\Factory\UploadTransport as U;
 try{
  rejects(fn()=>U::begin('file.php',20),'Only ZIP');rejects(fn()=>U::begin('file.zip',0),'No empty upload');rejects(fn()=>U::status('../outside'),'Token traversal');
  $allowed=false;rejects(fn()=>U::begin('file.zip',5),'Administrator required');$allowed=true;
  $source=$root.'/source';file_put_contents($source,random_bytes(U::CHUNK));$tail=$root.'/tail';file_put_contents($tail,'ZIP final bytes');$size=U::CHUNK*2+filesize($tail);
  $r=U::begin('large.zip',$size);$token=$r['token'];$dir=$root.'/upload-'.$token;check(U::status($token)['offset']===0,'Initial offset');check((fileperms($dir)&0777)===0700,'Private directory');check((fileperms($dir.'/package.part')&0777)===0600,'Private file');
  rejects(fn()=>U::finish($token),'Cannot stage incomplete upload');rejects(fn()=>U::append($token,12,$source),'Gap rejected');
  $r=U::append($token,0,$source);check($r['offset']===U::CHUNK,'First part');$retry=U::append($token,0,$source);check($retry['offset']===U::CHUNK,'Lost response retry idempotent');check(filesize($dir.'/package.part')===U::CHUNK,'No duplicate bytes');
  $different=$root.'/different';file_put_contents($different,str_repeat('x',U::CHUNK));rejects(fn()=>U::append($token,0,$different),'Different retry rejected');
  $user=2;rejects(fn()=>U::status($token),'Other administrator cannot access');$user=1;$site='https://other.example.test';rejects(fn()=>U::status($token),'Other installation cannot access');$site='https://fixture.example.test';
  file_put_contents($dir.'/package.part','uncommitted',FILE_APPEND);U::status($token);clearstatcache(true,$dir.'/package.part');check(filesize($dir.'/package.part')===U::CHUNK,'Uncommitted tail rolled back');
  $huge=$root.'/oversized';$f=fopen($huge,'wb');ftruncate($f,U::CHUNK+1);fclose($f);rejects(fn()=>U::append($token,U::CHUNK,$huge),'Chunk limit');
  $r=U::append($token,U::CHUNK,$source);check($r['offset']===U::CHUNK*2,'Resume next part');rejects(fn()=>U::append($token,U::CHUNK*2,$source),'Total length limit');U::append($token,U::CHUNK*2,$tail);
  $expected=hash('sha256',file_get_contents($source).file_get_contents($source).file_get_contents($tail));$r=U::finish($token);check($staged_hash===$expected,'All bytes unchanged');check($stages===1,'Staging once');check(!is_file($dir.'/package.part'),'Temporary large upload removed');check(isset(U::status($token)['redirect']),'Completed upload survives lost reply');U::finish($token);check($stages===1,'Finish retry idempotent');
  $state=json_decode(file_get_contents($dir.'/state.json'),true);$state['updated']=time()-U::TTL-1;file_put_contents($dir.'/state.json',json_encode($state));rejects(fn()=>U::status($token),'Expiry');U::begin('new.zip',20);check(!is_dir($dir),'Own expired upload purged');
  echo "$checks resumable upload checks passed.\n";
 }finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);}
}
