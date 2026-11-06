<?php
// Runtime bundling and file-install contracts; only synthetic temporary directories.
use Emonks\Factory\{DeliverySoftware,ThemeRelease};
$root=sys_get_temp_dir().'/emsf-delivery-software-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/plugins');mkdir($root.'/themes');mkdir($root.'/bundle');mkdir($root.'/bundle/software');
define('WP_PLUGIN_DIR',$root.'/plugins');define('ABSPATH',$root.'/');
$allowed=true;$active=[];
function current_user_can($cap){return $GLOBALS['allowed'];}function wp_is_file_mod_allowed($context){return true;}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0755,true);}function get_theme_root(){return $GLOBALS['root'].'/themes';}
function get_stylesheet(){return 'skeletor';}function is_plugin_active($file){return in_array($file,$GLOBALS['active'],true);}
function get_plugins(){
 $out=[];foreach(glob(WP_PLUGIN_DIR.'/*/*.php')?:[] as $file){$data=file_get_contents($file);if(preg_match('/Plugin Name:\s*([^\r\n]+)/',$data,$n)&&preg_match('/Version:\s*(\S+)/',$data,$v)){$out[basename(dirname($file)).'/'.basename($file)]=['Name'=>trim($n[1]),'Version'=>$v[1]];}}return $out;
}
function wp_get_theme($slug){return new class($slug){public function __construct(private string $slug){}public function exists(){return is_file(get_theme_root().'/'.$this->slug.'/style.css');}public function get($name){$file=get_theme_root().'/'.$this->slug.'/style.css';$text=is_file($file)?file_get_contents($file):'';preg_match('/Version:\s*(\S+)/',$text,$m);return $m[1]??'';}};}
require dirname(__DIR__).'/includes/ThemeRelease.php';require dirname(__DIR__).'/includes/DeliverySoftware.php';
$n=0;function check($ok,$label){global $n;if(!$ok){throw new RuntimeException($label);}$n++;}function rejects($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function zipFiles($path,$files){$z=new ZipArchive();$z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach($files as $name=>$body){$z->addFromString($name,$body);}$z->close();}
try {
 $specs=[['theme','theme','skeletor','style.css','Skeletor'],['acf','plugin','advanced-custom-fields-pro','acf.php','Advanced Custom Fields PRO'],['dashboard','plugin','emonks-dashboard','websitebeheer.php','Emonks Dashboard']];$components=[];
 foreach($specs as [$role,$kind,$slug,$entry,$name]){
  $header="<?php\n/*\n".($kind==='theme'?'Theme':'Plugin')." Name: $name\nVersion: 1.0.0\n*/";
  $files=[$slug.'/'.$entry=>$header];if($kind==='theme'){$files[$slug.'/index.php']='<?php';$files[$slug.'/functions.php']='<?php';}
  $path=$root.'/bundle/software/'.$role.'.zip';zipFiles($path,$files);
  $components[]=['role'=>$role,'kind'=>$kind,'slug'=>$slug,'entry'=>$entry,'name'=>$name,'version'=>'1.0.0','archive'=>'software/'.$role.'.zip','sha256'=>hash_file('sha256',$path),'size'=>filesize($path)];
 }
 $bundle=['format'=>DeliverySoftware::FORMAT,'components'=>$components];DeliverySoftware::validate($root.'/bundle',$bundle);check(true,'Complete runtime bundle validates');
 $bad=$bundle;$bad['components'][]=$components[0];rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Duplicate role');
 $bad=$bundle;$bad['components'][0]['target']='/outside';rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Forged installation target');
 $bad=$bundle;$bad['components'][0]['slug']='../outside';rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Unsafe slug');
 $bad=$bundle;$bad['components'][1]['version']='2.0.0';rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Header version mismatch');
 $bad=$bundle;$bad['components'][1]['sha256']=str_repeat('0',64);rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Archive checksum mismatch');
 $bad=$bundle;array_pop($bad['components']);rejects(fn()=>DeliverySoftware::validate($root.'/bundle',$bad),'Missing dependencies');
 $evil=$root.'/evil.zip';zipFiles($evil,['skeletor/style.css'=>"/* Version: 1.0.0 */",'skeletor/../escape.php'=>'<?php']);rejects(fn()=>DeliverySoftware::inspect($evil,$components[0]),'Nested path traversal');
 $plan=DeliverySoftware::plan($bundle);check(count($plan)===3,'Installation plan before ACF exists');
 $operation='12345678-1234-1234-1234-123456789012';foreach($plan as $entry){DeliverySoftware::install($root.'/bundle',$entry,$operation);}
 check(is_file(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/acf.php'),'Missing ACF installed from bundle');check(is_file(get_theme_root().'/skeletor/functions.php'),'Theme installed from bundle');
 file_put_contents(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/preserve.txt','Existing file');DeliverySoftware::install($root.'/bundle',$plan[1],$operation);check(file_get_contents(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/preserve.txt')==='Existing file','Retry does not overwrite existing install');
 unlink(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/acf.php');rejects(fn()=>DeliverySoftware::install($root.'/bundle',$plan[1],$operation),'Incomplete existing runtime rejected');$restore=new ZipArchive();$restore->open($root.'/bundle/software/acf.zip');file_put_contents(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/acf.php',$restore->getFromName('advanced-custom-fields-pro/acf.php'));$restore->close();
 rejects(fn()=>DeliverySoftware::confirm($bundle),'Inactive plugin cannot pass content preflight');
 $active=['advanced-custom-fields-pro/acf.php','emonks-dashboard/websitebeheer.php'];DeliverySoftware::confirm($bundle);check(true,'Activation confirmation');
 file_put_contents(WP_PLUGIN_DIR.'/advanced-custom-fields-pro/acf.php',"<?php\n/* Plugin Name: Advanced Custom Fields PRO\nVersion: 2.0.0\n*/");rejects(fn()=>DeliverySoftware::plan($bundle),'Existing different version never overwritten');
 $allowed=false;rejects(fn()=>DeliverySoftware::plan($bundle),'Installation capability required');$allowed=true;
 $source=$root.'/source';mkdir($source);file_put_contents($source.'/acf.php','<?php');file_put_contents($source.'/composer.json','{"autoload":{}}');file_put_contents($source.'/.env','private');file_put_contents($source.'/customer.sql','private');mkdir($source.'/tests');file_put_contents($source.'/tests/fixture.php','<?php');
 $packed=$root.'/packed.zip';DeliverySoftware::packPlugin($source,$packed,'acf');$z=new ZipArchive();$z->open($packed);check($z->numFiles===2&&$z->locateName('acf/acf.php')!==false&&$z->locateName('acf/composer.json')!==false,'Only runtime files bundled; no env, SQL or tests');$z->close();
 $first=hash_file('sha256',$packed);DeliverySoftware::packPlugin($source,$packed,'acf');check($first===hash_file('sha256',$packed),'Bundle stable for repeated approval fingerprint');
 symlink($source.'/acf.php',$source.'/linked.php');rejects(fn()=>DeliverySoftware::packPlugin($source,$packed,'acf'),'Runtime symlink rejected');
 echo "$n delivery software checks passed.\n";
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
}
