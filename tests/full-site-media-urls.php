<?php
// No WordPress/database/network; exercise the real import mapping and file adapter.
use Emonks\Factory\FullSite;
require ($argv[1]??dirname(__DIR__).'/includes/FullSite.php');
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function is_serialized($value){return is_string($value)&&preg_match('/^(a|O|s|b|i|d|N)[:;]/',$value);}
$n=0;function check($ok){global $n;if(!$ok){throw new RuntimeException('Check '.($n+1).' failed');}$n++;}
$upload=['basedir'=>'/destination/uploads','baseurl'=>'https://destination.test/wp-content/uploads'];
$source='https://shop.source.test';$path='/wp-content/uploads/sites/25';$relative='/2026/10/SLAMMER-2.0.pdf';
foreach(['https://network.source.test',$source] as $host){
    $m=['source'=>$source,'source_uploads'=>['dir'=>'/source/uploads/sites/25','url'=>$host.$path]];
    $map=FullSite::importReplacements($m,$upload,'https://destination.test');
    // Same escape expansion as the real import, including JSON-encoded URLs.
    foreach($map as $a=>$b){$map[str_replace('/','\\/',$a)]=str_replace('/','\\/',$b);}
    foreach([$source.$path.$relative,$host.$path.$relative] as $url){
        check(FullSite::rewrite('<a href="'.$url.'">maattabel</a>',$map)==='<a href="'.$upload['baseurl'].$relative.'">maattabel</a>');
        $value=serialize(['nested'=>['download'=>$url]]);
        check(unserialize(FullSite::rewrite($value,$map))['nested']['download']===$upload['baseurl'].$relative);
        check(json_decode(FullSite::rewrite(json_encode(['url'=>$url]),$map),true)['url']===$upload['baseurl'].$relative);
    }
    check(FullSite::rewrite($source.'/shop/hoodie/',$map)==='https://destination.test/shop/hoodie/');
    check(FullSite::rewrite('https://external.test'.$path.$relative,$map)==='https://external.test'.$path.$relative);
    check(FullSite::rewrite('/source/uploads/sites/25'.$relative,$map)===$upload['basedir'].$relative);
}
// Subdirectory sites and ports must use the source origin, not append its site path.
$m=['source'=>'https://network.test:8443/shop','source_uploads'=>['dir'=>'/source','url'=>'https://network.test:8443/wp-content/uploads/sites/25']];
$map=FullSite::importReplacements($m,$upload,'https://destination.test');
check(FullSite::rewrite($m['source_uploads']['url'].$relative,$map)===$upload['baseurl'].$relative);
// A PDF is materialized byte-for-byte with the same method used by the import.
$root=realpath(sys_get_temp_dir()).'/emsf-media-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/files');mkdir($root.'/files/snapshot');
try {
    $bytes="%PDF-1.4\nSize guide transport fixture\n%%EOF\n";$hash=hash('sha256',$bytes);$chunk='snapshot/'.$hash.'.bin';file_put_contents($root.'/files/'.$chunk,$bytes);
    $m=['blobs'=>[$hash=>['size'=>strlen($bytes),'chunks'=>[$chunk]]],'files'=>[$chunk=>['size'=>strlen($bytes),'sha256'=>$hash]]];
    (new ReflectionMethod(FullSite::class,'materialize'))->invoke(null,$root,$m,$hash,$root.'/SLAMMER-2.0.pdf',0644);
    check(file_get_contents($root.'/SLAMMER-2.0.pdf')===$bytes);
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
}
echo "$n media URL and PDF checks passed.\n";
