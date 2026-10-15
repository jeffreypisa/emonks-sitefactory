<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\Package;

final class Receiver {
    public static bool $importing = false;
    private static bool $applicationPassword = false;
    public static function boot(): void {
        add_action('application_password_did_authenticate', static function() { self::$applicationPassword = true; });
        add_action('rest_api_init', static function() {
            foreach (['software_prepare'=>'POST','software_asset'=>'POST','software_execute'=>'POST','download'=>'POST','download_asset'=>'POST','status'=>'GET','check'=>'POST','prepare'=>'POST','asset'=>'POST','execute'=>'POST','manage'=>'POST'] as $route=>$method) {
                register_rest_route('emsf/v1', '/'.$route, ['methods'=>$method, 'permission_callback'=>[self::class, 'permission'], 'callback'=>static function($request) use ($route) {
                    try {
                        if (strlen($request->get_body()) > 12 * 1024 * 1024) { throw new \RuntimeException('Verzoek te groot.'); }
                        if (isset($request['theme_slug'])) { \Emonks\Factory\ThemeRelease::useRequestSlug((string)$request['theme_slug']); }
                        nocache_headers();
                        return Accounts::silent(static fn()=>Store::locked('receiver-'.get_current_network_id(), static fn()=>str_starts_with($route,'software_') ? Software::{substr($route,9)}($request) : self::$route($request)));
                    } catch (\Throwable $e) { return new \WP_Error('emsf_operation', $e->getMessage(), ['status'=>409]); }
                }]);
            }
        });
    }
    public static function permission(): bool {
        return defined('EMSF_ONLINE') && EMSF_ONLINE && is_multisite() && is_main_site() && is_ssl() && self::$applicationPassword && is_super_admin() && current_user_can('manage_network');
    }
    public static function preflight(): void {
        Store::root();
        if (!defined('EMSF_PRIVATE_HOSTING_VERIFIED') || !EMSF_PRIVATE_HOSTING_VERIFIED) { throw new \RuntimeException('Hostingcontrole voor private opslag, cache en media ontbreekt.'); }
        if (is_subdomain_install()) { throw new \RuntimeException('Ontvanger vereist submappen-Multisite.'); }
        if (!function_exists('acf_get_field') || !defined('ACF_PRO') || !ACF_PRO) { throw new \RuntimeException('ACF Pro ontbreekt of is niet netwerkactief. Activeer ACF Pro online voor het hele netwerk.'); }
        if (!wp_get_theme(\Emonks\Factory\ThemeRelease::slug())->exists()) { throw new \RuntimeException('De ingestelde themamap ontbreekt: '.\Emonks\Factory\ThemeRelease::slug().'. Upload het thema online of pas de themamap aan bij Online verbinding.'); }
    }
    private static function source($request): string { return Store::key((string)$request['source']); }
    private static function operation($request): string { return 'op-'.Store::key((string)$request['operation']); }
    public static function status($request): array {
        Store::root();
        $link = Store::read('link-'.self::source($request));
        if ($link && !empty($link['site']) && empty($link['deleted'])) {
            $link['name'] = get_blog_option($link['site'], 'blogname');
            $link['theme_slug'] = get_blog_option($link['site'], 'stylesheet');
            $link['state'] = get_site_meta($link['site'], 'emsf_state', true);
            $link['url'] = get_home_url($link['site']);
            $link['mail_mode'] = Mail::mode((int)$link['site']);
            $link['content_changes'] = function_exists('acf_get_field') ? ContentChanges::status((int)$link['site']) : [];
            $link['preview_enabled'] = Preview::enabled((int)$link['site']);
            $link['preview_mode'] = Preview::mode((int)$link['site']);
        }
        if (!empty($link['deleted'])) { $link['state']='deleted'; $link['name']='Verwijderde site'; $link['url']=''; }
        if (empty($request['operation']) && !empty($link['operation'])) { $request['operation'] = substr($link['operation'], 3); }
        $op = !empty($request['operation']) ? Store::read(self::operation($request)) : [];
        if ($op && $op['source'] !== self::source($request)) { throw new \RuntimeException('Verkeerde operatiebron.'); }
        return ['software_update'=>true,'environment'=>\Emonks\Factory\ThemeRelease::environment(), 'plugin_version'=>\Emonks\Factory\ThemeRelease::pluginVersion(), 'protocol'=>4, 'content_pull'=>true, 'optional_customer'=>true, 'customer_management'=>true, 'optional_preview'=>true, 'persistent_preview'=>true, 'publication_check'=>true, 'theme'=>['version'=>\Emonks\Factory\ThemeRelease::version(),'digest'=>Package::theme_digest(),'releases'=>\Emonks\Factory\ThemeRelease::releases()], 'activity'=>!empty($link['site'])&&empty($link['deleted'])?Activity::snapshot((int)$link['site']):[], 'customer'=>!empty($link['site']) && empty($link['deleted']) ? Accounts::details((int)$link['site']) : [], 'link'=>$link, 'operation'=>$op ? ['id'=>substr(self::operation($request),3)]+array_intersect_key($op, array_flip(['status','site','backup','error','action','created'])) : []];
    }
    /** Private, authenticated data-only export. One expiring snapshot per source. */
    public static function download($request): array {
        self::preflight();$source=self::source($request);$link=Store::read('link-'.$source);
        self::linked($link,$source);
        if (!in_array(get_site_meta($link['site'],'emsf_state',true),['ready','archived'],true)) { throw new \RuntimeException('Wacht tot de online bewerking is afgerond.'); }
        $previous=Store::read('download-'.$source);
        if (!empty($previous['id'])) { Publication::discardExport(Store::root().'/download-'.Store::key($previous['id'])); }
        $id=wp_generate_uuid4();$dir=Store::root().'/download-'.$id;
        switch_to_blog((int)$link['site']);
        try {
            Package::export($dir);$manifest=Package::read($dir);
            if (!empty($manifest['unsupported'])) { throw new \RuntimeException(implode('; ',$manifest['unsupported'])); }
            $total=0;foreach($manifest['files'] as $name=>$file){self::assetName($name);$total+=$file['size'];if($file['size']>8*1024*1024){throw new \RuntimeException('Mediabestand groter dan 8 MiB.');}}
            if($total>256*1024*1024){throw new \RuntimeException('Pakket groter dan 256 MiB.');}
            $manifest['prospect']=['uuid'=>$source];Package::json($dir.'/manifest.json',$manifest);
            Store::write('download-'.$source,['id'=>$id,'expires'=>time()+3600]);
            return ['id'=>$id,'manifest'=>$manifest];
        } catch(\Throwable $e){Publication::discardExport($dir);throw $e;}
        finally { restore_current_blog(); }
    }
    public static function download_asset($request): array {
        self::preflight();$source=self::source($request);self::linked(Store::read('link-'.$source),$source);
        $saved=Store::read('download-'.$source);$id=Store::key((string)$request['download']);
        if(($saved['id']??'')!==$id||($saved['expires']??0)<time()){throw new \RuntimeException('Download verlopen. Start het ophalen opnieuw.');}
        $name=(string)$request['name'];self::assetName($name);$dir=Store::root().'/download-'.$id;
        $manifest=Package::read($dir);
        if(!isset($manifest['files'][$name])){throw new \RuntimeException('Bestand hoort niet bij deze download.');}
        return ['bytes'=>base64_encode((string)file_get_contents($dir.'/files/'.$name))];
    }
    public static function check($request): array {
        self::preflight();$source=self::source($request);$data=$request->get_json_params();$package=$data['manifest']??[];$issues=[];
        if(!is_array($package)||($package['format']??'')!==Package::FORMAT||($package['version']??0)!==1||($package['prospect']['uuid']??'')!==$source){throw new \RuntimeException('Ongeldig prospectpakket voor controle.');}
        try{self::validatePackage($package);Package::check_models($package);}catch(\Throwable $e){$issues[]=$e->getMessage();}
        $bytes=0;foreach($package['files']??[]as$name=>$file){try{self::assetName($name);if(!is_int($file['size']??null)||$file['size']<0||$file['size']>8*1024*1024||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']??'')){throw new \RuntimeException('Ongeldig bestand of groter dan 8 MB: '.$name);}$bytes+=$file['size'];}catch(\Throwable $e){$issues[]=$e->getMessage();}}
        if($bytes>256*1024*1024){$issues[]='Pakket groter dan 256 MB.';}
        $link=Store::read('link-'.$source);$changes=[];
        if(!empty($link['site'])){self::linked($link,$source);$changes=ContentChanges::status((int)$link['site']);if(get_site_meta($link['site'],'emsf_state',true)!=='ready'){$issues[]='Online site is niet gereed. Controleer de status en herstel de bestaande publicatie.';}else{switch_to_blog((int)$link['site']);try{self::contentPreflight();}catch(\Throwable $e){$issues[]=$e->getMessage();}finally{restore_current_blog();}}}
        return ['ok'=>!$issues,'issues'=>array_values(array_unique($issues)),'changes'=>$changes,'posts'=>count($package['posts']??[]),'files'=>count($package['files']??[]),'bytes'=>$bytes];
    }
    public static function prepare($request): array {
        self::preflight();
        $key = self::operation($request); $source = self::source($request);
        $data = $request->get_json_params();
        $manifest = $data['manifest'] ?? [];
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== Package::FORMAT || ($manifest['version'] ?? 0) !== 1 || ($manifest['prospect']['uuid'] ?? '') !== $source) { throw new \RuntimeException('Ongeldig prospectpakket.'); }
        if (!in_array($data['action'] ?? '', ['create','replace'], true) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $data['slug'] ?? '') || strlen($data['slug']) > 60) { throw new \RuntimeException('Ongeldige actie of slug.'); }
        $customer = $data['action']==='create' ? Accounts::optional((array)($data['customer']??[])) : [];
        $mode=$data['preview_mode']??'closed';if(!in_array($mode,['public','code','closed'],true)){throw new \RuntimeException('Ongeldige toegangskeuze.');}
        $identity=[$manifest,$data['action'],$data['slug'],$data['confirm']??'',$customer];
        if(array_key_exists('theme_slug',$data)&&isset($manifest['dependencies']['factory_version'])){$identity[]=\Emonks\Factory\ThemeRelease::validateSlug((string)$data['theme_slug']);}
        if(array_key_exists('preview_mode',$data)){$identity[]=$mode;} // Preserve legacy retry digests.
        $digest = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $old = Store::read($key);
        if ($old) {
            if ($old['source'] !== $source || $old['digest'] !== $digest) { throw new \RuntimeException('Operatiesleutel is al voor andere inhoud gebruikt.'); }
            return ['status'=>$old['status']];
        }
        self::validatePackage($manifest);Package::check_models($manifest);
        if($data['action']==='replace'){$target=Store::read('link-'.$source);self::linked($target,$source);ContentChanges::guard((int)$target['site'],$data);}
        $total = 0;
        foreach ($manifest['files'] ?? [] as $name=>$file) {
            self::assetName($name);
            if (!is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > 8*1024*1024 || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '')) { throw new \RuntimeException('Ongeldig bestand of groter dan 8 MiB.'); }
            $total += $file['size'];
        }
        if ($total > 256*1024*1024) { throw new \RuntimeException('Pakket groter dan 256 MiB.'); }
        $dir = Store::root().'/'.$key;
        if (!wp_mkdir_p($dir.'/files')) { throw new \RuntimeException('Pakketmap aanmaken mislukt.'); }
        Package::json($dir.'/manifest.json', $manifest);
        Store::write($key, ['source'=>$source, 'digest'=>$digest, 'status'=>'receiving', 'action'=>$data['action'], 'slug'=>$data['slug'], 'confirm'=>$data['confirm'] ?? '', 'created'=>gmdate('c'), 'preview_mode'=>$mode, 'customer'=>$customer,'expected_revision'=>$data['expected_revision']??'','replace_changes'=>!empty($data['replace_changes'])]);
        return ['status'=>'receiving'];
    }
    public static function assetName(string $name): void {
        if (!preg_match('~^(media|fonts)/[a-zA-Z0-9_./-]+\.(jpe?g|png|webp|gif|svg|avif|ico|pdf|woff2?|ttf|otf|css|txt|json)$~iD', $name) || str_contains($name, '..')) { throw new \RuntimeException('Onveilig bestandspad.'); }
    }
    public static function asset($request): array {
        $key = self::operation($request); $op = Store::read($key);
        if (!$op || $op['source'] !== self::source($request)) { throw new \RuntimeException('Operatie onbekend.'); }
        if ($op['status'] !== 'receiving') { return ['status'=>$op['status']]; }
        $data = $request->get_json_params(); $name = (string)($data['name'] ?? ''); self::assetName($name);
        $dir = Store::root().'/'.$key;
        $manifest = json_decode((string)file_get_contents($dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $meta = $manifest['files'][$name] ?? null;
        $bytes = base64_decode($data['bytes'] ?? '', true);
        if (!$meta || $bytes === false || strlen($bytes) !== $meta['size'] || !hash_equals($meta['sha256'], hash('sha256', $bytes))) { throw new \RuntimeException('Bestandscontrole mislukt.'); }
        wp_mkdir_p(dirname($dir.'/files/'.$name));
        if (file_put_contents($dir.'/files/'.$name, $bytes, LOCK_EX) !== strlen($bytes)) { throw new \RuntimeException('Bestand opslaan mislukt.'); }
        return ['stored'=>true];
    }
    public static function execute($request): array { return Accounts::silent(static fn()=>self::executeSilent($request)); }
    private static function executeSilent($request): array {
        self::preflight();
        $key = self::operation($request); $op = Store::read($key); $source = self::source($request);
        if (!$op || $op['source'] !== $source) { throw new \RuntimeException('Operatie onbekend.'); }
        if ($op['status'] === 'complete') {
            if (!empty($op['site']) && get_site_meta($op['site'], 'emsf_operation', true) === $key && get_site_meta($op['site'], 'emsf_state', true) === 'importing') { update_site_meta($op['site'], 'emsf_state', 'ready'); }
            return self::status($request);
        }
        if ($op['status'] !== 'receiving') { throw new \RuntimeException('Operatie onderbroken of mislukt. Controleer status en herstel eerst de herstelkopie; niet automatisch opnieuw toepassen.'); }
        $dir = Store::root().'/'.$key; $package = Package::read($dir);
        self::validatePackage($package);Package::check_models($package);
        $linkKey = 'link-'.$source; $link = Store::read($linkKey);
        $network = get_network(); $path = trailingslashit($network->path).$op['slug'].'/';
        if ($op['action'] === 'create') {
            if ($link || domain_exists($network->domain, $path, $network->id)) { throw new \RuntimeException('Dit doeladres of deze bron is al gekoppeld. Niets overschreven.'); }
            $customer=Accounts::optional((array)($op['customer']??[])); if($customer){Accounts::available($customer);}
            $op['status'] = 'creating'; Store::write($key, $op);
            // Persist the reservation BEFORE creation; crashes never silently adopt another site.
            Store::write($linkKey, ['source'=>$source, 'path'=>$path, 'operation'=>$key, 'site'=>0]);
            $site = wp_insert_site(['domain'=>$network->domain,'path'=>$path,'network_id'=>$network->id,'public'=>0,'user_id'=>get_current_user_id(),'title'=>$package['prospect']['name'],'options'=>['emsf_uuid'=>$source,'blog_public'=>0],'meta'=>['emsf_managed'=>1,'emsf_mail_mode'=>'off','emsf_state'=>'importing','emsf_preview_mode'=>$op['preview_mode']??'closed','emsf_source'=>$source]]);
            if (is_wp_error($site)) { throw new \RuntimeException($site->get_error_message()); }
            $link['site'] = (int)$site; $link['source'] = $source; $link['path'] = $path;
            Store::write($linkKey, $link);
        } else {
            self::linked($link, $source);
            ContentChanges::guard((int)$link['site'],$op);
            if ($op['confirm'] !== get_blog_option($link['site'], 'blogname')) { throw new \RuntimeException('Typ de exacte online sitenaam om vervanging te bevestigen.'); }
            if (get_site_meta($link['site'], 'emsf_state', true) !== 'ready') { throw new \RuntimeException('Site is niet gereed; herstel of activeer deze eerst.'); }
        }
        $link['operation'] = $key; Store::write($linkKey, $link);
        $site = (int)$link['site'];
        $op['site'] = $site; $op['status'] = 'backing_up'; Store::write($key, $op);
        update_site_meta($site, 'emsf_operation', $key);
        update_site_meta($site, 'emsf_state', 'importing');
        switch_to_blog($site);
        try {
            self::contentPreflight();
            $op['backup'] = Snapshot::create(); $op['status'] = 'applying'; Store::write($key, $op);
            Snapshot::clearFiles();
            switch_theme(\Emonks\Factory\ThemeRelease::slug());
            self::$importing = true;
            Package::models($dir);
            Package::import($dir, true, true);
            if ($op['action']==='create' && $customer) { Accounts::provision($site,$source,$customer); }
            ContentChanges::baseline($site);
            update_site_meta($site, 'emsf_state', 'ready');
            if($op['action']==='create'&&($op['preview_mode']??'')==='code'){Preview::manage($site);}
            $op['status'] = 'complete'; Store::write($key, $op);
        } catch (\Throwable $e) {
            $op['status'] = 'failed'; $op['error'] = $e->getMessage(); Store::write($key, $op);
            update_site_meta($site, 'emsf_state', empty($op['backup']) && $op['action'] === 'replace' ? 'ready' : 'failed');
            throw new \RuntimeException((empty($op['backup']) && $op['action'] === 'replace' ? 'Voorbereiding mislukt; bestaande inhoud behouden. ' : 'Publicatie gestopt; site blijft afgeschermd. ').$e->getMessage());
        } finally { self::$importing = false; restore_current_blog(); }
        return self::status($request);
    }
    private static function validatePackage(array $package): void {
        if (!empty($package['unsupported'])) { throw new \RuntimeException('Pakket vereist extra adapters: '.implode('; ', $package['unsupported'])); }
        \Emonks\Factory\ThemeRelease::requireVersion($package['dependencies']??[]);
        $factory=$package['dependencies']['factory_version']??null;
        if ($factory !== null && (!is_string($factory) || \Emonks\Factory\ThemeRelease::compare(\Emonks\Factory\ThemeRelease::pluginVersion(),$factory)!=='current')) { throw new \RuntimeException('Site Factory-pluginversie wijkt af. Installeer lokaal en online dezelfde pluginuitgave.'); }
        // Plugin release numbers are informational; required import functionality remains mandatory.
        if (!empty($package['forms']) && !defined('FLUENTFORM_VERSION')) { throw new \RuntimeException('Activeer Fluent Forms netwerkbreed om de formulieren uit dit pakket te importeren.'); }
        foreach ($package['posts'] as $row) {
            if (in_array($row['post']['post_type'], ['user','shop_order','product','product_variation','acf-field','acf-field-group','wp_global_styles'], true)) { throw new \RuntimeException('Inhoudstype vereist een aparte migratieadapter.'); }
        }
    }
    private static function contentPreflight(): void {
        global $wpdb;
        $table = $wpdb->prefix.'fluentform_submissions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) && (int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")) { throw new \RuntimeException('Online formulierinzendingen aanwezig; exporteer en behandel die eerst.'); }
        if (class_exists('WooCommerce') || function_exists('pll_current_language')) { throw new \RuntimeException('WooCommerce/Polylang valt buiten deze publicatieadapter.'); }
    }
    private static function linked(array $link, string $source): void {
        $site = !empty($link['site']) ? get_site($link['site']) : null;
        if (!$site || (int)$site->blog_id === get_main_site_id() || (int)$site->network_id !== get_current_network_id() || get_site_meta($site->blog_id, 'emsf_source', true) !== $source || !get_site_meta($site->blog_id, 'emsf_managed', true)) { throw new \RuntimeException('Geen geldige beheerde subsitekoppeling.'); }
    }
    /** Explicit recovery of the legacy model collision, before content import ever ran. */
    private static function retryModels($request,array $link): array {
        $key=self::operation($request);$op=Store::read($key);$site=(int)$link['site'];$source=self::source($request);
        if(($op['source']??'')!==$source || (int)($op['site']??0)!==$site || ($link['operation']??'')!==$key || get_site_meta($site,'emsf_operation',true)!==$key){throw new \RuntimeException('Deze publicatie hoort niet bij de gekoppelde site.');}
        if(($op['status']??'')==='complete'){return self::status($request);}
        if(($op['status']??'')!=='failed' || get_site_meta($site,'emsf_state',true)!=='failed' || ($op['action']??'')!=='create' || !in_array($op['error']??'',['Projectmodel overschrijft gedeeld thema.','Ontbrekend inhoudstype: skeletor_teaser'],true) || empty($op['backup'])){throw new \RuntimeException('Deze fout vereist ander herstel; niets gewijzigd.');}
        $dir=Store::root().'/'.$key;$package=Package::read($dir);self::validatePackage($package);
        $customer=Accounts::optional((array)($op['customer']??[]));
        if(get_site_meta($site,'emsf_customer_id',true)){throw new \RuntimeException('Er bestaat al klanttoegang; laat het herstel eerst controleren.');}
        if($customer){Accounts::available($customer);}
        switch_to_blog($site);
        try {
            // Verify the corrected definitions before touching files or tables.
            if(get_stylesheet()!==\Emonks\Factory\ThemeRelease::slug()){throw new \RuntimeException('De gekoppelde site gebruikt niet Skeletor.');}
            Package::project_models($package['models']);self::contentPreflight();
            $op['recovery_backup']=Snapshot::create();
            $op['status']='restoring';Store::write($key,$op);update_site_meta($site,'emsf_state','restoring');
            Snapshot::restore($op['backup']);switch_theme(\Emonks\Factory\ThemeRelease::slug());
            $op['status']='applying';Store::write($key,$op);update_site_meta($site,'emsf_state','importing');
            self::$importing=true;Snapshot::clearFiles();Package::models($dir);Package::import($dir,true,true);
            if($customer){Accounts::provision($site,$source,$customer);}
            ContentChanges::baseline($site);update_site_meta($site,'emsf_state','ready');if(($op['preview_mode']??'')==='code'){Preview::manage($site);}$op['status']='complete';unset($op['error']);Store::write($key,$op);update_site_meta($site,'emsf_state','ready');
        } catch(\Throwable $e){
            $op['status']='failed';$op['error']=$e->getMessage();Store::write($key,$op);update_site_meta($site,'emsf_state','failed');throw $e;
        } finally {self::$importing=false;restore_current_blog();}
        return self::status($request);
    }
    public static function manage($request): array {
        self::preflight(); $source = self::source($request); $link = Store::read('link-'.$source);
        $data = $request->get_json_params();
        if (($data['action'] ?? '') === 'cancel') {
            $key = self::operation($request); $op = Store::read($key);
            if (($op['source'] ?? '') !== $source || !in_array($op['status'] ?? '', ['receiving','failed'], true) || !empty($op['backup'])) { throw new \RuntimeException('Alleen een upload of mislukte voorbereiding zonder inhoudswijzigingen kan worden geannuleerd.'); }
            $manifest = json_decode((string)file_get_contents(Store::root().'/'.$key.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $name = !empty($link['site']) ? get_blog_option($link['site'], 'blogname') : $manifest['prospect']['name'];
            if (($data['confirm'] ?? '') !== $name || ($op['action'] === 'create' && !empty($op['site']))) { throw new \RuntimeException('Bevestiging ongeldig of siteaanmaak vereist handmatig herstel.'); }
            $op['status'] = 'cancelled'; Store::write($key, $op);
            return ['cancelled'=>true];
        }
        self::linked($link, $source);
        $site = (int)$link['site']; $data = $request->get_json_params(); $action = $data['action'] ?? '';
        if (!in_array($action,['preview_get','preview_generate','preview_rotate','preview_revoke','preview_public','setup_link','activity_settings','users_get','customer_set','user_create'],true) && ($data['confirm'] ?? '') !== get_blog_option($site, 'blogname')) { throw new \RuntimeException('Bevestig met de exacte online sitenaam.'); }
        if($action==='retry_models'){return self::retryModels($request,$link);}
        $state = get_site_meta($site, 'emsf_state', true);
        if (in_array($action,['preview_get','preview_generate','preview_rotate','preview_revoke','preview_public'],true)) {
            $preview=$action==='preview_public'?Preview::makePublic($site):($action==='preview_get'?Preview::details($site):Preview::manage($site,$action==='preview_revoke',$action==='preview_rotate'));
            return self::status($request)+['preview'=>$preview];
        }
        if(in_array($action,['users_get','customer_set','user_create'],true)){
            if(!in_array($state,['ready','archived'],true)){throw new \RuntimeException('Gebruikers beheren kan na een geslaagde publicatie of herstel.');}
            if($action!=='users_get'){
                $expected=filter_var($data['expected_customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if($expected===false||$expected!==(int)get_site_meta($site,'emsf_customer_id',true)){throw new \RuntimeException('De opdrachtgever is ondertussen gewijzigd. Open Gebruikers opnieuw.');}
            }
            if($action==='customer_set'){
                $user=filter_var($data['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if($user===false){throw new \RuntimeException('Kies een geldige gebruiker of Geen.');}
                Accounts::assign($site,$user);
            }
            if($action==='user_create'){Accounts::createUser($site,$source,(array)($data['customer']??[]),!empty($data['assign_customer']));}
            return self::status($request)+['users'=>Accounts::users($site),'customer_id'=>(int)get_site_meta($site,'emsf_customer_id',true),'users_saved'=>$action!=='users_get'];
        }
        if ($action === 'activity_settings') { Activity::configure($site,$data);return self::status($request); }
        if ($action === 'mail') { Mail::set($site,(string)($data['mail_mode']??'')); }
        elseif ($action === 'archive' && $state === 'ready') { update_site_meta($site, 'emsf_state', 'archived'); }
        elseif ($action === 'unarchive' && $state === 'archived') { update_site_meta($site, 'emsf_state', 'ready'); }
        elseif ($action === 'restore') {
            $opKey = 'op-'.Store::key((string)($data['operation'] ?? '')); $op = Store::read($opKey);
            if (($op['source'] ?? '') !== $source || ($op['site'] ?? 0) !== $site || empty($op['backup'])) { throw new \RuntimeException('Geen passende herstelkopie voor deze operatie.'); }
            update_site_meta($site, 'emsf_state', 'restoring'); switch_to_blog($site);
            try {
                // Preserve the current state before every manual restore as well.
                $safety = Snapshot::create();
                Store::write('restore-'.$site, ['operation'=>$opKey,'safety_backup'=>$safety,'created'=>gmdate('c')]);
                Snapshot::restore($op['backup']);
                $op['status'] = 'restored'; Store::write($opKey, $op);
                update_site_meta($site, 'emsf_state', 'ready');ContentChanges::touch('Herstelkopie teruggezet');
            } finally { restore_current_blog(); }
        } elseif ($action === 'setup_link' && $state === 'ready') {
            if(isset($data['expected_customer_id'])&&(int)$data['expected_customer_id']!==(int)get_site_meta($site,'emsf_customer_id',true)){throw new \RuntimeException('De opdrachtgever is ondertussen gewijzigd. Open Mails maken opnieuw.');}
            return ['customer'=>Accounts::setupLink($site)];
        } elseif ($action === 'delete' && in_array($state,['ready','archived','failed'],true) && ($data['delete_confirm'] ?? '') === 'DEFINITIEF VERWIJDEREN') {
            switch_to_blog($site);
            try { $backup = Snapshot::create(); } finally { restore_current_blog(); }
            Store::write('deleted-'.$source, $link+['backup'=>$backup,'deleted'=>gmdate('c')]);
            require_once ABSPATH.'wp-admin/includes/ms.php';
            // wp_delete_site removes this site's tables/memberships, never network users.
            $result = wp_delete_site($site);
            if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); }
            Store::write('link-'.$source, $link+['deleted'=>true]);
            return ['deleted'=>true,'backup'=>$backup];
        } else { throw new \RuntimeException('Actie niet toegestaan in de huidige toestand.'); }
        return self::status($request);
    }
}
