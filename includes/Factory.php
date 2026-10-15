<?php
declare(strict_types=1);
namespace Emonks\Factory;
final class Factory {
    const TYPE='emsf_prospect';
    const STATUSES=['NEW','RESEARCHED','BUILDING','REVIEW','APPROVED','PUBLISHED','CONTACTED','INTERESTED','CUSTOMER','ARCHIVED'];
    public static function boot(): void {
        add_filter('pre_option_blog_public',static fn()=>0);
        add_filter('acf/settings/save_json',static fn()=>self::model_dir(),999);
        add_filter('acf/settings/load_json',static function($paths){$paths[]=self::model_dir();return $paths;},999);
        add_action('init',static function(){
            if (is_main_site()) { register_post_type(self::TYPE,['label'=>'Prospects','public'=>false,'show_ui'=>false,'supports'=>['title','editor']]); }
        });
        add_action('admin_post_emsf_save',[self::class,'admin_save']);
        add_action('save_post',static function($id,$post){
            if (!is_main_site() && !wp_is_post_revision($id) && !in_array($post->post_type,['attachment','revision'],true)) { self::invalidate_approval(); }
        },10,2);
        add_action('acf/save_post',static fn()=>self::invalidate_approval(),30);
        add_action('updated_option',static function($name){if(str_starts_with($name,'options_')||str_starts_with($name,'theme_mods_')||in_array($name,['blogname','blogdescription','page_on_front','show_on_front'],true)){self::invalidate_approval();}},10,1);
        add_action('wp_update_nav_menu',static fn()=>self::invalidate_approval());
        add_action('wp_footer',static function(){if(!is_main_site()){echo '<aside aria-label="Demowebsite" style="position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#172b2a;color:#fff;padding:6px 14px;text-align:center;font:13px sans-serif">Lokaal websitevoorstel van Emonks'.(Online\Mail::off()?' · E-mail uit: formulieren versturen geen berichten.':' · E-mail aan.').'</aside>';}});
        add_action('send_headers',static fn()=>header('X-Robots-Tag: noindex, nofollow, noarchive'));
        // Prevent third-party form integrations from making outbound requests in local demos.
        add_filter('pre_http_request',static function($pre,$args,$url){
            if(Online\Mail::managed(get_current_blog_id()) && Online\Mail::mode()==='on'){return $pre;}
            $host=wp_parse_url($url,PHP_URL_HOST);
            if (Online\Client::isSending() && defined('EMSF_REMOTE_URL') && str_starts_with($url, rtrim(EMSF_REMOTE_URL, '/').'/wp-json/emsf/v1/')) { return $pre; }
            // Keep official update checks and package downloads available without
            // opening outbound requests for demo forms or third-party integrations.
            if (wp_parse_url($url,PHP_URL_SCHEME)==='https' && in_array($host,['api.wordpress.org','downloads.wordpress.org'],true)) { return $pre; }
            if (self::pluginUpdateRequest($url, $args)) { return $pre; }
            if (in_array($host,['fonts.googleapis.com','fonts.gstatic.com','fonts.google.com'],true)) { return $pre; }
            if (is_string($host) && ($host==='sites.test'||str_ends_with($host,'.sites.test'))) { return $pre; }
            return new \WP_Error('emsf_local_only','Externe verzoeken uitgeschakeld in lokale demo’s.');
        },999,3);
    }
    /** Only public update metadata and installation ZIPs for the two emonks plugins. */
    public static function pluginUpdateRequest(string $url, array $args = []): bool {
        if (!in_array(strtoupper($args['method'] ?? 'GET'), ['GET', 'HEAD'], true)) return false;
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $repo = '(?:emonks-dashboard|emonks-sitefactory)';
        if ($host === 'api.github.com') return (bool) preg_match('~^/repos/jeffreypisa/' . $repo . '/(?:releases(?:/latest|/assets/[0-9]+)?|contents/[^\\?#]+|commits/[^/]+)$~D', $path);
        if ($host === 'github.com') return (bool) preg_match('~^/jeffreypisa/' . $repo . '/releases/download/v[0-9]+\.[0-9]+\.[0-9]+/(?:emonks-dashboard|emonks-site-factory)-[0-9]+\.[0-9]+\.[0-9]+\.zip$~D', $path);
        return false;
    }

