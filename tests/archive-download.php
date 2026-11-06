<?php
/** Reproduce an archive bigger than available PHP memory, with output buffering enabled. */
use Emonks\Factory\Handover;
require dirname(__DIR__).'/includes/Handover.php';
function nocache_headers(): void {}
if(($argv[1]??'')==='stream'){
    $baseline=str_repeat('x',88*1024*1024);ob_start();echo 'This buffered page output must not appear in the archive.';
    Handover::streamArchive($argv[2],'site-oplevering.zip');exit;
}
$root=sys_get_temp_dir().'/emsf-download-test-'.bin2hex(random_bytes(6));mkdir($root,0700);$checks=0;
function check($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);}$checks++;}
try{
    $source=$root.'/payload.bin';$file=fopen($source,'wb');ftruncate($file,60*1024*1024);fclose($file);
    $path=$root.'/package.zip';$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE);$zip->addFile($source,'payload.bin');$zip->setCompressionName('payload.bin',ZipArchive::CM_STORE);$zip->close();
    check(filesize($path)>60*1024*1024,'Fixture is larger than 60 MiB');
    $out=$root.'/download.zip';$err=$root.'/stderr.txt';
    $process=proc_open([PHP_BINARY,'-d','memory_limit=128M',__FILE__,'stream',$path],[0=>['pipe','r'],1=>['file',$out,'wb'],2=>['file',$err,'wb']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start memory-limited child');}fclose($pipes[0]);$code=proc_close($process);
    check($code===0,'Memory-limited streaming succeeds: '.file_get_contents($err));
    check(filesize($out)===filesize($path),'Entire archive received');
    check(hash_file('sha256',$out)===hash_file('sha256',$path),'Output matches archive bytes; no buffered HTML or error page');
    check(file_get_contents($err)==='','No PHP warnings or memory errors');
    $zip=new ZipArchive();check($zip->open($out)===true,'Received file is a ZIP');$zip->close();
    try{Handover::streamArchive($path,"bad\r\nname.zip");throw new LogicException('Header injection accepted');}catch(RuntimeException $e){check(true,'Unsafe filename rejected before download');}
    try{Handover::streamArchive($root.'/missing.zip','package.zip');throw new LogicException('Missing file accepted');}catch(RuntimeException $e){check(true,'Missing file rejected before download');}
    echo "$checks bounded-memory archive download checks passed.\n";
}finally{foreach(glob($root.'/*') as $file){unlink($file);}rmdir($root);}
