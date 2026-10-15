<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Site-scoped WordPress mail gate. Discards, never stores or replays messages. */
final class Mail {
    public const NOTICE='E-mail staat uit voor dit voorstel. Er worden geen formulier- of automatische mails verstuurd. Vraag je contactpersoon om toegang.';
    public static function managed(int $id): bool {
        return $id!==get_main_site_id() && (get_site_meta($id,'emsf_managed',true) || (Sites::local() && (bool)get_blog_option($id,'emsf_uuid')));
    }
    public static function mode(?int $id=null): string {
        $id=$id??get_current_blog_id();
        if(!self::managed($id)){return 'on';}
        $mode=get_site_meta($id,'emsf_mail_mode',true);
        return $mode==='on'?'on':'off'; // Legacy Factory demos were already muted; other sites are unaffected.
    }
    public static function off(): bool { return self::mode()==='off'; }
    public static function set(int $id,string $mode): void {
        Sites::requireAdmin();Sites::site($id);
        if(!in_array($mode,['on','off'],true)){throw new \RuntimeException('Kies E-mail aan of E-mail uit.');}
        update_site_meta($id,'emsf_mail_mode',$mode);
        // No queue, schedule, flush or resend action is performed when changing this flag.
    }
    public static function signature(int $id): string { return hash_hmac('sha256','emsf-reset-context:'.$id,wp_salt('auth')); }
    public static function context(): int {
        $id=absint($_REQUEST['emsf_site']??0);$sig=(string)($_REQUEST['emsf_context']??'');
        $site=$id?get_site($id):null;
        if($site && (int)$site->network_id===get_current_network_id() && self::managed($id) && hash_equals(self::signature($id),$sig)){return $id;}
        return self::managed(get_current_blog_id())?get_current_blog_id():0;
    }
    public static function resetSite(\WP_User $user): int {
        if(is_super_admin($user->ID)){return 0;}
        $context=self::context();
        if($context && is_user_member_of_blog($user->ID,$context)){return $context;}
        // Generic network login: infer only unambiguous single-site customer membership.
        $blogs=get_blogs_of_user($user->ID,true);
        if(count($blogs)===1){$id=(int)array_key_first($blogs);if(self::managed($id)){return $id;}}
        return 0; // Never suppress unrelated network/multi-site account mail by guessing.
    }
    /** Only WordPress' own cron endpoint; never permit arbitrary local integrations. */
    public static function cronRequest(array $args,string $url): bool {
        if (!Sites::local() || strtoupper($args['method']??'GET')!=='POST') { return false; }
        $target=wp_parse_url($url);$own=wp_parse_url(site_url('wp-cron.php'));
        foreach (['scheme','host','port','path'] as $part) {
            if (($target[$part]??null)!==($own[$part]??null)) { return false; }
        }
        return !isset($target['user']) && !isset($target['pass']);
    }
    public static function boot(): void {
        add_filter('http_request_args',static function($args,$url){if(self::cronRequest($args,$url)){$args['redirection']=0;}return $args;},PHP_INT_MAX,2);
        add_filter('pre_wp_mail',static fn($pre,$atts)=>self::off()&&!Activity::permittedMail($atts)?true:$pre,PHP_INT_MAX,2);
        add_filter('send_retrieve_password_email',static function($send,$login,$user){$site=self::resetSite($user);return $site && self::mode($site)==='off'?false:$send;},PHP_INT_MAX,3);
        add_filter('lostpassword_url',static function($url){$site=self::context();return $site?add_query_arg(['emsf_site'=>$site,'emsf_context'=>self::signature($site)],$url):$url;});
        add_action('lostpassword_form',static function(){
            $site=self::context();if($site){echo '<input type="hidden" name="emsf_site" value="'.esc_attr((string)$site).'"><input type="hidden" name="emsf_context" value="'.esc_attr(self::signature($site)).'">';}
        });
        add_filter('login_message',static function($message){
            $site=self::context();
            if($site && self::mode($site)==='off'){return $message.'<p class="message">'.esc_html(self::NOTICE).'</p>';}
            if(is_main_site() && in_array($_REQUEST['action']??'',['lostpassword','retrievepassword'],true)){
                // Same message for every visitor; do not reveal whether the supplied account exists.
                return $message.'<p class="message">Staat e-mail voor je voorstel uit? Dan ontvang je geen herstelmail. Vraag je contactpersoon om toegang.</p>';
            }return $message;
        });
        add_filter('fluentform/validation_errors',static function($errors){if(self::off()){$errors['emsf_mail_off']=[self::NOTICE];}return $errors;},PHP_INT_MAX);
        add_action('fluentform/before_insert_submission',static function(){if(self::off()){wp_send_json(['errors'=>['emsf_mail_off'=>[self::NOTICE]]],422);}},PHP_INT_MIN);
        add_filter('fluentform/global_notification_active_types',static fn($types)=>self::off()?[]:$types,PHP_INT_MAX);
        // Stops provider APIs using WordPress HTTP while a demo is muted. Native sockets are outside this layer.
        add_filter('pre_http_request',static function($pre,$args,$url){
            if(!self::off() || Client::isSending() || Activity::sending() || self::cronRequest($args,$url)){return $pre;}
            $host=wp_parse_url($url,PHP_URL_HOST);
            if(wp_parse_url($url,PHP_URL_SCHEME)==='https' && in_array($host,['api.wordpress.org','downloads.wordpress.org','fonts.googleapis.com','fonts.gstatic.com','fonts.google.com'],true)){return $pre;}
            return new \WP_Error('emsf_demo_offline','Uitgaande integraties staan uit voor dit voorstel.');
        },PHP_INT_MAX,3);
        add_action('wp_footer',static function(){if(!Sites::local()&&self::off()){echo '<aside role="note" style="padding:12px;text-align:center;background:#f6f7f7;color:#1d2327">'.esc_html(self::NOTICE).'</aside>';}});
    }
}
