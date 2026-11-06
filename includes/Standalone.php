<?php
declare(strict_types=1);
namespace Emonks\Factory;

require_once __DIR__.'/StandaloneContent.php';
require_once __DIR__.'/FullSite.php';
require_once __DIR__.'/UploadTransport.php';

/** Temporary single-site receiver. Its journal survives timeouts and plugin removal. */
final class Standalone {
    private static ?array $context=null;
    private const OPTION='emsf_handover_import';
    public static function importing(): bool { return self::$context!==null; }
    public static function authorize(): void {
        if (!defined('EMSF_HANDOVER_IMPORTER') || !EMSF_HANDOVER_IMPORTER || is_multisite() || !current_user_can('manage_options') || !current_user_can('install_plugins')) { throw new \RuntimeException('Gebruik de tijdelijke importplugin als beheerder op een zelfstandige WordPress-installatie.'); }
        if ((defined('EMSF_LOCAL')&&EMSF_LOCAL)||(defined('EMSF_ONLINE')&&EMSF_ONLINE)) { throw new \RuntimeException('De bestemming mag geen Factory-omgeving zijn.'); }
    }
    public static function storage(): string {
        $base=realpath(get_temp_dir());$web=realpath(ABSPATH);$content=realpath(WP_CONTENT_DIR);
        if(!$base||!$web||$base===$web||str_starts_with($base,$web.'/')||($content&&($base===$content||str_starts_with($base,$content.'/')))){throw new \RuntimeException('Stel WP_TEMP_DIR in op een private schrijfbare map buiten alle webroots.');}
        $dir=$base.'/emonks-handover-'.substr(hash('sha256',ABSPATH),0,24);
        if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700))||!is_writable($dir)||!chmod($dir,0700)){throw new \RuntimeException('Private tijdelijke opslag is niet schrijfbaar.');}return $dir;
    }
    /** Read the durable row, not a possibly stale persistent object cache after rollback. */
    public static function state(): array {
        global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::OPTION));return $raw?(array)maybe_unserialize($raw):[];
    }
    private static function save(array $value): void {
        global $wpdb;
        if($wpdb->replace($wpdb->options,['option_name'=>self::OPTION,'option_value'=>maybe_serialize($value),'autoload'=>'off'])===false){throw new \RuntimeException('Opleverstatus opslaan mislukt.');}
        wp_cache_delete(self::OPTION,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
    }
    public static function locked(callable $fn) {
        $file=fopen(self::storage().'/import.lock','c');if(!$file||!flock($file,LOCK_EX|LOCK_NB)){throw new \RuntimeException('Een opleveractie is nog bezig. Probeer straks opnieuw.');}
        try{return $fn();}finally{flock($file,LOCK_UN);fclose($file);}
    }
    /** Strict, bounded extraction: only declared data files, no scripts, symlinks or duplicates. */
    public static function unpack(string $archive): string {
        self::authorize();if(!class_exists('ZipArchive')){throw new \RuntimeException('De PHP ZIP-extensie ontbreekt.');}
        $zip=new \ZipArchive();if($zip->open($archive)!==true){throw new \RuntimeException('Pakketzip is ongeldig.');}
        $dir=self::storage().'/incoming-'.wp_generate_uuid4();mkdir($dir,0700);
        try {
            if($zip->numFiles>20000){throw new \RuntimeException('Te veel pakketbestanden.');}
            $names=[];$bytes=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $s=$zip->statIndex($i);$name=$s['name'];$opsys=0;$attr=0;$zip->getExternalAttributesIndex($i,$opsys,$attr);
                if(isset($names[$name])||(($attr>>16)&0170000)===0120000||str_contains($name,'..')||str_contains($name,'\\')||str_starts_with($name,'/')||str_contains($name,"\0")||!preg_match('~^(manifest\.json|handover\.json|files/(?:media|fonts|snapshot)/[a-zA-Z0-9_./-]+|software/(?:theme|acf|dashboard|forms|forms_pro|plugin_[a-f0-9]{16})\.zip)$~D',$name)) {throw new \RuntimeException('Onveilig of dubbel pakketpad.');}
                if(str_starts_with($name,'files/')&&!str_starts_with($name,'files/snapshot/')){\Emonks\Factory\Online\Receiver::assetName(substr($name,6));}
                $bytes+=$s['size'];if($s['size']>(str_starts_with($name,'software/')?DeliverySoftware::LIMIT:16*1024*1024)||$bytes>FullSite::LIMIT){throw new \RuntimeException('Pakket overschrijdt de importlimiet (16 MiB per gegevensdeel, 256 MiB per softwarezip en 16 GiB totaal).');}$names[$name]=$s;
            }
            if(!isset($names['manifest.json'],$names['handover.json'])){throw new \RuntimeException('Oplevermanifest ontbreekt.');}
            $free=disk_free_space(self::storage());if($free===false||$free<$bytes*3+64*1024*1024){throw new \RuntimeException('Onvoldoende vrije opslag voor uitpakken en importeren.');}
            foreach($names as $name=>$s){
                $path=$dir.'/'.$name;if(!is_dir(dirname($path))&&!wp_mkdir_p(dirname($path))){throw new \RuntimeException('Pakketmap maken mislukt.');}
                $in=$zip->getStream($name);$out=fopen($path,'xb');if(!$in||!$out){throw new \RuntimeException('Pakketbestand openen mislukt.');}
                try{$written=stream_copy_to_stream($in,$out,$s['size']+1);}finally{fclose($in);fclose($out);}
                chmod($path,0600);if($written!==$s['size']){throw new \RuntimeException('Pakketbestand is onvolledig.');}
            }
            $m=self::read($dir);$allowed=array_fill_keys(array_merge(['manifest.json','handover.json'],array_map(static fn($n)=>'files/'.$n,array_keys($m['files'])),array_column($m['software']['components']??[],'archive')),true);
            if(array_diff_key($names,$allowed)||array_diff_key($allowed,$names)){throw new \RuntimeException('Zip bevat ontbrekende of niet gedeclareerde bestanden.');}return $dir;
        } catch(\Throwable $e){self::removeTree($dir);throw $e;}
        finally{$zip->close();}
    }
    public static function removeTree(string $dir): void {
        $root=self::storage();if(dirname($dir)!==$root||!preg_match('/^(incoming|package)-[a-zA-Z0-9-]+$/D',basename($dir))||is_link($dir)){throw new \RuntimeException('Ongeldige tijdelijke pakketmap.');}
        if(!is_dir($dir)){return;}
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($dir);
    }
    public static function read(string $dir): array {
        $header=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$m=($header['format']??'')===FullSite::FORMAT?FullSite::read($dir):Package::read($dir);$h=json_decode((string)file_get_contents($dir.'/handover.json'),true,64,JSON_THROW_ON_ERROR);
        $prepared=is_array($h)&&((!empty($h['ready_at'])&&!empty($h['ready_by']))||(!empty($h['approved_at'])&&!empty($h['approved_by'])));
        if(($h['format']??'')!==Handover::FORMAT||!$prepared||!is_string($h['operation']??null)||!preg_match('/^[a-f0-9-]{36}$/D',$h['operation'])||!is_string($h['manifest_sha256']??null)||!hash_equals($h['manifest_sha256'],hash_file('sha256',$dir.'/manifest.json'))||!hash_equals((string)($h['fingerprint']??''),Handover::fingerprint($m))){throw new \RuntimeException('Het pakket is niet klaargezet of de inhoud is gewijzigd.');}
        Handover::target((string)$h['target']);if(!empty($m['unsupported'])){throw new \RuntimeException(implode('; ',$m['unsupported']));}
        if(!is_string($h['source']??null)||$h['source']!==($m['source']??null)){throw new \RuntimeException('De pakketbron wijkt af.');}
        if(isset($m['software'])){DeliverySoftware::validate($dir,$m['software']);}
        $m['handover']=$h;return $m;
    }
    public static function stage(string $archive,bool $hostingPrivate): array {
        self::authorize();return self::locked(static function()use($archive,$hostingPrivate){
            $old=self::state();
            if(!$hostingPrivate){throw new \RuntimeException('Bevestig eerst dat de hosting ook rechtstreekse media afschermt tijdens de oplevering.');}
            $dir=self::unpack($archive);
            try {
                $m=self::read($dir);
                if(!empty($old['committed']) && ($m['handover']['operation']??'')===$old['operation']){
                    if(!hash_equals($old['manifest_sha256'],$m['handover']['manifest_sha256'])||!hash_equals($old['fingerprint'],$m['handover']['fingerprint'])){throw new \RuntimeException('Deze operatie hoort bij een ander pakket.');}
                    if(is_dir($old['dir']??'')){throw new \RuntimeException('Dit pakket is al geïmporteerd. Bekijk het opleverrapport of kies een nieuw pakket.');}
                    $dest=self::storage().'/package-'.$old['operation'];if(!rename($dir,$dest)){throw new \RuntimeException('Pakket herstellen mislukt.');}$dir=$dest;$old['dir']=$dest;self::save($old);return $old;
                }
                if(isset($m['software'])){self::basePreflight($m,true);DeliverySoftware::plan($m['software']);}else{self::preflight($m,true);}
                $dest=self::storage().'/package-'.$m['handover']['operation'];
                if(is_dir($dest)){self::removeTree($dest);}if(!rename($dir,$dest)){throw new \RuntimeException('Pakket bewaren mislukt.');}$dir=$dest;
                self::installGuard();update_option('blog_public',0);
                $r=['operation'=>$m['handover']['operation'],'fingerprint'=>$m['handover']['fingerprint'],'manifest_sha256'=>$m['handover']['manifest_sha256'],'source'=>$m['source'],'target'=>self::destination(),'package_target'=>$m['handover']['target'],'state'=>isset($m['software'])?'software_pending':'staged','dir'=>$dest,'staged_at'=>gmdate('c'),'hosting_private_confirmed_by'=>get_current_user_id()];
                if($old){$r['previous_operations']=$old['previous_operations']??[];$r['previous_operations'][]=array_intersect_key($old,array_flip(['operation','target','dir','backup','committed','state','previous_backups']));}
                self::save($r);
                if(!empty($old['dir'])&&$old['dir']!==$dest&&empty($old['committed'])&&empty($old['backup'])){self::removeTree($old['dir']);}return $r;
            }catch(\Throwable $e){self::removeTree($dir);throw $e;}
        });
    }
    public static function destination(): string {
        $target=Handover::target(site_url());
        if(defined('WP_HOME') && untrailingslashit(WP_HOME)!==$target){throw new \RuntimeException('WP_HOME wijkt af van het installatieadres. Pas deze vaste configuratie eerst aan.');}
        return $target;
    }
    private static function assertDestination(array $record): void {
        if(self::destination()!==$record['target']){throw new \RuntimeException('Het installatieadres is gewijzigd. Upload het pakket opnieuw en controleer de nieuwe bestemming.');}
    }
    /** Called inside Package::import's transaction, before any new models or content. */
    public static function prepareContent(): void {
        if(!self::importing()){throw new \RuntimeException('Geen actieve opleverimport.');}
        StandaloneContent::lockInventory(self::$context['inventory']['fingerprint']);
        StandaloneContent::clear(self::$context['replace_mode']??'empty',self::$context['inventory']);
        global $wpdb;
        // The temporary home filter keeps generated URLs correct; bypass its value
        // comparison when persisting the address inside the transaction.
        if($wpdb->update($wpdb->options,['option_value'=>self::$context['target']],['option_name'=>'home'])===false){throw new \RuntimeException('Websiteadres opslaan mislukt.');}
        wp_cache_delete('home','options');wp_cache_delete('alloptions','options');
        update_option('siteurl',self::$context['target']);
    }
    private static function finishReplacement(array $r): void {
        if(($r['backup_mode']??'')!=='external' && ($r['replace_mode']??'')==='replace' && !empty($r['backup']) && empty($r['media_cleaned'])){
            $package=self::read($r['dir']);StandaloneContent::cleanOldUploads($r['backup'],$package);
            $r['media_cleaned']=true;self::save($r);
        }
    }
    public static function restore(string $operation): void {
        self::authorize();self::locked(static function()use($operation){
            $r=self::state();if(($r['operation']??'')!==$operation||empty($r['backup'])||($r['state']??'')==='released'){throw new \RuntimeException('Geen herstelkopie beschikbaar voor deze oplevering.');}
            self::assertDestination($r);self::installGuard();StandaloneContent::restore($r['backup']);
            $r['state']=isset(self::read($r['dir'])['software'])?'software_pending':'staged';
            unset($r['committed'],$r['imported_at'],$r['replace_mode'],$r['inventory'],$r['media_cleaned'],$r['error']);
            $r['previous_backups'][]=$r['backup'];unset($r['backup']);
            $r['restored_at']=gmdate('c');self::save($r);
            update_option('blog_public',0);
        });
    }
    public static function basePreflight(array $m,bool $allowContent=false): void {
        self::authorize();global $wpdb;
        if(($m['format']??'')===FullSite::FORMAT&&wp_convert_hr_to_bytes(WP_MEMORY_LIMIT)<256*1024*1024){throw new \RuntimeException('Stel op de hosting WP_MEMORY_LIMIT in op minimaal 256M; voor deze volledige webshop raden we 512M aan.');}
        self::destination();
        if(!$allowContent){StandaloneContent::validateChoice(StandaloneContent::inventory(),'empty');}
        $tables=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);
        foreach($tables as $table){if(strcasecmp($table['Engine']??'','InnoDB')){throw new \RuntimeException('Alle doeltabellen moeten InnoDB gebruiken.');}}
    }
    public static function preflight(array $m,bool $allowContent=false): array {
        self::authorize();global $wpdb;
        if(version_compare(PHP_VERSION,'8.1','<')){throw new \RuntimeException('PHP 8.1 of nieuwer vereist.');}
        if(!defined('ACF_PRO')||!ACF_PRO||!function_exists('acf_import_post_type')||!function_exists('acf_import_taxonomy')||!function_exists('acf_import_field_group')){throw new \RuntimeException('ACF Pro ontbreekt. Gebruik Installeer thema en plugins. Bevat dit oudere pakket geen software? Maak dan een nieuw pakket met Site Factory 0.17.0 of installeer ACF Pro afzonderlijk.');}
        ThemeRelease::useRequestSlug(get_stylesheet());ThemeRelease::requireVersion($m['dependencies']);
        $dashboard=ThemeRelease::dashboard();if(empty($dashboard['installed'])||!is_plugin_active($dashboard['file'])){throw new \RuntimeException('Activeer Emonks Dashboard op de bestemming.');}
        if(!empty($m['forms'])&&!defined('FLUENTFORM_VERSION')){throw new \RuntimeException('Activeer Fluent Forms voor dit pakket.');}
        self::basePreflight($m,$allowContent);
        if(isset($m['software'])){DeliverySoftware::confirm($m['software']);}
        if(($m['format']??'')===FullSite::FORMAT){return FullSite::preflight($m);}
        Package::check_models($m);
        $bytes=array_sum(array_column($m['files'],'size'));$upload=wp_upload_dir();if(!empty($upload['error'])||!is_writable($upload['basedir'])){throw new \RuntimeException('De uploadmap is niet schrijfbaar.');}
        $free=disk_free_space($upload['basedir']);if($free===false||$free<$bytes*3+64*1024*1024){throw new \RuntimeException('Onvoldoende vrije schijfruimte.');}
        foreach($m['files'] as $name=>$meta){$existing=str_starts_with($name,'fonts/')?$upload['basedir'].'/skeletor-fonts/'.substr($name,6):$upload['basedir'].'/site-factory/'.$name;if(is_file($existing)&&!hash_equals($meta['sha256'],hash_file('sha256',$existing))){throw new \RuntimeException('Een bestaand doelbestand wijkt af: '.$name.'. Gebruik een schone bestemming.');}}
        return ['ok'=>true,'posts'=>count($m['posts']),'files'=>count($m['files']),'forms'=>count($m['forms']),'models'=>count($m['models']),'bytes'=>$bytes,'theme_version'=>ThemeRelease::version()];
    }
    public static function installSoftware(string $operation): array {
        self::authorize();return self::locked(static function()use($operation){
            $r=self::state();if(($r['operation']??'')!==$operation||!empty($r['committed'])){throw new \RuntimeException('Kies de bedoelde, nog niet geïmporteerde oplevering.');}
            $m=self::read($r['dir']);if(empty($m['software'])){throw new \RuntimeException('Dit oudere pakket bevat geen thema of plugins. Maak een nieuw pakket.');}
            self::assertDestination($r);self::basePreflight($m,true);$plan=DeliverySoftware::plan($m['software']);
            $r['backup_mode']='external';self::save($r);
            self::installGuard();
            $r['state']='software_installing';self::save($r);
            try {
                foreach($plan as $component){DeliverySoftware::install($r['dir'],$component,$operation);$r['software_files'][$component['role']]=true;self::save($r);}
                wp_clean_plugins_cache(false);wp_clean_themes_cache(false);
                foreach($plan as $component){if($component['kind']==='plugin'){DeliverySoftware::activate($component);$r['software_active'][$component['role']]=true;self::save($r);}}
                foreach($plan as $component){if($component['kind']==='theme'){DeliverySoftware::activate($component);}}
                $r['state']='software_ready';unset($r['error']);self::save($r);return $r;
            }catch(\Throwable $e){$r['state']='software_pending';$r['error']=$e->getMessage();self::save($r);throw $e;}
        });
    }
    /** Executed within the same transaction as content. ACF persists definitions in its database. */
    public static function installModels(array $m): void {
        self::authorize();$previous=acf_get_setting('json');acf_update_setting('json',false);
        try {
            foreach(Package::project_models($m['models']) as $model){
                $key=$model['key'];$fn=str_starts_with($key,'group_')?'acf_import_field_group':(str_starts_with($key,'post_type_')?'acf_import_post_type':'acf_import_taxonomy');
                unset($model['ID'],$model['local']);$imported=$fn($model);if(!is_array($imported)||empty($imported['ID'])){throw new \RuntimeException('Projectmodel opslaan mislukt: '.$key);}
                if(str_starts_with($key,'group_')){acf_add_local_field_group($model);}
            }
        }finally{acf_update_setting('json',$previous);}
    }
    /** A committed receipt cannot be separated from the imported database rows. */
    public static function commitReceipt(): void {
        if(!self::importing()){throw new \RuntimeException('Geen actieve opleverimport.');}
        // Replacement assigns new term IDs; keep WordPress's default category valid.
        global $wpdb;
        if(!term_exists((int)get_option('default_category'),'category')){
            $category=(int)$wpdb->get_var("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='category' ORDER BY term_id LIMIT 1");
            if(!$category){$term=wp_insert_term(__('Uncategorized'),'category');if(is_wp_error($term)){throw new \RuntimeException('Standaardcategorie herstellen mislukt.');}$category=(int)$term['term_id'];}
            update_option('default_category',$category);
        }
        $receipt=self::$context;$receipt['state']='imported';$receipt['committed']=true;$receipt['imported_at']=gmdate('c');self::save($receipt);
    }
    public static function apply(string $operation,array $confirmation=[]): array {
        self::authorize();return self::locked(static function()use($operation,$confirmation){
            $r=self::state();if(!$operation||($r['operation']??'')!==$operation){throw new \RuntimeException('De operatie is gewijzigd. Open de pagina opnieuw.');}
            if(!empty($r['committed'])){self::finishReplacement($r);return self::report();}
            self::installGuard();$m=self::read($r['dir']);if(!hash_equals($r['manifest_sha256'],$m['handover']['manifest_sha256'])){throw new \RuntimeException('Het opgeslagen pakket is gewijzigd.');}self::assertDestination($r);self::preflight($m,true);
            $inventory=StandaloneContent::inventory();
            $mode=$inventory['empty']?'empty':'replace';
            $r['backup_mode']='external';
            $r['inventory']=$inventory;$r['replace_mode']=$mode;
            // Journal is written before BEGIN. A killed PHP/MySQL connection rolls back the
            // entire content transaction. Retry repeats it; a committed receipt skips it.
            $r['state']='importing';unset($r['error']);self::save($r);self::$context=$r;
            $targetFilter=static fn()=>$r['target'];add_filter('pre_option_home',$targetFilter,PHP_INT_MAX);wp_upload_dir(null,false,true);
            try {if(($m['format']??'')===FullSite::FORMAT){FullSite::import($r['dir'],$m);}else{Package::import($r['dir'],true,true);}}
            catch(\Throwable $e){if(empty(self::state()['committed'])){$r['state']='retry';$r['error']=$e->getMessage();self::save($r);}throw $e;}
            finally{remove_filter('pre_option_home',$targetFilter,PHP_INT_MAX);self::$context=null;}
            self::finishReplacement(self::state());
            return self::report();
        });
    }
    /** User-facing import: resume safely, validate, then activate without another click. */
    public static function importWebsite(string $operation,array $confirmation=[]): array {
        self::apply($operation,$confirmation);
        // apply releases its lock first. release verifies the operation again, so
        // an intervening stage cannot accidentally activate a different package.
        return self::release($operation,[],true);
    }
    public static function report(): array {
        self::authorize();global $wpdb;$r=self::state();if(($r['state']??'')==='released'){return $r['report'];}if(empty($r['committed'])){throw new \RuntimeException('De import is nog niet voltooid.');}
        $m=self::read($r['dir']);if(($m['format']??'')===FullSite::FORMAT){return FullSite::report($m,$r);}$map=(array)get_option('emsf_import_map',[]);$issues=[];
        foreach(['posts','terms','menus','forms'] as $kind){foreach($m[$kind] as $row){$id=(int)($map[$row['ref']]??0);$exists=$id>0;
            if($kind==='posts'){$exists=$exists&&get_post($id)!==null;}elseif($kind==='terms'){$exists=$exists&&(bool)term_exists($id,$row['taxonomy']);}elseif($kind==='menus'){$exists=$exists&&(bool)wp_get_nav_menu_object($id);}else{$exists=$exists&&(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$id));}
            if(!$exists){$issues[]='Ontbrekende doelkoppeling: '.$row['ref'];}
        }}
        $upload=wp_upload_dir();foreach($m['files'] as $name=>$meta){$path=str_starts_with($name,'fonts/')?$upload['basedir'].'/skeletor-fonts/'.substr($name,6):$upload['basedir'].'/site-factory/'.$name;if(!is_file($path)||!hash_equals($meta['sha256'],hash_file('sha256',$path))){$issues[]='Bestand ontbreekt of wijkt af: '.$name;}}
        foreach($m['models'] as $model){$type=str_starts_with($model['key'],'group_')?'acf-field-group':(str_starts_with($model['key'],'post_type_')?'acf-post-type':'acf-taxonomy');if(!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type=%s",$model['key'],$type))){$issues[]='Projectmodel ontbreekt in database: '.$model['key'];}}
        $bad=[(string)$m['source'],'{{emsf:'];
        $values=$wpdb->get_col("SELECT post_content FROM {$wpdb->posts} UNION ALL SELECT post_excerpt FROM {$wpdb->posts} UNION ALL SELECT meta_value FROM {$wpdb->postmeta}");
        foreach(array_keys($m['options']) as $key){$values[]=maybe_serialize(get_option($key));}$values[]=maybe_serialize(get_theme_mod('nav_menu_locations',[]));
        foreach($values as $v){foreach($bad as $needle){if(str_contains(str_replace('\\/','/',(string)$v),$needle)){$issues[]='Achtergebleven bronadres of pakketreferentie in inhoud/instellingen.';break 2;}}}
        return ['operation'=>$r['operation'],'state'=>($r['state']??'')==='released'?'released':'imported','committed'=>true,'ok'=>!$issues,'issues'=>array_values(array_unique($issues)),'source'=>$r['source'],'target'=>$r['target'],'package_target'=>$r['package_target']??$r['target'],'backup'=>$r['backup']['id']??null,'fingerprint'=>$r['fingerprint'],'imported_at'=>$r['imported_at'],'software'=>array_map(static fn($component)=>array_intersect_key($component,array_flip(['role','name','version'])),$m['software']['components']??[]),'counts'=>array_map('count',array_intersect_key($m,array_flip(['posts','terms','menus','forms','models','files']))),'checked_at'=>gmdate('c')];
    }
    public static function release(string $operation,array $checks=[],bool $automatic=false): array {
        self::authorize();return self::locked(static function()use($operation,$checks,$automatic){
            $r=self::state();if(($r['operation']??'')!==$operation||empty($r['committed'])){throw new \RuntimeException('Voltooi eerst de bedoelde import.');}
            if(($r['state']??'')==='released'){return $r['report'];}
            if(Handover::target(home_url())!==$r['target']||!is_ssl()){throw new \RuntimeException('Open de bestemming via het definitieve HTTPS-adres.');}
            self::finishReplacement($r);$r=self::state();$report=self::report();if(!$report['ok']){throw new \RuntimeException(implode('; ',$report['issues']));}
            $guard=WPMU_PLUGIN_DIR.'/emonks-handover-guard.php';if(is_file($guard)&&!hash_equals(hash('sha256',self::guardCode()),hash_file('sha256',$guard))){throw new \RuntimeException('Tijdelijke opleverbeveiliging wijkt af. Controleer het bestand vóór vrijgeven.');}
            $report['state']='released';$report['released_at']=gmdate('c');$report['released_by']=get_current_user_id();$report['checks']=$checks;$report['release_mode']=$automatic?'automatic':'manual';
            // Persist the release before removing its protection: a failed database write
            // must never leave an unrecorded, unprotected destination.
            $previous=$r;$r['state']='released';$r['test_mode']=false;$r['report']=$report;self::save($r);
            if(is_file($guard)&&!unlink($guard)){self::save($previous);throw new \RuntimeException('Tijdelijke opleverbeveiliging kon niet worden verwijderd.');}
            update_option('blog_public',1);return $report;
        });
    }
    public static function testing(string $operation): array {
        self::authorize();return self::locked(static function()use($operation){
            $r=self::state();if(empty($r['committed'])||($r['operation']??'')!==$operation||($r['state']??'')==='released'){throw new \RuntimeException('Voltooi eerst deze import.');}
            $r['test_mode']=empty($r['test_mode']);self::save($r);return $r;
        });
    }
    private static function installGuard(): void {
        $dir=WPMU_PLUGIN_DIR;if(!is_dir($dir)&&!wp_mkdir_p($dir)){throw new \RuntimeException('Map voor tijdelijke opleverbeveiliging is niet schrijfbaar.');}
        $file=$dir.'/emonks-handover-guard.php';$code=self::guardCode();if(is_file($file)){if(!hash_equals(hash('sha256',$code),hash_file('sha256',$file))){throw new \RuntimeException('Een andere opleverbeveiliging staat op dezelfde plek.');}return;}
        $tmp=tempnam($dir,'.handover-');if(!$tmp){throw new \RuntimeException('Opleverbeveiliging schrijven mislukt.');}
        try{if(file_put_contents($tmp,$code)!==strlen($code)||!chmod($tmp,0644)||!rename($tmp,$file)){throw new \RuntimeException('Opleverbeveiliging schrijven mislukt.');}}finally{if(is_file($tmp)){unlink($tmp);}}
        require_once $file;
    }
    private static function guardCode(): string {return <<<'GUARD'
<?php
/** Plugin Name: Emonks tijdelijke opleverbeveiliging */
if (!defined('ABSPATH')) { exit; }
// Intentionally self-contained: remains active when the importer is deactivated.
if (get_option('emsf_handover_import',[])['state'] ?? false) {
    if ((get_option('emsf_handover_import',[])['state'] ?? '') === 'released') { return; }
}
// Only an explicitly enabled administrator test may use mail/HTTP transports.
$testing=static fn()=>!empty(get_option('emsf_handover_import',[])['test_mode']) && current_user_can('manage_options');
add_filter('pre_wp_mail',static fn($pre)=>$testing()?$pre:false,PHP_INT_MAX);
add_filter('pre_http_request',static fn($pre)=>$testing()?$pre:new WP_Error('emsf_handover_staging','Externe integraties staan uit tijdens oplevering.'),PHP_INT_MAX);
add_filter('pre_option_cron',static fn()=>[],PHP_INT_MAX);
add_action('init',static function(){
    if ((defined('WP_CLI')&&WP_CLI) || current_user_can('manage_options') || ($GLOBALS['pagenow']??'')==='wp-login.php') { return; }
    nocache_headers();header('X-Robots-Tag: noindex, nofollow');wp_die('Website wordt opgeleverd.','Oplevering',['response'=>503]);
},-100);
GUARD;}
    public static function boot(): void {
        add_action('admin_menu',static fn()=>add_management_page('Site opleveren','Site opleveren','manage_options','emsf-handover',[self::class,'page']));
        add_action('admin_post_emsf_handover_import',[self::class,'submit']);
        add_action('wp_ajax_emsf_handover_upload',[UploadTransport::class,'request']);
        add_action('wp_ajax_emsf_handover_step',[self::class,'stepRequest']);
        add_action('admin_enqueue_scripts',static function($hook){
            if($hook!=='tools_page_emsf-handover'){return;}
            $root=dirname(__DIR__);$base=$root.'/emonks-site-importer.php';
            wp_enqueue_style('emsf-import',plugins_url('assets/importer.css',$base),[],(string)filemtime($root.'/assets/importer.css'));
            wp_enqueue_script('emsf-import',plugins_url('assets/importer.js',$base),[],(string)filemtime($root.'/assets/importer.js'),true);
        });
    }
    public static function page(): void {
        self::authorize();$r=self::state();$state=$r['state']??'';$committed=!empty($r['committed']);$released=$state==='released';
        echo '<div class="wrap emsf-import"><h1>Website overzetten</h1>';
        if($r){echo '<p class="emsf-import-destination">Bestemming: <strong>'.esc_html($r['target']).'</strong></p>';}
        if(!empty($r['error'])){echo '<div class="notice notice-error inline" role="alert"><p>'.esc_html($r['error']).'</p></div>';}
        echo '<section class="emsf-import-main">';
        if(!$r){echo '<h2>Kies je opleverpakket</h2><p>Upload de zip uit Site Factory. Daarna installeren we alles automatisch en vervangen we de bestaande site-inhoud. De importer maakt geen extra herstelkopie.</p>';self::uploadForm();}
        elseif(!$committed){
            if(in_array($state,['software_pending','software_installing'],true)){
                echo '<h2>Website installeren</h2><p>Het thema en de plugins worden automatisch geïnstalleerd. Daarna zetten we de inhoud over.</p>';
                self::form('software','Installatie hervatten');
            }else{echo '<h2>Website installeren</h2><p>De inhoud en instellingen worden automatisch overgezet. Er wordt geen extra herstelkopie gemaakt.</p>';self::form('apply','Installatie hervatten');}
        }elseif(!$released&&!is_dir($r['dir']??'')){
            echo '<h2>Pakket opnieuw toevoegen</h2><p>Upload hetzelfde pakket om verder te gaan. De geïmporteerde inhoud blijft behouden.</p>';self::uploadForm();
        }else{
            $ok=false;
            try{$report=self::report();$ok=!empty($report['ok']);if(!$ok){echo '<div class="notice notice-error inline"><p>'.esc_html(implode('; ',$report['issues'])).'</p></div>';}}
            catch(\Throwable $e){echo '<div class="notice notice-error inline"><p>'.esc_html($e->getMessage()).'</p></div>';}
            if($released){
                echo '<h2>Website is geactiveerd</h2><p>De import is afgerond. Je kunt de tijdelijke importplugin verwijderen via Plugins.</p><p><a class="button" href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">Website bekijken ↗</a></p>';
                if(empty($r['cleaned_at'])){self::form('cleanup','Tijdelijke bestanden opruimen');}else{echo '<p class="emsf-import-success">Tijdelijke bestanden zijn opgeruimd.</p>';}
            }else{
                echo '<h2>Website is geïmporteerd</h2>';
                if($ok){echo '<p class="emsf-import-success">De automatische controle is geslaagd.</p>';}
                echo '<p><a class="button" href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">Website bekijken ↗</a></p><p>Activeren maakt de website toegankelijk en zet mail, integraties en indexering aan. Hostingafscherming en DNS beheer je zelf.</p>';
                if($ok){self::form('release','Website activeren');}
            }
        }
        echo '</section>';
        if($r){
            if(!$committed){echo '<details class="emsf-import-extra"><summary>Ander pakket kiezen</summary>';self::uploadForm();echo '</details>';}
            if(!empty($r['backup'])){
                echo '<details class="emsf-import-extra"><summary>Herstelkopie</summary><p>De inhoud van vóór deze import is bewaard. Gebruikersaccounts blijven behouden.</p>';self::form('backup','Herstelkopie downloaden');
                if(!$released){self::form('restore','Vorige inhoud terugzetten',static function(){echo '<p><label><input type="checkbox" name="restore_confirmed" value="1" required> Huidige inhoud vervangen door de herstelkopie.</label></p>';});}echo '</details>';
            }
            if($committed&&!$released&&is_dir($r['dir']??'')){
                echo '<details class="emsf-import-extra"><summary>Formulieren en integraties testen</summary><p>Sta mail en externe integraties tijdelijk toe voor ingelogde beheerders.</p>';
                if(!empty($r['test_mode'])){echo '<p class="emsf-import-test-status">Testmodus staat aan voor beheerders.</p>';}
                self::form('testing',!empty($r['test_mode'])?'Testmodus uitzetten':'Testmodus aanzetten');echo '</details>';
            }
            echo '<details class="emsf-import-extra"><summary>Log en pakketgegevens</summary><dl><dt>Status</dt><dd>'.esc_html($state).'</dd><dt>Bron</dt><dd>'.esc_html($r['source']).'</dd><dt>Pakket</dt><dd><code>'.esc_html(substr($r['fingerprint'],0,12)).'</code></dd>';
            if(($r['package_target']??$r['target'])!==$r['target']){echo '<dt>Oorspronkelijk pakketadres</dt><dd>'.esc_html($r['package_target']).'</dd>';}
            foreach(['staged_at'=>'Pakket toegevoegd','imported_at'=>'Inhoud geïmporteerd','restored_at'=>'Inhoud hersteld','cleaned_at'=>'Bestanden opgeruimd'] as $key=>$label){if(!empty($r[$key])){echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html(wp_date('d-m-Y H:i',strtotime($r[$key]))).'</dd>';}}echo '</dl>';
            try{if(is_dir($r['dir']??'')){$m=self::read($r['dir']);echo '<ul>';foreach($m['software']['components']??[] as $component){echo '<li>'.esc_html($component['name'].' '.$component['version']).'</li>';}echo '</ul>';}}catch(\Throwable $e){echo '<p>'.esc_html($e->getMessage()).'</p>';}
            if($committed){self::form('report','Rapport downloaden');}echo '</details>';
        }
        echo '</div>';
    }
    private static function uploadForm(): void {
        self::form('stage','Pakket uploaden',static function(){echo '<p><label class="emsf-import-file">Opleverpakket (.zip)<input type="file" name="package" accept=".zip" required></label></p><p><label><input type="checkbox" name="hosting_private" value="1" required> Website en media zijn afgeschermd via de hosting.</label></p><p>Grote pakketten uploaden we in kleine delen. Bij een onderbreking kun je dezelfde upload hervatten.</p><noscript><p>Schakel JavaScript in om grote pakketten in delen te uploaden.</p></noscript>';},true);
    }
    private static function form(string $step,string $label,?callable $fields=null,bool $upload=false): void {
        echo '<form data-import-auto="'.(in_array($step,['software','apply'],true)?'1':'0').'" data-upload-url="'.esc_url(admin_url('admin-ajax.php')).'" data-import-step="'.esc_attr($step).'" method="post" '.($upload?'enctype="multipart/form-data" ':'').'action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="emsf_handover_import"><input type="hidden" name="step" value="'.esc_attr($step).'"><input type="hidden" name="operation" value="'.esc_attr(self::state()['operation']??'').'">';wp_nonce_field('emsf_handover_import');if($fields){$fields();}submit_button($label,in_array($step,['stage','software','apply','release','cleanup'],true)?'primary':'secondary','emsf_handover_'.$step);echo '</form>';
    }
    /** One automatic phase per request so newly installed plugins load before content import. */
    public static function stepRequest(): void {
        try{
            self::authorize();if(!check_ajax_referer('emsf_handover_import','_wpnonce',false)){throw new \RuntimeException('Je sessie is verlopen. Vernieuw de pagina om de installatie te hervatten.');}
            if(function_exists('set_time_limit')){@set_time_limit(0);}wp_raise_memory_limit('admin');ignore_user_abort(true);
            $op=(string)($_POST['operation']??'');$step=(string)($_POST['step']??'');
            if($step==='software'){self::installSoftware($op);}elseif($step==='apply'){self::importWebsite($op);}else{throw new \RuntimeException('Onbekende installatiestap.');}
            wp_send_json_success(['redirect'=>admin_url('tools.php?page=emsf-handover')]);
        }catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
    public static function submit(): void {
        $authorized=false;if(function_exists('set_time_limit')){@set_time_limit(0);}if(function_exists('wp_raise_memory_limit')){wp_raise_memory_limit('admin');}
        try{
            self::authorize();check_admin_referer('emsf_handover_import');$authorized=true;nocache_headers();$step=(string)($_POST['step']??'');$op=(string)($_POST['operation']??'');
            if($step==='stage'){$f=$_FILES['package']??[];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name'])){throw new \RuntimeException('Pakketupload mislukt. Controleer ook de uploadlimiet van de hosting.');}self::stage($f['tmp_name'],!empty($_POST['hosting_private']));}
            elseif($step==='software'){self::installSoftware($op);}
            elseif($step==='apply'){self::importWebsite($op,['mode'=>(string)($_POST['replace_mode']??'empty'),'inventory'=>(string)($_POST['inventory']??''),'replace_confirmed'=>(string)($_POST['replace_confirmed']??''),'use_destination'=>(string)($_POST['use_destination']??'')]);}
            elseif($step==='restore'){if(($_POST['restore_confirmed']??'')!=='1'){throw new \RuntimeException('Bevestig het terugzetten van de herstelkopie.');}self::restore($op);}
            elseif($step==='testing'){self::testing($op);}
            elseif($step==='release'){self::release($op,(array)($_POST['checks']??[]));}
            elseif($step==='backup'){self::locked(static function()use($op){$r=self::state();if(($r['operation']??'')!==$op||empty($r['backup'])){throw new \RuntimeException('Geen herstelkopie voor deze oplevering.');}$path=StandaloneContent::archive($r['backup']);try{header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="website-herstelkopie.zip"');header('Content-Length: '.filesize($path));readfile($path);}finally{unlink($path);}});exit;}
            elseif($step==='report'){$r=self::state();$report=($r['state']??'')==='released'?$r['report']:self::report();header('Content-Type: application/json');header('Content-Disposition: attachment; filename="opleverrapport.json"');echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
            elseif($step==='cleanup'){self::locked(static function()use($op){$r=self::state();if(($r['state']??'')!=='released'||($r['operation']??'')!==$op){throw new \RuntimeException('Geef eerst deze oplevering vrij.');}if(!empty($r['dir'])){self::removeTree($r['dir']);unset($r['dir']);$r['cleaned_at']=gmdate('c');self::save($r);}});}
            else{throw new \RuntimeException('Onbekende importactie.');}
            wp_safe_redirect(admin_url('tools.php?page=emsf-handover'));exit;
        }catch(\Throwable $e){$r=$authorized?self::state():[];if($r){$r['error']=$e->getMessage();self::save($r);wp_safe_redirect(admin_url('tools.php?page=emsf-handover'));exit;}wp_die(esc_html($e->getMessage()),'Oplevering gestopt',['back_link'=>true]);}
    }
}
