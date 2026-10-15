<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\{Factory,ThemeRelease};

/** Explicit network-wide code deployment, separate from site content. */
final class Software {
    const LIMIT=256*1024*1024;
    const CHUNK=2*1024*1024;
    public static function component(string $component): string {
        if(!in_array($component,['theme','plugin'],true)){throw new \RuntimeException('Kies het thema of de Site Factory-plugin.');}
        return $component;
    }
    public static function package(string $component,string $path): array {
        self::component($component);
        if(!class_exists('ZipArchive')){throw new \RuntimeException('PHP ZipArchive ontbreekt.');}
        $root=$component==='theme'?wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory():dirname(__DIR__,2);
        $slug=$component==='theme'?ThemeRelease::remoteSlug():'emonks-site-factory';
        $version=$component==='theme'?ThemeRelease::version():ThemeRelease::pluginVersion();
        if(!ThemeRelease::valid($version)){throw new \RuntimeException('De lokale uitgave heeft geen geldig versienummer.');}
        $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new \RuntimeException('Pakket aanmaken mislukt.');}
        try{
            // Runtime folders only: never include research, customers, local state or development tooling.
            $folders=$component==='theme'?['src','views','components','acf-json','dist','assets','fonts','static','vendor']:['includes','assets'];
            $files=[];
            foreach(glob($root.'/*')?:[] as $file){if(is_file($file)&&($component==='theme'?(in_array(pathinfo($file,PATHINFO_EXTENSION),['php','css','ico','png','json','txt'],true)&&!in_array(basename($file),['package.json','package-lock.json','package-min.json','composer.json'],true)):in_array(basename($file),['emonks-site-factory.php','shared-schema.json','README.md'],true))){$files[]=$file;}}
            foreach($folders as $folder){if(!is_dir($root.'/'.$folder)){continue;}foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$folder,\FilesystemIterator::SKIP_DOTS))as$file){if($file->isFile()){$files[]=$file->getPathname();}}}
            $size=0;
            foreach($files as $file){$relative=substr($file,strlen($root)+1);if(preg_match('~(?:^|/)\.|(?:^|/)(?:tests?|docs|node_modules)(?:/|$)~',$relative)){continue;}if(is_link($file)){throw new \RuntimeException('Een runtimebestand is een symbolische link.');}$size+=filesize($file);if($size>self::LIMIT){throw new \RuntimeException('Softwarepakket groter dan 256 MiB.');}if(!$zip->addFile($file,$slug.'/'.$relative)){throw new \RuntimeException('Runtimebestand toevoegen mislukt.');}}
        }finally{$zip->close();}
        if(filesize($path)>self::LIMIT){throw new \RuntimeException('Zip groter dan 256 MiB.');}
        return ['component'=>$component,'slug'=>$slug,'version'=>$version,'size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
    }
    public static function push(string $slug,string $component): array {
        Factory::local();self::component($component);$p=Factory::find($slug);
        return Store::locked('software-client',static function()use($slug,$component,$p){
            $status=Client::status($slug);
            if(empty($status['software_update'])){throw new \RuntimeException('Deze online plugin kan nog geen updates ontvangen. Download de lokale pluginzip en installeer die één keer via online Netwerkbeheer → Plugins → Nieuwe plugin → Uploaden. Controleer daarna opnieuw.');}
            $id=wp_generate_uuid4();$path=Store::root().'/software-'.$id.'.zip';
            try{
                Publication::progress($p['uuid'],'packaging','Lokale software verzamelen…');$meta=self::package($component,$path);
                $installed=$status['environment'][$component]['version']??'';
                if(ThemeRelease::compare((string)$installed,$meta['version'])==='ahead'){throw new \RuntimeException('De online uitgave is nieuwer. Werk eerst lokaal bij.');}
                $payload=['source'=>$p['uuid'],'upload'=>$id,'meta'=>$meta,'expected_version'=>$installed];
                Client::request('software_prepare',$payload);
                $handle=fopen($path,'rb');if(!$handle){throw new \RuntimeException('Pakket niet leesbaar.');}
                try{for($offset=0;$offset<$meta['size'];){$bytes=fread($handle,self::CHUNK);if($bytes===false||$bytes===''){throw new \RuntimeException('Pakket lezen mislukt.');}Publication::progress($p['uuid'],'uploading','Software uploaden…',$offset,$meta['size']);Client::request('software_asset',$payload+['offset'=>$offset,'bytes'=>base64_encode($bytes)]);$offset+=strlen($bytes);}}finally{fclose($handle);}
                Publication::progress($p['uuid'],'installing','Online software herstellen en bijwerken…');
                $result=Client::request('software_execute',$payload);
                // A new request loads the updated plugin, rather than the old classes in memory.
                $fresh=Client::status($slug);
                if(ThemeRelease::compare((string)($fresh['environment'][$component]['version']??''),$meta['version'])!=='current'){throw new \RuntimeException('Upload afgerond, maar de geïnstalleerde versie is nog niet bevestigd. Kies Alles controleren.');}
                Store::write('check-'.$p['uuid'],[]);
                return $result;
            }finally{if(is_file($path)){unlink($path);}}
        });
    }
    private static function key($request): string {
        $id=(string)$request['upload'];if(!preg_match('/^[a-f0-9-]{36}$/D',$id)){throw new \RuntimeException('Ongeldige upload.');}return 'software-'.$id;
    }
    private static function upload($request): array {
        $saved=Store::read(self::key($request));
        if(!$saved||($saved['source']??'')!==(string)$request['source']||($saved['user']??0)!==get_current_user_id()||($saved['expires']??0)<time()){throw new \RuntimeException('Software-upload ontbreekt of is verlopen. Start opnieuw.');}
        return $saved;
    }
    public static function prepare($request): array {
        if(!class_exists('ZipArchive')){throw new \RuntimeException('PHP ZipArchive ontbreekt online.');}
        if(!current_user_can('install_plugins')||!current_user_can('install_themes')||wp_is_file_mod_allowed('emsf_software')===false){throw new \RuntimeException('Software installeren is op deze hosting niet toegestaan.');}
        $meta=(array)$request['meta'];self::component((string)($meta['component']??''));
        $slug=$meta['component']==='theme'?ThemeRelease::slug():'emonks-site-factory';
        if(($meta['slug']??'')!==$slug||!ThemeRelease::valid((string)($meta['version']??''))||!is_int($meta['size']??null)||$meta['size']<1||$meta['size']>self::LIMIT||!preg_match('/^[a-f0-9]{64}$/D',(string)($meta['sha256']??''))){throw new \RuntimeException('Ongeldig softwarepakket.');}
        $key=self::key($request);$path=Store::root().'/'.$key.'.zip';
        if(is_file($path)||Store::read($key)){throw new \RuntimeException('Upload bestaat al. Controleer de status en start opnieuw.');}
        $expected=(string)($request['expected_version']??'');
        Store::write($key,['source'=>Store::key((string)$request['source']),'user'=>get_current_user_id(),'expires'=>time()+3600,'meta'=>$meta,'offset'=>0,'status'=>'receiving','expected_version'=>$expected]);
        if(file_put_contents($path,'')===false){throw new \RuntimeException('Uploadmap niet schrijfbaar.');}chmod($path,0600);
        return ['ready'=>true];
    }
    public static function asset($request): array {
        $saved=self::upload($request);$bytes=base64_decode((string)$request['bytes'],true);
        if($saved['status']!=='receiving'||!is_int($request['offset'])||$request['offset']!==$saved['offset']||$bytes===false||strlen($bytes)<1||strlen($bytes)>self::CHUNK||$saved['offset']+strlen($bytes)>$saved['meta']['size']){throw new \RuntimeException('Uploadblok ongeldig. Start opnieuw.');}
        $path=Store::root().'/'.self::key($request).'.zip';
        if(file_put_contents($path,$bytes,FILE_APPEND|LOCK_EX)!==strlen($bytes)){throw new \RuntimeException('Uploadblok opslaan mislukt.');}
        $saved['offset']+=strlen($bytes);Store::write(self::key($request),$saved);return ['offset'=>$saved['offset']];
    }
    /** Validate every entry before handing the local zip to WordPress. */
    public static function validate(string $path,array $meta): void {
        $zip=new \ZipArchive();if($zip->open($path)!==true){throw new \RuntimeException('Ongeldige zip.');}
        try{
            $size=0;$names=[];
            for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$name=$stat['name'];$parts=explode('/',rtrim($name,'/'));$zip->getExternalAttributesIndex($i,$ops,$attrs);
                if(!str_starts_with($name,$meta['slug'].'/')||str_contains($name,'\\')||str_contains($name,"\0")||in_array('..',$parts,true)||in_array('.',$parts,true)||in_array('',$parts,true)||(($attrs>>16)&0170000)===0120000||isset($names[$name])){throw new \RuntimeException('Onveilig pad in softwarepakket.');}$names[$name]=true;$size+=$stat['size'];if($size>self::LIMIT||$zip->numFiles>25000){throw new \RuntimeException('Uitgepakt pakket te groot.');}}
            $header=$zip->getFromName($meta['slug'].'/'.($meta['component']==='theme'?'style.css':'emonks-site-factory.php'));
            if(!is_string($header)||!preg_match('/^[ \t\/*#]*Version:\s*(\S+)/mi',$header,$match)||$match[1]!==$meta['version']){throw new \RuntimeException('Versie in softwarepakket wijkt af.');}
            if($meta['component']==='theme'&&(!isset($names[$meta['slug'].'/functions.php'])||!isset($names[$meta['slug'].'/index.php']))){throw new \RuntimeException('Themapakket onvolledig.');}
            if($meta['component']==='plugin'&&!str_contains($header,'Plugin Name: Emonks Site Factory')){throw new \RuntimeException('Verkeerde plugin.');}
        }finally{$zip->close();}
    }
    private static function invalidate(string $target): void {
        if(function_exists('opcache_invalidate')&&is_dir($target)){
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target,\FilesystemIterator::SKIP_DOTS))as$file){if($file->isFile()&&$file->getExtension()==='php'){opcache_invalidate($file->getPathname(),true);}}
        }
        wp_clean_themes_cache(false);wp_clean_plugins_cache(false);
    }
    public static function execute($request): array {
        $saved=self::upload($request);$key=self::key($request);$path=Store::root().'/'.$key.'.zip';$meta=$saved['meta'];
        if($saved['status']!=='receiving'||$saved['offset']!==$meta['size']||!hash_equals($meta['sha256'],hash_file('sha256',$path))){throw new \RuntimeException('Software-upload onvolledig of beschadigd.');}
        self::validate($path,$meta);
        $installed=$meta['component']==='theme'?ThemeRelease::version():ThemeRelease::pluginVersion();
        if($installed!==$saved['expected_version']||ThemeRelease::compare($installed,$meta['version'])==='ahead'){throw new \RuntimeException('De online uitgave is ondertussen gewijzigd of nieuwer. Controleer opnieuw.');}
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if(!wp_is_file_mod_allowed('emsf_software')||get_filesystem_method()!=='direct'||!WP_Filesystem()){throw new \RuntimeException('Hosting vereist handmatige installatie: direct schrijven is niet beschikbaar.');}
        global $wp_filesystem;
        $target=$meta['component']==='theme'?get_theme_root().'/'.$meta['slug']:WP_PLUGIN_DIR.'/emonks-site-factory';
        if(is_link($target)){throw new \RuntimeException('Online doelmap mag geen symbolische link zijn.');}
        $backup=Store::root().'/'.$key.'-backup';$existed=is_dir($target);
        if($existed){Store::copyTree($target,$backup);}
        $saved['status']='installing';$saved['backup']=$existed?$backup:'';Store::write($key,$saved);
        try{
            $skin=new \Automatic_Upgrader_Skin();$upgrader=$meta['component']==='theme'?new \Theme_Upgrader($skin):new \Plugin_Upgrader($skin);
            $result=$upgrader->install($path,['overwrite_package'=>true,'clear_update_cache'=>false]);
            if(is_wp_error($result)||$result!==true){throw new \RuntimeException(is_wp_error($result)?$result->get_error_message():'WordPress kon de software niet installeren.');}
            if($meta['component']==='theme'){\WP_Theme::network_enable_theme($meta['slug']);wp_clean_themes_cache(false);}else{wp_clean_plugins_cache(false);}
            self::invalidate($target);$saved['status']='complete';Store::write($key,$saved);if(is_file($path)){unlink($path);}
            return ['updated'=>true,'version'=>$meta['version'],'backup'=>$existed];
        }catch(\Throwable $e){
            if(file_exists($target)&&!$wp_filesystem->delete($target,true)){throw new \RuntimeException('Update mislukt; automatisch herstel is gestopt. De herstelkopie staat in private opslag.');}
            if($existed){Store::copyTree($backup,$target);}
            self::invalidate($target);$saved['status']='restored';Store::write($key,$saved);throw new \RuntimeException('Update mislukt; de vorige bestanden zijn teruggezet. '.$e->getMessage());
        }
    }
}
