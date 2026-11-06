<?php
// Contract test for transport of Word downloads; no WordPress/database/network.
use Emonks\Factory\Package;
use Emonks\Factory\Online\Receiver;
require dirname(__DIR__).'/includes/Package.php';
require dirname(__DIR__).'/includes/Online/Receiver.php';
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
$count=0;
function verify($ok,$label){global $count;if(!$ok){throw new RuntimeException($label);}$count++;}
function rejects(callable $fn,$label){try{$fn();}catch(RuntimeException $e){verify(true,$label);return;}throw new RuntimeException($label);}
$root=sys_get_temp_dir().'/emsf-document-test-'.bin2hex(random_bytes(6));mkdir($root,0700);
try {
    $source=$root.'/aanvraagformulier-noodfonds-januari-2026.docx';
    $zip=new ZipArchive();$zip->open($source,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml','<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Transport fixture</w:t></w:r></w:p></w:body></w:document>');$zip->close();
    $before=hash_file('sha256',$source);$name='media/'.$before.'-'.basename($source);
    foreach([$name,'media/DOWNLOAD.DOCX','media/photo.jpg','media/document.pdf','fonts/normal.woff2'] as $allowed){Receiver::assetName($allowed);verify(true,'Accepted '.$allowed);}
    foreach(['media/download.docx.php','media/download.php','media/../download.docx','/media/download.docx','media/foo%2fdownload.docx','media/foo\\download.docx'] as $unsafe){rejects(static fn()=>Receiver::assetName($unsafe),'Rejected '.$unsafe);}
    $package=['format'=>Package::FORMAT,'version'=>1,'theme'=>'skeletor','posts'=>[],'terms'=>[],'menus'=>[],'forms'=>[],'options'=>[],'files'=>[]];$dir=$root.'/package';
    $asset=new ReflectionMethod(Package::class,'asset');$args=[$source,$name,$dir,&$package];$asset->invokeArgs(null,$args);
    verify(hash_file('sha256',$source)===$before,'Source document unchanged');
    verify(hash_file('sha256',$dir.'/files/'.$name)===$before,'Document bytes copied intact');
    Package::json($dir.'/manifest.json',$package);verify(Package::read($dir)['files'][$name]['sha256']===$before,'DOCX accepted by package reader');
    file_put_contents($dir.'/files/'.$name,'corrupted');rejects(static fn()=>Package::read($dir),'Corrupt Word download rejected');
    echo "$count document transport checks passed.\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
}
