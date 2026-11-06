<?php
// Private package history, site isolation and recoverable deletion; synthetic files only.
use Emonks\Factory\{Handover,HandoverLibrary};
$root=sys_get_temp_dir().'/emsf-package-library-test-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/web');mkdir($root.'/private');
define('ABSPATH',$root.'/web/');define('EMSF_STATE_DIR',$root.'/private');$site=1;$options=[];
function get_current_blog_id(){return $GLOBALS['site'];}function get_current_user_id(){return 1;}
function get_option($key,$default=[]){return $GLOBALS['options'][$GLOBALS['site']][$key]??$default;}
function update_option($key,$value,$autoload=false){$old=get_option($key,null);$GLOBALS['options'][$GLOBALS['site']][$key]=$value;return $old!==$value;}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function get_home_url($id){return 'https://site'.$id.'.example.test';}
require dirname(__DIR__).'/includes/Online/Store.php';require dirname(__DIR__).'/includes/Handover.php';require dirname(__DIR__).'/includes/HandoverLibrary.php';
$n=0;function check($ok,$label){global $n;if(!$ok){throw new RuntimeException($label);}$n++;}function rejects($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function record($op,$created,$legacy=false){
 $dir=$legacy?EMSF_STATE_DIR.'/handover-'.$op:HandoverLibrary::destination($op);wp_mkdir_p($dir);file_put_contents($dir.'/oplevering.zip','Snapshot '.$op);
 $r=['format'=>Handover::FORMAT,'site'=>get_current_blog_id(),'operation'=>$op,'dir'=>$dir,'created_at'=>$created,'fingerprint'=>str_repeat('a',64),'target'=>'https://target.example.test','source'=>get_home_url(get_current_blog_id()),'state'=>'ready'];file_put_contents($dir.'/handover.json',json_encode($r));return $r;
}
try{
 $old=record('11111111-1111-4111-8111-111111111111','2026-10-26T12:00:00Z',true);update_option('emsf_handover',$old);
 check(count(HandoverLibrary::available())===1,'Existing pre-library package adopted');
 $a=record('22222222-2222-4222-8222-222222222222','2026-10-27T12:00:00Z');HandoverLibrary::save($a);
 $b=record('33333333-3333-4333-8333-333333333333','2026-10-27T12:00:00Z');HandoverLibrary::save($b);
 check(array_keys(HandoverLibrary::available())===[$b['operation'],$a['operation'],$old['operation']],'Newest first, including identical timestamps');
 $a['archive_size']=24;HandoverLibrary::save($a);check(array_key_first(HandoverLibrary::available())===$b['operation'],'Metadata updates do not reorder snapshots');
 check(HandoverLibrary::find($a['operation'])['operation']===$a['operation'],'Exact package selected rather than identical fingerprint');
 check(HandoverLibrary::find($a['fingerprint'])['operation']===$b['operation'],'Legacy fingerprint selects newest matching package');
 $sourceBytes=file_get_contents($a['dir'].'/oplevering.zip');update_option('blogname','Changed source');check(file_get_contents($a['dir'].'/oplevering.zip')===$sourceBytes,'Stored snapshot unaffected by source updates');
 HandoverLibrary::remove($b['operation']);check(count(HandoverLibrary::available())===2,'Delete hides exactly one package');check(is_file($b['dir'].'/oplevering.zip'),'Deleted package retained for undo');
 update_option('emsf_handover',$b);check(count(HandoverLibrary::available())===2,'Legacy pointer does not resurrect deleted package');rejects(fn()=>HandoverLibrary::find($b['operation']),'Deleted package cannot download');
 HandoverLibrary::restore($b['operation']);check(count(HandoverLibrary::available())===3,'Undo restores package');check(array_key_first(HandoverLibrary::available())===$b['operation'],'Undo preserves original creation order');
 $site=2;rejects(fn()=>HandoverLibrary::path($a),'Other site path rejected');rejects(fn()=>HandoverLibrary::find($a['operation']),'Other site package inaccessible');
 $forged=$a;$forged['site']=2;rejects(fn()=>HandoverLibrary::save($forged),'Forged site ownership cannot select original directory');check(file_get_contents($a['dir'].'/oplevering.zip')===$sourceBytes,'Other site files unchanged');$site=1;
 $bad=$a;$bad['dir']=$root.'/web';rejects(fn()=>HandoverLibrary::path($bad),'Arbitrary directory refused');
 $op='44444444-4444-4444-8444-444444444444';$link=EMSF_STATE_DIR.'/handovers/1/'.$op;symlink($root.'/web',$link);$bad=$a;$bad['operation']=$op;$bad['dir']=$link;rejects(fn()=>HandoverLibrary::path($bad),'Symlink package directory refused');unlink($link);
 $meta=json_decode(file_get_contents($old['dir'].'/handover.json'),true);$meta['site']=2;file_put_contents($old['dir'].'/handover.json',json_encode($meta));rejects(fn()=>HandoverLibrary::path($old),'Legacy metadata ownership checked');$meta['site']=1;file_put_contents($old['dir'].'/handover.json',json_encode($meta));
 HandoverLibrary::remove($a['operation']);$rows=get_option('emsf_handover_packages');$rows[$a['operation']]['deleted_at']=gmdate('c',time()-31*86400);update_option('emsf_handover_packages',$rows);HandoverLibrary::purge();
 check(!is_dir($a['dir']),'Expired explicitly deleted files purged');check(is_file($old['dir'].'/oplevering.zip')&&is_file($b['dir'].'/oplevering.zip'),'Visible package files preserved');rejects(fn()=>HandoverLibrary::restore($a['operation']),'Purged package cannot be restored');
 check(count(get_option('emsf_handover_log'))>=3,'Delete and undo recorded in log');
 echo "$n package library checks passed.\n";
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
}
