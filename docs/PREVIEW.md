# Vaste previewcode en klanttoegang — Site Factory 0.5.7

## Voor de eigenaar

WordPress **Sites** behoudt zijn standaardkolommen en gewone beheerlinks. Bij een beheerd voorstel staat één extra link: **Beheren in Site Factory**. Onder Sites staat ook Site Factory, met een sitekeuze, plus Nieuwe lokale site en Online verbinding. Archiveer/verwijderacties voor Factory-sites lopen via de afzonderlijke bevestigingen; destructieve bulkacties blijven uitgeschakeld.

Het Factory-scherm toont bovenaan de lokale werkversie en de online toestand. De online toestand is opgeslagen informatie: Status controleren haalt de actuele toestand op. De onderdelen Publiceren, Preview & klanttoegang, Activiteit, E-mail en Beheer zijn echte verticale tabs links: rechts is uitsluitend het gekozen paneel zichtbaar. Op mobiel staan de tabs boven het paneel. Pijltjestoetsen en Home/End werken; de selectie blijft behouden na opslaan of een fout. Bij de eerste publicatie wordt de online subsite automatisch aangemaakt; maak vooraf geen lege doelsite aan. Daarna vervang je bewust de inhoud van de gekoppelde site, met naambevestiging en backup. Andere sites worden niet gepubliceerd.

Na een geslaagde publicatie:

1. Open de site in **Site Factory → Preview & klanttoegang**.
2. Klik eenmalig op **Vaste code maken**. Geen sitenaam overtypen.
3. De gewone sitelink en de vaste code van vier tekens staan voortaan in dit scherm. Klik **Kopieer link en code** en plak het bericht naar je klant. Als het klembord niet beschikbaar is, verschijnt selecteerbare tekst.
4. De klant opent de link en vult de code in. Geen account nodig; hoofdletters en kleine letters werken beide.
5. Dezelfde code blijft geldig bij opnieuw openen, delen en herpubliceren. Er is geen vervaldatum voor de code. **Code wijzigen of toegang uitzetten** staat ingeklapt en is alleen nodig als je bewust de oude toegang wilt beëindigen.

Voor teksten bewerken staat hieronder apart **Instellink voor redacteur maken**, eveneens zonder sitenaam overtypen. Dit blijft persoonlijk accountbeheer; de previewcode geeft geen bewerktoegang.

Bestaande codes uit oudere versies blijven werken. Omdat ze alleen als hash zijn opgeslagen, kan de oude code niet worden teruggetoond. Het scherm biedt dan expliciet **Vaste code maken**: één bewuste vervanging, waarna de nieuwe code steeds terug te vinden is. Ook na wijziging van de WordPress-authenticatiesleutels kan deze eenmalige vervanging nodig zijn. Er wordt nooit bij het bekijken van het scherm automatisch een code veranderd.

Een previewsessie duurt maximaal acht uur; de code zelf blijft geldig tot vernieuwen of intrekken. Dit is bewust lichte afscherming voor voorstellen en **niet geschikt voor gevoelige of vertrouwelijke gegevens**. Iedereen met de code kan het voorstel bekijken en de code doorgeven. Gebruik voor bewerken en voor sterker gecontroleerde toegang het afzonderlijke persoonlijke redacteuraccount. Previewcodes geven geen toegang tot dashboard, editor, REST/API, AJAX, XML-RPC, feeds of schrijfacties. Formulieren testen of inhoud wijzigen hoort bij persoonlijke accounttoegang, met het bestaande mailbeleid.

## Grenzen tegen raden en sessies

