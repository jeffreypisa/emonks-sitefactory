<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\Factory;

final class Sites {
    public static function allowed(): bool { return is_multisite() && is_super_admin() && current_user_can('manage_network'); }
    public static function requireAdmin(): void { if (!self::allowed()) { throw new \RuntimeException('Alleen de netwerkbeheerder kan deze actie uitvoeren.'); } }
    public static function local(): bool { return defined('EMSF_LOCAL') && EMSF_LOCAL; }
    public static function prospect(int $id): array {
        if (!self::local() || $id===get_main_site_id()) { return []; }
        foreach (Factory::prospects() as $p) { if ((int)$p['site_id']===$id) { return $p; } }
        return [];
    }
    public static function site(int $id): \WP_Site {
        self::requireAdmin();$site=get_site($id);
        if (!$site || (int)$site->network_id!==get_current_network_id() || $id===get_main_site_id()) { throw new \RuntimeException('Kies een bestaande subsite in dit netwerk.'); }
        if (self::local() ? !self::prospect($id) : !get_site_meta($id,'emsf_managed',true)) { throw new \RuntimeException('Deze site wordt niet door Site Factory beheerd.'); }
        return $site;
    }
    /** Read the actual source URL from the linked publication; never guess a local host. */
    public static function localSource(int $id): string {
        self::requireAdmin();
        try {
            $source=(string)get_site_meta($id,'emsf_source',true);
            if($source===''){return '';}
            $link=Store::read('link-'.$source);
            if((int)($link['site']??0)!==$id||empty($link['operation'])){return '';}
            $key=Store::key((string)$link['operation']);
            $file=Store::root().'/'.$key.'/manifest.json';
            if(!is_file($file)){return '';}
            $manifest=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            $url=$manifest['source']??'';
            if(!is_string($url)){return '';}
            $parts=wp_parse_url($url);
            if(!$parts||!in_array($parts['scheme']??'',['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])){return '';}
            return esc_url_raw($url,['http','https']);
        } catch(\Throwable $e) { return ''; }
    }
    public static function url(int $id, string $tab=''): string {
        return add_query_arg(['page'=>'emsf-site','id'=>$id,'tab'=>$tab],network_admin_url('admin.php'));
    }
    public static function rows(array $actions, $id): array {
        if (!self::allowed()) { return $actions; }
        $id=(int)$id;$p=self::prospect($id);
        if (self::local() ? !$p : !get_site_meta($id,'emsf_managed',true)) { return $actions; }
        foreach (['archive','unarchive','delete','spam','unspam','activate','deactivate'] as $key) { unset($actions[$key]); }
        $actions['emsf']='<a href="'.esc_url(self::url($id)).'">Beheren in Site Factory</a>';
        return $actions;
    }
    public static function manageLocal(int $id, string $action, string $confirm, string $deleteConfirm=''): array {
        Factory::local();$site=self::site($id);$p=self::prospect($id);
        if ($confirm!==get_blog_option($id,'blogname')) { throw new \RuntimeException('Typ de exacte lokale sitenaam.'); }
        return Store::locked('local-site-'.$id, static function() use ($id,$action,$site,$p,$deleteConfirm) {
            if ($action==='archive' && !$site->archived) { update_blog_status($id,'archived',1); }
            elseif ($action==='unarchive' && $site->archived) { update_blog_status($id,'archived',0); }
            elseif ($action==='delete' && $deleteConfirm==='DEFINITIEF LOKAAL VERWIJDEREN') {
                switch_to_blog($id);try { $backup=Snapshot::localBackup(); } finally { restore_current_blog(); }
                Store::write('local-deleted-'.$p['uuid'],['site'=>$id,'backup'=>$backup,'created'=>gmdate('c')]);
                require_once ABSPATH.'wp-admin/includes/ms.php';
                $r=wp_delete_site($id);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}
                Factory::main(static function() use($p){$record=get_post_meta($p['id'],'_emsf',true);$record['site_id']=0;$record['status']='ARCHIVED';update_post_meta($p['id'],'_emsf',$record);});
                return ['local_deleted'=>true];
            } else { throw new \RuntimeException('Actie niet beschikbaar of ongeldige bevestiging voor deze site.'); }
            return ['local_state'=>$action==='archive'?'Gearchiveerd':'Hersteld'];
        });
    }
    public static function createLocal(array $data): array {
        Factory::local();self::requireAdmin();
        $name=trim((string)($data['name']??''));$slug=trim((string)($data['slug']??''));
        if ($name==='' || sanitize_text_field($name)!==$name || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>60) { throw new \RuntimeException('Vul een sitenaam en geldige korte slug in.'); }
        return Store::locked('local-create',static function() use($name,$slug){
            foreach(Factory::prospects() as $p){if($p['slug']===$slug && $p['site_id']){throw new \RuntimeException('Dit lokale concept bestaat al.');}}
            return Accounts::silent(static function() use($name,$slug){Factory::save(['name'=>$name,'slug'=>$slug]);return Factory::site($slug);});
        });
    }
}
