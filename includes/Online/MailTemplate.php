<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Network-wide plain-text templates. Never executes WordPress shortcodes. */
final class MailTemplate {
    private const OPTION='emsf_mail_template';
    public static function tokens(): array {
        return ['voornaam'=>'Voornaam contactpersoon','bedrijfsnaam'=>'Bedrijfsnaam','werkzaamheden'=>'Werk voor deze site','kosten'=>'Eenmalig bedrag, inclusief euroteken','maandkosten'=>'Maandbedrag, inclusief euroteken','btw'=>'Altijd exclusief btw','bedenkdatum'=>'Laatste dag bedenktijd','sitelink'=>'Gewone online sitelink','previewcode'=>'Vaste viertekenscode','email'=>'E-mailadres klant','gebruikersnaam'=>'Gebruikersnaam klant','inloglink'=>'Inloggen in het beheer','instellink'=>'Persoonlijke link (alleen nieuw account)','instellink_geldig_tot'=>'Vervaldatum instellink (alleen nieuw account)','redacteurtoegang'=>'Passend tekstblok voor nieuw of bestaand account','screenshot'=>'Screenshottekst, alleen als aangevinkt'];
    }
    public static function defaults(): array {
        return [
            'intro_subject'=>'Een idee voor de website van [bedrijfsnaam]',
            'intro_body'=>"Hoi [voornaam],\n\nIk ben Jeffrey van Emonks. Vanuit mijn kantoor op het Hof van Cartesius in Utrecht maak ik WordPress-websites voor lokale ondernemers.\n\nIk kwam [bedrijfsnaam] tegen en kreeg wat ideeën voor jullie website. Daarom heb ik alvast een eerste ontwerp gemaakt. [screenshot]\n\nWe kennen elkaar nog niet, maar het leek me leuk om het gewoon eens te laten zien.\n\nOm je meteen een idee te geven van de kosten: voor [kosten] [btw] werk ik de website samen met je af en zet ik hem online. Hosting en onderhoud kosten [maandkosten] [btw] per maand.\n\nMocht het ontwerp je aanspreken, dan werk ik het graag vrijblijvend uit tot een eerste website waar je zelf doorheen kunt klikken en alvast wat teksten kunt aanpassen. Zo kun je rustig kijken of het bij jullie past, voordat je ergens voor kiest.\n\nLiever eerst even kennismaken? We kunnen ook bellen of een Google Meet afspreken.\n\nIn ieder geval veel plezier met het bekijken van het ontwerp!\n\nGroet uit Utrecht,\nJeffrey\nEmonks · Hof van Cartesius",
            'follow_subject'=>'Het websitevoorstel voor [bedrijfsnaam]',
            'follow_body'=>"Hoi [voornaam],\n\nLeuk dat je interesse hebt! Zoals beloofd stuur ik je het voorstel voor [bedrijfsnaam].\n\nHier kun je rustig rondkijken:\n[sitelink]\nCode: [previewcode]\n\nDit is een aparte voorstelomgeving; aan je huidige website verandert niets.\n\n[redacteurtoegang]\n\nDe kosten zijn [kosten] [btw]. Daarvoor werk ik het voorstel samen met je af en zet ik de website online. Hosting en onderhoud kosten [maandkosten] [btw] per maand.\n\nNeem gerust tot en met [bedenkdatum] de tijd om te kijken en erover na te denken. Je zit nergens aan vast.\n\nIk hoor graag wat je ervan vindt. Heb je vragen of wil je iets bespreken? We kunnen ook even bellen of een Google Meet afspreken.\n\nGroet uit Utrecht,\nJeffrey\nEmonks · Hof van Cartesius",
            'price'=>'','monthly'=>'','vat'=>'exclusief btw',
            'subject'=>'Ik heb iets gemaakt voor [bedrijfsnaam]',
            'body'=>"Hoi [voornaam],\n\nIk ben Jeffrey, van Emonks. Vanuit mijn kantoor op het Hof van Cartesius in Utrecht maak ik WordPress-websites voor lokale ondernemers.\n\nIk kwam [bedrijfsnaam] tegen en kreeg meteen wat ideeën voor jullie website. Daarom heb ik alvast een eerste voorstel gemaakt.\n\n[werkzaamheden]\n\nLeek me leuk om het gewoon te laten zien, zodat je zelf kunt kijken of het bij jullie past!\n\n[screenshot]\n\nHier kun je rondkijken:\n[sitelink]\nCode: [previewcode]\n\n[redacteurtoegang]\n\nJe kunt rustig wat uitproberen. Dit is een aparte voorstelomgeving; aan je huidige website verandert niets.\n\nVind je dit een mooie richting? Dit zijn de kosten en wat je daarvoor krijgt:\n\n[kosten]\n\nJe hebt tot en met [bedenkdatum] om op je gemak te kijken en erover na te denken. Je zit nergens aan vast.\n\nIk ben benieuwd wat je ervan vindt! Stuur gerust een berichtje terug, ook als je vragen hebt of iets anders zou willen. Even bellen of kennismaken op het Hof kan natuurlijk ook.\n\nGroet,\nJeffrey\nEmonks · Hof van Cartesius, Utrecht",
            'new_account'=>"Wil je ook eens proberen hoe je zelf teksten kunt aanpassen? Via deze persoonlijke link maak je een eigen wachtwoord aan voor het beheer:\n\n[instellink]\nGebruikersnaam: [gebruikersnaam]\nDeze instellink is geldig tot [instellink_geldig_tot]. Lukt het niet op tijd? Stuur me even een berichtje.",
            'existing_account'=>"Wil je zelf teksten aanpassen? Je kunt inloggen met je bestaande account:\n\n[inloglink]\nGebruikersnaam: [gebruikersnaam]",
            'screenshot'=>"In de bijlage zie je een screenshot van het ontwerp.",
            'work'=>'','costs'=>'','days'=>14,
        ];
    }
    public static function settings(): array {
        $settings=array_intersect_key((array)get_site_option(self::OPTION,[]),self::defaults())+self::defaults();
        if($settings['screenshot']==='Bij deze mail vind je alvast een screenshot van de homepage. Benieuwd naar de rest? Via de link kun je zelf even rondkijken.'){$settings['screenshot']=self::defaults()['screenshot'];}
        $previousIntro=str_replace("Mocht het ontwerp je aanspreken, dan werk ik het graag vrijblijvend uit tot een eerste website waar je zelf doorheen kunt klikken en alvast wat teksten kunt aanpassen. Zo kun je rustig kijken of het bij jullie past, voordat je ergens voor kiest.\n\nLiever eerst even kennismaken? We kunnen ook bellen of een Google Meet afspreken.", "Mocht je interesse hebben, dan stuur ik je graag het volledige voorstel. Dat is helemaal vrijblijvend. Liever eerst even kennismaken? We kunnen ook bellen of een Google Meet afspreken.", self::defaults()['intro_body']);
        if($settings['intro_body']===$previousIntro){$settings['intro_body']=self::defaults()['intro_body'];}
        $settings['vat']='exclusief btw';
        return $settings;
    }
    public static function validate(array $input): array {
        $result=[];
        foreach(self::defaults() as $key=>$default){
            $value=$input[$key]??self::settings()[$key]??$default;
            if(in_array($key,['price','monthly'],true)){$result[$key]=self::amount($value,true);continue;}
            if($key==='vat'){$result[$key]='exclusief btw';continue;}
            if($key==='days'){
                if(!is_scalar($value)||!ctype_digit((string)$value)||(int)$value<1||(int)$value>365){throw new \RuntimeException('Kies een bedenktijd van 1 tot en met 365 dagen.');}
                $result[$key]=(int)$value;continue;
            }
            if(!is_string($value)||strlen($value)>(in_array($key,['work','costs'],true)?5000:20000)){throw new \RuntimeException('Ongeldige of te lange tekst voor '.$key.'.');}
            $value=str_ends_with($key,'subject')||$key==='subject'?sanitize_text_field($value):sanitize_textarea_field($value);
            if(in_array($key,['intro_subject','intro_body','follow_subject','follow_body','subject','body','new_account','existing_account'],true)&&trim($value)===''){throw new \RuntimeException('Vul onderwerp, mailtekst en beide accountteksten in.');}
            preg_match_all('/\[([^\]\r\n]+)\]/u',$value,$matches);
            foreach($matches[1]as$token){
                if(str_starts_with($key,'intro_')&&in_array($token,['sitelink','previewcode','redacteurtoegang','inloglink','instellink','instellink_geldig_tot','gebruikersnaam','bedenkdatum'],true)){throw new \RuntimeException('De eerste mail bevat geen toegangslinks, codes of bedenktijd. Gebruik deze invulcode in de vervolgmail.');}
                if(!isset(self::tokens()[$token])){throw new \RuntimeException('Onbekende invulcode: ['.$token.']. Gebruik de knoppen naast de template.');}
                if(in_array($key,['work','costs'],true)){throw new \RuntimeException('Gebruik gewone tekst in de standaardvelden; invulcodes horen in de template.');}
                if(!in_array($key,['body','intro_body','follow_body'],true)&&in_array($token,['redacteurtoegang','screenshot'],true)){throw new \RuntimeException('Tekstblokken [redacteurtoegang] en [screenshot] horen alleen in de mailtekst.');}
            }
            $result[$key]=$value;
        }
        return $result;
    }
    public static function save(array $input): void { Sites::requireAdmin();update_site_option(self::OPTION,self::validate($input)); }
    public static function expand(string $template,array $values): string {
        $map=[];foreach($values as$key=>$value){$map['['.$key.']']=(string)$value;}
        return trim((string)preg_replace('/\n{3,}/',"\n\n",strtr($template,$map)));
    }
    public static function url(): string { return network_admin_url('admin.php?page=emsf-mail-template'); }
    public static function amount($value,bool $optional=false): string {
        if(!is_string($value)){throw new \RuntimeException('Vul een bedrag in, bijvoorbeeld 995 of 995,00.');}
        $value=trim($value);
        if($value===''&&$optional){return '';}
        if(!preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/D',$value)){throw new \RuntimeException('Vul een bedrag in zonder euroteken of duizendtallen, bijvoorbeeld 995 of 995,00.');}
        return str_replace(',','.',$value);
    }
    public static function money(string $value): string { return '€ '.number_format((float)self::amount($value),2,',','.'); }
    public static function page(): void {
        Sites::requireAdmin();$error='';$saved=false;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            check_admin_referer('emsf_mail_template');
            try{$input=wp_unslash($_POST['template']??[]);if(!is_array($input)){throw new \RuntimeException('Ongeldig formulier.');}self::save($input);$saved=true;}catch(\Throwable $e){$error=$e->getMessage();}
        }
        $settings=self::settings();
        if($error&&!empty($input)&&is_array($input)){foreach($input as$key=>$value){if(isset($settings[$key])&&is_string($value)){$settings[$key]=$value;}}}
        echo '<div class="wrap emsf-wrap"><h1>Mailtemplates</h1><p>Twee mails, dezelfde klantgegevens. Bewerk de tekst hier één keer en maak per site een ingevulde mail om te kopiëren. Er wordt niets automatisch verstuurd.</p><p>Lokaal en online hebben ieder hun eigen templates. Beheer ze op de plek waar je de mails maakt.</p>';
        if($saved){echo '<div class="notice notice-success"><p>Beide mailtemplates opgeslagen.</p></div>';}
        if($error){echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';}
        echo '<form method="post">';wp_nonce_field('emsf_mail_template');
        echo '<section class="emsf-panel"><h2>Bedragen en bedenktijd</h2><p>Optionele standaardbedragen. Laat ze leeg om per klant een prijs in te vullen. Een per site ingevuld bedrag blijft behouden.</p>';
        foreach(['price'=>'Eenmalige prijs','monthly'=>'Hosting en onderhoud per maand']as$key=>$label){echo '<p><label>'.esc_html($label).' (€)<br><input class="regular-text" inputmode="decimal" name="template['.$key.']" value="'.esc_attr($settings[$key]).'" placeholder="Bijvoorbeeld '.($key==='price'?'995,00':'60,00').'"></label></p>';}
        echo '<p>Alle bedragen zijn exclusief btw.</p><p><label>Bedenktijd voor de vervolgmail (dagen)<br><input type="number" min="1" max="365" name="template[days]" value="'.esc_attr((string)$settings['days']).'" required></label></p></section>';
        echo '<section class="emsf-panel"><h2>Slimme velden invoegen</h2><p>Klik in een mailtekst en daarna op een veld. [kosten] en [maandkosten] worden bedragen; [redacteurtoegang] voegt alleen toegang toe als je die bij het maken van de vervolgmail aanvinkt.</p><div class="emsf-template-tokens">';
        foreach(self::tokens()as$token=>$label){if($token==='werkzaamheden'){continue;}echo '<button type="button" class="button" data-template-token="['.esc_attr($token).']" title="'.esc_attr($label).'">['.esc_html($token).']</button> ';}echo '</div><p role="status" id="emsf-token-status"></p></section>';
        foreach(['intro'=>'1. Eerste kennismaking','follow'=>'2. Vervolg bij interesse']as$step=>$label){
            echo '<section class="emsf-panel"><h2>'.esc_html($label).'</h2><p>'.($step==='intro'?'Je eerste mail met screenshot en prijs, zonder toegangslinks of codes. Kan al vóór online publicatie.':'De website en previewcode delen, met optioneel toegang om teksten te bewerken.').'</p>';
            foreach(['subject'=>'Onderwerp','body'=>'Mailtekst']as$part=>$title){$key=$step.'_'.$part;echo '<p><label>'.esc_html($title).'<br><textarea data-mail-template class="large-text" rows="'.($part==='body'?20:2).'" name="template['.$key.']" required>'.esc_textarea($settings[$key]).'</textarea></label></p>';}echo '</section>';
        }
        echo '<details><summary>Tekstblokken voor toegang en screenshot</summary>';
        foreach(['new_account'=>'Nieuw klantaccount: eigen wachtwoord instellen','existing_account'=>'Bestaand klantaccount: inloggen','screenshot'=>'Screenshotzin']as$key=>$label){echo '<p><label>'.esc_html($label).'<br><textarea data-mail-template class="large-text" rows="5" name="template['.$key.']">'.esc_textarea($settings[$key]).'</textarea></label></p>';}echo '</details>';
        submit_button('Beide mailtemplates opslaan');echo '</form></div>';
    }
}