- Vijf mislukte pogingen per bezoekersadres binnen vijftien minuten; twintig mislukte pogingen per site binnen één uur. Ook de juiste code wordt tijdens de bijbehorende blokkade tijdelijk geweigerd. Bestaande geldige previewsessies worden niet door de raadlimiet beëindigd.
- De sitebrede limiet blijft gelden wanneer een aanvaller bezoekersadressen wisselt. Achter een proxy kunnen meerdere bezoekers hetzelfde serveradres delen en dus eerder worden begrensd. De plugin vertrouwt geen door bezoekers aangeleverde proxyheaders. Hosting kan aanvullende rate limiting toepassen.
- Tellers worden onder een per-site filesystem-lock bijgewerkt in private opslag. Alleen een gezouten hash van het adres en tellers/tijden worden opgeslagen, geen onversleuteld bezoekersadres of ingevoerde code. Een nieuwe code reset de limiet niet. Er is geen automatische opruimer voor oude site-ratebestanden; behandel ze volgens de bestaande private-state-retentie.
- Site-meta bevat de wachtwoordhash, een willekeurige generatie-ID en een met AES-256-GCM versleutelde kopie van de code. De sleutel is gebonden aan de WordPress-authenticatiesleutel, het netwerk en de site. Alleen netwerkbeheerders kunnen de code opvragen. Oude deellinks behouden hun bestaande tokenhash tot de code bewust wordt vervangen. Sessiecookies zijn ondertekend en gebonden aan netwerk, site, generatie en vervaldatum. Ze zijn Secure, HttpOnly, SameSite=Lax en beperkt tot het sitepad. HTTP geeft geen code- of sessietoegang.
- De code wordt via POST verzonden met een tijdelijke, sitegebonden formulierchallenge. De geheime code verschijnt nooit in gegenereerde URL’s, normale statusantwoorden, publicatiejournals of inhoudsexports. Configureer hosting/APM niet om verzoekbody’s, cookies of de expliciete codebeheerrespons te loggen.
- De plugin maakt geen WordPress-login aan voor een previewbezoeker. Acties voor genereren/intrekken vereisen netwerkbeheer, noncecontrole in het beheer en de gecontroleerde sitekoppeling, zonder overtypen. De remote route behoudt haar HTTPS/applicatiewachtwoordcontrole.
- Archive/import/herstel/fouttoestanden blijven gesloten. Herpublicatie en inhoudsherstel veranderen previewcode, account en mailbeleid niet. Vernieuwen/intrekken werkt onmiddellijk voor volgende verzoeken; reeds gedownloade inhoud is uiteraard niet terug te halen.

## Online upgraden

1. Maak een hostingbackup van plugin, database en private opslag. Behoud de private opslag en bestaande configuratie; maak geen nieuwe subsite aan.
2. Installeer `emonks-site-factory-0.5.7.zip` als update van dezelfde netwerkplugin. Vermijd een venster met gedeactiveerde privacyplugin. Werk de lokale en online plugin bij voordat je previewcodes beheert. Een oude online ontvanger geeft bij codebeheer een expliciete upgrade-melding.
3. Houd HTTPS en de bestaande private-media-inrichting. Sluit de **hele voorstelroute**, inclusief het codeformulier, ingelogde previews, foutantwoorden en media, uit van server/CDN/page-cache. De PHP-antwoorden bevatten private/no-store/no-cache, noindex en no-referrer; PHP kan een upstream-cache niet beveiligen. Purge eventuele oude cache.
4. Controleer Status voor één site. Publiceer eerst het afgesproken proefvoorstel. Maak bewust de code, open een privévenster en controleer de eerste toegang, afbeelding/thumbnail/font, verkeerde code en herhaalpogingen.
5. Test een andere subsite en directe beheer-/API-adressen. Vernieuw en trek de code in en controleer dat oude previewsessies niet meer werken. Controleer daarnaast de persoonlijke redacteurtoegang en dat e-mail uit blijft.

Deze update is lokaal uitgevoerd en getest. De daadwerkelijke online installatie, cache-uitsluitingen, proxygedrag en HTTPS-cookieafhandeling zijn niet door deze test gecertificeerd en moeten in de hostingpilot worden gecontroleerd. Er is tijdens de update geen online site gewijzigd en geen mail verstuurd.

## Gerichte validatie

`preview-access.php` test codehash, kleine letters, gescheiden challenge/session, tampering/expiry, andere-site-isolatie, verbod op beheer/API/schrijfacties, archief/import/herstel/fout, HTTPS-eis, rotatie/intrekking, beide raadlimieten, verlopen teller, exportuitsluiting, redacteurbehoud en bevoegdheden. `preview-http.py` test de echte HTTP-invoerflow en cookieattributen, voorpagina, beschermde media/thumbnails/font, REST/AJAX/XML-RPC/feeds/beheerblokkades, schrijfverbod, padtraversal en behoud van de normale login. De HTTP-fixture simuleert TLS-status; dit is geen echte TLS-/hostingtest.

