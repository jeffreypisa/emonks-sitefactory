<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
final class FollowUp {
    public static function labels(): array { return ['new'=>'Nog benaderen','first_sent'=>'Eerste mail verstuurd','interested'=>'Interesse ontvangen','preparing'=>'Websitevoorstel uitwerken','follow_sent'=>'Vervolgmail met website verstuurd','changes'=>'Aanpassingen bespreken','accepted'=>'Akkoord','declined'=>'Geen interesse','sent'=>'Verstuurd (oude registratie)','response'=>'Reactie ontvangen (oude registratie)']; }
    public static function get(int $id): array { return (array)get_site_meta($id,'emsf_follow_up',true)+['stage'=>'new','notes'=>'','price'=>'','date'=>'','sent_at'=>'','first_sent_at'=>'','follow_sent_at'=>'','updated_at'=>'']; }
    public static function save(int $id,array $data,bool|string $sent=false): array {
        Sites::site($id);
        return Store::locked('follow-up-'.$id,static function()use($id,$data,$sent){
            $record=self::get($id);
            if($sent){
                $stage=$sent===true?'sent':$sent;
                if(!in_array($stage,['sent','first_sent','follow_sent'],true)){throw new \RuntimeException('Kies een geldig mailmoment.');}
                $field=$stage==='sent'?'sent_at':$stage.'_at';
                if(!$record[$field]){$record[$field]=gmdate('c');}
                // A repeated click must not move an interested/accepted customer backwards.
                $order=['new'=>0,'sent'=>0,'response'=>0,'first_sent'=>1,'interested'=>2,'preparing'=>3,'follow_sent'=>4,'changes'=>5,'accepted'=>6,'declined'=>6];
                if($stage==='sent'||($order[$record['stage']]??0)<=($order[$stage]??0)){$record['stage']=$stage;}
            }
            else{
                if(!is_string($data['follow_stage']??null)||!isset(self::labels()[$data['follow_stage']])){throw new \RuntimeException('Kies een geldige klantfase.');}
                $record['stage']=$data['follow_stage'];
                foreach(['notes','price','date']as$key){$value=$data['follow_'.$key]??'';if(!is_string($value)||strlen($value)>10000){throw new \RuntimeException('Ongeldig opvolgveld.');}$record[$key]=$key==='notes'?sanitize_textarea_field($value):sanitize_text_field($value);}
                if($record['date']!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$record['date']);if(!$date||$date->format('Y-m-d')!==$record['date']){throw new \RuntimeException('Kies een geldige opvolgdatum.');}}
                foreach(['sent'=>'sent_at','first_sent'=>'first_sent_at','follow_sent'=>'follow_sent_at']as$stage=>$field){if($record['stage']===$stage&&!$record[$field]){$record[$field]=gmdate('c');}}
            }
            $record['updated_at']=gmdate('c');update_site_meta($id,'emsf_follow_up',$record);return ['follow_up_saved'=>true];
        });
    }
    public static function due(array $record): bool { return $record['date']!==''&&$record['date']<=wp_date('Y-m-d')&&!in_array($record['stage'],['accepted','declined'],true); }
    public static function panel(int $id): void {
        $record=self::get($id);
        echo '<p>Klantfase en afspraken staan los van de publicatiestatus. Kopiëren van een mail markeert deze niet als verstuurd. Deze gegevens blijven bij dit netwerk.</p>';
        echo '<p><label>Klantfase<br><select name="follow_stage">';foreach(self::labels()as$key=>$label){if(in_array($key,['sent','response'],true)&&$record['stage']!==$key){continue;}echo '<option value="'.esc_attr($key).'" '.selected($record['stage'],$key,false).'>'.esc_html($label).'</option>';}echo '</select></label></p>';
        foreach(['price'=>'Afgesproken prijs (exclusief btw)','date'=>'Opvolgdatum']as$key=>$label){echo '<p><label>'.esc_html($label).'<br><input class="regular-text" name="follow_'.$key.'" type="'.($key==='date'?'date':'text').'" value="'.esc_attr($record[$key]).'"></label></p>';}
        echo '<p><label>Contactnotities<br><textarea class="large-text" name="follow_notes" rows="6">'.esc_textarea($record['notes']).'</textarea></label></p>';
        echo '<p><a href="'.esc_url(Sites::url($id,'email')).'">Eerste mail of vervolgmail maken →</a></p>';
        foreach(['first_sent_at'=>'Eerste mail verstuurd','follow_sent_at'=>'Vervolgmail verstuurd','sent_at'=>'Eerdere mailregistratie (mailtype onbekend)']as$field=>$label){
            if($record[$field]){echo '<p>'.esc_html($label.': '.wp_date('d-m-Y H:i',strtotime($record[$field]))).'</p>';}
        }
    }
}
