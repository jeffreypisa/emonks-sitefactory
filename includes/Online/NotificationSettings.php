<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

final class NotificationSettings {
    public static function websiteNoticesDefault(): bool {
        return in_array(get_site_option('emsf_show_website_notices','1'),[true,1,'1'],true);
    }
    public static function websiteNotices(int $site): array {
        $mode=get_site_meta($site,'emsf_website_notices',true);
        if(!in_array($mode,['show','hide'],true)){$mode='inherit';}
        $default=self::websiteNoticesDefault();
        return ['mode'=>$mode,'default'=>$default,'enabled'=>$mode==='inherit'?$default:$mode==='show'];
    }
    public static function showWebsiteNotices(?int $site=null): bool {
        return self::websiteNotices($site??get_current_blog_id())['enabled'];
    }
    public static function saveSiteWebsiteNotices(int $site,array $input): void {
        Sites::requireAdmin();Sites::site($site);
        if(Sites::local()){throw new \RuntimeException('Stel de online websitemeldingen in via de online verbinding.');}
        $mode=$input['website_notices_mode']??null;
        if(!is_string($mode)||!in_array($mode,['inherit','show','hide'],true)){throw new \RuntimeException('Kies Standaard volgen, Tonen of Verbergen.');}
        if($mode==='inherit'){delete_site_meta($site,'emsf_website_notices');}
        else{update_site_meta($site,'emsf_website_notices',$mode);}
    }
    public static function saveWebsiteNotices(array $input): void {
        Sites::requireAdmin();
        if(Sites::local()){throw new \RuntimeException('Stel websitemeldingen in op het online netwerk.');}
        // WordPress skips a first update when both the missing old value and new value are false.
        update_site_option('emsf_show_website_notices',empty($input['show_website_notices'])?'0':'1');
    }
    public static function defaults(): array {
        return (array)get_site_option('emsf_notifications',[])+['enabled'=>true,'recipient'=>'','notify_view'=>false,'notify_login'=>false,'notify_content'=>false];
    }
    public static function save(array $input): void {
        Sites::requireAdmin();
        if(Sites::local()){throw new \RuntimeException('Stel meldingen in op het online netwerk.');}
        $recipient=trim((string)($input['recipient']??''));
        if(!is_email($recipient)||sanitize_email($recipient)!==$recipient){throw new \RuntimeException('Vul een geldig ontvangstadres in.');}
        $policy=['enabled'=>true,'recipient'=>$recipient];
        foreach(['view','login','content']as$kind){$policy['notify_'.$kind]=!empty($input['notify_'.$kind]);}
        update_site_option('emsf_notifications',$policy);
        if(!empty($input['apply_all'])){
            foreach(get_sites(['network_id'=>get_current_network_id(),'number'=>0])as$site){
                if(get_site_meta((int)$site->blog_id,'emsf_managed',true)){delete_site_meta((int)$site->blog_id,'emsf_activity_policy');}
            }
        }
    }
    public static function page(): void {
        Sites::requireAdmin();
        echo '<div class="wrap emsf-wrap"><h1>Meldingen</h1>';
        if(Sites::local()){echo '<p>Stel je ontvangstadres één keer in op sites.emonks.nl onder Site Factory → Meldingen. Het online netwerk verstuurt de meldingen, ook wanneer je computer uit staat.</p></div>';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            check_admin_referer('emsf_notifications');
            try{if(($_POST['settings_section']??'')==='website'){self::saveWebsiteNotices(wp_unslash($_POST));}else{self::save(wp_unslash($_POST));}echo '<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>';}
            catch(\Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>';}
        }
        echo '<form method="post"><section class="emsf-panel"><h2>Meldingen op de website</h2>';
        wp_nonce_field('emsf_notifications');
        echo '<input type="hidden" name="settings_section" value="website"><p><label><input type="checkbox" name="show_website_notices" value="1" '.checked(self::websiteNoticesDefault(),true,false).'> Meldingen op de website standaard tonen</label></p><p>Toont de teksten over uitgeschakelde e-mail en activiteitsregistratie onderaan voorstelwebsites die de standaard volgen. Per site kun je hiervan afwijken onder Activiteit → Meldingen op de website. De mailblokkade, registratie en meldingen aan de maker blijven ongewijzigd.</p>';
        submit_button('Weergave opslaan');echo '</section></form>';
        $settings=self::defaults();$configured=$settings['recipient']!=='';
        echo '<p>Ontvang een mail wanneer iemand een voorstel bekijkt, de klant inlogt of het beheer opent, of de klant inhoud wijzigt. Eigen netwerkbeheerderacties en publicaties tellen niet mee.</p><form method="post"><section class="emsf-panel">';
        wp_nonce_field('emsf_notifications');
        echo '<p><label>E-mailadres voor jouw meldingen<br><input type="email" class="regular-text" name="recipient" required value="'.esc_attr($configured?$settings['recipient']:(string)get_site_option('admin_email','')).'"></label></p>';
        foreach(['view'=>'Voorstel bekeken','login'=>'Klant ingelogd / beheer geopend','content'=>'Inhoud gewijzigd door de klant']as$kind=>$label){echo '<p><label><input type="checkbox" name="notify_'.$kind.'" value="1" '.checked(!$configured||!empty($settings['notify_'.$kind]),true,false).'> '.esc_html($label).'</label></p>';}
        echo '<p><label><input type="checkbox" name="apply_all" value="1"> Ook toepassen op alle bestaande voorstelwebsites (vervangt eigen activiteitsinstellingen per site)</label></p><p>Nieuwe sites gebruiken automatisch deze instellingen. Je kunt per site afwijken in de tab Activiteit.</p><p>Maximaal één mail per soort per site per uur; tussentijdse acties blijven in het log. Wijzigingen tijdens één opslagactie worden samengevoegd. Opslaan verstuurt geen mail en haalt geen oude gebeurtenissen in. Een previewcode bewijst niet wie kijkt.</p><p>Dit staat los van de uitgeschakelde klantmail. WordPress moet online wel werkende mailbezorging hebben. De afleverstatus zie je per site bij Activiteit.</p>';
        submit_button('Meldingen opslaan');echo '</section></form></div>';
    }
}