Alle bestaande publicatie-, account-, mail- en HTTP-privacycontroles zijn mee uitgevoerd. E-mailtransports zijn onderschept; fixtures bevatten alleen synthetische gegevens. De previewpagina is visueel gecontroleerd op desktop en 390 px breedte.

Het echte lokale Factory-beheer is eveneens op desktop en 390 px breedte gecontroleerd: geen horizontale overloop, aparte lokale/online kaarten, vijf werkonderdelen, standaard Sites-kolommen met precies elf Factory-links voor de elf voorstellen, en succesvolle ongewijzigde opslag van lokaal e-mail uit. Alle twaalf bestaande sites bleven behouden.

## Deellink en activiteit

Vanaf 0.5.7 deel je de gewone sitelink en vaste code. Een geslaagde code-invoer plus een werkelijk gerenderde pagina telt als anonieme preview als registratie is ingeschakeld. Dit bewijst niet welke persoon heeft gekeken. Het openen van alleen de codepagina telt niet als bezoek.

Deellinks die vóór 0.5.7 zijn gemaakt blijven ondersteund zolang de bijbehorende code niet wordt vervangen of ingetrokken. Hun token blijft uitsluitend een bronkenmerk en slaat de code-invoer nooit over. Nieuwe codes hebben geen speciale deellink nodig.

De tab **Activiteit** toont tijd (UTC), soort en bron/account, plus eerste/laatste bezichtiging en aantal sessies binnen het bewaarde log. Het onderscheid is:

- Anonieme preview via deellink of losse code: geen persoonsnaam, IP-analyse of fingerprint.
- Voorpagina bekeken door het gekoppelde persoonlijke klantaccount.
- Succesvolle login van dat klantaccount.
- Werkelijk openen van het beheer met dat account, afzonderlijk van login.

Elk type telt eenmaal per sessie; een nieuwe sessie wordt opnieuw zichtbaar. Superadminbezoeken worden overgeslagen. Eigen tests met het klantaccount zijn niet van klantbezoeken te onderscheiden: pauzeer registratie daarvoor expliciet. Een gedeelde link/code bewijst geen identiteit. De codepagina, login en voorstelvoet tonen uitleg over registratie. Een aangepast thema moet `wp_footer()` aanroepen; anders kan de plugin een frontbezichtiging niet betrouwbaar vastleggen. Het log bewijst een gerenderde pagina, niet dat een mens de inhoud heeft gelezen.

Maximaal 100 gebeurtenissen en 500 gehashte deduplicatiesleutels, gedurende 30 dagen. Opschonen gebeurt bij lezen/schrijven en dagelijks via WP-Cron, ook voor lokaal opgeslagen statusgegevens. WP-Cron is afhankelijk van verkeer of een ingestelde servercron. Backups vallen onder het afzonderlijke hosting-retentiebeleid. De twee minimale meldingsstatussen blijven behouden om herhaling te voorkomen. Bij opslagfouten blijft de bezoekpagina werken, maar kan een gebeurtenis ontbreken. Er is geen uitgebreide analyticsdienst.

## Beheerdersmeldingen

De ontvanger is standaard leeg en beide meldingen staan uit. Vul zelf het bedoelde beheerderadres in en bevestig dit voordat je een melding aanzet. Kies apart voor de eerste nieuwe bezichtiging en/of eerste nieuwe succesvolle klantlogin na inschakelen. Opslaan verzendt niets; oude gebeurtenissen worden nooit ingehaald. Per voorstel wordt elke soort maximaal eenmaal aangeboden. Na een mislukte of onzekere verzending volgt geen automatische herhaling, ook niet na opnieuw inschakelen of codevernieuwing. Herpublicatie/herstel behoudt de instellingen en activiteit.

Deze twee expliciete beheerdersmeldingen hebben een smalle uitzondering op `wp_mail`-blokkering: uitsluitend de intern opgebouwde ontvanger, onderwerp en inhoud mogen passeren. De gewone site-mailstand blijft uit; klantmail, formulieren en automatische accountmail blijven geblokkeerd. Er wordt geen SMTP-provider geconfigureerd. Een provider die via de WordPress HTTP API verzendt kan nog door de bestaande integratieblokkade worden gestopt; test de bedoelde online mailvoorziening apart. De status 'aangeboden' is geen aflevergarantie.

