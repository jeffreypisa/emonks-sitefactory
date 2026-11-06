<?php
namespace Emonks\Factory\Online {
final class Sites {public static bool $allowed=true;public static function requireAdmin():void{if(!self::$allowed)throw new \RuntimeException('Administrator required');}}
final class Store {public static string $root;public static function root():string{return self::$root;}public static function locked($key,$fn){return $fn();}}
}
namespace Emonks\Factory {
final class ThemeRelease {public static function pluginVersion():string{preg_match('/Version:\s*([0-9.]+)/',file_get_contents(dirname(__DIR__).'/emonks-site-factory.php'),$m);return $m[1];}}
require dirname(__DIR__).'/includes/Handover.php';
$dir=sys_get_temp_dir().'/emsf-importer-archive-'.bin2hex(random_bytes(6));mkdir($dir,0700);Online\Store::$root=$dir;
try{
 $path=Handover::importerArchive();$zip=new \ZipArchive();if($zip->open($path)!==true)throw new \RuntimeException('Archive invalid');
 $checks=0;
 foreach(['shared-schema.json','includes/Factory.php','includes/ThemeRelease.php','includes/Package.php','includes/Handover.php','includes/HandoverLibrary.php','includes/Standalone.php','includes/StandaloneContent.php','includes/DeliverySoftware.php','includes/Online/Receiver.php'] as $file){
  if($zip->getFromName('emonks-site-importer/'.$file)!==file_get_contents(dirname(__DIR__).'/'.$file))throw new \RuntimeException('Importer archive missing or changing '.$file);$checks++;
 }
 if(!str_contains($zip->getFromName('emonks-site-importer/emonks-site-importer.php'),'Version: '.ThemeRelease::pluginVersion()))throw new \RuntimeException('Importer version mismatch');$checks++;$zip->close();
 Online\Sites::$allowed=false;try{Handover::importerArchive();throw new \RuntimeException('Unauthorized archive accepted');}catch(\RuntimeException $e){if($e->getMessage()!=='Administrator required')throw $e;$checks++;}
 echo "PASS: $checks in-app importer archive, exact runtime bytes, version and access checks.\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
}
