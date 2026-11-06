<?php
declare(strict_types=1);
namespace Emonks\Factory;
use Emonks\Factory\Online\{Sites,Store};

/** Creates and serves immutable per-site delivery snapshots. Never publishes to production. */
final class Handover {
    public const FORMAT='emonks-handover-v1';
    public static function boot(): void {
        if (!is_multisite()) { return; }
        add_action('admin_post_emsf_handover',[self::class,'submit']);
    }
    public static function target(string $url): string {
        $url=rtrim(trim($url),'/');$p=parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || isset($p['port']) || !filter_var($url,FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Vul het definitieve HTTPS-adres in, zonder inloggegevens, poort, query of fragment.');
        }
        return $url;
    }
    public static function fingerprint(array $manifest): string {
        unset($manifest['created_at']);
        $sort=static function($value)use(&$sort){if(!is_array($value)){return $value;}if(!array_is_list($value)){ksort($value);}foreach($value as &$v){$v=$sort($v);}return $value;};
        return hash('sha256',json_encode($sort($manifest),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private static function eligible(int $id): void {
        $site=Sites::site($id);
        if ($site->archived || $site->deleted || $site->spam) { throw new \RuntimeException('Herstel eerst het voorstel.'); }
        if (!Sites::local() && get_site_meta($id,'emsf_state',true)!=='ready') { throw new \RuntimeException('Rond eerst de online publicatie of het herstel af.'); }
        $p=Sites::local()?Sites::prospect($id):[];
        if ($p && Store::read('pending-'.$p['uuid'])) { throw new \RuntimeException('Rond eerst de openstaande online publicatie af.'); }
    }
    /** Discarded discussions stay on the source; never discard active/orphaned comments. */
    public static function commentInventory(): array {
        global $wpdb;
        $active="c.comment_approved NOT IN ('spam','trash','post-trashed') AND (p.ID IS NULL OR p.post_status <> 'trash')";
        $row=$wpdb->get_row("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN {$active} THEN 1 ELSE 0 END),0) AS blocking, COALESCE(SUM(CASE WHEN {$active} AND c.comment_type='order_note' THEN 1 ELSE 0 END),0) AS order_notes FROM {$wpdb->comments} c LEFT JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID",ARRAY_A);
        if(!is_array($row)||!isset($row['total'],$row['blocking'],$row['order_notes'])){throw new \RuntimeException('Reacties konden niet worden gecontroleerd. Probeer opnieuw.');}
        return ['blocking'=>(int)$row['blocking'],'excluded'=>(int)$row['total']-(int)$row['blocking'],'order_notes'=>(int)$row['order_notes']];
    }
    public static function checkComments(): array {
        $counts=self::commentInventory();
        if($counts['order_notes']){
            $other=$counts['blocking']-$counts['order_notes'];
            $message=$counts['order_notes'].' WooCommerce-bestelnotities horen bij de bestellingen. Het opleverpakket ondersteunt de webshopgegevens nog niet. Gebruik voor deze website een afzonderlijke WooCommerce-overdracht met bestellingen, bestelnotities, voorraad en downloads. De bestelnotities blijven op de bronwebsite bewaard.';
            if($other){$message.=' Daarnaast zijn er '.$other.' actieve of nog te beoordelen reactie(s) die nog niet worden overgedragen.';}
            throw new \RuntimeException($message);
        }
        if($counts['blocking']){throw new \RuntimeException($counts['blocking'].' actieve of nog te beoordelen reactie(s) worden nog niet overgedragen. Bekijk Reacties op de bronwebsite en verwerk deze afzonderlijk. Reacties in spam of de prullenbak blokkeren de oplevering niet.');}
        return $counts;
    }
    private static function collect(string $dir): array {
        $m=FullSite::export($dir);
        $m['software']=DeliverySoftware::collect($dir,$m);
        Package::json($dir.'/manifest.json',$m);
        return $m;
    }
    private static function discard(string $dir): void {
        if(!preg_match('~^'.preg_quote(Store::root(),'~').'/handover-(?:check-)?[a-f0-9-]{36}(?:-verify)?$~D',$dir)||is_link($dir)){throw new \RuntimeException('Ongeldig tijdelijk opleverpad.');}
        if(!is_dir($dir)){return;}
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($dir);
    }
    public static function prepare(int $id,string $target,bool $sourceConfirmed=true): array {
        if(function_exists('set_time_limit')){@set_time_limit(0);}if(function_exists('wp_raise_memory_limit')){wp_raise_memory_limit('admin');}
        self::eligible($id);$target=self::target($target);
        if (!$sourceConfirmed) { throw new \RuntimeException('Bevestig dat deze omgeving de definitieve inhoud bevat, inclusief eventuele online klantwijzigingen.'); }
        return Store::locked('handover-site-'.$id,static function()use($id,$target){
            switch_to_blog($id);$dir=Store::root().'/handover-'.wp_generate_uuid4();$verify=$dir.'-verify';
            try {
                HandoverLibrary::purge();$m=self::collect($dir);wp_cache_flush();$second=self::collect($verify);
                if (!hash_equals(self::fingerprint($m),self::fingerprint($second))) { throw new \RuntimeException('Inhoud is tijdens samenstellen gewijzigd. Stop bewerkingen en maak opnieuw een pakket.'); }
                $record=['format'=>self::FORMAT,'operation'=>wp_generate_uuid4(),'site'=>$id,'source'=>home_url(),'target'=>$target,'fingerprint'=>self::fingerprint($m),'manifest_sha256'=>hash_file('sha256',$dir.'/manifest.json'),'created_at'=>gmdate('c'),'created_by'=>get_current_user_id(),'software'=>array_map(static fn($e)=>array_intersect_key($e,array_flip(['role','name','version'])), $m['software']['components']),'counts'=>['posts'=>count($m['posts']),'files'=>count($m['files']),'forms'=>count($m['forms'])],'state'=>'ready','ready_at'=>gmdate('c'),'ready_by'=>get_current_user_id(),'dir'=>$dir];
                self::saveReady($record);$archive=self::buildArchive($record);$destination=HandoverLibrary::destination($record['operation']);
                if(!rename($archive,$destination.'/oplevering.zip')){throw new \RuntimeException('Het pakket kon niet worden opgeslagen.');}
                copy($dir.'/handover.json',$destination.'/handover.json');$record['dir']=$destination;$record['archive_size']=filesize($destination.'/oplevering.zip');$record['archive_sha256']=hash_file('sha256',$destination.'/oplevering.zip');
                HandoverLibrary::save($record);update_option('emsf_handover',$record,false);HandoverLibrary::log('created','Pakket klaargezet.',$record);
                self::discard($dir);return $record;
            } catch (\Throwable $e) { self::discard($dir);throw $e; }
            finally { self::discard($verify);restore_current_blog(); }
        });
    }
    private static function saveReady(array $record): void {
        // Version-1 importers require these legacy transport keys. They alias technical
        // readiness; no separate operator/customer approval is created or logged.
        $payload=array_diff_key($record,['dir'=>true,'state'=>true]);
        $payload['approval_mode']='ready_compat';$payload['approved_at']=$record['ready_at'];$payload['approved_by']=$record['ready_by'];
        Package::json($record['dir'].'/handover.json',$payload);
        Store::write('handover-ready-'.$record['operation'],array_diff_key($record,['dir'=>true]));
    }
    private static function buildArchive(array $record): string {
        $dir=$record['dir'];$header=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$manifest=($header['format']??'')===FullSite::FORMAT?FullSite::read($dir):Package::read($dir);
        if(!hash_equals($record['manifest_sha256'],hash_file('sha256',$dir.'/manifest.json'))||!hash_equals($record['fingerprint'],self::fingerprint($manifest))){throw new \RuntimeException('De opgeslagen pakketinhoud is gewijzigd.');}
        if(isset($manifest['software'])){DeliverySoftware::validate($dir,$manifest['software']);}
        if(!is_file($dir.'/handover.json')){throw new \RuntimeException('Pakketgegevens ontbreken.');}
        $path=$dir.'/oplevering.zip';$temporary=$path.'.partial';$zip=new \ZipArchive();
        if($zip->open($temporary,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new \RuntimeException('Pakketzip maken mislukt.');}
        $closed=false;
        try{
            $names=['manifest.json','handover.json'];foreach($manifest['files'] as $name=>$meta){$names[]='files/'.$name;}foreach($manifest['software']['components']??[] as $component){$names[]=$component['archive'];}
            foreach($names as $name){if(!$zip->addFile($dir.'/'.$name,$name)){throw new \RuntimeException('Pakketbestand toevoegen mislukt.');}if(str_starts_with($name,'files/snapshot/')||str_starts_with($name,'software/')){$zip->setCompressionName($name,\ZipArchive::CM_STORE);}}
            if(!$zip->close()){throw new \RuntimeException('Pakketzip afronden mislukt.');}$closed=true;
            if(!chmod($temporary,0600)||!rename($temporary,$path)){throw new \RuntimeException('Pakketzip opslaan mislukt.');}return $path;
        }finally{if(!$closed){$zip->close();}if(is_file($temporary)){unlink($temporary);}}
    }
    /** Adopt a completed pre-library archive without rebuilding from the live website. */
    private static function inspectLegacyArchive(array $record,string $path): array {
        $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CHECKCONS)!==true){throw new \RuntimeException('Het pakketbestand is beschadigd.');}
        try{
            $raw=$zip->getFromName('manifest.json',16*1024*1024);$meta=$zip->getFromName('handover.json',1024*1024);
            if(!is_string($raw)||!hash_equals($record['manifest_sha256'],hash('sha256',$raw))||!is_string($meta)){throw new \RuntimeException('De opgeslagen pakketinhoud wijkt af.');}
            $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$handover=json_decode($meta,true,64,JSON_THROW_ON_ERROR);
            if(!hash_equals($record['fingerprint'],self::fingerprint($manifest))||($handover['operation']??'')!==$record['operation']||(int)($handover['site']??0)!==(int)$record['site']){throw new \RuntimeException('De pakketgegevens komen niet overeen.');}
            $files=[];foreach($manifest['files'] as $name=>$file){$files['files/'.$name]=$file;}foreach($manifest['software']['components']??[] as $component){$files[$component['archive']]=$component;}
            if($zip->numFiles!==count($files)+2){throw new \RuntimeException('Het pakket bevat onverwachte bestanden.');}
            foreach($files as $name=>$file){$stat=$zip->statName($name);if(!$stat||$stat['size']!==$file['size']){throw new \RuntimeException('Pakketbestand ontbreekt: '.$name);}$in=$zip->getStream($name);if(!$in){throw new \RuntimeException('Pakketbestand lezen mislukt.');}try{$hash=hash_init('sha256');hash_update_stream($hash,$in);if(!hash_equals($file['sha256'],hash_final($hash))){throw new \RuntimeException('Pakketbestand is beschadigd: '.$name);}}finally{fclose($in);}}
        }finally{$zip->close();}
        $record['archive_size']=filesize($path);$record['archive_sha256']=hash_file('sha256',$path);return $record;
    }
    public static function archive(int $id,string $selector): string {
        Sites::site($id);
        return Store::locked('handover-site-'.$id,static function()use($id,$selector){
            switch_to_blog($id);
            try{
                $record=HandoverLibrary::find($selector);$path=HandoverLibrary::path($record).'/oplevering.zip';
                if(is_link($path)){throw new \RuntimeException('Onveilig pakketbestand.');}
                if(!is_file($path)){
                    if(!is_file($record['dir'].'/manifest.json')){throw new \RuntimeException('Het pakketbestand ontbreekt. Zet een nieuw pakket klaar.');}
                    if(!is_file($record['dir'].'/handover.json')){$record['ready_at']=gmdate('c');$record['ready_by']=get_current_user_id();self::saveReady($record);}
                    $path=self::buildArchive($record);
                }
                if(empty($record['archive_sha256'])){$record=self::inspectLegacyArchive($record,$path);HandoverLibrary::save($record);}
                if(filesize($path)!==$record['archive_size']||!hash_equals($record['archive_sha256'],hash_file('sha256',$path))){throw new \RuntimeException('Het pakketbestand is beschadigd. Zet een nieuw pakket klaar.');}
                return $path;
            }finally{restore_current_blog();}
        });
    }
    public static function packages(int $id): array {
        Sites::site($id);switch_to_blog($id);try{return HandoverLibrary::available();}finally{restore_current_blog();}
    }
    public static function remove(int $id,string $operation): void {
        Sites::site($id);Store::locked('handover-site-'.$id,static function()use($id,$operation){switch_to_blog($id);try{HandoverLibrary::purge();HandoverLibrary::remove($operation);}finally{restore_current_blog();}});
    }
    public static function restore(int $id,string $operation): void {
        Sites::site($id);Store::locked('handover-site-'.$id,static function()use($id,$operation){switch_to_blog($id);try{HandoverLibrary::purge();HandoverLibrary::restore($operation);}finally{restore_current_blog();}});
    }
    public static function importerArchive(): string {
        Sites::requireAdmin();if(!class_exists('ZipArchive')){throw new \RuntimeException('De PHP ZIP-extensie ontbreekt.');}
        $path=Store::root().'/handover-importer-'.ThemeRelease::pluginVersion().'.zip';
        return Store::locked('handover-importer',static function()use($path){
            $root=dirname(__DIR__);$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new \RuntimeException('Importplugin verpakken mislukt.');}
            $zip->addFile($root.'/importer/emonks-site-importer.php','emonks-site-importer/emonks-site-importer.php');
            foreach(['assets/importer.css','assets/importer.js','shared-schema.json','includes/Factory.php','includes/ThemeRelease.php','includes/Package.php','includes/Handover.php','includes/HandoverLibrary.php','includes/Standalone.php','includes/StandaloneContent.php','includes/DeliverySoftware.php','includes/FullSite.php','includes/UploadTransport.php','includes/Online/Receiver.php'] as $file){$zip->addFile($root.'/'.$file,'emonks-site-importer/'.$file);}
            if(!$zip->close()){throw new \RuntimeException('Importplugin verpakken mislukt.');}chmod($path,0600);return $path;
        });
    }
    public static function panel(int $id): void {
        $records=self::packages($id);$latest=reset($records)?:get_blog_option($id,'emsf_handover',[]);$target=$latest['target']??'';
        $notice=get_transient('emsf_handover_notice_'.$id.'_'.get_current_user_id());if($notice){delete_transient('emsf_handover_notice_'.$id.'_'.get_current_user_id());echo '<div class="notice notice-error inline"><p>'.esc_html($notice).'</p></div>';}
        echo '<div class="emsf-package-heading"><h2>Opleverpakketten</h2>';self::form($id,'importer','Importplugin downloaden');echo '</div>';
        echo '<p>Zelfstandige WordPress-site met thema, actieve plugins, instellingen, media en webshopgegevens. Het pakket bevat vertrouwelijke gegevens; bewaar het privé.</p>';
        self::form($id,'prepare','Pakket klaarzetten',static function()use($target){echo '<label>Websiteadres<input type="url" name="target" value="'.esc_attr($target).'" placeholder="https://www.klant.nl" required></label>';},'',true);
        $removed=is_string($_GET['removed']??null)?$_GET['removed']:'';
        if($removed){switch_to_blog($id);try{$all=HandoverLibrary::all();$deleted=$all[$removed]??[];}finally{restore_current_blog();}if(!empty($deleted['deleted_at'])&&empty($deleted['purged_at'])){echo '<div class="emsf-package-undo" role="status">Pakket verwijderd.';self::form($id,'restore','Ongedaan maken',null,$removed);echo '</div>';}}
        echo '<h3>Beschikbare pakketten</h3>';
        if(!$records){echo '<p class="emsf-package-empty">Er staan nog geen pakketten klaar.</p>';}
        else{
            echo '<table class="widefat striped emsf-package-list"><thead><tr><th scope="col">Gemaakt op</th><th scope="col">Websiteadres</th><th scope="col">Grootte</th><th scope="col">Acties</th></tr></thead><tbody>';$first=true;
            foreach($records as $record){
                $path='';try{switch_to_blog($id);try{$path=HandoverLibrary::path($record).'/oplevering.zip';}finally{restore_current_blog();}}catch(\Throwable $e){}
                $size=$record['archive_size']??($path&&is_file($path)?filesize($path):0);
                echo '<tr><td data-label="Gemaakt op"><strong>'.esc_html(wp_date('d-m-Y H:i',strtotime($record['created_at']))).'</strong>'.($first?' <span class="emsf-package-badge">Nieuwste</span>':'').'</td><td data-label="Websiteadres">'.esc_html($record['target']).'</td><td data-label="Grootte">'.esc_html($size?size_format($size,1):'—').'</td><td data-label="Acties"><div class="emsf-package-actions">';
                self::form($id,'download','Downloaden',null,$record['operation'],$first);self::form($id,'delete','Verwijderen',null,$record['operation']);echo '</div></td></tr>';$first=false;
            }
            echo '</tbody></table>';
        }
        switch_to_blog($id);try{$log=(array)get_option('emsf_handover_log',[]);}finally{restore_current_blog();}
        echo '<details class="emsf-package-log"><summary>Log en details</summary>';
        if(!$log&& !$records){echo '<p>Nog geen activiteiten.</p>';}
        foreach($log as $event){echo '<p><strong>'.esc_html(wp_date('d-m-Y H:i',strtotime($event['at']))).'</strong> · '.esc_html($event['message']).(!empty($event['target'])?' · '.esc_html($event['target']):'').'</p>';}
        foreach($records as $record){echo '<p><strong>'.esc_html(wp_date('d-m-Y H:i',strtotime($record['created_at']))).'</strong><br>Bron: '.esc_html($record['source']).'<br>Pakket: <code>'.esc_html(substr($record['operation'],0,8)).'</code></p>';if(!empty($record['software'])){echo '<ul>';foreach($record['software'] as $component){echo '<li>'.esc_html($component['name']).' '.esc_html($component['version']).'</li>';}echo '</ul>';}}
        echo '<p>Verwijderde pakketten blijven '.HandoverLibrary::TRASH_DAYS.' dagen herstelbaar. Daarna worden de bestanden bij een volgende pakketactie opgeruimd.</p></details>';
    }
    private static function form(int $id,string $step,string $label,?callable $fields=null,string $selector='',bool $primary=false): void {
        $scoped=$selector!=='';$nonce='emsf_handover_'.$id.($scoped?'_'.$step.'_'.$selector:'');
        echo '<form class="'.($step==='prepare'?'emsf-package-create':'emsf-package-action').'" '.($step==='prepare'?'data-handover-prepare ':'').'method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="emsf_handover"><input type="hidden" name="site_id" value="'.$id.'"><input type="hidden" name="step" value="'.esc_attr($step).'">';
        if($scoped){echo '<input type="hidden" name="package" value="'.esc_attr($selector).'">';}wp_nonce_field($nonce);if($fields){$fields();}
        if(in_array($step,['delete','restore'],true)){echo '<button type="submit" class="'.($step==='delete'?'button-link button-link-delete':'button-link').'">'.esc_html($label).'</button>';}
        else{submit_button($label,$primary?'primary':'secondary','emsf_handover_'.$step);}echo '</form>';
    }
    /** Send large archives without retaining the whole response in PHP output buffers. */
    public static function streamArchive(string $path,string $filename): void {
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.zip$/D',$filename)||!is_file($path)||!is_readable($path)){throw new \RuntimeException('Het downloadbestand is niet beschikbaar.');}
        $handle=@fopen($path,'rb');if(!$handle){throw new \RuntimeException('Het downloadbestand kan niet worden geopend.');}
        try{
            $stat=fstat($handle);if(!$stat||$stat['size']<1){throw new \RuntimeException('Het downloadbestand is leeg.');}
            while(ob_get_level()>0){$buffer=ob_get_status();if(!(($buffer['flags']??0)&PHP_OUTPUT_HANDLER_REMOVABLE)||!ob_end_clean()){throw new \RuntimeException('De hosting kan de download niet zonder buffering versturen.');}}
            if(headers_sent()){throw new \RuntimeException('De download kan niet starten omdat de pagina al uitvoer heeft verstuurd.');}
            @ini_set('zlib.output_compression','0');
            nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.$stat['size']);header('X-Content-Type-Options: nosniff');
            while(!feof($handle)){
                $chunk=fread($handle,1024*1024);if($chunk===false){throw new \RuntimeException('Downloadbestand lezen mislukt.');}
                echo $chunk;flush();if(connection_aborted()){break;}
            }
        }finally{fclose($handle);}
    }
    public static function submit(): void {
        $id=absint($_POST['site_id']??0);$authorized=false;
        try{
            Sites::requireAdmin();Sites::site($id);$step=is_string($_POST['step']??null)?$_POST['step']:'';$selector=is_string($_POST['package']??null)?$_POST['package']:'';
            check_admin_referer('emsf_handover_'.$id.($selector!==''?'_'.$step.'_'.$selector:''));$authorized=true;nocache_headers();
            if($step==='prepare'){self::prepare($id,(string)wp_unslash($_POST['target']??''),true);}
            elseif($step==='importer'){$path=self::importerArchive();self::streamArchive($path,'emonks-site-importer.zip');exit;}
            elseif($step==='download'){
                $selector=$selector?:((string)($_POST['fingerprint']??''));$path=self::archive($id,$selector);switch_to_blog($id);try{$record=HandoverLibrary::find($selector);HandoverLibrary::log('downloaded','Pakket gedownload.',$record);}finally{restore_current_blog();}
                $name='site-oplevering-'.wp_date('Ymd-Hi',strtotime($record['created_at'])).'-'.substr($record['operation'],0,8).'.zip';self::streamArchive($path,$name);exit;
            }elseif($step==='delete'){self::remove($id,$selector);wp_safe_redirect(add_query_arg('removed',$selector,Sites::url($id,'oplevering')));exit;}
            elseif($step==='restore'){self::restore($id,$selector);}
            else{throw new \RuntimeException('Onbekende pakketactie.');}
            wp_safe_redirect(Sites::url($id,'oplevering'));exit;
        }catch(\Throwable $e){
            if(!$authorized){wp_die(esc_html($e->getMessage()),'Actie niet toegestaan',['response'=>403]);}
            switch_to_blog($id);try{HandoverLibrary::log('error',$e->getMessage());}finally{restore_current_blog();}
            if(headers_sent()){error_log('Site Factory download: '.$e->getMessage());exit;}
            set_transient('emsf_handover_notice_'.$id.'_'.get_current_user_id(),$e->getMessage(),300);wp_safe_redirect(Sites::url($id,'oplevering'));exit;
        }
    }
}
