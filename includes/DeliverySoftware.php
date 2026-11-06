<?php
declare(strict_types=1);
namespace Emonks\Factory;

/** Runtime software for an explicitly reviewed standalone delivery, never the Factory network. */
final class DeliverySoftware {
    public const FORMAT='emonks-delivery-software-v1';
    public const LIMIT=256*1024*1024;
    private const PLUGINS=[
        'acf'=>['entry'=>'acf.php','name'=>'Advanced Custom Fields PRO'],
        'dashboard'=>['entry'=>'websitebeheer.php','name'=>'Emonks Dashboard'],
        'forms'=>['entry'=>'fluentform.php','name'=>'Fluent Forms'],
        'forms_pro'=>['entry'=>'fluentformpro.php','name'=>'Fluent Forms Pro Add On Pack'],
    ];
    public static function plugins(): array {if(!function_exists('get_plugins')){require_once ABSPATH.'wp-admin/includes/plugin.php';}return get_plugins();}
    public static function find(string $role,bool $active=false): ?array {
        if(!isset(self::PLUGINS[$role])){throw new \RuntimeException('Onbekende softwareafhankelijkheid.');}
        $rule=self::PLUGINS[$role];$found=[];
        foreach(self::plugins() as $file=>$data){if(basename($file)===$rule['entry']&&($data['Name']??'')===$rule['name']){$found[]=['file'=>$file,'slug'=>dirname($file),'name'=>$rule['name'],'version'=>(string)$data['Version'],'active'=>is_plugin_active($file)];}}
        if(count($found)>1){throw new \RuntimeException('Meerdere installaties van '.$rule['name'].'. Verwijder eerst de dubbele installatie.');}
        $result=$found[0]??null;
        if($active&&(!$result||!$result['active'])){throw new \RuntimeException('Activeer '.$rule['name'].' op de bronwebsite voordat je het pakket maakt.');}
        return $result;
    }
    /** A standalone delivery includes site plugins and runtime dependencies, never Factory itself. */
    private static function runtimePlugins(): array {
        $files=array_unique(array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[]))));
        $result=[];$installed=self::plugins();
        foreach($files as $file){
            if(preg_match('~^(?:emonks-site-factory|emonks-sitefactory|emonks-site-importer)/~',$file)){continue;}
            if(!isset($installed[$file])||dirname($file)==='.'||substr_count($file,'/')!==1){throw new \RuntimeException('Een actieve plugin kan niet zelfstandig worden verpakt: '.$file);}
            $data=$installed[$file];$role='plugin_'.substr(hash('sha256',$file),0,16);
            foreach(self::PLUGINS as $known=>$rule){if(basename($file)===$rule['entry']&&$data['Name']===$rule['name']){$role=$known;}}
            $result[$role]=['file'=>$file,'slug'=>dirname($file),'name'=>$data['Name'],'version'=>$data['Version']];
        }
        foreach(['acf','dashboard'] as $required){if(!isset($result[$required])){throw new \RuntimeException('Een vereiste runtimeplugin ontbreekt: '.$required);}}
        return $result;
    }
    private static function dynamic(string $role): bool {return (bool)preg_match('/^plugin_[a-f0-9]{16}$/D',$role);}
    private static function locate(array $entry): ?array {
        if(!self::dynamic($entry['role'])){return self::find($entry['role']);}
        foreach(self::plugins() as $file=>$data){if($file===$entry['slug'].'/'.$entry['entry']){if($data['Name']!==$entry['name']){throw new \RuntimeException('Pluginidentiteit op de bestemming wijkt af.');}return ['file'=>$file,'slug'=>dirname($file),'version'=>$data['Version']];}}return null;
    }
    public static function collect(string $dir,array $content): array {
        if(!class_exists('ZipArchive')){throw new \RuntimeException('PHP ZIP ontbreekt.');}
        if(get_stylesheet()!==get_template()){throw new \RuntimeException('De softwareoverdracht ondersteunt het gedeelde thema zonder child theme.');}
        $theme=ThemeRelease::validateSlug(get_stylesheet());wp_mkdir_p($dir.'/software');$entries=[];
        $meta=Online\Software::package('theme',$dir.'/software/theme.zip','',$theme);
        $entries[]=['role'=>'theme','kind'=>'theme','name'=>wp_get_theme($theme)->get('Name'),'slug'=>$theme,'entry'=>'style.css','archive'=>'software/theme.zip','version'=>$meta['version'],'size'=>$meta['size'],'sha256'=>$meta['sha256']];
        $roles=['acf','dashboard'];
        if(!empty($content['forms'])){$roles[]='forms';$pro=self::find('forms_pro');if($pro&&$pro['active']){$roles[]='forms_pro';}}
         $runtime=($content['format']??'')===FullSite::FORMAT?self::runtimePlugins():array_combine($roles,array_map(static fn($role)=>self::find($role,true),$roles));
        foreach($runtime as $role=>$plugin){
            ThemeRelease::validateSlug($plugin['slug']);
            $path=$dir.'/software/'.$role.'.zip';
            if($role==='dashboard'){$meta=Online\Software::package('dashboard',$path,$plugin['slug']);}
            else{$meta=self::packPlugin(WP_PLUGIN_DIR.'/'.$plugin['slug'],$path,$plugin['slug']);}
            $entries[]=['role'=>$role,'kind'=>'plugin','name'=>$plugin['name'],'slug'=>$plugin['slug'],'entry'=>basename($plugin['file']),'archive'=>'software/'.$role.'.zip','version'=>$plugin['version'],'size'=>$meta['size'],'sha256'=>$meta['sha256']];
        }
        $bundle=['format'=>self::FORMAT,'components'=>$entries];self::validate($dir,$bundle);return $bundle;
    }
    /** Pack installed vendor code, excluding development, local storage and secret-file formats. */
    public static function packPlugin(string $root,string $path,string $slug): array {
        $root=realpath($root);ThemeRelease::validateSlug($slug);if(!$root){throw new \RuntimeException('Pluginmap ontbreekt.');}
        $files=[];$size=0;
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){
            $relative=substr($file->getPathname(),strlen($root)+1);
            if(preg_match('~(?:^|/)(?:\.[^/]*|node_modules|tests?|docs|backups?|uploads|private|vendor/bin)(?:/|$)~',$relative)||preg_match('~(?:^|/)(?:wp-config\.php|composer\.lock|package(?:-lock)?\.json)$|\.(?:sql|log|pem|key|cookie|token)$~i',$relative)){continue;}
            if($file->isLink()){throw new \RuntimeException('Symbolische link in pluginruntime: '.$relative);}
            if(!$file->isFile()){continue;}$size+=$file->getSize();if($size>self::LIMIT||count($files)>=25000){throw new \RuntimeException('Pluginruntime is te groot.');}$files[$relative]=$file->getPathname();
        }
        ksort($files);$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new \RuntimeException('Pluginzip maken mislukt.');}
        try{foreach($files as $relative=>$file){if(!$zip->addFile($file,$slug.'/'.$relative)){throw new \RuntimeException('Pluginbestand toevoegen mislukt.');}}}finally{if(!$zip->close()){throw new \RuntimeException('Pluginzip afronden mislukt.');}}
        return ['size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
    }
    /** Validate all declared archives before any executable file is installed. */
    public static function validate(string $dir,array $bundle): void {
        if(($bundle['format']??'')!==self::FORMAT||!is_array($bundle['components']??null)||count($bundle['components'])<3||count($bundle['components'])>100){throw new \RuntimeException('Ongeldig softwareoverzicht.');}
        $roles=[];$destinations=[];$bytes=0;$expanded=0;
        foreach($bundle['components'] as $entry){
            $role=$entry['role']??'';
            if(array_diff(array_keys($entry),['role','kind','name','slug','entry','archive','version','size','sha256'])||!is_string($entry['name']??null)||$entry['name']===''||strlen($entry['name'])>200){throw new \RuntimeException('Onverwachte software-eigenschappen.');}
            if((!in_array($role,['theme','acf','dashboard','forms','forms_pro'],true)&&!self::dynamic($role))||isset($roles[$role])){throw new \RuntimeException('Onbekende of dubbele softwarecomponent.');}$roles[$role]=true;
            ThemeRelease::validateSlug((string)($entry['slug']??''));
            $kind=$role==='theme'?'theme':'plugin';$main=$role==='theme'?'style.css':(self::PLUGINS[$role]['entry']??($entry['entry']??''));if(self::dynamic($role)&&(!preg_match('/^[A-Za-z0-9_.-]+\.php$/D',$main)||$role!=='plugin_'.substr(hash('sha256',$entry['slug'].'/'.$main),0,16))){throw new \RuntimeException('Ongeldige pluginidentiteit.');}
            if(($entry['kind']??'')!==$kind||($entry['entry']??'')!==$main||($entry['archive']??'')!=='software/'.$role.'.zip'||!ThemeRelease::valid((string)($entry['version']??''))||!is_int($entry['size']??null)||$entry['size']<1||$entry['size']>self::LIMIT||!preg_match('/^[a-f0-9]{64}$/D',(string)($entry['sha256']??''))){throw new \RuntimeException('Ongeldige softwarecomponent.');}
            $dest=$kind.':'.$entry['slug'];if(isset($destinations[$dest])){throw new \RuntimeException('Dubbele softwaredoelmap.');}$destinations[$dest]=true;
            $path=$dir.'/'.$entry['archive'];if(!is_file($path)||is_link($path)||filesize($path)!==$entry['size']||!hash_equals($entry['sha256'],hash_file('sha256',$path))){throw new \RuntimeException('Softwarebestand ontbreekt of is beschadigd: '.$role);}
            $expanded+=self::inspect($path,$entry);$bytes+=$entry['size'];
            if($bytes>self::LIMIT||$expanded>self::LIMIT){throw new \RuntimeException('Gebundelde software is groter dan 256 MiB.');}
        }
        if(!isset($roles['theme'],$roles['acf'],$roles['dashboard'])||(isset($roles['forms_pro'])&&!isset($roles['forms']))){throw new \RuntimeException('Benodigde software ontbreekt in het overzicht.');}
    }
    public static function inspect(string $path,array $entry): int {
        $zip=new \ZipArchive();if($zip->open($path)!==true){throw new \RuntimeException('Ongeldige softwarezip.');}
        try{
            $seen=[];$size=0;
            if($zip->numFiles>25000){throw new \RuntimeException('Te veel softwarebestanden.');}
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=$stat['name'];$parts=explode('/',rtrim($name,'/'));$os=0;$attrs=0;$zip->getExternalAttributesIndex($i,$os,$attrs);
                if(!str_starts_with($name,$entry['slug'].'/')||str_contains($name,'\\')||str_contains($name,"\0")||str_contains($name,':')||in_array('..',$parts,true)||in_array('.',$parts,true)||in_array('',$parts,true)||isset($seen[$name])||(($attrs>>16)&0170000)===0120000){throw new \RuntimeException('Onveilig pad in softwarepakket.');}
                $seen[$name]=true;$size+=$stat['size'];if($size>self::LIMIT){throw new \RuntimeException('Uitgepakte software is te groot.');}
            }
            $header=$zip->getFromName($entry['slug'].'/'.$entry['entry'],8192);
            if(!is_string($header)||!preg_match('/^[ \t\/*#]*Version:\s*(\S+)/mi',$header,$v)||$v[1]!==$entry['version']){throw new \RuntimeException('Softwareversie wijkt af van het pakket.');}
            if($entry['kind']==='theme'){
                if(!isset($seen[$entry['slug'].'/functions.php'],$seen[$entry['slug'].'/index.php'])){throw new \RuntimeException('Themapakket is onvolledig.');}
            }elseif(!preg_match('/^[ \t\/*#]*Plugin Name:\s*'.preg_quote(self::PLUGINS[$entry['role']]['name']??$entry['name'],'/').'\s*$/mi',$header)){throw new \RuntimeException('Pluginidentiteit wijkt af.');}
            return $size;
        }finally{$zip->close();}
    }
    public static function plan(array $bundle): array {
        if(!current_user_can('install_plugins')||!current_user_can('activate_plugins')||!current_user_can('install_themes')||!current_user_can('switch_themes')||!wp_is_file_mod_allowed('emsf_delivery')){throw new \RuntimeException('De beheerder mag op deze hosting geen thema’s en plugins installeren en activeren.');}
        $result=[];
        foreach($bundle['components'] as $entry){
            if($entry['kind']==='theme'){$theme=wp_get_theme($entry['slug']);$existing=$theme->exists();$version=$existing?(string)$theme->get('Version'):'';$target=get_theme_root().'/'.$entry['slug'];$file='';}
            else{$plugin=self::locate($entry);$existing=$plugin!==null;$version=$plugin['version']??'';$target=WP_PLUGIN_DIR.'/'.($plugin['slug']??$entry['slug']);$file=$plugin['file']??($entry['slug'].'/'.$entry['entry']);if(!$existing&&file_exists($target)){throw new \RuntimeException('De pluginmap bestaat al en hoort niet bij '.$entry['name'].'.');}}
            if(is_link($target)){throw new \RuntimeException('De softwaredoelmap is een symbolische link. Gebruik een gewone hostinginstallatie.');}
            if($existing&&$version!==$entry['version']){throw new \RuntimeException($entry['name'].' staat al op de bestemming met versie '.$version.'; het pakket bevat '.$entry['version'].'. Bestaande software wordt niet overschreven. Gebruik dezelfde versie of een nieuwe lege installatie.');}
            if(file_exists($target)&&!is_dir($target)){throw new \RuntimeException('De softwaredoelmap is geen map.');}
            $result[]=array_replace($entry,['target'=>$target,'installed_file'=>$file,'exists'=>$existing]);
        }
        return $result;
    }
    public static function confirm(array $bundle): void {
        foreach(self::plan($bundle) as $component){
            if(!$component['exists']||($component['kind']==='theme'?get_stylesheet()!==$component['slug']:!is_plugin_active($component['installed_file']))){throw new \RuntimeException('Installeer en activeer eerst alle software uit het pakket: '.$component['name']);}
        }
    }
    /** Atomic creation of absent software only; existing installations are never replaced. */
    public static function install(string $dir,array $entry,string $operation): void {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$operation)){throw new \RuntimeException('Ongeldige softwareoperatie.');}
        $path=$dir.'/'.$entry['archive'];$expanded=self::inspect($path,$entry);
        if(file_exists($entry['target'])){self::verifyInstalled($path,$entry);return;}
        $parent=dirname($entry['target']);if(!is_dir($parent)||!is_writable($parent)){throw new \RuntimeException('De hostingmap voor '.$entry['name'].' is niet schrijfbaar.');}
        $free=disk_free_space($parent);if($free===false||$free<$expanded*2+64*1024*1024){throw new \RuntimeException('Onvoldoende ruimte om '.$entry['name'].' te installeren.');}
        $stage=$parent.'/.emsf-'.$operation.'-'.$entry['role'];
        if(is_link($stage)){throw new \RuntimeException('Onveilige softwarewerkmap.');}if(is_dir($stage)){self::removeStage($stage);}
        if(!mkdir($stage,0700)){throw new \RuntimeException('Softwarewerkmap maken mislukt.');}
        $zip=new \ZipArchive();$zip->open($path);
        try{
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=$stat['name'];$relative=substr($name,strlen($entry['slug'])+1);if($relative===''){continue;}
                $dest=$stage.'/'.$relative;if(str_ends_with($name,'/')){wp_mkdir_p($dest);continue;}
                if(!wp_mkdir_p(dirname($dest))){throw new \RuntimeException('Softwaremap maken mislukt.');}
                $in=$zip->getStream($name);$out=fopen($dest,'xb');if(!$in||!$out){throw new \RuntimeException('Softwarebestand openen mislukt.');}
                try{$size=stream_copy_to_stream($in,$out,$stat['size']+1);}finally{fclose($in);fclose($out);}
                if($size!==$stat['size']||!chmod($dest,0644)){throw new \RuntimeException('Softwarebestand is onvolledig.');}
            }
            chmod($stage,0755);if(file_exists($entry['target'])||!rename($stage,$entry['target'])){throw new \RuntimeException('Softwaredoelmap is gewijzigd of kan niet worden geplaatst.');}
        }finally{$zip->close();if(is_dir($stage)){self::removeStage($stage);}}
    }
    private static function verifyInstalled(string $path,array $entry): void {
        $root=realpath($entry['target']);$zip=new \ZipArchive();$zip->open($path);
        try{
            for($i=0;$i<$zip->numFiles;$i++){
                $name=$zip->getNameIndex($i);if(str_ends_with($name,'/')){continue;}$file=$entry['target'].'/'.substr($name,strlen($entry['slug'])+1);$real=realpath($file);
                if(!$root||!$real||!str_starts_with($real,$root.'/')||is_link($file)||!is_file($file)){throw new \RuntimeException('De bestaande installatie van '.$entry['name'].' is onvolledig. Zij wordt niet overschreven.');}
                $in=$zip->getStream($name);if(!$in){throw new \RuntimeException('Softwarebestand lezen mislukt.');}
                try{$hash=hash_init('sha256');hash_update_stream($hash,$in);$expected=hash_final($hash);}finally{fclose($in);}
                if(!hash_equals($expected,hash_file('sha256',$file))){throw new \RuntimeException('Bestanden van de bestaande '.$entry['name'].' wijken af van het pakket. Zij worden niet overschreven.');}
            }
        }finally{$zip->close();}
    }
    private static function removeStage(string $path): void {
        if(!preg_match('/^\.emsf-[a-f0-9-]{36}-(theme|acf|dashboard|forms|forms_pro|plugin_[a-f0-9]{16})$/D',basename($path))||is_link($path)){throw new \RuntimeException('Onveilige softwarewerkmap.');}
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($path);
    }
    public static function activate(array $entry): void {
        if($entry['kind']==='theme'){switch_theme($entry['slug']);return;}
        $file=$entry['installed_file'];if(is_plugin_active($file)){return;}
        // Fluent Forms' public migration methods initialize its schema without creating
        // the vendor demo form, which would otherwise make an empty target nonempty.
        $forms=$entry['role']==='forms';$result=activate_plugin($file,'',false,$forms);
        if(is_wp_error($result)){throw new \RuntimeException($result->get_error_message());}
        if($forms){
            $class='FluentForm\\App\\Hooks\\Handlers\\ActivationHandler';
            if(!class_exists($class)||!method_exists($class,'migrate')||!method_exists($class,'setCronSchedule')){deactivate_plugins($file,true);throw new \RuntimeException('Deze Fluent Forms-versie kan niet zonder voorbeeldinhoud worden voorbereid.');}
            $handler=new $class();$handler->migrate();$handler->setCronSchedule();
        }
        if(!is_plugin_active($file)){throw new \RuntimeException('Pluginactivering is niet bevestigd: '.$entry['name']);}
    }
}