    public static function local(): void {
        if (!defined('EMSF_LOCAL')||!EMSF_LOCAL||wp_get_environment_type()!=='local'||!is_multisite()) { throw new \RuntimeException('Alleen beschikbaar in het lokale Factory-netwerk.'); }
        if (!current_user_can('manage_network')) { throw new \RuntimeException('Netwerkbeheerder vereist. Gebruik --user=emonks.'); }
    }
    public static function state(): string { return EMSF_STATE_DIR; }
    public static function model_dir(): string {
        $dir=self::state().'/models/'.get_current_blog_id();
        if (!is_dir($dir)&&!wp_mkdir_p($dir)) { throw new \RuntimeException('Projectmodellenmap niet schrijfbaar.'); }
        return $dir;
    }
    public static function main(callable $fn) {
        $switch=!is_main_site();if($switch){switch_to_blog(get_main_site_id());}
        try {return $fn();} finally {if($switch){restore_current_blog();}}
    }
    public static function prospects(): array {
        return self::main(static function(){return array_map(static fn($p)=>self::record($p->ID),get_posts(['post_type'=>self::TYPE,'post_status'=>'private','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']));});
    }
    public static function record(int $id): array {
        $p=get_post($id);if(!$p||$p->post_type!==self::TYPE){throw new \RuntimeException('Prospect niet gevonden.');}
        return ['id'=>$id,'name'=>$p->post_title,'notes'=>$p->post_content]+(array)get_post_meta($id,'_emsf',true);
    }
    public static function find(string $slug): array {
        return self::main(static function()use($slug){$ps=get_posts(['post_type'=>self::TYPE,'post_status'=>'private','name'=>sanitize_title($slug),'numberposts'=>1]);if(!$ps){throw new \RuntimeException('Prospect niet gevonden: '.$slug);}return self::record($ps[0]->ID);});
    }
    public static function save(array $input): array {
        self::local();$slug=sanitize_title($input['slug']??'');if(!$slug){throw new \RuntimeException('Slug vereist.');}
        return self::main(static function()use($input,$slug){
            $ps=get_posts(['post_type'=>self::TYPE,'post_status'=>'private','name'=>$slug,'numberposts'=>1]);$old=$ps?self::record($ps[0]->ID):[];
            $status=!empty($input['status'])?$input['status']:($old['status']??'NEW');
            if($status==='APPROVED'&&(!($old['site_id']??0)||!in_array($old['status']??'',['REVIEW','APPROVED'],true))){throw new \RuntimeException('Alleen een site in REVIEW kan goedgekeurd worden.');}
            if(!in_array($status,self::STATUSES,true)){throw new \RuntimeException('Ongeldige status.');}
            if(in_array($status,['PUBLISHED','CONTACTED','INTERESTED','CUSTOMER'],true)&&$status!==($old['status']??'')){throw new \RuntimeException('Deze fase is nog niet beschikbaar in lokale V1.');}
            $name=sanitize_text_field($input['name']??($old['name']??''));if(!$name){throw new \RuntimeException('Prospectnaam vereist.');}
            $record=['slug'=>$slug,'uuid'=>$old['uuid']??wp_generate_uuid4(),'status'=>$status,'site_id'=>$old['site_id']??0,'created_at'=>$old['created_at']??gmdate('c'),'updated_at'=>gmdate('c')];
            foreach(['website','industry','region','research','rules_version','last_build','error'] as $key){$record[$key]=$input[$key]??($old[$key]??'');}
            $record['website']=esc_url_raw((string)$record['website']);
            $id=wp_insert_post(['ID'=>$old['id']??0,'post_type'=>self::TYPE,'post_status'=>'private','post_name'=>$slug,'post_title'=>$name,'post_content'=>wp_kses_post($input['notes']??($old['notes']??''))],true);
            if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}update_post_meta($id,'_emsf',$record);return self::record($id);
        });
    }
    public static function site(string $slug): array { return Online\Accounts::silent(static fn()=>self::createSite($slug)); }
    private static function createSite(string $slug): array {
        self::local();$p=self::find($slug);if($p['site_id']){return $p;}
        $domain=$p['slug'].'.'.get_network()->domain;
        if(domain_exists($domain,'/',get_current_network_id())){throw new \RuntimeException('Domein bestaat al en is niet aan dit prospect gekoppeld.');}
        $id=wpmu_create_blog($domain,'/',$p['name'],get_current_user_id(),['public'=>0],get_current_network_id());
        if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}
        update_site_meta($id,'emsf_mail_mode','off');
        switch_to_blog($id);
        try{switch_theme(ThemeRelease::slug());update_option('home','https://'.$domain);update_option('siteurl','https://'.$domain);update_option('blog_public',0);update_option('emsf_uuid',$p['uuid']);update_option('emsf_slug',$p['slug']);update_option('permalink_structure','/%postname%/');}finally{restore_current_blog();}
        self::main(static function()use($p,$id){$v=get_post_meta($p['id'],'_emsf',true);$v['site_id']=$id;$v['updated_at']=gmdate('c');update_post_meta($p['id'],'_emsf',$v);});return self::find($slug);
    }
    public static function invalidate_approval(): void {
        $slug=get_option('emsf_slug');if(!$slug){return;}
        self::main(static function()use($slug){$p=self::find($slug);if($p['status']==='APPROVED'){$data=get_post_meta($p['id'],'_emsf',true);$data['status']='REVIEW';$data['updated_at']=gmdate('c');update_post_meta($p['id'],'_emsf',$data);}});
    }
    public static function admin(): void {
        if(!current_user_can('manage_network')){return;}
        $editing=[];if(!empty($_GET['prospect'])){try{$editing=self::find(sanitize_title(wp_unslash($_GET['prospect'])));}catch(\Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>';}}
        echo '<div class="wrap"><h1>Emonks Site Factory</h1><p>Lokale voorstellen · online publicatie via het menu Online publicatie.</p><table class="widefat striped"><thead><tr><th>Prospect</th><th>Status</th><th>Website</th><th>Laatste wijziging</th></tr></thead><tbody>';
        foreach(self::prospects() as $p){$site=$p['site_id']?get_site($p['site_id']):null;echo '<tr><td>'.'<a href="'.esc_url(network_admin_url('admin.php?page=emonks-factory&prospect='.$p['slug'])).'">'.esc_html($p['name']).'</a></td><td>'.esc_html($p['status']).'</td><td>'.($site?'<a href="'.esc_url(get_home_url($site->blog_id)).'">Bekijk voorstel</a> · <a href="'.esc_url(get_admin_url($site->blog_id)).'">Bewerken</a>':'Nog geen site').'</td><td>'.esc_html($p['updated_at']).'</td></tr>';}
        echo '</tbody></table><h2>Prospect toevoegen of bijwerken</h2><form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="emsf_save">';wp_nonce_field('emsf_save');
        foreach(['name'=>'Naam','slug'=>'Slug','website'=>'Huidige website','industry'=>'Branche','region'=>'Regio','notes'=>'Opmerkingen'] as $key=>$label){echo '<p><label>'.esc_html($label).'<br><input class="regular-text" name="'.esc_attr($key).'" value="'.esc_attr($editing[$key]??'').'" '.($key==='slug'&&$editing?'readonly ':'').(in_array($key,['name','slug'],true)?'required':'').'></label></p>';}
        echo '<p><label>Status<br><select name="status"><option value="">Huidige status behouden</option>';foreach(['NEW','RESEARCHED','BUILDING','REVIEW','APPROVED','ARCHIVED'] as $status){echo '<option value="'.esc_attr($status).'">'.esc_html($status).'</option>';}echo '</select></label></p><p><label><input type="checkbox" name="create_site" value="1"> Lokale subsite aanmaken als deze ontbreekt</label></p>';
        submit_button('Opslaan');echo '</form></div>';
    }
    public static function admin_save(): void {
        check_admin_referer('emsf_save');try{$p=self::save(wp_unslash($_POST));if(!empty($_POST['create_site'])){self::site($p['slug']);}}catch(\Throwable $e){wp_die(esc_html($e->getMessage()));}wp_safe_redirect(network_admin_url('sites.php'));exit;
    }
}
