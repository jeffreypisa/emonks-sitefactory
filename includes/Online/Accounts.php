<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Account provisioning and manual access sharing. Never sends WordPress mail. */
final class Accounts {
    private static int $silentDepth = 0;
    public static function boot(): void {
        foreach (['wp_send_new_user_notification_to_user','wp_send_new_user_notification_to_admin'] as $hook) {
            add_filter($hook, static fn($send,$user)=>self::$silentDepth>0 || get_user_meta($user->ID,'emsf_customer_source',true) ? false : $send, PHP_INT_MAX, 2);
        }
        add_action('after_password_reset', static function($user) {
            if (get_user_meta($user->ID, 'emsf_customer_source', true)) { update_user_meta($user->ID, 'emsf_access_initialized', 1); }
        });
        add_action('wp_login', static function($login, $user) {
            if (get_user_meta($user->ID, 'emsf_customer_source', true)) { update_user_meta($user->ID, 'emsf_access_initialized', 1); }
        }, 10, 2);
    }
    public static function silent(callable $fn) {
        // Also covers wp_mail called by user_register, wpmu_new_user, site hooks or reset-key hooks.
        $block = static fn()=>false;
        self::$silentDepth++;
        add_filter('pre_wp_mail', $block, PHP_INT_MAX);
        try { return $fn(); } finally { remove_filter('pre_wp_mail', $block, PHP_INT_MAX); self::$silentDepth--; }
    }
    /** Empty means deliberately no account; nonempty input must still be complete. */
    public static function optional(array $data): array {
        return $data === [] ? [] : self::validate($data);
    }
    public static function users(int $site): array {
        $users=[];
        foreach(get_users(['blog_id'=>$site,'orderby'=>'display_name','order'=>'ASC']) as $user){
            if(is_super_admin($user->ID)){continue;}
            $users[]=['id'=>(int)$user->ID,'name'=>$user->display_name,'username'=>$user->user_login,'email'=>$user->user_email,'roles'=>array_values($user->roles)];
        }
        return $users;
    }
    /** A contact designation never grants, revokes or changes access. */
    public static function assign(int $site,int $user): void {
        if($user && (!get_userdata($user)||is_super_admin($user)||!is_user_member_of_blog($user,$site))){throw new \RuntimeException('Kies een bestaande gebruiker van deze site. Netwerkbeheerders kunnen geen opdrachtgever zijn.');}
        update_site_meta($site,'emsf_customer_id',$user);
    }
    public static function createUser(int $site,string $source,array $data,bool $assign=true): array {
        $data=self::validate($data);
        return self::silent(static function()use($site,$source,$data,$assign){
            self::available($data);
            $id=wpmu_create_user($data['username'],wp_generate_password(48,true,true),$data['email']);
            if(!$id){throw new \RuntimeException('Gebruiker aanmaken mislukt. Controleer Gebruikers voordat je opnieuw begint.');}
            update_user_meta($id,'emsf_customer_source',$source);
            update_user_meta($id,'emsf_customer_site',$site);
            $result=wp_update_user(['ID'=>$id,'display_name'=>$data['name']]);
            if(is_wp_error($result)){throw new \RuntimeException($result->get_error_message());}
            $result=add_user_to_blog($site,$id,'editor');
            if(is_wp_error($result)){throw new \RuntimeException($result->get_error_message());}
            if($assign){self::assign($site,(int)$id);}
            if($data['layout_access']){
                $roles=(array)get_blog_option($site,'options_skeletor_theme_options_roles',[]);
                update_blog_option($site,'options_skeletor_theme_options_roles',array_values(array_unique(array_merge($roles,['editor']))));
            }
            return ['user_id'=>(int)$id];
        });
    }
    public static function errors(array $data): array {
        $errors=[];$name=is_string($data['name']??null)?trim($data['name']):'';$login=is_string($data['username']??null)?strtolower(trim($data['username'])):'';$email=is_string($data['email']??null)?trim($data['email']):'';
        if($name===''){$errors['customer_name']='Vul de naam van de contactpersoon in.';}
        elseif(sanitize_text_field($name)!==$name||strlen($name)>100){$errors['customer_name']='Gebruik een naam zonder HTML of regeleinden, van maximaal 100 tekens.';}
        if($login===''){$errors['customer_username']='Vul een gebruikersnaam voor het klantaccount in.';}
        elseif(strlen($login)>60||sanitize_user($login,true)!==$login||!validate_username($login)){$errors['customer_username']='Gebruik maximaal 60 tekens: letters zonder accenten, cijfers, spaties of . - _ @.';}
        if(!is_email($email)||sanitize_email($email)!==$email){$errors['customer_email']='Vul een geldig e-mailadres in, bijvoorbeeld naam@bedrijf.nl.';}
        if(empty($data['confirmed'])){$errors['customer_confirmed']='Bevestig dat je de klantgegevens en het e-mailadres hebt gecontroleerd.';}
        return $errors;
    }
    public static function validate(array $data): array {
        $errors=self::errors($data);if($errors){throw new \RuntimeException(implode(' ',$errors));}
        return ['name'=>trim($data['name']),'username'=>strtolower(trim($data['username'])),'email'=>trim($data['email']),'confirmed'=>true,'layout_access'=>!empty($data['layout_access'])];
    }
    public static function available(array $data): void {
        if(username_exists($data['username'])){throw new \RuntimeException('Deze gebruikersnaam bestaat al online. Kies een andere gebruikersnaam; het bestaande account blijft ongewijzigd.');}
        if(email_exists($data['email'])){throw new \RuntimeException('Dit e-mailadres hoort al bij een online account. Het bestaande account wordt niet overgenomen of gereset; laat deze klanttoegang apart beoordelen.');}
    }
    public static function provision(int $site, string $source, array $data): array {
        if(!(int)get_site_meta($site,'emsf_customer_id',true)){self::createUser($site,$source,$data);}
        return self::details($site);
    }
    public static function details(int $site): array {
        $id=(int)get_site_meta($site,'emsf_customer_id',true);$user=$id?get_userdata($id):false;
        if (!$user || !is_user_member_of_blog($id,$site)) { return []; }
        return ['id'=>(int)$user->ID,'name'=>$user->display_name,'username'=>$user->user_login,'email'=>$user->user_email,'login_url'=>get_site_url($site,'wp-login.php','login'),'can_setup'=>get_user_meta($id,'emsf_customer_source',true)===get_site_meta($site,'emsf_source',true) && !get_user_meta($id,'emsf_access_initialized',true)];
    }
    public static function setupLink(int $site): array {
        $details=self::details($site);
        if (!$details || !$details['can_setup']) { throw new \RuntimeException('Bestaand of al gebruikt account: wachtwoord en toegang blijven ongewijzigd. Laat de klant zo nodig zelf Wachtwoord vergeten gebruiken.'); }
        return self::silent(static function() use ($site,$details) {
            $user=get_userdata((int)get_site_meta($site,'emsf_customer_id',true));
            $expiry=(int)apply_filters('password_reset_expiration',DAY_IN_SECONDS);
            if ($expiry<1 || $expiry>DAY_IN_SECONDS) { throw new \RuntimeException('Instellinks vereisen een WordPress-vervaltermijn van maximaal 24 uur.'); }
            $key=get_password_reset_key($user);
            if (is_wp_error($key)) { throw new \RuntimeException($key->get_error_message()); }
            // Returned once over the authenticated connection; never saved in journals or exports.
            return $details+['setup_url'=>add_query_arg(['action'=>'rp','key'=>$key,'login'=>$user->user_login],get_site_url($site,'wp-login.php','login')),'expires_at'=>gmdate('c',time()+$expiry)];
        });
    }
}
