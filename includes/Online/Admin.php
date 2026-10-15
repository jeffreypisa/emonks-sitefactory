<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\Factory;

/** Native network Sites list, with per-site confirmation screens and no bulk publishing. */
final class Admin {
    private static array $customerDraft=[];
    private static array $fieldErrors=[];
    private static string $formError='';
    public static function boot(): void {
        if (!is_multisite() || !(Sites::local() || (defined('EMSF_ONLINE') && EMSF_ONLINE))) { return; }
        add_filter('manage_sites_action_links',[Sites::class,'rows'],10,2);
        // Destructive operations require an individual scope/name confirmation, never a bulk publication.
        add_filter('bulk_actions-sites-network',static function($actions){foreach(['delete','archive','unarchive','spam','notspam','activate','deactivate'] as $key){unset($actions[$key]);}return $actions;});
        add_action('network_admin_menu',static function(){
            $hook=add_menu_page('Site Factory','Site Factory','manage_network','emsf-site',[self::class,'page'],'dashicons-admin-multisite');
            add_submenu_page('emsf-site','Alle sites','Alle sites','manage_network','emsf-site',[self::class,'page']);
            add_submenu_page('emsf-site','Meldingen','Meldingen','manage_network','emsf-notifications',[NotificationSettings::class,'page']);
            add_submenu_page('emsf-site','Mailtemplates','Mailtemplates','manage_network','emsf-mail-template',[MailTemplate::class,'page']);
            add_action('load-'.$hook,static function(){global $title;$title='Site Factory';self::maybeSubmit();});
            if(Sites::local()){
                remove_submenu_page('sites.php','site-new.php');
                add_submenu_page('emsf-site','Nieuwe lokale site','Nieuwe lokale site','manage_network','emsf-site-new',[self::class,'newPage']);
                add_submenu_page('emsf-site','Online verbinding','Online verbinding','manage_network','emsf-connection',[self::class,'connection']);
            }
        });
        add_filter('parent_file',static fn($parent)=>str_starts_with((string)($_GET['page']??''),'emsf-')?'emsf-site':$parent);
        add_action('load-site-new.php',static function(){if(Sites::local()&&Sites::allowed()){wp_safe_redirect(network_admin_url('admin.php?page=emsf-site-new'));exit;}});
        add_action('admin_init',static function(){if(is_network_admin()&&Sites::allowed()&&in_array($_GET['page']??'', ['emonks-factory','emsf-online'],true)){wp_safe_redirect(network_admin_url('admin.php?page=emsf-site'));exit;}});
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_action('wp_ajax_emsf_refresh_status',[self::class,'refreshStatus']);
        add_action('admin_post_emsf_site',[self::class,'submit']);
        add_action('admin_post_emsf_new_site',[self::class,'create']);
    }
    public static function assets(): void {
        if(!is_network_admin() || !Sites::allowed()){return;}
        $screen=get_current_screen();
        if(!str_starts_with((string)($_GET['page']??''),'emsf-')){return;}
        $base=plugins_url('assets/',dirname(__DIR__,2).'/emonks-site-factory.php');
        wp_enqueue_style('emsf-admin',$base.'admin.css',[],'0.13.1');
        wp_enqueue_script('emsf-admin',$base.'admin.js',[],'0.13.1',true);
    }
    private static function protect(): void {
        Sites::requireAdmin();nocache_headers();if(!headers_sent()){header('Referrer-Policy: no-referrer');}
    }
    private static function field(string $name,string $label,string $value='',string $type='text',bool $required=false): void {
        $customer=str_starts_with($name,'customer_');$error=self::$fieldErrors[$name]??'';$id='emsf-'.$name;
        echo '<p><label>'.esc_html($label).'<br><input class="regular-text" '.($customer?'id="'.$id.'" ':'').'type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.($required?'required ':'').'autocomplete="off" '.($customer?'aria-describedby="'.$id.'-error" aria-invalid="'.($error?'true':'false').'" ':'').'></label>';
        if($customer){echo '<span class="emsf-field-error" id="'.$id.'-error" '.($error?'':'hidden').'>'.esc_html($error).'</span>';}
        if($name==='customer_username'){echo '<span class="description">Hoofdletters worden automatisch kleine letters in de gebruikersnaam.</span>';}
        echo '</p>';
    }
    private static function customerInput(array $data): array {
        if(($data['customer_mode']??'new')==='none'){return [];}
        if(!in_array($data['customer_mode']??'new',['new','none'],true)){throw new \RuntimeException('Kies of je een opdrachtgever wilt aanmaken.');}
        return ['name'=>$data['customer_name']??'','username'=>$data['customer_username']??'','email'=>$data['customer_email']??'','confirmed'=>!empty($data['customer_confirmed']),'layout_access'=>!empty($data['layout_access'])];
    }
    /** Same-page POST keeps entered values in memory only, including server/remote errors. */
    public static function maybeSubmit(): void {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||($_POST['action']??'')!=='emsf_site'){return;}
        self::protect();$id=absint($_POST['site_id']??0);Sites::site($id);check_admin_referer('emsf_site_'.$id);
        if($id!==absint($_GET['id']??0)||($_POST['site_action']??'')!=='create'||($_POST['scope']??'')!=='online'){wp_die('Ongeldig publicatieformulier.','Actie gestopt',['response'=>400]);}
        $_POST['return_tab']='publicatie';$data=wp_unslash($_POST);$customer=[];
        try{$customer=self::customerInput($data);$result=self::run($id,$data);}catch(\Throwable $e){
            self::$formError=$e->getMessage();self::$fieldErrors=$customer?Accounts::errors($customer):[];
            foreach(['name','username','email']as$key){self::$customerDraft[$key]=is_string($customer[$key]??null)?$customer[$key]:'';}
            self::$customerDraft['confirmed']=$customer['confirmed']??false;self::$customerDraft['layout_access']=$customer['layout_access']??false;self::$customerDraft['mode']=$customer?'new':'none';status_header(409);return;
        }
        self::result($id,$result);
    }
    private static function activeTab(): string {
        $tab=(string)($_POST['return_tab']??$_GET['tab']??'publicatie');
        $tab=['mail'=>'email','archive'=>'beheer','unarchive'=>'beheer','delete'=>'beheer'][$tab]??$tab;
        if(!in_array($tab,['ophalen','publicatie','opvolging','toegang','gebruikers','activiteit','email','beheer'],true)){$tab='publicatie';}
        return !Sites::local()&&$tab==='publicatie'?'toegang':$tab;
    }
    private static function panel(string $tab): string {
        return ' id="'.$tab.'" role="tabpanel" aria-labelledby="emsf-tab-'.$tab.'" tabindex="-1"'.(self::activeTab()===$tab?'':' hidden');
    }
    private static function form(int $id,string $action,string $scope,string $label,?callable $fields=null): void {
        $customer=$action==='create'&&$scope==='online'&&Sites::local();
        echo '<form class="emsf-action-form" '.(Sites::local()&&in_array($action,['create','replace','preflight','status'],true)?'data-publication data-ajax="'.esc_url(admin_url('admin-ajax.php')).'" ':'').($customer?'data-customer-form novalidate ':'').'method="post" action="'.esc_url($customer?Sites::url($id,'publicatie'):admin_url('admin-post.php')).'"><input type="hidden" name="action" value="emsf_site"><input type="hidden" name="site_id" value="'.esc_attr((string)$id).'"><input type="hidden" name="site_action" value="'.esc_attr($action).'"><input type="hidden" name="scope" value="'.esc_attr($scope).'">';
        echo '<input type="hidden" name="return_tab" value="'.esc_attr(self::activeTab()).'">';
        wp_nonce_field('emsf_site_'.$id);if($fields){$fields();}submit_button($label,$action==='delete'?'emsf-delete':(in_array($action,['create','replace'],true)?'primary':'secondary'));echo '</form>';
    }
    public static function newPage(): void {
        self::protect();Factory::local();
        echo '<div class="wrap emsf-wrap">';self::navigation();echo '<h1>Nieuwe lokale site</h1><p>Maak een lokaal concept. Er wordt niets online gepubliceerd en er wordt geen klantaccount of e-mail aangemaakt.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="emsf_new_site">';
        echo '<p><a href="'.esc_url(network_admin_url('sites.php')).'">← Alle sites</a></p>';wp_nonce_field('emsf_new_site');self::field('name','Sitenaam','','text',true);self::field('slug','Korte naam in het lokale adres (bijvoorbeeld nieuw-concept)','','text',true);submit_button('Lokaal concept aanmaken');echo '</form></div>';
    }
    public static function connection(): void {
        self::protect();$saved=false;$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            check_admin_referer('emsf_connection_settings');
            try{
                $local=\Emonks\Factory\ThemeRelease::validateSlug((string)wp_unslash($_POST['local_theme_slug']??''));
                $remote=\Emonks\Factory\ThemeRelease::validateSlug((string)wp_unslash($_POST['remote_theme_slug']??''));
                if(!wp_get_theme($local)->exists()){throw new \RuntimeException('Deze lokale themamap is niet geïnstalleerd.');}
                update_site_option('emsf_theme_slug',$local);update_site_option('emsf_remote_theme_slug',$remote);$saved=true;
            }catch(\Throwable $e){$error=$e->getMessage();}
        }
        echo '<div class="wrap emsf-wrap">';self::navigation();echo '<h1>Online verbinding</h1>';
        if($saved){echo '<div class="notice notice-success"><p>Themamappen opgeslagen. Kies bij je site Alles controleren.</p></div>';}
        if($error){echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';}
        try{echo '<p>Online netwerk: <strong>'.esc_html(Client::endpoint()).'</strong></p>';}catch(\Throwable $e){echo '<p>Stel het online netwerk in met EMSF_REMOTE_URL, EMSF_REMOTE_USER en EMSF_REMOTE_PASSWORD in wp-config.php.</p>';}
        echo '<form method="post">';wp_nonce_field('emsf_connection_settings');
        self::field('local_theme_slug','Themamap lokaal',\Emonks\Factory\ThemeRelease::slug(),'text',true);
        self::field('remote_theme_slug','Themamap online',\Emonks\Factory\ThemeRelease::remoteSlug(),'text',true);
        echo '<p>Vul de mapnaam binnen wp-content/themes in, bijvoorbeeld skeletor of skeletor-master. Lokaal en online mogen verschillende mapnamen gebruiken. Het thema wordt afzonderlijk geüpload; deze instelling hernoemt of activeert niets.</p>';
        submit_button('Themamappen opslaan');echo '</form><p>Open daarna je site en kies Alles controleren. Daar zie je verbinding, ACF Pro, thema- en pluginversie en de volgende stap per onderdeel.</p></div>';
    }
    private static function navigation(): void {
        echo '<nav class="emsf-topnav" aria-label="Site Factory"><a href="'.esc_url(network_admin_url('sites.php')).'">← WordPress Sites</a><a href="'.esc_url(network_admin_url('admin.php?page=emsf-site')).'">Alle sites</a>';
        if(Sites::local()){echo '<a href="'.esc_url(network_admin_url('admin.php?page=emsf-site-new')).'">Nieuwe lokale site</a><a href="'.esc_url(network_admin_url('admin.php?page=emsf-connection')).'">Online verbinding</a>';}
        echo '</nav>';
    }
    private static function date(string $value): string {
        $time=$value!==''?strtotime($value.' UTC'):false;
        return $time?wp_date('d-m-Y H:i',$time):'—';
    }
    public static function visitSummary(array $snapshot): string {
        $events=array_values(array_filter($snapshot['events']??[],static fn($e)=>(int)($e['at']??0)>=time()-30*DAY_IN_SECONDS));
        $labels=['customer_front'=>'Bekeken via klantaccount','customer_login'=>'Klant ingelogd','customer_dashboard'=>'Klantdashboard geopend','preview_view'=>'Anonieme preview bekeken'];
        usort($events,static fn($a,$b)=>$b['at']<=>$a['at']);
        foreach($events as $event){if(isset($labels[$event['kind']??''])){return $labels[$event['kind']].' · '.wp_date('d-m-Y H:i',(int)$event['at']);}}
        if(isset($snapshot['policy']['enabled'])&&!$snapshot['policy']['enabled']){return 'Registratie uitgeschakeld';}
        return $snapshot?'Geen bezoek geregistreerd (30 dagen)':'Nog niet opgehaald';
    }
    private static function landing(): void {
        $local=Sites::local();$prospects=[];
        if($local){foreach(Factory::prospects() as $p){$prospects[(int)$p['site_id']]=$p;}}
        echo '<div class="wrap emsf-wrap emsf-overview">';self::navigation();echo '<header class="emsf-heading"><p class="emsf-eyebrow">EMONKS · SITE FACTORY</p><h1>Alle sites</h1><p>Je voorstellen, publicatiestatus en klantactiviteit op één plek.</p></header>';
        if($local){echo '<p><a class="button button-primary" href="'.esc_url(network_admin_url('admin.php?page=emsf-site-new')).'">Nieuwe lokale site</a></p><p>Online status en bezoek zijn laatst opgehaalde gegevens. Verversen haalt alleen de status op; er wordt geen inhoud gesynchroniseerd. Zonder verbinding blijven de laatst bekende gegevens zichtbaar.</p>';}
        if($local){echo '<div class="emsf-refresh-bar"><button type="button" class="button" data-refresh-all data-url="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('emsf_refresh')).'">Online status verversen</button><span role="status" aria-live="polite" data-refresh-summary>Ververs alle zichtbare gekoppelde sites.</span></div>';}
        echo '<form method="get" class="emsf-filter"><input type="hidden" name="page" value="emsf-site"><label>Zoek site <input name="site_search" value="'.esc_attr(is_string($_GET['site_search']??null)?$_GET['site_search']:'').'"></label> <label>Klantfase <select name="follow_stage"><option value="">Alle fases</option>';
        foreach(FollowUp::labels()as$key=>$label){echo '<option value="'.$key.'" '.selected($_GET['follow_stage']??'',$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label> <label><input type="checkbox" name="follow_due" value="1" '.checked(!empty($_GET['follow_due']),true,false).'> Vandaag opvolgen / te laat</label> <button class="button">Filteren</button></form>';
        echo '<details class="emsf-help"><summary>Hoe wordt klantactiviteit gemeten?</summary><p>Activiteit betreft de laatste 30 dagen, maximaal 100 gebeurtenissen. Een anonieme preview bewijst niet dat de klant heeft gekeken. Eigen bezoeken als netwerkbeheerder tellen niet mee.</p></details><div class="emsf-log"><table class="widefat striped emsf-sites"><thead><tr><th>Site</th><th>Status</th><th>Klantactiviteit</th><th>Klantopvolging</th><th>Acties</th></tr></thead><tbody>';
        $filter=is_string($_GET['follow_stage']??null)?sanitize_key($_GET['follow_stage']):'';$search=is_string($_GET['site_search']??null)?sanitize_text_field($_GET['site_search']):'';$due=!empty($_GET['follow_due']);
        $count=0;$states=['ready'=>'Online','archived'=>'Online gearchiveerd','importing'=>'Publicatie bezig','failed'=>'Herstel nodig','restoring'=>'Herstel bezig'];
        foreach(get_sites(['number'=>0,'network_id'=>get_current_network_id(),'orderby'=>'registered','order'=>'DESC']) as $site){
            $id=(int)$site->blog_id;$p=$prospects[$id]??[];
            if($local?!$p:!get_site_meta($id,'emsf_managed',true)){continue;}$follow=FollowUp::get($id);if(($filter&&$follow['stage']!==$filter)||($due&&!FollowUp::due($follow))||($search&&stripos((string)get_blog_option($id,'blogname'),$search)===false)){continue;}$count++;
            $bound=$local?Store::read('remote-'.$p['uuid']):['site'=>$id,'state'=>get_site_meta($id,'emsf_state',true),'activity'=>Activity::snapshot($id)];
            $pending=$local?Store::read('pending-'.$p['uuid']):[];
            if(!$local){$bound['preview_mode']=Preview::mode($id);}
            $checked=$local?Store::read('status-'.$p['uuid']):[];
            $status=!empty($bound['deleted'])?'Online verwijderd':(!empty($bound['site'])?($states[$bound['state']??'']??'Online status controleren'):'Lokaal concept');
            if(($bound['state']??'')==='ready'&&isset($bound['preview_mode'])){$status.=$bound['preview_mode']==='public'?' · zonder code':($bound['preview_mode']==='code'?' · met code':' · alleen ingelogd');}
            if($pending){$status='Publicatie open — hervatten';}if($site->archived){$status=$local?'Lokaal gearchiveerd · '.$status:'Online gearchiveerd';}
            echo '<tr data-site-id="'.$id.'" data-connected="'.($local&&(!empty($bound['site'])||$pending)?'1':'0').'"><td><strong><a href="'.esc_url(Sites::url($id)).'">'.esc_html(get_blog_option($id,'blogname')).'</a></strong><br><a href="'.esc_url(get_home_url($id)).'">'.($local?'Lokaal bekijken':'Online bekijken').' ↗</a><span class="description">Gewijzigd: '.esc_html(self::date($site->last_updated)).'</span></td><td><span data-online-state>'.esc_html($status).'</span>';
            if($local){echo '<span class="description" data-checked>Laatste controle: '.esc_html(self::date($checked['checked_at']??'')).'</span><span class="description" data-status-error role="status">'.esc_html($checked['error']??'').'</span>';if(!empty($bound['url'])){echo '<a href="'.esc_url($bound['url']).'">Online bekijken ↗</a>';}}
            echo '</td><td><a data-activity href="'.esc_url(Sites::url($id,'activiteit')).'">'.esc_html(empty($bound['site'])?'Nog niet online':self::visitSummary($bound['activity']??[])).'</a></td><td><a href="'.esc_url(Sites::url($id,'opvolging')).'">'.esc_html(FollowUp::labels()[$follow['stage']]??'Nog benaderen').'</a>'.($follow['date']?'<br><strong>'.esc_html((FollowUp::due($follow)?'Opvolgen: ':'Gepland: ').$follow['date']).'</strong>':'').($follow['price']?'<br>'.esc_html($follow['price']):'').'</td><td class="emsf-actions-cell"><div class="emsf-row-actions"><a class="button" href="'.esc_url(Sites::url($id)).'">'.($pending?'Publicatie hervatten':'Beheren').'</a><a class="emsf-edit-link" href="'.esc_url(get_admin_url($id)).'">Site bewerken</a></div></td></tr>';
        }
        if(!$count){echo '<tr><td colspan="5">Geen sites gevonden. Pas de filters aan of maak een nieuw lokaal concept.</td></tr>';}
        echo '</tbody></table></div></div>';
    }
    public static function refreshStatus(): void {
        Sites::requireAdmin();Factory::local();check_ajax_referer('emsf_refresh','nonce');
        $id=absint($_POST['site_id']??0);
        try{$p=Sites::prospect($id);$fresh=Client::status($p['slug']);$link=$fresh['link']??[];
            $labels=['ready'=>'Online beschikbaar','archived'=>'Online gearchiveerd','failed'=>'Herstel nodig','importing'=>'Publicatie bezig','deleted'=>'Online verwijderd'];
            wp_send_json_success(['status'=>$labels[$link['state']??'']??'Nog niet online','checked'=>self::date(gmdate('Y-m-d H:i:s')),'activity'=>self::visitSummary($fresh['activity']??[])]);
        }catch(\Throwable $e){wp_send_json_error(['message'=>'Verversen mislukt. Laatst bekende gegevens blijven staan.'],409);}
    }
    private static function themeInfo(array $p,array $pending): void {
        $version=\Emonks\Factory\ThemeRelease::version();$digest=\Emonks\Factory\Package::theme_digest();
        $checked=Store::read('status-'.$p['uuid']);$remote=$checked['theme']??[];
        $installed=is_string($remote['version']??null)?$remote['version']:'';
        echo '<div class="emsf-callout"><strong>Skeletor-versie</strong><p>Verwacht (lokaal): '.esc_html($version?:'Geen versienummer').' · Geïnstalleerd (online): '.esc_html($remote?($installed?:'Geen versienummer'):'Nog niet opgehaald').'</p>';
        if($remote){
            $comparison=\Emonks\Factory\ThemeRelease::compare($installed,$version);
            $messages=['current'=>'De themaversies komen overeen.','behind'=>'Online gebruikt een oudere themaversie. Installeer de verwachte release online.','ahead'=>'Online gebruikt een nieuwere themaversie. Werk het lokale thema bij.','unknown'=>'Versiecontrole niet mogelijk: een geldig versienummer ontbreekt.'];
            echo '<p><strong>'.esc_html($messages[$comparison]).'</strong> Laatste controle: '.esc_html(self::date($checked['checked_at']??'')).'.</p>';
            if(!empty($remote['digest'])&&$remote['digest']!==$digest){echo '<p>De gecontroleerde bestanden verschillen. Het versienummer bepaalt of synchronisatie is toegestaan.</p>';}
        }
        if($pending){
            try{$dir=Store::root().'/out-'.Store::key((string)$pending['operation']);if(!is_readable($dir.'/manifest.json')){throw new \RuntimeException('Pakket ontbreekt.');}$manifest=\Emonks\Factory\Package::read($dir);$deps=$manifest['dependencies']??[];
                $packageVersion=is_string($deps['theme_version']??null)?$deps['theme_version']:'';
                echo '<p>Opgeslagen publicatiepakket: '.esc_html($packageVersion?:'Zonder versienummer').'.</p>';
                if(\Emonks\Factory\ThemeRelease::compare($packageVersion,$version)!=='current'){echo '<p><strong>Dit pakket verwacht een andere themaversie of mist een geldige versie.</strong> Hervatten blijft het oude pakket gebruiken. Controleer de online status, annuleer de openstaande upload onder Beheer & herstel en maak een nieuw pakket.</p>';}
                elseif(!empty($deps['theme_digest'])&&$deps['theme_digest']!==$digest){echo '<p>De pakketbestanden verschillen; de themaversie komt overeen.</p>';}
            }catch(\Throwable $e){echo '<p>Het opgeslagen pakket is niet leesbaar. Controleer de online status en gebruik Beheer & herstel.</p>';}
        }
        $releases=\Emonks\Factory\ThemeRelease::releases();
        // Prefer the local expected release; include online-only releases when online is newer.
        foreach(is_array($remote['releases']??null)?$remote['releases']:[] as $release){
            if(!is_array($release)||!is_string($release['version']??null)||!\Emonks\Factory\ThemeRelease::valid($release['version'])||!is_string($release['date']??null)||!is_array($release['changes']??null)){continue;}
            if(!in_array($release['version'],array_column($releases,'version'),true)){$releases[]=$release;}
        }
        usort($releases,static fn($a,$b)=>version_compare($b['version'],$a['version']));
        if($releases){
            echo '<details><summary>Wijzigingen per release</summary>';
            foreach(array_slice($releases,0,100) as $release){
                $missing=\Emonks\Factory\ThemeRelease::valid($installed)&&version_compare($release['version'],$installed,'>')&&version_compare($release['version'],$version,'<=');
                echo '<h4>'.esc_html($release['version'].' · '.$release['date']).($missing?' — Nog niet online':'').'</h4><ul>';
                foreach(array_slice($release['changes'],0,50) as $change){if(is_string($change)){echo '<li>'.esc_html($change).'</li>';}}
                echo '</ul>';
            }
            echo '</details>';
        }else{echo '<p>Voor deze thema-uitgave zijn nog geen releasewijzigingen beschikbaar.</p>';}
        echo '</div>';
    }
    public static function page(): void {
        self::protect();$id=absint($_GET['id']??0);if(!$id){self::landing();return;}
        try{$site=Sites::site($id);}catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'Site niet beschikbaar',['response'=>403]);}
        $local=Sites::local();$p=$local?Sites::prospect($id):[];$name=(string)get_blog_option($id,'blogname');
        $bound=$local?Store::read('remote-'.$p['uuid']):['site'=>$id,'name'=>$name,'url'=>get_home_url($id),'state'=>get_site_meta($id,'emsf_state',true),'mail_mode'=>Mail::mode($id),'preview_mode'=>Preview::mode($id),'preview_enabled'=>Preview::enabled($id),'activity'=>Activity::snapshot($id)];
        $pending=$local?Store::read('pending-'.$p['uuid']):[];$hasOnline=!empty($bound['site'])&&empty($bound['deleted']);
        $opState=$local?(Store::read('status-'.$p['uuid'])['operation']??[]):[];
        $states=['ready'=>'Online beschikbaar','archived'=>'Gearchiveerd','importing'=>'Publicatie bezig','failed'=>'Herstel nodig','restoring'=>'Herstel bezig'];
        $localUrl=$local?get_home_url($id):Sites::localSource($id);
        $onlineStatus=!empty($bound['deleted'])?'Online verwijderd':($hasOnline?($states[$bound['state']??'']??'Status controleren'):'Nog niet gepubliceerd');
        if(($bound['state']??'')==='ready'&&isset($bound['preview_mode'])){$onlineStatus.=$bound['preview_mode']==='public'?' · zonder code':($bound['preview_mode']==='code'?' · met code':' · alleen ingelogd');}
        echo '<div class="wrap emsf-wrap">';self::navigation();echo '<header class="emsf-heading"><p class="emsf-eyebrow">EMONKS · SITE FACTORY · '.($local?'LOKAAL':'ONLINE').'</p><h1>'.esc_html($name).'</h1><nav class="emsf-site-links" aria-label="Dit voorstel openen">';
        if($localUrl){echo '<a href="'.esc_url($localUrl).'"'.(!$local?' title="Opent de lokale werkversie op je eigen computer"':'').'>Lokaal bekijken ↗</a>';}
        if($hasOnline){echo '<a href="'.esc_url($bound['url']).'">Online bekijken ↗</a>';}
        echo '<a href="'.esc_url(get_admin_url($id)).'">'.($local?'Lokaal bewerken':'Online bewerken').' ↗</a><span class="emsf-site-state">'.esc_html(($local&&$site->archived?'Lokaal gearchiveerd · ':'').$onlineStatus).($local&&$hasOnline?' · laatst opgehaald':'').'</span></nav></header><div class="emsf-workspace"><nav class="emsf-tabs" role="tablist" aria-orientation="vertical" aria-label="Onderdelen van deze site">';
        foreach(['publicatie'=>'Publiceren','ophalen'=>'Online → lokaal','opvolging'=>'Klantopvolging','toegang'=>'Preview & klanttoegang','gebruikers'=>'Gebruikers','activiteit'=>'Activiteit','email'=>'Mails maken','beheer'=>'Beheer & herstel']as$tab=>$label){
            if(!$local&&in_array($tab,['publicatie','ophalen'],true)){continue;}$selected=self::activeTab()===$tab;
            echo '<a role="tab" id="emsf-tab-'.$tab.'" aria-controls="'.$tab.'" aria-selected="'.($selected?'true':'false').'" tabindex="'.($selected?'0':'-1').'" href="'.esc_url(Sites::url($id,$tab)).'">'.esc_html($label).'</a>';
        }
        echo '</nav><div class="emsf-panels">';
        if($local){
            echo '<section class="emsf-panel"'.self::panel('publicatie').'><p class="emsf-eyebrow">VAN LOKAAL NAAR ONLINE</p><h2>'.($hasOnline?'Online inhoud bijwerken':'Eerste online publicatie').'</h2>';
            $connected=true;try{$target=$hasOnline?$bound['url']:Client::endpoint().'/'.$p['slug'].'/';echo '<p>Alleen dit voorstel gaat naar <strong>'.esc_html($target).'</strong>.</p>';}catch(\Throwable $e){$connected=false;echo '<div class="emsf-callout"><p>Online verbinding nog niet ingesteld. Je kunt het lokale voorstel alvast bewerken.</p><a href="'.esc_url(network_admin_url('admin.php?page=emsf-connection')).'">Online verbinding instellen →</a></div>';}
            if($site->archived){echo '<p>Herstel het lokale concept onder Beheer voordat je publiceert.</p>';}
            elseif(!empty($bound['deleted'])){echo '<p>Deze online site is verwijderd. Laat de koppeling eerst controleren voordat je opnieuw publiceert.</p>';}
            elseif($connected && ($bound['state']??'')==='failed' && ($pending['action']??'')==='create'&&in_array($opState['error']??'',['Projectmodel overschrijft gedeeld thema.','Ontbrekend inhoudstype: skeletor_teaser'],true)){
                echo '<p class="emsf-callout">De eerste publicatie is mislukt. De online subsite blijft afgeschermd. Herstellen gebruikt de bestaande herstelkopie, hetzelfde pakket en dezelfde klantgegevens; er wordt geen tweede subsite aangemaakt en geen e-mail verstuurd.</p>';
                self::form($id,'retry_models','online','Mislukte publicatie herstellen en hervatten',static function()use($pending,$bound,$name){self::field('confirm','Exacte online sitenaam',$bound['name']??$name,'text',true);echo '<input type="hidden" name="operation" value="'.esc_attr($pending['operation']).'">';});
            }
            elseif($connected&&($bound['state']??'')==='failed'){
                echo '<p class="emsf-callout">'.esc_html(($opState['error']??'Publicatie gestopt.').' '.Publication::recovery(['operation'=>$opState])).'</p>';
                if(!empty($opState['backup'])&&!empty($opState['id'])){self::form($id,'restore','online','Herstelkopie terugzetten',static function()use($opState,$bound,$name){echo '<p>Zet deze site terug naar de toestand vóór de mislukte publicatie. Eerst wordt ook de huidige toestand bewaard.</p><input type="hidden" name="operation" value="'.esc_attr($opState['id']).'"><input type="hidden" name="confirm" value="'.esc_attr($bound['name']??$name).'">';});}
                else{echo '<a class="button" href="'.esc_url(Sites::url($id,'beheer')).'">Naar herstelopties</a>';}
            }
            elseif($connected){
                $action=$pending['action']??($hasOnline?'replace':'create');
                $report=Store::read('check-'.$p['uuid']);
                self::form($id,'preflight','online','Alles controleren');
                $display=$report?:['ok'=>false,'checks'=>Publication::environmentChecks([]),'unverified'=>true];
                if(!isset($display['checks'])){$cached=Store::read('status-'.$p['uuid']);$display['checks']=Publication::environmentChecks($cached);}
                echo '<div data-check-results>'.Publication::summary($display).'</div>';
                echo '<p><a href="'.esc_url(network_admin_url('admin.php?page=emsf-connection')).'">Themamappen en online verbinding instellen →</a></p>';
                $operation=Store::read('status-'.$p['uuid'])['operation']??[];if($operation){echo '<p class="emsf-callout">'.esc_html(Publication::recovery(['operation'=>$operation])).'</p>';}
                self::form($id,$action,'online',$pending?'Publicatie hervatten':($action==='create'?'Online subsite aanmaken en publiceren':'Online inhoud vervangen'),static function()use($action,$pending,$p,$report){
                    if($action==='create'){
                        echo '<p><label><input type="checkbox" name="preview_protected" value="1" '.checked(($pending['preview_mode']??'')==='code'||(!$pending&&!empty($_POST['preview_protected'])),true,false).'> Meteen beschermen met een toegangscode</label></p>';
                        $c=self::$customerDraft?:($pending['customer']??[]);
                        echo '<div class="emsf-form-error" role="alert" tabindex="-1" '.(self::$formError?'':'hidden').'>'.esc_html(self::$formError).'</div>';
                        $mode=$c['mode']??($c?'new':'none');
                        echo '<p>Nieuwe websites zijn standaard toegankelijk zonder code. Onder Preview & klanttoegang kun je codebeveiliging aanzetten. Kies zelf wanneer je een opdrachtgever koppelt. Er wordt geen e-mail verstuurd.</p><p><label>Opdrachtgever<br><select name="customer_mode" data-customer-mode><option value="none" '.selected($mode,'none',false).'>Later instellen</option><option value="new" '.selected($mode,'new',false).'>Nieuwe gebruiker aanmaken en koppelen</option></select></label></p><fieldset data-customer-fields><legend>Nieuw redacteuraccount (alleen bij aanmaken)</legend>';
                        self::field('customer_name','Naam contactpersoon',$c['name']??'','text');
                        self::field('customer_username','Gebruikersnaam (aanpasbaar)',$c['username']??sanitize_user($p['slug'],true),'text');
                        self::field('customer_email','Gecontroleerd e-mailadres',$c['email']??'','email');
                        echo '<p><label><input id="emsf-customer_confirmed" type="checkbox" name="customer_confirmed" value="1" '.(!empty($c['confirmed'])?'checked ':'').'aria-describedby="emsf-customer_confirmed-error" aria-invalid="'.(isset(self::$fieldErrors['customer_confirmed'])?'true':'false').'"> Ik heb de klantgegevens en het e-mailadres gecontroleerd.</label><span class="emsf-field-error" id="emsf-customer_confirmed-error" '.(isset(self::$fieldErrors['customer_confirmed'])?'':'hidden').'>'.esc_html(self::$fieldErrors['customer_confirmed']??'').'</span></p><p><label><input type="checkbox" name="layout_access" value="1" '.(!empty($c['layout_access'])?'checked':'').'> Redacteuren van deze site ook Skeletor-themaopties laten bewerken</label></p>';
                        echo '</fieldset>';
                    }else{
                        echo '<div class="emsf-callout"><p><strong>Online wijzigingen worden vervangen.</strong> Pagina’s, indeling, media, menu’s, reacties en formulierdefinities maken plaats voor deze lokale versie. Er wordt eerst een herstelkopie gemaakt.</p><p>Klantaccounts, wachtwoorden, previewcodes en de mailstand blijven behouden. Het gedeelde thema wordt apart bijgewerkt.</p></div>';
                        self::field('confirm','Typ de exacte huidige online sitenaam',$pending['confirm']??'','text',true);
                        $changes=$report['changes']??[];$revision=$pending['expected_revision']??($changes['token']??'');
                        echo '<input type="hidden" name="expected_revision" value="'.esc_attr($revision).'">';
                        if(!$pending){$reviewRequired=$changes&&(!$changes['known']||$changes['changed']);echo '<p data-replace-review '.($reviewRequired?'':'hidden').'><label><input type="checkbox" name="replace_changes" value="1" '.($reviewRequired?'required':'disabled').'> Ik heb de online inhoud gecontroleerd en wil deze vervangen door dit lokale voorstel.</label></p>';if(!$changes){echo '<p data-check-first><strong>Kies eerst Alles controleren om de online inhoud te vergelijken.</strong></p>';}}elseif(!empty($pending['replace_changes'])){echo '<input type="hidden" name="replace_changes" value="1">';}
                    }
                    if($pending){echo '<p class="emsf-callout">Deze publicatie staat nog open. De knop Publicatie hervatten gaat verder met het opgeslagen pakket en dezelfde klantgegevens. Voor een nieuw pakket: controleer de status en annuleer de upload onder Beheer & herstel.</p>';}
                });
            }
            echo '</section>';
        }
        if($local){
            echo '<section class="emsf-panel"'.self::panel('ophalen').'><p class="emsf-eyebrow">VAN ONLINE NAAR LOKAAL</p><h2>Online inhoud ophalen</h2><p>Haal de gekoppelde online site naar deze lokale werkversie om een probleem te onderzoeken. Pagina’s, media, menu’s, formulierdefinities en site-instellingen vervangen de lokale inhoud. Er wordt eerst een lokale herstelkopie gemaakt.</p><p>Thema, plugincode, accounts, wachtwoorden en formulierinzendingen worden niet overgenomen. Lokale e-mail en integraties staan na ophalen uit. De online site blijft ongewijzigd.</p>';
            if($hasOnline&&!$pending){self::form($id,'pull','online','Online inhoud ophalen en lokaal vervangen',static function()use($name){echo '<p><strong>Lokale wijzigingen worden vervangen.</strong> Typ ter bevestiging: <strong>'.esc_html($name).'</strong>.</p>';self::field('confirm','Exacte lokale sitenaam','','text',true);});}
            else{echo '<p>Koppel eerst een online site en rond eventuele openstaande publicaties af.</p>';}
            $pull=Store::read('pull-'.$p['uuid']);if($pull){echo '<p>Laatste ophaalactie: '.esc_html($pull['at']).' · '.esc_html($pull['state']).'<br>Lokale herstelkopie: <code>'.esc_html($pull['backup']).'</code></p>';}
            if(!empty($pull['backup'])){self::form($id,'pull_restore','online','Lokale herstelkopie terugzetten',static function()use($pull){echo '<p>Vervangt deze lokale werkversie door de herstelkopie. Ook de huidige lokale versie wordt eerst bewaard.</p><input type="hidden" name="backup" value="'.esc_attr($pull['backup']).'">';self::field('confirm','Exacte lokale sitenaam','','text',true);});}
            echo '</section>';
        }
        echo '<section class="emsf-panel"'.self::panel('opvolging').'><h2>Klantopvolging</h2>';
        self::form($id,'follow_save','site','Opvolging opslaan',static fn()=>FollowUp::panel($id));
        foreach(['first_sent'=>'Eerste mail verstuurd','follow_sent'=>'Vervolgmail verstuurd']as$mailStep=>$label){
            self::form($id,'follow_mark_'.$mailStep,'site',$label,static fn()=>print('<p>Gebruik deze knop nadat je deze mail zelf hebt verstuurd. De verzenddatum wordt vastgelegd; opgeslagen notities en afspraken blijven behouden.</p>'));
        }echo '</section>';
        echo '<section'.self::panel('toegang').'><h2 class="emsf-section-title">Preview & klanttoegang</h2><p>Kijken en bewerken zijn twee afzonderlijke vormen van toegang.</p>';
        if($hasOnline){
            echo '<div class="emsf-grid">';self::previewForm($id,(bool)($bound['preview_enabled']??false));
            echo '<section class="emsf-panel"><p class="emsf-eyebrow">PERSOONLIJK ACCOUNT</p><h2>Teksten laten bewerken</h2><p>De opdrachtgever gebruikt een persoonlijk account. De gebruikersrol bepaalt wat diegene mag bewerken. Een instellink is mogelijk zolang het via deze site aangemaakte account nog niet is gebruikt. Een nieuwe instellink maakt de vorige ongeldig.</p>';
            $contact=$local?($bound['customer']??[]):Accounts::details($id);
            if(!empty($contact['can_setup'])){self::form($id,'setup_link','online','Instellink voor opdrachtgever maken',static fn()=>print('<input type="hidden" name="expected_customer_id" value="'.esc_attr((string)($contact['id']??0)).'">')); }elseif($contact){echo '<p>Gebruik het bestaande account: <a href="'.esc_url($contact['login_url']).'">Inloggen</a>. Het wachtwoord blijft ongewijzigd.</p>';}else{echo '<p>Er is nog geen opdrachtgever gekoppeld.</p>';}
            echo '<p><a href="'.esc_url(Sites::url($id,'gebruikers')).'">Gebruikers en opdrachtgever beheren</a></p></section></div>';
        }else{echo '<div class="emsf-panel"><h3>Eerst publiceren, daarna toegang delen</h3><p>Na de eerste publicatie maak je hier een previewcode of persoonlijke instellink. Geen automatische uitnodigingen of mail.</p></div>';}
        echo '</section>';self::usersPanel($id,$hasOnline);self::activityPanel($id,$hasOnline,$bound['activity']??[]);
        echo '<section'.self::panel('email').'><h2 class="emsf-section-title">Mails maken</h2>';
        echo '<section class="emsf-panel"><h2>Eerste mail of vervolgmail</h2>';
        if($local&&$hasOnline&&self::activeTab()==='email'){try{$fresh=Client::status($p['slug']);$bound=($fresh['link']??[])+['customer'=>$fresh['customer']??[]];}catch(\Throwable $e){echo '<p role="alert">'.esc_html($e->getMessage()).' Controleer de actuele opdrachtgever voordat je toegang deelt.</p>';}}
        $mailCustomer=$local?($bound['customer']??[]):Accounts::details($id);
        if(($bound['preview_mode']??'')==='public'){self::publicReminder($id);}
        self::form($id,'mail_draft','online','Mail maken om te kopiëren',static fn()=>MailDraft::form($id,$mailCustomer,$name));
        echo '</section><details><summary>Automatische e-mail per omgeving</summary><p>Dit staat los van de mails die je hierboven zelf kopieert en verstuurt.</p><div class="emsf-grid">';
        if($local){self::mailForm($id,'local',$name,Mail::mode($id));}
        if($hasOnline){self::mailForm($id,'online',(string)($bound['name']??$name),(string)($bound['mail_mode']??'off'));}echo '</div></details></section>';
        echo '<section'.self::panel('beheer').'><h2 class="emsf-section-title">Beheer & herstel</h2><p>Archiveren maakt het voorstel tijdelijk onbeschikbaar. De rode verwijderknop verwijdert de gekozen site direct, na het maken van een herstelkopie.</p><div class="emsf-grid">';
        if($local){echo '<section class="emsf-panel"><span class="emsf-badge">LOKAAL</span><h3>Lokaal concept</h3><p>Deze acties veranderen niets aan de gekoppelde online site.</p>';self::lifecycle($id,'local',(bool)$site->archived,$name);echo '</section>';}
        if($hasOnline){echo '<section class="emsf-panel"><span class="emsf-badge emsf-badge-online">ONLINE</span><h3>Online voorstel</h3><p>Deze acties veranderen uitsluitend de online subsite.</p>';self::lifecycle($id,'online',isset($bound['state'])?$bound['state']==='archived':null,(string)($bound['name']??$name));echo '</section>';}echo '</div>';
        if($hasOnline||$pending){echo '<details class="emsf-recovery"><summary>Herstelkopie of afgebroken upload</summary><p>Gebruik het operatie-ID uit Status controleren. Terugzetten vervangt de online inhoud door de gekozen herstelkopie.</p>';foreach(['restore'=>'Herstelkopie terugzetten','cancel'=>'Upload annuleren']as$action=>$label){self::form($id,$action,'online',$label,static function()use($pending,$bound,$name,$action){self::field('confirm','Exacte sitenaam',$action==='cancel'?($bound['name']??$name):'','text',true);self::field('operation','Operatie-ID uit status',$action==='cancel'?($pending['operation']??''):'','text',true);});}echo '</details>';}
        echo '</section></div></div></div>';
    }
    private static function usersPanel(int $id,bool $online): void {
        echo '<section'.self::panel('gebruikers').'><h2>Gebruikers & opdrachtgever</h2><p>Beheer de gebruikers van de online site. De opdrachtgever is de primaire contactpersoon; de gebruikersrol bepaalt wat iemand mag.</p>';
        if(!$online){echo '<p>Publiceer eerst de site. Dat kan zonder opdrachtgever; daarna kun je hier gebruikers aanmaken en koppelen.</p></section>';return;}
        if(self::activeTab()!=='gebruikers'){echo '</section>';return;}
        try{$result=self::run($id,['scope'=>'online','site_action'=>'users_get']);}catch(\Throwable $e){echo '<p role="alert">'.esc_html($e->getMessage()).'</p></section>';return;}
        $current=(int)$result['customer_id'];$users=$result['users'];
        echo '<section class="emsf-panel"><h3>Opdrachtgever</h3>';
        self::form($id,'customer_set','online','Opdrachtgever opslaan',static function()use($users,$current){
            echo '<input type="hidden" name="expected_customer_id" value="'.esc_attr((string)$current).'"><p><label>Primaire contactpersoon<br><select name="customer_id"><option value="0">Geen opdrachtgever</option>';
            foreach($users as$user){echo '<option value="'.esc_attr((string)$user['id']).'" '.selected($current,$user['id'],false).'>'.esc_html($user['name'].' — '.$user['email'].' ('.$user['username'].')').'</option>';}
            echo '</select></label></p><p>Je kunt dit op elk moment wijzigen. De vorige opdrachtgever blijft gebruiker en houdt toegang. Rollen en wachtwoorden veranderen niet.</p>';
        });echo '</section><section class="emsf-panel"><h3>Gebruikers van deze site</h3>';
        if(!$users){echo '<p>Nog geen gebruikers naast de netwerkbeheerder.</p>';}else{
            echo '<table class="widefat striped"><thead><tr><th>Naam</th><th>E-mailadres</th><th>Rol</th><th>Opdrachtgever</th></tr></thead><tbody>';
            foreach($users as$user){echo '<tr><td>'.esc_html($user['name'].' ('.$user['username'].')').'</td><td>'.esc_html($user['email']).'</td><td>'.esc_html(implode(', ',array_map(static fn($role)=>['editor'=>'Redacteur','administrator'=>'Beheerder','author'=>'Auteur','contributor'=>'Schrijver','subscriber'=>'Abonnee'][$role]??$role,$user['roles']))).'</td><td>'.($user['id']===$current?'Ja':'—').'</td></tr>';}
            echo '</tbody></table>';
        }
        $url=get_admin_url((int)$result['link']['site'],'users.php');
        if(Sites::local()){$url=rtrim(Client::endpoint(),'/').'/wp-admin/network/site-users.php?id='.(int)$result['link']['site'];}
        echo '<p><a href="'.esc_url($url).'">Toegang en rollen beheren in WordPress ↗</a></p></section><section class="emsf-panel"><h3>Nieuwe gebruiker</h3><p>Maak een redacteur aan voor deze online site. Er wordt geen e-mail verstuurd.</p>';
        self::form($id,'user_create','online','Gebruiker aanmaken',static function()use($current){
            echo '<input type="hidden" name="expected_customer_id" value="'.esc_attr((string)$current).'">';
            self::field('customer_name','Naam','','text',true);self::field('customer_username','Gebruikersnaam','','text',true);self::field('customer_email','E-mailadres','','email',true);
            echo '<p><label><input type="checkbox" name="customer_confirmed" value="1" required> Ik heb de naam en het e-mailadres gecontroleerd.</label></p><p><label><input type="checkbox" name="assign_customer" value="1" '.checked($current,0,false).'> Instellen als opdrachtgever van deze site</label></p>';
        });echo '</section></section>';
    }
    private static function activityPanel(int $id,bool $online,array $snapshot): void {
        echo '<section'.self::panel('activiteit').'><h2 class="emsf-section-title">Activiteit van dit voorstel</h2><p>Online bron · '.(Sites::local()?'opgeslagen status; haal de actuele gegevens op met Status controleren.':'actuele lokale registratie van deze online site.').'</p>';
        if(!$online){echo '<div class="emsf-panel"><h3>Nog geen online activiteit</h3><p>Na publicatie zie je hier anonieme previewbezoeken, klantlogins en dashboardopeningen.</p></div></section>';return;}
        if(Sites::local()){self::form($id,'status','online','Activiteit ophalen',static fn()=>print('<p class="description">Leest uitsluitend de online status.</p>'));}
        $policy=$snapshot['policy']??['enabled'=>true,'recipient'=>'','notify_view'=>false,'notify_login'=>false];$events=$snapshot['events']??[];
        echo '<div class="emsf-panel"><h3>Activiteitenlog</h3><p>Laatste 30 dagen, maximaal 100 gebeurtenissen. Herladen en mediabestanden tellen niet opnieuw mee; een nieuwe sessie wel. Een gedeelde code of deellink bewijst geen identiteit. Netwerkbeheerderbezoeken tellen niet mee.</p>';
        if(!$events){echo '<p class="emsf-callout">Nog geen bevestigde bezichtiging of klantlogin geregistreerd.</p>';}
        else{
            $views=array_values(array_filter($events,static fn($e)=>in_array($e['kind'],['preview_view','customer_front'],true)));
            if($views){echo '<p><strong>'.count($views).' bezichtigingssessies in dit log.</strong><br>Eerste: '.esc_html(gmdate('d-m-Y H:i',$views[0]['at'])).' UTC · Laatste: '.esc_html(gmdate('d-m-Y H:i',$views[count($views)-1]['at'])).' UTC</p>';}
            echo '<div class="emsf-log"><table class="widefat striped"><thead><tr><th>Tijd (UTC)</th><th>Gebeurtenis</th><th>Herkomst / account</th></tr></thead><tbody>';
            foreach(array_reverse($events)as$event){echo '<tr><td>'.esc_html(gmdate('d-m-Y H:i:s',$event['at'])).'</td><td>'.esc_html(Activity::labels()[$event['kind']]??'Activiteit').'</td><td>'.esc_html($event['account']??(($event['source']??'')==='share_link'?'Anonieme deellink':'Anonieme previewcode')).'</td></tr>';}
            echo '</tbody></table></div>';
        }echo '</div><div class="emsf-panel"><h3>Registratie & meldingen aan de maker</h3><p>Deze meldingen staan los van klantmail. Opslaan verstuurt niets en haalt geen oude meldingen in. Elke soort wordt maximaal eenmaal per site per uur aangeboden; een fout of onzekere aflevering wordt niet automatisch herhaald.</p>';
        self::form($id,'activity_settings','online','Activiteitsinstellingen opslaan',static function()use($policy){
            echo '<p><label><input type="checkbox" name="activity_enabled" value="1" '.checked(!empty($policy['enabled']),true,false).'> Activiteit registreren (uitzetten tijdens eigen tests met het klantaccount)</label></p>';
            echo '<p><label><input type="checkbox" name="activity_inherit" value="1"> Gebruik de centrale online meldingsinstellingen voor deze site</label></p>';
            self::field('activity_recipient','Beheerderontvanger voor meldingen (zelf invullen)',(string)$policy['recipient'],'email');
            echo '<p><label><input type="checkbox" name="notify_view" value="1" '.checked(!empty($policy['notify_view']),true,false).'> Bij een nieuw bezoek</label></p><p><label><input type="checkbox" name="notify_login" value="1" '.checked(!empty($policy['notify_login']),true,false).'> Bij klantlogin of openen van het beheer</label></p><p><label><input type="checkbox" name="activity_confirmed" value="1"> Ik bevestig dat dit het bedoelde beheerderadres is.</label></p>';
            echo '<p><label><input type="checkbox" name="notify_content" value="1" '.checked(!empty($policy['notify_content']),true,false).'> Bij inhoudswijzigingen door de klant</label></p>';
        });
        $states=['reserved'=>'verzending gereserveerd; uitkomst onzeker','submitted'=>'aangeboden aan de mailvoorziening (geen aflevergarantie)','failed'=>'verzending mislukt; geen automatische herhaling'];
        foreach($snapshot['notifications']??[]as$type=>$notice){echo '<p>'.esc_html((['view'=>'Bezichtigingsmelding: ','login'=>'Loginmelding: ','content'=>'Inhoudsmelding: '][$type]??'Melding: ').($states[$notice['status']]??'onbekend')).'</p>';}
        echo '</div></section>';
    }
    private static function publicReminder(int $id): void {
        echo '<div class="emsf-callout"><p>Deze website is toegankelijk zonder code. Wil je het voorstel afschermen voordat je het deelt?</p>';
        self::form($id,'preview_generate','online','Codebeveiliging aanzetten');
        echo '</div>';
    }
    private static function previewFields(array $preview): string {
        $message="Bekijk je websitevoorstel: ".$preview['url']."\nToegangscode: ".$preview['code'];
        $html='<div class="emsf-preview-share"><p><label>Website voor de klant<br><input class="large-text" readonly onclick="this.select()" value="'.esc_attr($preview['url']).'"></label></p><p><label>Vaste toegangscode<br><input class="emsf-preview-code" readonly onclick="this.select()" value="'.esc_attr($preview['code']).'"></label></p>';
        $html.='<button type="button" class="button button-primary" data-preview-copy="'.esc_attr($message).'">Kopieer link en code</button><span class="emsf-copy-status" role="status" aria-live="polite"></span><p>Deze code blijft geldig. Je vindt hem hier altijd terug. De klant opent de link en vult de code in; een account is niet nodig.</p></div>';
        return $html;
    }
    private static function previewForm(int $id,bool $enabled): void {
        echo '<section class="emsf-panel"><p class="emsf-eyebrow">ALLEEN BEKIJKEN</p><h2>Website delen met je klant</h2>';
        $preview=[];$error='';
        if(self::activeTab()==='toegang'){
            try{$preview=self::run($id,['scope'=>'online','site_action'=>'preview_get'])['preview']??[];}
            catch(\Throwable $e){$error=$e->getMessage();}
        }
        if($error){echo '<p role="alert">'.esc_html($error).'</p>';}
        elseif(($preview['mode']??'')==='public'){
            self::publicReminder($id);
            echo '<p><label>Website voor de klant<br><input class="large-text" readonly value="'.esc_attr($preview['url']).'"></label></p><button type="button" class="button button-primary" data-preview-copy="'.esc_attr($preview['url']).'">Kopieer sitelink</button><span class="emsf-copy-status" role="status"></span>';
        }
        elseif(!empty($preview['code'])){echo self::previewFields($preview);}
        elseif(!empty($preview['legacy'])){echo '<p>De bestaande code blijft werken, maar is in de oude versie niet terugleesbaar opgeslagen. Maak eenmalig een vaste code die je hier voortaan kunt terugvinden. De oude code vervalt dan.</p>';self::form($id,'preview_rotate','online','Vaste code maken');}
        else{echo '<p>Deze bestaande website is alleen toegankelijk na inloggen. Kies hoe bezoekers het voorstel mogen bekijken.</p>';self::form($id,'preview_generate','online','Codebeveiliging aanzetten');self::form($id,'preview_public','online','Toegankelijk maken zonder code',static fn()=>print('<p>Iedereen met de link kan de website en bijbehorende media bekijken.</p>'));}
        if(!$error&&(!empty($preview['enabled'])||$enabled)){
            echo '<details><summary>Codebeveiliging wijzigen</summary><p>Een nieuwe code vervangt de oude. Zonder code kan iedereen met de link de website en bijbehorende media bekijken.</p>';
            self::form($id,'preview_rotate','online','Andere code maken');self::form($id,'preview_public','online','Codebeveiliging uitzetten — iedereen mag kijken');self::form($id,'preview_revoke','online','Alleen ingelogde gebruikers toegang geven');echo '</details>';
        }
        echo '</section>';
    }
    private static function mailForm(int $id,string $scope,string $name,string $mode): void {
        echo '<section class="emsf-panel"><span class="emsf-badge">'.($scope==='local'?'LOKAAL':'ONLINE').'</span><h3>E-mail '.($mode==='on'?'aan':'uit (voorstel)').'</h3>';
        self::form($id,'mail',$scope,'Mailinstelling voor deze site opslaan',static function()use($scope,$name,$mode){
            echo '<input type="hidden" name="confirm" value="'.esc_attr($name).'">';
            echo '<p>Uit: geen WordPress-mail; Fluent Forms-inzendingen en uitgaande integraties worden tegengehouden. Ook wachtwoordherstel kan dan geen mail versturen. Deel zelf een instellink.</p><p>Aan: normale nieuwe herstelmails en ingestelde formuliermails mogen worden verstuurd. Controleer de ontvangers in Fluent Forms; deze schakelaar stelt geen mailadressen in. Onderdrukte berichten worden niet bewaard of alsnog verzonden. Accountaanmaak en publicatie blijven altijd zonder mail.</p>';
            echo '<p><label><input type="radio" name="mail_mode" value="off" '.checked($mode,'off',false).'> E-mail uit (voorstel)</label><br><label><input type="radio" name="mail_mode" value="on" '.checked($mode,'on',false).'> E-mail aan</label></p><p>Geldt uitsluitend '.($scope==='local'?'lokaal':'online').' voor deze site. Mailplugins met eigen directe SMTP/API-verbindingen vereisen een aparte controle; de schakelaar dekt WordPress-mail en de onderzochte Fluent Forms-routes.</p>';
        });echo '</section>';
    }
    private static function lifecycle(int $id,string $scope,?bool $archived,string $name): void {
        $label=$scope==='local'?'lokaal':'online';
        foreach(['archive'=>'Archiveren','unarchive'=>'Herstellen uit archief'] as $action=>$title){
            if(($archived===true&&$action==='archive')||($archived===false&&$action==='unarchive')){continue;}
            echo '<details '.(($_GET['tab']??'')===$action?'open':'').'><summary>'.esc_html($title.' ('.$label.')').'</summary>';
            self::form($id,$action,$scope,$title.' ('.$label.')',static function()use($scope,$label,$action){
                echo '<p>Dit verandert uitsluitend de '.($scope==='local'?'lokale':'online').' site.</p>';self::field('confirm','Exacte '.($scope==='local'?'lokale':'online').' sitenaam','','text',true);
            });echo '</details>';
        }
        self::form($id,'delete',$scope,'Definitief verwijderen ('.$label.')',static function()use($scope,$label,$name){
            echo '<p><strong>'.esc_html($name).' — '.esc_html($label).'</strong><br>Verwijdert deze subsite, inhoud, media en site-toegang direct. Netwerkaccounts blijven bestaan. Er wordt eerst een herstelkopie gemaakt; terugzetten vereist handmatig beheer.</p>';
            echo '<input type="hidden" name="confirm" value="'.esc_attr($name).'"><input type="hidden" name="delete_confirm" value="'.esc_attr($scope==='local'?'DEFINITIEF LOKAAL VERWIJDEREN':'DEFINITIEF VERWIJDEREN').'">';
        });
    }
    public static function run(int $id,array $data): array {
        Sites::requireAdmin();$site=Sites::site($id);$action=(string)($data['site_action']??'');$scope=(string)($data['scope']??'');$confirm=(string)($data['confirm']??'');
        if($scope==='site'&&in_array($action,['follow_mark_first_sent','follow_mark_follow_sent'],true)){return FollowUp::save($id,$data,substr($action,12));}
        if($scope==='site'&&in_array($action,['follow_save','follow_sent'],true)){return FollowUp::save($id,$data,$action==='follow_sent');}
        if($scope==='local' && Sites::local() && $action==='mail'){
            if($confirm!==get_blog_option($id,'blogname')){throw new \RuntimeException('De lokale sitenaam is gewijzigd; open de site opnieuw.');}
            Mail::set($id,(string)($data['mail_mode']??''));return ['local_state'=>Mail::mode($id)==='on'?'E-mail aan':'E-mail uit (voorstel)'];
        }
        if($scope==='local' && Sites::local()){return Sites::manageLocal($id,$action,$confirm,(string)($data['delete_confirm']??''));}
        if($scope!=='online'){throw new \RuntimeException('Kies expliciet een lokale of online actie.');}
        if($action==='mail_draft'){return MailDraft::prepare($id,$data);}
        if($action==='user_create'){$data['customer']=self::customerInput($data);}
        if(Sites::local()){
            $p=Sites::prospect($id);
            if($action==='preflight'){return Publication::inspect($p['slug']);}
            if($action==='status'){return Publication::inspect($p['slug']);}
            if($action==='pull_restore'){return Pull::restore($p['slug'],$confirm,(string)($data['backup']??''));}
            if($action==='pull'){return Pull::run($p['slug'],$confirm);}
            if(in_array($action,['create','replace'],true)){
                if($site->archived||$site->deleted){throw new \RuntimeException('Herstel het lokale concept vóór publicatie.');}
                return Client::push($p['slug'],$action,$confirm,self::customerInput($data),array_intersect_key($data,array_flip(['expected_revision','replace_changes','preview_protected'])));
            }
            return Client::manage($p['slug'],$action,$confirm,array_intersect_key($data,array_flip(['operation','delete_confirm','mail_mode','activity_enabled','activity_recipient','activity_confirmed','notify_view','notify_login','notify_content','activity_inherit','customer_id','expected_customer_id','customer','assign_customer'])));
        }
        $request=new \WP_REST_Request('POST');$request->set_header('Content-Type','application/json');
        $request->set_body(wp_json_encode(['source'=>get_site_meta($id,'emsf_source',true),'action'=>$action,'confirm'=>$confirm,'operation'=>$data['operation']??'','delete_confirm'=>$data['delete_confirm']??'','mail_mode'=>$data['mail_mode']??'']+array_intersect_key($data,array_flip(['activity_enabled','activity_recipient','activity_confirmed','notify_view','notify_login','notify_content','activity_inherit','customer_id','expected_customer_id','customer','assign_customer']))));
        return Accounts::silent(static fn()=>Store::locked('receiver-'.get_current_network_id(),static fn()=>Receiver::manage($request)));
    }
    public static function submit(): void {
        self::protect();$id=absint($_POST['site_id']??0);check_admin_referer('emsf_site_'.$id);
        try{$result=self::run($id,wp_unslash($_POST));if(isset($result['publication_check'])){wp_safe_redirect(Sites::url($id,'publicatie'),303);exit;}if(!empty($result['users_saved'])){wp_safe_redirect(Sites::url($id,'gebruikers'),303);exit;}if(!empty($result['follow_up_saved'])){wp_safe_redirect(Sites::url($id,'opvolging'),303);exit;}if(isset($result['preview'])){wp_safe_redirect(Sites::url($id,'toegang'),303);exit;}self::result($id,$result);}catch(\Throwable $e){wp_die(esc_html($e->getMessage()).'<p><a href="'.esc_url(Sites::url($id,self::activeTab())).'">Terug naar Site Factory</a></p>','Actie gestopt',['response'=>409,'back_link'=>true]);}
    }
    public static function create(): void {
        self::protect();check_admin_referer('emsf_new_site');
        try{$p=Sites::createLocal(wp_unslash($_POST));wp_safe_redirect(Sites::url((int)$p['site_id']));exit;}catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'Lokaal aanmaken gestopt',['response'=>409,'back_link'=>true]);}
    }
    private static function result(int $id,array $result): void {
        $html='<h1>Actie afgerond</h1>';
        if(!empty($result['local_backup'])){$html.='<p>Lokale herstelkopie: <code>'.esc_html($result['local_backup']).'</code></p>';}
        if(isset($result['mail_draft'])){ob_start();if(!empty($result['mail_draft']['unprotected'])){self::publicReminder($id);}$reminder=ob_get_clean();wp_die($reminder.MailDraft::result($result['mail_draft']).'<p><a href="'.esc_url(Sites::url($id,'email')).'">Terug naar Site Factory</a></p>','Voorstelmail',['response'=>200]);}
        if(is_array($result['publication_check']??null)){$html.=Publication::summary($result['publication_check']);}
        if(isset($result['operation'])){$html.='<p>'.esc_html(Publication::recovery($result)).'</p>';}
        $labels=['complete'=>'Publicatie voltooid','ready'=>'Online beschikbaar','archived'=>'Gearchiveerd','importing'=>'Publicatie bezig','restoring'=>'Herstel bezig','failed'=>'Actie gestopt — herstel nodig','prepared'=>'Voorbereid','uploading'=>'Upload bezig','cancelled'=>'Geannuleerd'];
        if(isset($result['protocol'])&&empty($result['link'])){$html.='<p>Verbinding geslaagd. Deze site is nog niet online gekoppeld.</p>';}
        if(!empty($result['link']['url'])){$html.='<p>Online site: '.esc_html($result['link']['url']).'</p>';}
        foreach(['local_state','local_deleted','deleted','cancelled'] as $key){if(!empty($result[$key])){$html.='<p>'.esc_html(['local_state'=>'Lokale site: '.($result['local_state']??''),'local_deleted'=>'Lokale site verwijderd; online site ongewijzigd.','deleted'=>'Online site verwijderd.','cancelled'=>'Bewerking geannuleerd.'][$key]).'</p>';}}
        if(!empty($result['operation']['status'])){$html.='<p>Online bewerking: '.esc_html($labels[$result['operation']['status']]??$result['operation']['status']).'</p>';}
        if(!empty($result['link']['mail_mode'])){$html.='<p>E-mail online: '.($result['link']['mail_mode']==='on'?'aan':'uit (voorstel)').'</p>';}
        if(!empty($result['link']['state'])){$html.='<p>Online toestand: '.esc_html($labels[$result['link']['state']]??$result['link']['state']).'</p>';}
        if(!empty($result['operation']['error'])){$html.='<p>'.esc_html($result['operation']['error']).'</p>';}
        $operation=$result['local_operation']??($result['operation']['id']??'');if($operation){$html.='<p>Operatie-ID voor herstel: <code>'.esc_html($operation).'</code></p>';}
        if(isset($result['preview'])){
            $preview=$result['preview'];$html.='<h2>Previewtoegang</h2>';
            if(!empty($preview['code'])){
                $html.=self::previewFields($preview);
            }elseif(($preview['mode']??'')==='public'){$html.='<p>De website is nu toegankelijk zonder code.</p>';}else{$html.='<p>Previewcode en bestaande previewsessies ingetrokken. Persoonlijke accounts blijven beschikbaar.</p>';}
        }elseif(($result['link']['preview_mode']??'')==='public'){$html.='<p>Deze website is toegankelijk zonder code. Je kunt codebeveiliging aanzetten bij Preview & klanttoegang.</p>';}elseif(!empty($result['link']['site'])&&empty($result['link']['preview_enabled'])){$html.='<p>Je kunt bij deze site nu een previewcode maken om het voorstel zonder account te delen.</p>';}
        if(!empty($result['customer'])){
            $c=$result['customer'];$html.='<h2>Klanttoegang — zelf delen</h2><p>Er is geen e-mail verstuurd. Deel deze gegevens alleen persoonlijk met de gecontroleerde klant.</p>';
            foreach(['name'=>'Klant','email'=>'E-mailadres','username'=>'Gebruikersnaam','login_url'=>'Inlog-URL','setup_url'=>'Tijdelijke wachtwoord-instellink','expires_at'=>'Instellink geldig tot (UTC)'] as $key=>$label){if(!empty($c[$key])){$html.='<p><label>'.esc_html($label).'<br><input type="text" readonly onclick="this.select()" style="width:100%" value="'.esc_attr($c[$key]).'"></label></p>';}}
            if(!empty($c['can_setup'])&&empty($c['setup_url'])){$html.='<p>Ga terug naar deze site en kies Instellink maken — geen e-mail. De geheime link wordt uitsluitend na die actie getoond.</p>';}
        }
        $html.='<p><a href="'.esc_url(network_admin_url('admin.php?page=emsf-site')).'">Alle sites</a>';
        if(get_site($id)){$html.=' · <a href="'.esc_url(Sites::url($id,self::activeTab())).'">Terug naar Site Factory</a>';}
        wp_die($html.'</p>','Sitebeheer',['response'=>200]);
    }
}
