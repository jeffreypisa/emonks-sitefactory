<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

final class NotificationSettings {
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
            try{self::save(wp_unslash($_POST));echo '<div class="notice notice-success"><p>Meldingen opgeslagen. Nieuwe klantactiviteit wordt vanaf nu volgens deze instellingen gemeld.</p></div>';}
            catch(\Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>';}
        }
        $settings=self::defaults();$configured=$settings['recipient']!=='';
        echo '<p>Ontvang een mail wanneer iemand een voorstel bekijkt, de klant inlogt of het beheer opent, of de klant inhoud wijzigt. Eigen netwerkbeheerderacties en publicaties tellen niet mee.</p><form method="post"><section class="emsf-panel">';
        wp_nonce_field('emsf_notifications');
        echo '<p><label>E-mailadres voor jouw meldingen<br><input type="email" class="regular-text" name="recipient" required value="'.esc_attr($configured?$settings['recipient']:(string)get_site_option('admin_email','')).'"></label></p>';
        foreach(['view'=>'Voorstel bekeken','login'=>'Klant ingelogd / beheer geopend','content'=>'Inhoud gewijzigd door de klant']as$kind=>$label){echo '<p><label><input type="checkbox" name="notify_'.$kind.'" value="1" '.checked(!$configured||!empty($settings['notify_'.$kind]),true,false).'> '.esc_html($label).'</label></p>';}
        echo '<p><label><input type="checkbox" name="apply_all" value="1"> Ook toepassen op alle bestaande voorstelwebsites (vervangt eigen activiteitsinstellingen per site)</label></p><p>Nieuwe sites gebruiken automatisch deze instellingen. Je kunt per site afwijken in de tab Activiteit.</p><p>Maximaal één mail per soort per site per uur; tussentijdse acties blijven in het log. Wijzigingen tijdens één opslagactie worden samengevoegd. Opslaan verstuurt geen mail en haalt geen oude gebeurtenissen in. Een previewcode bewijst niet wie kijkt.</p><p>Dit staat los van de uitgeschakelde klantmail. WordPress moet online wel werkende mailbezorging hebben. De afleverstatus zie je per site bij Activiteit.</p>';
        submit_button('Meldingen opslaan');echo '</section></form></div>';
    }
}