`activity.php` controleert onder meer herkenbare versus anonieme sessies, afzonderlijke login/dashboardopening, duplicaten, superadminuitsluiting, pauze, expliciete ontvanger, de smalle mailuitzondering, mislukte verzending, rotatie/intrekking en begrensde retentie. Alle testmail is onderschept.

## Voorstelmail kopiëren (0.5.8)

Open Preview & klanttoegang → Voorstelmail maken. Controleer de voornaam en bedrijfsnaam en vul werkzaamheden, kosten/btw/periodieke kosten en de uiterste bedenkdatum in. Vink aan of je zelf een screenshot toevoegt. Mail maken toont een bewerkbaar onderwerp en tekst met aparte kopieerknoppen. Er wordt geen mail verzonden en geen screenshot of PDF gemaakt. De invoer blijft per site bewaard, zonder de gegenereerde toegangsinformatie.

De mail gebruikt de vaste code en gewone sitelink. Voor een ongebruikt klantaccount wordt bij deze bewuste actie een nieuwe instellink aangemaakt; de vorige vervalt en de nieuwe heeft een termijn van maximaal 24 uur. Die termijn staat in de mail en verandert de bedenktijd niet. Voor een reeds gebruikt account bevat de mail de bestaande inloglink; het wachtwoord wordt niet veranderd. Opnieuw kopiëren of de tekst bewerken maakt geen nieuwe link.

Activiteitregistratie wordt niet automatisch ingeschakeld. Indien ingeschakeld telt een daadwerkelijk bekeken pagina na geldige code-invoer als anonieme preview; een persoonlijke klantlogin wordt afzonderlijk vastgelegd. Een screenshot of codepagina openen bewijst geen bezichtiging.

## Centrale mailtemplate (0.5.9)

Ga naar **Site Factory → Mailtemplate**. Daar beheer je het onderwerp en de volledige mail voor alle subsites van dat netwerk. Ook de tekst voor een nieuw account, een bestaand account en de optionele screenshot is daar aanpasbaar. De bestaande Jeffrey/Emonks-mail is de standaard; een pluginupdate overschrijft opgeslagen wijzigingen niet.

Klik in het gewenste templateveld en daarna op een invulcode. Beschikbaar: `[voornaam]`, `[bedrijfsnaam]`, `[werkzaamheden]`, `[kosten]`, `[bedenkdatum]`, `[sitelink]`, `[previewcode]`, `[email]`, `[gebruikersnaam]`, `[inloglink]`, `[instellink]`, `[instellink_geldig_tot]`, `[redacteurtoegang]`, `[screenshot]`. Gebruik in de hoofdtekst bij voorkeur `[redacteurtoegang]`: dit kiest automatisch de uitnodiging of bestaande login. `[screenshot]` wordt alleen ingevuld als het vakje bij de site is aangevinkt. Instellinkcodes zijn alleen gevuld voor een nieuw account. Deze invulcodes vervangen uitsluitend tekst; WordPress-shortcodes of PHP worden niet uitgevoerd. Een onbekende invulcode wordt bij opslaan gemeld.

Stel centraal de standaard werkzaamheden, kostentekst en bedenktijd in dagen in. Bedragen worden niet vooraf ingevuld; vermeld zelf btw en periodieke kosten. De standaard bedenktijd is 14 dagen en kan worden gewijzigd. Op de site laat je werkzaamheden/kosten/datum leeg om steeds de actuele centrale waarden te gebruiken. De datum wordt dan bij het maken van de mail berekend. Vul een eigen waarde in voor een uitzondering; maak dat veld leeg om weer de centrale standaard te gebruiken. Eerder ingevulde waarden uit 0.5.8 blijven als siteafwijkingen staan. Voornaam en bedrijfsnaam blijven per site.

Een wijziging werkt door in iedere volgende gegenereerde mail, ook voor bestaande subsites die de standaarden volgen. Reeds gekopieerde of verstuurde mails worden niet gewijzigd. De template bevat geen opgeslagen previewcodes of instellinks. Bewerken of opslaan van de centrale template maakt geen accounts, toegangscodes of uitnodigingen en verstuurt geen mail.

Beheer dit bij voorkeur op het lokale netwerk als je daar je mails samenstelt. Het online netwerk bewaart zijn eigen netwerktemplate; er is geen automatische synchronisatie tussen installaties. Binnen één netwerk is er één centrale template voor alle subsites.
