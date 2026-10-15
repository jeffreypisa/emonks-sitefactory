<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Two manual emails; access is only prepared for a requested follow-up. */
final class MailDraft {
    public static function fields(array $data): array {
        $settings=MailTemplate::settings();$step=$data['draft_step']??'intro';
        if(!in_array($step,['intro','follow'],true)){throw new \RuntimeException('Kies eerste mail of vervolgmail.');}
        $fields=['step'=>$step];
        foreach(['first_name'=>'Voornaam','company'=>'Bedrijfsnaam']as$key=>$label){
            $value=$data['draft_'.$key]??'';
            if(!is_string($value)||strlen($value)>200||trim($value)===''){throw new \RuntimeException('Vul in: '.$label.'.');}
            $fields[$key]=sanitize_text_field($value);
            if($fields[$key]===''){throw new \RuntimeException('Vul in: '.$label.'.');}
        }
        $email=$data['draft_email']??'';
        if(!is_string($email)||($email!==''&&!is_email($email))){throw new \RuntimeException('Controleer het e-mailadres.');}
        $fields['email']=sanitize_email($email);
        $template=$settings[$step.'_subject'].' '.$settings[$step.'_body'];
        foreach(['price'=>'kosten','monthly'=>'maandkosten']as$key=>$token){
            $value=$data['draft_'.$key]??'';if(is_string($value)){$value=trim($value);}if($value===''){$value=$settings[$key];}
            if($value===''&&str_contains($template,'['.$token.']')){throw new \RuntimeException('Stel de '.($key==='price'?'eenmalige prijs':'maandprijs').' één keer in bij Mailtemplates in dit netwerk, of vul voor deze site een afwijkend bedrag in.');}
            try{$fields[$key]=MailTemplate::amount($value,!str_contains($template,'['.$token.']'));}catch(\RuntimeException $e){throw new \RuntimeException(($key==='price'?'Eenmalige prijs: ':'Maandbedrag: ').$e->getMessage());}
        }
        $fields['vat']='exclusief btw';
        $fields['until']='';
        if($step==='follow'){
            $until=$data['draft_until']??'';if($until===''){$until=(new \DateTimeImmutable('now',wp_timezone()))->modify('+'.$settings['days'].' days')->format('Y-m-d');}
            if(!is_string($until)){throw new \RuntimeException('Kies een geldige datum.');}
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$until);
            if(!$date||$date->format('Y-m-d')!==$until||$until<wp_date('Y-m-d')){throw new \RuntimeException('Kies een bedenktijd van vandaag of later.');}
            $fields['until']=$until;
        }
        $fields['include_backend']=$step==='follow'&&!empty($data['draft_include_backend']);
        $fields['screenshot']=!empty($data['draft_screenshot']);
        return $fields;
    }
    public static function prepare(int $id,array $data): array {
        Sites::site($id);$fields=self::fields($data);
        $saved=(array)get_blog_option($id,'emsf_mail_draft',[]);
        foreach($fields as$key=>$value){if($fields['step']==='intro'&&in_array($key,['until','include_backend'],true)){continue;}$saved[$key]=$value;}
        foreach(['price','monthly','vat','until']as$key){if($key==='until'&&$fields['step']==='intro'){continue;}if(trim((string)($data['draft_'.$key]??''))===''){$saved[$key]='';}}
        if(isset($data['draft_customer_id'])){$saved['customer_id']=(int)$data['draft_customer_id'];}
        update_blog_option($id,'emsf_mail_draft',$saved);
        // First contact does not call the online receiver or create any access.
        if($fields['step']==='intro'){return ['mail_draft'=>self::compose($fields,[],[])];}
        $access=Admin::run($id,['scope'=>'online','site_action'=>'preview_get']);
        if(($access['link']['state']??'')!=='ready'){throw new \RuntimeException('Publiceer de site eerst succesvol voordat je de vervolgmail maakt. Je mailgegevens zijn bewaard.');}
        $public=($access['preview']['mode']??'')==='public';
        if(!$public&&empty($access['preview']['code'])){throw new \RuntimeException('Kies eerst bij Preview & klanttoegang hoe je de website deelt: zonder code of met een terugleesbare code. Je mailgegevens zijn bewaard.');}
        $customer=[];
        if($fields['include_backend']){
            $customer=$access['customer']??[];
            if(!$customer){throw new \RuntimeException('Voor toegang tot het beheer ontbreekt een gekoppeld klantaccount.');}
            if((isset($data['draft_customer_id'])&&(int)$data['draft_customer_id']!==(int)($customer['id']??0))||($fields['email']!==''&&strcasecmp($fields['email'],$customer['email'])!==0)){throw new \RuntimeException('De mailgegevens horen niet bij de huidige opdrachtgever. Open Mails maken opnieuw en controleer de ontvanger.');}
            $fields['email']=$customer['email'];
            if(!empty($customer['can_setup'])){$customer=Admin::run($id,['scope'=>'online','site_action'=>'setup_link','expected_customer_id'=>$customer['id']??0])['customer'];}
        }
        return ['mail_draft'=>self::compose($fields,$access['preview'],$customer)];
    }
    private static function date(int $time,bool $clock=false): string {
        $months=[1=>'januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
        return wp_date('j',$time).' '.$months[(int)wp_date('n',$time)].' '.wp_date('Y',$time).($clock?' om '.wp_date('H:i',$time):'');
    }
    public static function compose(array $fields,array $preview,array $customer): array {
        $settings=MailTemplate::settings();$step=$fields['step'];$follow=$step==='follow';
        if(!$follow){$preview=[];}$backend=$follow&&!empty($fields['include_backend']);if(!$backend){$customer=[];}
        $tokens=['voornaam'=>$fields['first_name'],'bedrijfsnaam'=>$fields['company'],'kosten'=>$fields['price']!==''?MailTemplate::money($fields['price']):'','maandkosten'=>$fields['monthly']!==''?MailTemplate::money($fields['monthly']):'','btw'=>'exclusief btw','werkzaamheden'=>'','bedenkdatum'=>$follow?self::date((new \DateTimeImmutable($fields['until'].' 12:00:00',wp_timezone()))->getTimestamp()):'','sitelink'=>$preview['url']??'','previewcode'=>$preview['code']??(($preview['mode']??'')==='public'?'geen code nodig':''),'email'=>$fields['email']?:($customer['email']??''),'gebruikersnaam'=>$customer['username']??'','inloglink'=>$customer['login_url']??'','instellink'=>$customer['setup_url']??'','instellink_geldig_tot'=>!empty($customer['expires_at'])?self::date(strtotime($customer['expires_at']),true):''];
        $tokens['redacteurtoegang']=$backend?MailTemplate::expand($settings[!empty($customer['setup_url'])?'new_account':'existing_account'],$tokens):'';
        $tokens['screenshot']=$fields['screenshot']?MailTemplate::expand($settings['screenshot'],$tokens):'';
        $public=$follow&&($preview['mode']??'')==='public';
        if($public){$settings[$step.'_body']=preg_replace('/^[\h]*(?:Toegangs)?code:[\h]*\[previewcode\][\h]*\R?/mi','',$settings[$step.'_body']);}
        return ['unprotected'=>$public,'step'=>$step,'subject'=>MailTemplate::expand($settings[$step.'_subject'],$tokens),'body'=>MailTemplate::expand($settings[$step.'_body'],$tokens),'email'=>$tokens['email'],'screenshot'=>$fields['screenshot'],'expires_at'=>$customer['expires_at']??''];
    }
    public static function form(int $id,array $customer,string $company): void {
        $settings=MailTemplate::settings();$saved=(array)get_blog_option($id,'emsf_mail_draft',[]);
        $current=(int)($customer['id']??0);
        if($current!==(int)($saved['customer_id']??0)){unset($saved['first_name'],$saved['email']);$saved['include_backend']=false;}
        echo '<input type="hidden" name="draft_customer_id" value="'.esc_attr((string)$current).'">';
        $values=$saved+['step'=>'intro','first_name'=>explode(' ',trim((string)($customer['name']??'')))[0],'company'=>$company,'email'=>$customer['email']??'','price'=>'','monthly'=>'','vat'=>'','until'=>'','screenshot'=>true,'include_backend'=>false];
        echo '<p><a href="'.esc_url(MailTemplate::url()).'">Beide mailtemplates en standaardprijzen beheren</a></p><p>Dezelfde klantgegevens voor beide mails. Bedragen zonder euroteken invullen; leeg betekent de centrale standaard gebruiken. Er wordt niets verstuurd.</p>';
        echo '<p><label>Welke mail wil je maken?<br><select name="draft_step" data-mail-step>';
        foreach(['intro'=>'1. Eerste kennismaking — screenshot en prijs','follow'=>'2. Vervolg bij interesse — website en toegang']as$key=>$label){echo '<option value="'.$key.'" '.selected($values['step'],$key,false).'>'.esc_html($label).'</option>';}echo '</select></label></p>';
        foreach(['first_name'=>'Voornaam','company'=>'Bedrijfsnaam','email'=>'E-mailadres (optioneel)']as$key=>$label){echo '<p><label>'.esc_html($label).'<br><input class="regular-text" type="'.($key==='email'?'email':'text').'" name="draft_'.$key.'" value="'.esc_attr((string)$values[$key]).'" '.($key!=='email'?'required ':'').'maxlength="200"></label></p>';}
        echo '<p><strong>Prijzen voor deze mail</strong><br>';
        foreach(['price'=>'Eenmalig','monthly'=>'Per maand']as$key=>$label){
            $own=trim((string)$values[$key]);$amount=$own!==''?$own:$settings[$key];
            echo esc_html($label.': '.($amount!==''?MailTemplate::money($amount).' exclusief btw':'nog geen standaardprijs ingesteld').($own!==''?' (afwijking voor deze site)':' (uit Mailtemplates)')).'<br>';
        }
        echo '</p>';
        if($settings['price']===''||$settings['monthly']===''){echo '<p class="description">Standaardprijzen worden per netwerk bewaard. <a href="'.esc_url(MailTemplate::url()).'">Stel ze hier één keer in</a>; daarna kun je onderstaande velden leeg laten.</p>';}
        echo '<details'.((string)$values['price']!==''||(string)$values['monthly']!==''?' open':'').'><summary>Afwijkende prijzen voor deze site (optioneel)</summary><p>Alleen invullen als je wilt afwijken. Maak een veld leeg om weer de actuele standaard uit Mailtemplates te gebruiken. Alle bedragen zijn exclusief btw.</p>';
        foreach(['price'=>'Eenmalige prijs','monthly'=>'Hosting en onderhoud per maand']as$key=>$label){echo '<p><label>'.esc_html($label).' (€, optioneel)<br><input class="regular-text" inputmode="decimal" name="draft_'.$key.'" value="'.esc_attr((string)$values[$key]).'" placeholder="'.esc_attr($settings[$key]!==''?'Standaard: '.MailTemplate::money($settings[$key]):'Gebruik centrale standaard').'" ></label></p>';}
        echo '</details>';
        if(!$customer){echo '<p>Voor beheertoegang koppel je eerst een opdrachtgever onder <a href="'.esc_url(Sites::url($id,'gebruikers')).'">Gebruikers</a>. Een previewmail kan wel zonder account.</p>';}
        echo '<div data-mail-follow><p><label>Bedenktijd tot en met (leeg = '.$settings['days'].' dagen)<br><input type="date" name="draft_until" value="'.esc_attr((string)$values['until']).'" min="'.esc_attr(wp_date('Y-m-d')).'"></label></p><p><label><input type="checkbox" name="draft_include_backend" value="1" '.checked($customer&&!empty($values['include_backend']),true,false).(!$customer?' disabled data-unavailable':'').'> Ook toegang tot het beheer meesturen</label></p><p class="description">Nieuwe klant: een persoonlijke link om zelf een wachtwoord te kiezen (maximaal 24 uur geldig). Bestaande klant: de gewone inloglink. Opnieuw een instellink maken vervangt de vorige; de previewcode blijft gelijk.</p></div>';
        echo '<p><label><input type="checkbox" name="draft_screenshot" value="1" '.checked(!empty($values['screenshot']),true,false).'> Ik voeg zelf een screenshot toe</label></p>';
    }
    public static function result(array $draft): string {
        $html='<h1>'.(($draft['step']??'intro')==='intro'?'Je eerste mail staat klaar':'Je vervolgmail staat klaar').'</h1><p>Controleer de tekst, kopieer hem en plak hem in je eigen mailprogramma. Je kunt de tekst hieronder nog aanpassen. Er is niets verstuurd.</p>';
        if($draft['screenshot']){$html.='<p><strong>Voeg zelf je screenshot toe voordat je de mail verstuurt.</strong></p>';}
        if($draft['expires_at']){$html.='<p>De persoonlijke instellink is geldig tot '.esc_html(self::date(strtotime($draft['expires_at']),true)).'. Dit staat los van de bedenktijd en de vaste previewcode.</p>';}
        foreach(['email'=>'Aan','subject'=>'Onderwerp','body'=>'Mailtekst']as$key=>$label){
            $html.='<p><label for="draft-'.$key.'">'.$label.'</label><br>';
            $html.=$key==='body'?'<textarea id="draft-body" rows="25" style="width:100%;box-sizing:border-box">'.esc_textarea($draft[$key]).'</textarea>':'<input id="draft-'.$key.'" style="width:100%;box-sizing:border-box" value="'.esc_attr($draft[$key]).'">';
            $html.='</p><button type="button" data-copy-draft="draft-'.$key.'">Kopieer '.($key==='email'?'e-mailadres':strtolower($label)).'</button> ';
        }
        return $html.'<p id="draft-copy-status" role="status" aria-live="polite"></p><script>document.querySelectorAll("[data-copy-draft]").forEach(button=>button.addEventListener("click",async()=>{const field=document.getElementById(button.dataset.copyDraft);const status=document.getElementById("draft-copy-status");try{if(!navigator.clipboard)throw new Error();await navigator.clipboard.writeText(field.value);status.textContent="Gekopieerd.";}catch(e){field.focus();field.select();status.textContent="De tekst is geselecteerd. Kopieer deze met Ctrl+C of Cmd+C.";}}));</script>';
    }
}
