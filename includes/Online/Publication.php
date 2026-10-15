<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\{Factory,Package};
final class Publication {
    public static function progress(string $source,string $stage,string $message,int $done=0,int $total=0): void {
        Store::write('progress-'.$source,['stage'=>$stage,'message'=>$message,'done'=>$done,'total'=>$total,'at'=>gmdate('c')]);
    }
    public static function discardExport(string $dir): void {
        if(!preg_match('~^'.preg_quote(Store::root(),'~').'/(?:check|out|download|pull)-[a-f0-9-]{36}$~D',$dir)||is_link($dir)){throw new \RuntimeException('Ongeldig tijdelijk pakketpad.');}
        if(!is_dir($dir)){return;}
        $items=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($items as$item){if($item->isDir()&&!$item->isLink()){rmdir($item->getPathname());}else{unlink($item->getPathname());}}rmdir($dir);
    }
    /** Independent checks: a missing dependency must not hide the other results. */
    public static function environmentChecks(array $status): array {
        $local=\Emonks\Factory\ThemeRelease::environment();$remote=$status['environment']??[];
        $connected=!empty($status);$known=!empty($remote);$checks=[];
        $add=static function($label,$here,$there,$ok,$action)use(&$checks){$checks[]=['label'=>$label,'local'=>$here,'online'=>$there,'ok'=>$ok,'action'=>$action];};
        $add('Verbinding','Ingesteld',$connected?'Bereikbaar':'Niet gecontroleerd',$connected,$connected?'':'Controleer het online adres en de verbinding.');
        $theme=$remote['theme']??[];
        $configured=\Emonks\Factory\ThemeRelease::remoteSlug();
        $themeOk=!empty($local['theme']['installed'])&&!empty($local['theme']['enabled'])&&$known&&!empty($theme['installed'])&&!empty($theme['enabled'])&&($theme['slug']??'')===$configured;
        $add('Thema',$local['theme']['slug'],$known?($theme['slug']??$configured):$configured.' · niet gecontroleerd',$themeOk,
            empty($local['theme']['installed'])?'Installeer het ingestelde thema lokaal.':(empty($local['theme']['enabled'])?'Schakel het lokale thema netwerkbreed in.':(!$known?'Werk de online Site Factory-plugin bij en controleer opnieuw.':(($theme['slug']??'')!==$configured?'De themamap is gewijzigd. Controleer opnieuw.':(empty($theme['installed'])?'Upload het thema online in de map '.$configured.'. Of pas de themamap aan bij Online verbinding.':(empty($theme['enabled'])?'Schakel dit thema in via online Netwerkbeheer → Thema’s.':''))))));
        foreach(['Themaversie'=>[$local['theme']['version'],$theme['version']??($status['theme']['version']??''),'thema'], 'Site Factory-pluginversie'=>[$local['plugin']['version'],$remote['plugin']['version']??($status['plugin_version']??''),'Site Factory-plugin']] as $label=>[$here,$there,$component]){
            $state=\Emonks\Factory\ThemeRelease::compare((string)$there,(string)$here);
            $action=match($state){'current'=>'','behind'=>'Upload de lokale '.$component.'-uitgave ('.$here.') online.','ahead'=>'Werk de lokale '.$component.' bij naar de online uitgave ('.$there.').',default=>'Werk de online Site Factory-plugin bij en controleer opnieuw; het versienummer is nog niet beschikbaar.'};
            $add($label,$here?:'Ontbreekt',$there?:'Niet gecontroleerd',$state==='current',$action);
        }
        $acf=$remote['acf']??[];$acfOk=$known&&!empty($acf['active'])&&!empty($acf['pro']);
        $add('ACF Pro',!empty($local['acf']['pro'])?($local['acf']['version']?:'Actief'):'Niet actief',$known?($acfOk?($acf['version']?:'Actief'):'Niet actief'):'Niet gecontroleerd',$acfOk&&$local['acf']['active']&&$local['acf']['pro'],!$known?'Controleer opnieuw na de pluginupdate.':(!$acfOk?'Installeer ACF Pro online en activeer de plugin netwerkbreed.':(!$local['acf']['pro']?'Activeer ACF Pro lokaal.':'')));
        $hosting=$known&&!empty($remote['hosting']['verified']);
        $add('Online hosting','—',$known?($hosting?'Gecontroleerd':'Controle ontbreekt'):'Niet gecontroleerd',$hosting,$hosting?'':'Rond de online controle voor private opslag, caching en media af.');
        if(!empty($status['link']['site'])&&empty($status['link']['deleted'])){
            $active=$status['link']['theme_slug']??'';
            $add('Actief thema op deze site','—',$active?:'Niet gecontroleerd',$active===$configured,$active===$configured?'':'Activeer '.$configured.' op de gekoppelde online site.');
        }
        if(!$connected){foreach($checks as &$check){$check['action']='Kies Alles controleren om de actuele online gegevens op te halen.';}unset($check);}
        return $checks;
    }
    public static function inspect(string $slug): array {
        Factory::local();if(function_exists('wp_raise_memory_limit')){wp_raise_memory_limit('admin');}$p=Factory::find($slug);$status=[];$report=['ok'=>false,'issues'=>[],'checked_at'=>gmdate('c')];
        try{$status=Client::status($slug);}catch(\Throwable $e){$report['issues'][]=$e->getMessage();if($e->getCode()===409){$status=['reachable'=>true];}}
        $report['software_update']=!empty($status['software_update']);
        $report['checks']=self::environmentChecks($status);
        $ready=!array_filter($report['checks'],static fn($check)=>!$check['ok']);
        if(!$ready){
            $report['checks'][]=['label'=>'Inhoud en media','local'=>'Nog niet samengesteld','online'=>'Nog niet gecontroleerd','ok'=>false,'action'=>'Los eerst de omgevingspunten hierboven op en kies daarna Alles controleren.'];
            if(!empty($status['operation'])){$report['operation']=$status['operation'];}
            Store::write('check-'.$p['uuid'],$report);return ['publication_check'=>$report];
        }
        $pending=Store::read('pending-'.$p['uuid']);$temporary=!$pending;$dir='';
        try{
            $dir=Store::root().'/'.($temporary?'check-'.wp_generate_uuid4():'out-'.Store::key($pending['operation']));
            if($temporary){switch_to_blog((int)$p['site_id']);try{Package::export($dir);}finally{restore_current_blog();}}
            $manifest=Package::read($dir);
            $report+=['posts'=>count($manifest['posts']),'files'=>count($manifest['files']),'bytes'=>array_sum(array_column($manifest['files'],'size'))];
            $report['issues']=array_merge($report['issues'],$manifest['unsupported']??[]);
            if($ready&&empty($report['issues'])){
                $package=Client::checkPackage($p['uuid'],$manifest);$report=array_merge($report,$package);$report['pending']=!$temporary;
                $report['checks'][]=['label'=>'Inhoud en media','local'=>'Pakket samengesteld','online'=>!empty($package['ok'])?'Gecontroleerd':'Aandachtspunten','ok'=>!empty($package['ok']),'action'=>implode(' ',$package['issues']??[])];
            }else{$report['checks'][]=['label'=>'Inhoud en media','local'=>'Pakket samengesteld','online'=>'Nog niet gecontroleerd','ok'=>false,'action'=>'Los de punten hierboven op en kies Alles controleren.'];}
        }catch(\Throwable $e){$report['ok']=false;$report['issues'][]=$e->getMessage();}
        finally{if($temporary&&$dir){self::discardExport($dir);}}
        $report['ok']=$ready&&empty($report['issues'])&&!array_filter($report['checks'],static fn($check)=>!$check['ok']);
        if(!empty($status['operation'])){$report['operation']=$status['operation'];}
        Store::write('check-'.$p['uuid'],$report);return ['publication_check'=>$report];
    }
    public static function summary(array $report): string {
        $html='<div class="emsf-callout"><h3>Controleresultaat</h3><p>'.esc_html(!empty($report['unverified'])?'Nog niet gecontroleerd.':(!empty($report['ok'])?'Gereed voor publicatie.':'Er zijn nog punten om op te lossen.')).'</p>';
        if(!empty($report['checks'])){
            $html.='<table class="widefat striped"><thead><tr><th>Onderdeel</th><th>Lokaal</th><th>Online</th><th>Controle</th><th>Volgende stap</th></tr></thead><tbody>';
            foreach($report['checks'] as $check){$html.='<tr><th>'.esc_html($check['label']).'</th><td>'.esc_html($check['local']).'</td><td>'.esc_html($check['online']).'</td><td>'.(!empty($check['ok'])?'In orde':'Aandacht nodig').'</td><td>'.esc_html($check['action']?:'—').'</td></tr>';}
            $html.='</tbody></table>';
        }
        foreach($report['issues']??[]as$issue){$html.='<p role="alert">'.esc_html($issue).'</p>';}
        if(isset($report['posts'])){$html.='<p>'.(int)$report['posts'].' inhoudsitems · '.(int)$report['files'].' bestanden · '.esc_html(number_format((int)$report['bytes']/1048576,1,',','.')).' MB.</p>';}
        $changes=$report['changes']??[];
        if($changes&&(!$changes['known']||$changes['changed'])){$html.='<p><strong>'.esc_html(!$changes['known']?'Deze oudere site heeft nog geen nulmeting. Controleer de online inhoud vóór vervangen.':(!empty($changes['customer'])?'De klant heeft online inhoud aangepast sinds de laatste publicatie.':'Online inhoud is aangepast sinds de laatste publicatie.')).'</strong></p>';}
        if(!empty($report['operation'])){$html.='<p>'.esc_html(self::recovery(['operation'=>$report['operation']])).'</p>';}
        if(!empty($report['checked_at'])){$html.='<p>Gecontroleerd: '.esc_html(wp_date('d-m-Y H:i',strtotime($report['checked_at']))).'. Bij publicatie controleren we opnieuw.</p>';}
        try{
            $base=Client::endpoint().'/wp-admin/network/';
            if(!empty($report['software_update'])){
                $html.='<p>Bijwerken vervangt de gedeelde software voor alle websites op het online netwerk. Eerst wordt een herstelkopie gemaakt.</p><p><button type="button" class="button" data-software="theme">Lokaal thema online bijwerken</button> <button type="button" class="button" data-software="plugin">Lokale Site Factory-plugin online bijwerken</button></p>';
            }else{
                $html.='<p>De online plugin heeft eerst een eenmalige handmatige update nodig om updates vanuit lokaal te ontvangen.</p><p><button type="button" class="button" data-software-download>Lokale pluginzip downloaden</button> <a class="button" href="'.esc_url($base.'plugin-install.php?tab=upload').'" target="_blank" rel="noopener">Pluginzip online installeren ↗</a></p>';
            }
            $html.='<p><a href="'.esc_url($base.'theme-install.php?tab=upload').'" target="_blank" rel="noopener">Thema handmatig uploaden ↗</a></p>';
        }catch(\Throwable $e){}
        return $html.'</div>';
    }
    public static function recovery(array $status): string {
        $op=$status['operation']??[];$state=$op['status']??'';
        if($state==='complete'){return 'Publicatie voltooid. Open Preview & klanttoegang om het voorstel te delen.';}
        if($state==='receiving'){return 'De upload is nog niet toegepast. Hervat hetzelfde pakket, of annuleer de upload onder Beheer & herstel om opnieuw te beginnen.';}
        if($state==='failed'&&empty($op['backup'])){return 'De voorbereiding is gestopt. Controleer de melding en annuleer de upload onder Beheer & herstel voordat je een nieuw pakket maakt.';}
        if($state==='failed'){return 'Er is een herstelkopie. Gebruik de aangeboden herstelactie onder Publiceren of Beheer & herstel; start geen tweede site.';}
        if(in_array($state,['creating','backing_up','applying'],true)){return 'Online verwerking is begonnen. Controleer de status opnieuw; upload niet opnieuw zolang deze verwerking loopt.';}
        return 'Controleer de publicatie en de online toestand vóór opnieuw starten.';
    }
    public static function boot(): void {
        add_action('wp_ajax_emsf_software',static function(){
            try{
                Sites::requireAdmin();Factory::local();$id=absint($_POST['site_id']??0);check_ajax_referer('emsf_site_'.$id,'_wpnonce');Sites::site($id);$p=Sites::prospect($id);
                if(!$p){throw new \RuntimeException('Geen lokaal voorstel gevonden.');}
                if(function_exists('set_time_limit')){set_time_limit(300);}
                Software::push($p['slug'],(string)($_POST['component']??''));
                $report=self::inspect($p['slug'])['publication_check'];
                wp_send_json_success(['message'=>'Online software bijgewerkt. De controle is opnieuw uitgevoerd.','html'=>self::summary($report)]);
            }catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],409);}
        });
        add_action('admin_post_emsf_software_download',static function(){
            Sites::requireAdmin();Factory::local();$id=absint($_GET['site_id']??0);check_admin_referer('emsf_site_'.$id);Sites::site($id);
            $path=Store::root().'/software-'.wp_generate_uuid4().'.zip';
            try{
                $meta=Software::package('plugin',$path);nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="emonks-site-factory-'.$meta['version'].'.zip"');header('Content-Length: '.filesize($path));readfile($path);
            }finally{if(is_file($path)){unlink($path);}}exit;
        });
        add_action('wp_ajax_emsf_progress',static function(){
            try{Sites::requireAdmin();$id=absint($_GET['site_id']??0);check_ajax_referer('emsf_site_'.$id,'nonce');Sites::site($id);$p=Sites::prospect($id);if(!$p){throw new \RuntimeException('Alleen lokale publicaties hebben uploadvoortgang.');}wp_send_json_success(Store::read('progress-'.$p['uuid']));}catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],409);}
        });
        add_action('wp_ajax_emsf_publish',static function(){
            $id=absint($_POST['site_id']??0);
            try{Sites::requireAdmin();check_ajax_referer('emsf_site_'.$id,'_wpnonce');$data=wp_unslash($_POST);if(!in_array($data['site_action']??'',['create','replace','preflight','status'],true)||!Sites::local()){throw new \RuntimeException('Ongeldige publicatieactie.');}
                $result=Admin::run($id,$data);$message=is_array($result['publication_check']??null)?($result['publication_check']['ok']?'Controle afgerond. Bekijk hieronder het resultaat.':'Controle afgerond: er zijn nog aandachtspunten.'):'Publicatie voltooid.';
                wp_send_json_success(['message'=>$message,'html'=>is_array($result['publication_check']??null)?self::summary($result['publication_check']):'','changes'=>$result['publication_check']['changes']??[], 'url'=>Sites::url($id,'publicatie')]);
            }catch(\Throwable $e){$p=Sites::allowed()&&Sites::local()?Sites::prospect($id):[];if($p){self::progress($p['uuid'],'error',$e->getMessage());try{$pending=Store::read('pending-'.$p['uuid']);$status=Client::status($p['slug']);if(($data['site_action']??'')!=='preflight'&&$pending&&($status['operation']['id']??'')===$pending['operation']&&($status['operation']['status']??'')==='complete'){wp_send_json_success(['message'=>'Publicatie is online voltooid.','url'=>Sites::url($id,'publicatie')]);}}catch(\Throwable $ignored){}}wp_send_json_error(['message'=>$e->getMessage(),'url'=>Sites::url($id,'publicatie')],409);}
        });
    }
}
