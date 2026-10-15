# Site Factory 0.4 — online publicatie (reviewversie)

Dit is de zelfstandige **Emonks Site Factory**-plugin, versie 0.4.0. Deze reviewversie is lokaal getest; er is geen online hostingdeployment uitgevoerd. Online bereikbaarheid, serverconfiguratie, caching, licenties en mailaflevering zijn niet geverifieerd.

## Wat is gebouwd?

Dezelfde plugin draait lokaal als bouwer en online als ontvanger. De bestaande lokale werkstroom blijft bestaan. Netwerkbeheer krijgt lokaal **Sites** als centraal overzicht, met één link per beheerde rij naar het eigen Site Factory-scherm en **Sites → Nieuwe lokale site / Online verbinding**. De ontvanger werkt uitsluitend op de hoofdsite via WordPress Application Passwords voor een netwerkbeheerder, over HTTPS. Browsercookies zijn onvoldoende voor deze API.

- **Online site aanmaken** maakt een nieuwe privé-subsite onder `/prospectslug/`. Een bestaande slug of bronkoppeling wordt geweigerd; de plugin adopteert nooit stil een bestaande site.
- **Online inhoud vervangen** vraagt altijd de exacte huidige online sitenaam. De pagina waarschuwt dat online wijzigingen verloren gaan, ook bij de eerste vervanging. De doelsite en vervangingsscope zijn zichtbaar. Eerst volgt een herstelkopie, dan pas mutatie.
- **Status** toont de echte online koppeling, toestand, laatste operatie-ID en beschikbaarheid van een herstelkopie.
- **Archiveren / herstellen uit archief** maakt de subsite ontoegankelijk voor klanten / weer privé beschikbaar. Dit is een Factory-status; er wordt geen WordPress-netwerkarchiefvlag gebruikt, zodat de beheerverbinding bereikbaar blijft.
- **Herstelkopie terugzetten** vraagt sitenaam en operatie-ID en maakt ook vóór herstel een veiligheidskopie.
- **Klantaccount bij eerste publicatie**: vóór publicatie staan bewerkbare klantnaam, gebruikersnaam en e-mailadres op het scherm voor uitsluitend de geselecteerde site. Naam/e-mail blijven leeg tenzij expliciet eerder bevestigd; de slug is alleen een bewerkbare gebruikersnaamsuggestie. De beheerder moet bevestigen dat de gegevens voor deze klant zijn. De ontvanger valideert opnieuw en weigert bestaande netwerkgebruikersnamen/e-mailadressen. Een nieuw account krijgt alleen `editor` op deze subsite, zonder hoofdsite-rol. Een sterk willekeurig wachtwoord wordt niet gedeeld of opgeslagen in exports/journals. **Er wordt geen WordPress-mail verstuurd**, ook niet vanuit gebruikers-, site- of reset-key-hooks gedurende deze operatie.
- **Toegang handmatig delen**: na publicatie toont de beheerinterface de gekozen klant, gebruikersnaam en inlog-URL. Kies daarna bewust **Instellink maken — geen e-mail**; de gewone WordPress-wachtwoord-instellink wordt eenmalig getoond in een selecteerbaar/kopieerbaar veld. Standaard 24 uur geldig (een kortere ingestelde WordPress-termijn wordt gerespecteerd). Alleen voor het door deze bron aangemaakte account dat nog niet heeft ingelogd of zijn wachtwoord heeft ingesteld. Een nieuwe link vervangt de vorige. De link wordt niet in journals, exports, statusantwoorden of lokale opgeslagen koppelingen gezet. Het resultaat krijgt no-cache en no-referrer headers. Deel de link alleen in de eigen persoonlijke mail. Bij verlies kan bewust een nieuwe link worden gemaakt; publicatie/retry/repush doet dit nooit automatisch.
- **Bestaande klanttoegang bij vervanging** blijft volledig behouden. Geen rolwijziging, nieuw wachtwoord, instellink of automatische mail. Een reeds bestaand of al gebruikt account wordt niet via de instellinkactie gereset; de klant kan zelf de normale WordPress-functie Wachtwoord vergeten gebruiken. De eerdere `invite`-actie is verwijderd.
- **Extra layouttoegang** is een expliciete optie vóór de eerste publicatie. Zij gebruikt de bestaande `options_skeletor_theme_options_roles` / `manage_skeletor_options`-route in het thema en geldt alleen voor redacteuren van deze subsite. Geen netwerkbeheer of `manage_options` toevoegen.
- **Definitief verwijderen** gaat in de beheerinterface met één rode knop per omgeving, zonder typvelden of vooraf archiveren. De interface stuurt de sitenaam en scopebevestiging automatisch mee; CLI/API blijven deze waarden controleren. Online sites moeten gereed, gearchiveerd of mislukt zijn, niet bezig met import of herstel. Er wordt eerst een herstelkopie gemaakt. De subsite en bijbehorende lidmaatschappen verdwijnen; netwerkaccounts en andere subsites blijven bestaan. Een verwijderde site automatisch hercreëren is niet geïmplementeerd; de bronkoppeling blijft als tombstone bewaard.

## E-mail per subsite

In Site Factory → E-mail toont elke omgeving **E-mail uit (voorstel)** of **E-mail aan**. De instellingen gelden uitsluitend voor de gekozen site. Lokaal en online zijn afzonderlijk gelabeld; de schakelaar verandert nooit automatisch de andere omgeving. Alleen de netwerkbeheerder kan de keuze opslaan. Nieuwe lokale concepten en nieuwe online voorstellen starten met `emsf_mail_mode=off`. Vervangen en herstellen behouden deze instelling in site-meta. Bestaande Factory-demo’s waren al stil; ontbrekende legacy-instellingen vallen alleen voor die demo’s terug op uit. Onbeheerde sites en de netwerkhoofdsite worden niet globaal geblokkeerd.

- **Uit:** `wp_mail` wordt vóór transport afgevangen en het bericht direct weggegooid, zonder inhoudslog of verzendwachtrij. De afhandeling retourneert succes om herhaalpogingen door normale mailcallers te voorkomen. Publicatie/accountaanmaak gebruiken daarnaast altijd een aparte stille scope. Standaard WordPress-welkomstmeldingen voor Factory-klantaccounts zijn ook bij een uitgestelde oproep uitgeschakeld.
- **Fluent Forms uit:** validatie en een extra controle vóór invoegen stoppen voorstellen vóór inzending/opslag/integratiequeues; de bezoeker ziet dat het voorstel geen berichten verstuurt. De integratie-feedselectie is leeg. De onderzochte Fluent Forms 6.2.3-notificatiecode gebruikt `wp_mail` en valt onder de mailcontrole. Hiermee ontstaat tijdens de uit-stand geen Fluent Forms-mailachterstand.
- **Aan:** nieuwe normale WordPress-herstelmails en ingestelde formuliermails mogen via de aanwezige mailvoorziening worden verstuurd. De schakelaar stelt geen ontvangers in en configureert geen SMTP-provider. Geïmporteerde formulieren bevatten nog steeds geen oorspronkelijke notificatie-/integratieconfiguratie: stel de bedoelde meldingen en ontvangers bewust in Fluent Forms in. Omschakelen start geen replay, flush, cronjob of verzending.
- **Netwerk-wachtwoordreset:** links vanaf een beheerde subsite dragen een ondertekende subsitecontext die op het resetformulier behouden blijft. De instelling geldt alleen als het account werkelijk bij die site hoort. Op de generieke netwerklogin wordt uitsluitend een ondubbelzinnig account met één beheerde subsite aan die site gekoppeld. Voor gedeelde accounts met meerdere sites gebruikt een contextloze reset de normale netwerkwerking; start bij een specifieke subsite om haar beleid toe te passen. Een onjuiste context kan geen andere klantmail blokkeren. Netwerkbeheerders houden netwerkherstel. De openbare uitleg is niet afhankelijk van het ingevoerde account en introduceert geen nieuwe accountstatusmelding.
- **Handmatige instellink:** blijft zonder mail beschikbaar tijdens de voorstelstand. Bij een al gebruikt account kan de beheerder e-mail voor de site aanzetten, waarna de klant zelf normaal Wachtwoord vergeten gebruikt. Er wordt bij inschakelen niets automatisch verzonden.

De plugin blokkeert in uit-stand ook provider-/integratieverzoeken via de WordPress HTTP API voor die subsite; de expliciete Factory-verbinding en bekende WordPress-update-/font-hosts zijn uitgezonderd. Dit is **geen netwerkfirewall**. Een plugin die zelf native PHP `mail()`, eigen SMTP-sockets, cURL of een externe queue buiten WordPress gebruikt kan dit omzeilen. Die routes zijn niet gecertificeerd; controleer/disableer dergelijke integraties voor een voorstel of gebruik aanvullende server-egressblokkering. De bestaande lokale installatie heeft ACF, Fluent Forms/Pro, Site Factory en de afzonderlijke netwerkhomepage; er is geen aparte SMTP-mailplugin gevonden. De actuele Fluent Forms-basisroute is in een wegwerpomgeving getest. Eigen Pro/CRM-integraties of externe, reeds bestaande queues vereisen afzonderlijke controle en worden niet door de schakelaar herschreven.

## Installatie en koppeling

1. Installeer dezelfde pluginmap de repository-root als `wp-content/plugins/emonks-site-factory` op beide netwerken en activeer netwerkbreed.
2. Publiceer **het gedeelde Skeletor-thema afzonderlijk**, inclusief zijn normale dependencies. Een site-push bevat nooit themabestanden. De ontvanger vereist vanaf Site Factory 0.11.0 hetzelfde geldige themaversienummer als het pakket; oudere, nieuwere of ontbrekende versies blokkeren publicatie. De digest van `src`, `views`, `acf-json`, `dist`, `style.css` en `functions.php` blijft aanvullende informatie en blokkeert niet bij gelijke versies. Overige thema-assets en vendor-dependencies moeten via de eigen themadeployment overeenkomen.
3. Installeer dezelfde ACF Pro-versie, netwerkbreed. Gebruik Fluent Forms netwerkbreed wanneer pakketten formulieren bevatten; zijn normale nieuwe-site-initialisatie maakt de lokale formuliertabellen. Fluent Forms Pro/licenties indien inhoud die vereist. De plugin transporteert geen pluginbestanden of licenties.
4. Maak online een schrijfbare private map, **buiten alle publiek bereikbare documentroots en aliassen**. Controleer dat geen andere website onder hetzelfde hostingaccount deze map ontsluit. Houd de map persistent, inclusief uploads, modellen, journals en backups, en neem haar op in een onafhankelijke hostingbackup.
5. Voeg online toe aan `wp-config.php` (pad invullen):

```php
define('EMSF_ONLINE', true);
define('EMSF_STATE_DIR', '/absoluut/pad/buiten/public_html/factory-private');
// Pas na onderstaande hostingcontroles op true zetten:
define('EMSF_PRIVATE_HOSTING_VERIFIED', true);
```

Online mag `EMSF_LOCAL` niet `true` zijn. Lokaal blijven de bestaande `EMSF_LOCAL`, `EMSF_STATE_DIR` en `WP_ENVIRONMENT_TYPE=local` gelden; `EMSF_ONLINE` hoort daar niet aan.

6. Maak bewust een apart WordPress **applicatiewachtwoord** aan voor een netwerkbeheeraccount. De API vereist netwerkbeheer; dit credential heeft dus netwerkbrede bevoegdheden en hoort alleen in de lokale configuratie, niet in Git, pakketten of browserformulieren. De eerste versie beperkt niet tot één Application Password UUID. Intrekken gebeurt via het WordPress-gebruikersprofiel.
7. Configureer lokaal, zonder waarden te committen:

```php
define('EMSF_REMOTE_URL', 'https://voorstellen.example.com');
define('EMSF_REMOTE_USER', 'netwerkbeheer-login');
define('EMSF_REMOTE_PASSWORD', 'applicatiewachtwoord');
```

8. Controleer **Status** voor één lokale site. Test vervolgens met een wegwerp-prospect en een afzonderlijk klantaccount. Maak pas echte online klantaccounts/sites op concrete publicatieopdracht. Deze implementatie heeft geen dergelijke externe handelingen uitgevoerd.

Bronnen: [WordPress REST-authenticatie](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) en [Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/).

## Privacy en SiteGround-controles

Nieuwe Factory-subsites zijn altijd afgeschermd. Vanaf 0.4 is naast persoonlijk lidmaatschap optioneel een viertekens-previewcode mogelijk voor lichte voorsteltoegang. Zie [codes, raadlimieten en sessies](PREVIEW.md). Toegang op één site geeft geen toegang tot andere sites. De plugin controleert normale pagina’s, feeds, AJAX, REST en XML-RPC via WordPress. De login-/wachtwoordinstelpagina blijft bereikbaar. Er is geen openbare/live-klantsite-modus in deze versie.

Uploads staan fysiek onder `EMSF_STATE_DIR/uploads/<site-id>/`, nooit in de publieke uploadsmap. Media-URL’s lopen via `/klantnaam/emsf-media/...`; de plugin controleert daar de ingelogde gebruiker en het juiste subsite-lidmaatschap vóór bestandsuitlevering. Dit geldt ook voor fontbestanden en gegenereerde afbeeldingen. Bestanden krijgen private/no-cache-headers. Padtraversal wordt geweigerd. Het gehele voorstel krijgt noindex, maar **noindex is niet de toegangsbeveiliging**.

Controleer vóór het zetten van `EMSF_PRIVATE_HOSTING_VERIFIED`:

- SiteGround Dynamic Cache, CDN/edge cache en eventuele WordPress-page-cache mogen **geen** Factory-subsite of `emsf-media`-route cachen. PHP-controles kunnen een reeds door de webserver/CDN gecachte pagina niet tegenhouden. Purge bestaande caches.
- De private state-map is ook via alternatieve domeinen, aliases en statische file-serving onbereikbaar. Alleen “buiten ABSPATH” is onvoldoende wanneer WordPress in een submap van public_html staat. De code controleert ABSPATH; de beheerder moet de daadwerkelijke documentroots controleren.
- WordPress submap-rewrites sturen ook niet-bestaande fysieke `emsf-media`-paden naar WordPress. Bestaande publieke kopieën van oude uploads worden niet automatisch opgespoord. Gebruik deze ontvanger voor nieuwe lege subsites; publiceer geen kopieën van private media elders.
- Test uitgelogd én als klant van een andere subsite: pagina, feed, REST, bekende originele media-URL, thumbnail en font. Test als eigen klant dat preview, dashboard, upload en layouteditor werken. Controleer cacheheaders en herhaal na een ingelogd bezoek.
- HTTPS en de Authorization-header komen ongewijzigd bij WordPress aan; redirects bij de API worden door de client geweigerd.

Privébestanden via PHP kosten meer servercapaciteit. PDF-rangeverzoeken en media-offload/CDN worden niet ondersteund. Hosting-specifieke webserverconfiguratie en cache-uitsluitingen blijven noodzakelijk. De plugin kan hostingprivacy niet automatisch certificeren. Verwijder of deactiveer de netwerkplugin niet terwijl private sites bereikbaar zijn: de WordPress-paginabeveiliging hangt hiervan af (de fysieke private media blijven buiten de webroot).

## Opslag en overdrachtsgrenzen

De bestaande `Package`-adapter wordt hergebruikt:

| Onderdeel | Gedrag |
|---|---|
| Posts/pagina’s/Skeletor-stroken/teasers/custom typen | Nieuwe doel-ID’s; relaties via pakketreferenties |
| ACF-postmeta en ACF-opties | Raw waarden, veldreferenties en ondersteunde relatie-/media-/taxonomievelden vertaald |
| Termen, hiërarchie, menu’s en menu-locaties | ID’s en URL’s vertaald |
| Media, thumbnails, fonts | Bestandscontrolesommen, nieuwe private upload-URL’s, thumbnails opnieuw gegenereerd |
| Projectmodellen | Per-site JSON buiten het gedeelde thema; bestaande projectdefinities vervangen |
| Homepage, titel, ondertitel, permalinkinstelling, favicon | Expliciete opties-allowlist |
| Fluent Forms | Alleen definities en beperkte instellingen; geen submissions, notificaties, webhooks of integraties |
| Gebruikers, wachtwoorden, lidmaatschappen, rollen | Nooit onderdeel van inhoudsoverdracht; online waarden blijven behouden |
| Thema/pluginbestanden, globale/netwerktabellen | Nooit overgedragen |

Onbekende postmetadata en custom tabellen, termmetadata, WooCommerce/Polylang en herkende niet-vertaalde Gutenberg-/HTML-ID’s blokkeren online publicatie. ACF-gebruikersvelden vereisen een extra accountadapter. Dit is geen algemene databasekopie en niet elk willekeurig pluginveld wordt ondersteund. De bestaande export-allowlist sluit overige postmeta en niet-geselecteerde opties uit. Overgeslagen postmeta komt nu in de blokkerende inventaris; niet-geselecteerde WordPress/plugin-opties blijven buiten de overdracht. Inventariseer nieuwe plugins/schema’s vóór gebruik. Reacties worden niet geëxporteerd en bij expliciete vervanging verwijderd. Formulierinzendingen op de doelsite blokkeren vervanging zodat hun relaties niet worden beschadigd. Er is geen automatische conversie naar standalone WordPress en geen bidirectionele sync.

Lokaal en online hebben dezelfde export/importstructuur, zodat een latere standalone-adapter daarop kan voortbouwen. Online themawijzigingen worden nooit via een individuele site-push toegepast.

## Onderbrekingen, backups en herstel

De ontvanger gebruikt een filesystem-lock per netwerk, private JSON-journals met atomische rename, één bron-UUID per gekoppelde subsite en een operatie-UUID per inhoudsversie. Uploads zijn checksum-gecontroleerd; dezelfde operatie met gewijzigde payload wordt geweigerd. De lokale client bewaart het pakket en operatie-ID vóór verzending. Hervatten gebruikt exact dat pakket, niet ondertussen gewijzigde lokale inhoud.

`receiving → creating/backing_up → applying → complete`. Een fout tijdens toepassen geeft `failed`; klanten blijven buitengesloten. WordPress-databasewijzigingen lopen in een transactie op InnoDB. Vóór mutatie wordt een teruggelezen kopie gemaakt van **alle tabellen met exact het subsite-prefix**, alle private uploads en projectmodellen. Globale gebruikers-/usermetatabellen en andere sites vallen er niet onder. Voor herstel worden bestandscontrolesommen en tabelschema’s gecontroleerd. Restore behoudt netwerkaccounts en lidmaatschappen, vervangt site-tabellen en zet de opgeslagen bestanden terug. Database en filesystem vormen geen gezamenlijke transactie: na een fout kunnen bestanden ontbreken totdat expliciet herstel is uitgevoerd; daarom blijft de site dicht.

- `receiving`: voer dezelfde actie opnieuw uit; ontbrekende bestanden worden opnieuw gestuurd. Of annuleer met operatie-ID en sitenaam.
- Mislukte backup vóór bestaande inhoud is aangeraakt: geen overwrite, oorspronkelijke site blijft beschikbaar. Annuleer de mislukte voorbereiding en herstel opslag voordat je opnieuw begint.
- `applying`, `failed`, of een onderbroken `backing_up` mét backup: kies **Herstelkopie terugzetten** met de betreffende operatie-ID. Geen automatische replay van een onduidelijke commit.
- `complete` maar verbinding viel weg: dezelfde operatie retourneert de bestaande site. Een specifiek gecontroleerd commit/journal-interval wordt op retry afgerond zonder nieuwe import.
- `creating` of `backing_up` zonder geregistreerde backup na een harde processtop: handmatige controle door de netwerkbeheerder is vereist. De gereserveerde koppeling wordt niet automatisch verwijderd of overgenomen. Inspecteer journal, bron-UUID/site-meta en netwerk-sitegegevens; geef nooit blind dezelfde slug opnieuw vrij.
- Herstel van een definitief verwijderde subsite: handmatig door een beheerder uit de private backup plus hostingbackup. De ingebouwde restore vereist nog bestaande tabellen met hetzelfde schema.

Lokale acties in Sites zijn afzonderlijk gelabeld: lokaal archiveren/herstellen raakt geen online site. Definitief lokaal verwijderen vereist eerst lokaal archiveren, de exacte lokale sitenaam en `DEFINITIEF LOKAAL VERWIJDEREN`; eerst worden de subsite-tabellen, uploads en projectmodellen gecontroleerd geback-upt. Het prospectdossier en de online koppeling blijven bewaard, zodat online niet wordt verwijderd. Deze lokale verwijderbackup vereist handmatig herstel, net als een verwijderde online site. De native destructieve bulkacties worden niet aangeboden; er is nooit een bulkpublicatie. De bestaande Nieuwe toevoegen-knop boven de Sites-tabel opent lokaal de conceptaanmaak, zonder online actie of klantaccount.

Geen automatische backupretentie of opruimer. Bewaarde pakketten/backups kunnen persoonsgegevens en online wijzigingen bevatten. Beheer bewaartermijnen, toegangsrechten en vrije schijfruimte bewust. Eén server met een lokale, betrouwbare filesystem-lock is vereist; geen multi-webnode/NFS-garantie. Imports/backups draaien synchroon: PHP-/proxytime-outs en beschikbare RAM/schijfruimte bepalen de praktische capaciteit. Limieten: 8 MiB per bestand, 256 MiB totaal aan pakketbestanden, 12 MiB JSON per API-verzoek. Grote datasets vergen een latere queue/chunk-adapter. De ingebouwde kopie vervangt geen onafhankelijke hostingbackup.

Tijdens publicatie/herstel geen beheerwrites, cronjobs of externe writers op de betrokken subsite uitvoeren. De privacygate blokkeert normale gebruikersverzoeken tijdens mutatie, maar een reeds lopend verzoek of los databaseproces kan daarbuiten vallen. Gebruik een beheervenster; dit is geen generieke live-databasereplicatie.

## CLI

Gebruik WP-CLI op het lokale netwerk, met `--path=<WordPress-pad> --user=<netwerkbeheerder>`:

```sh
wp emonks factory online status klantnaam
wp emonks factory online create klantnaam --customer-name='Naam contactpersoon' --customer-username='gekozen-login' --customer-email='gecontroleerd@example.nl' --customer-confirmed
wp emonks factory online replace klantnaam --confirm='Exacte online sitenaam'
wp emonks factory online manage klantnaam archive --confirm='Exacte online sitenaam'
wp emonks factory online manage klantnaam unarchive --confirm='Exacte online sitenaam'
wp emonks factory online manage klantnaam restore --confirm='Exacte online sitenaam' --operation='UUID uit status'
wp emonks factory online manage klantnaam cancel --confirm='Exacte sitenaam' --operation='UUID uit status'
# Alleen de link tonen voor handmatig delen, nooit mail versturen:
wp emonks factory online manage klantnaam setup_link --confirm='Exacte online sitenaam'
# Alleen bij een bewuste verwijderopdracht:
wp emonks factory online manage klantnaam delete --confirm='Exacte online sitenaam' --delete-confirm='DEFINITIEF VERWIJDEREN'
```

Voeg desgewenst `--layout-access` toe aan de eerste create-actie. Bij een fout: eerst status ophalen; geen parallelle opdracht met een nieuwe operatie-ID starten.

## Gevalideerd en nog te valideren

Op 17 september 2026 lokaal getest met PHP 8.3, WordPress Multisite, ACF Pro en een volledig afzonderlijke MySQL 5.7-testserver, zonder netwerklistener op de database, bestaande sites of echte mail:

- `tests/online-integration.php`: 74 assertions, waaronder echte create/replace/restore/delete, idempotentie, verkeerde bevestigingen, media/menu/term-ID’s, submap-URL’s, backupfout vóór overwrite, corrupt herstelbestand, fout midden in import, accountbehoud, andere-site-isolatie en het ontbreken van automatische mail bij accountaanmaak/publicatie/instellink.
- `tests/http-privacy.py`: 13 geslaagde controles in een aparte lokale HTTP-fixture voor anonieme/andere-klanttoegang, eigen-klantmedia, padtraversal en geweigerde receiver-toegang via klantcookies. TLS wordt in deze fixture gesimuleerd; dit is geen TLS-/SiteGround-test.
- `tests/client-retry.php`: verloren remote-antwoord gesimuleerd, exact hetzelfde pakket/operatie-ID hervat ondanks tussentijdse lokale wijziging, doelkoppeling behouden en vijf onderschepte HTTP-verzoeken gecontroleerd op HTTPS/TLS/redirectinstellingen.
- `tests/sites-and-silent-accounts.php`: Sites-acties, afzonderlijke lokale concepten, selectie/permissies, expliciete klantgegevens, lokale archive/restore/delete met backup, geen remote calls voor lokale acties, en actief geïnjecteerde WordPress-welkomst-/reset-hooks waarvan verzending wordt geblokkeerd.
- `tests/mail-policy.php`: twintig controles met echte Fluent Forms 6.2.3-code, mail uit/aan, WordPress-HTTP-providerblokkade, geen replay, accountstilte ondanks mail-aan, reset via netwerk/main-site, ondertekende/onjuiste contexten, gedeelde accounts en isolatie van andere site-/netwerkmail. Alle toegestane berichten zijn door een testtransport onderschept.
- PHP-syntaxcontrole op alle pluginbestanden.

De integratietest weigert te starten zonder `EMSF_DISPOSABLE_TEST=yes`, een pad met `emsf-integration` en een leeg netwerk met precies één site. Hij is destructief voor zijn eigen fixtures; draai hem nooit tegen bestaande data. Voorzie een minimale Skeletor-testtheme met versie `test`, de nieuwe plugin, ACF Pro, `EMSF_ONLINE`, een private state-map en een mu-plugin die `pre_wp_mail` onderschept (`$GLOBALS['test_mail_count']` verhogen) en uitgaande HTTP blokkeert. Start dan `wp --user=<testnetwerkbeheerder> eval-file tests/online-integration.php`.

Nog geen end-to-endtest van lokale client → echt HTTPS/SiteGround, volledige bestaande prospectpakketten, Fluent Forms Pro, de volledige Skeletor-editor in een browser, harde proceskill/disk-full op productiehosting of gelijktijdige externe writers. Behandel deze oplevering daarom als review-/pilotversie, niet als productie-gecertificeerde migratie.

## Versie 0.4

De nieuwe previewtoegang en de herziene beheeromgeving staan beschreven in [PREVIEW.md](PREVIEW.md). Gebruik die handleiding voor upgrade, korte codes, beveiligingsgrenzen en de eerste hostingpilot.

## Inventorycorrectie voor bestaande lokale voorstellen (0.4)

De exporter laadt na een multisite-blogwissel de projectspecifieke ACF-definities en berichttypen voordat velden worden vertaald. Dynamische strookvelden worden via de bestaande themafilter geladen; relationship/taxonomy-waarden blijven echte pakketreferenties. ACF `_stroken_layout_meta` behoudt uitsluitend bekende disabled-rijindices en renamed-labels; dit zijn geen bericht-ID's. Uitlijning, de historische teaser-hovervelden en `referentie/beoordeling` (0–5) hebben gerichte adapters. Andere onbekende metadata blijft blokkeren.

Exact de vier `actionscheduler_actions/claims/groups/logs`-tabellen en `ff_scheduled_actions` zijn achtergrondtaken/logs, geen overdraagbare content. Het pakket meldt alleen hun aantallen onder `excluded_runtime_tables`; hun inhoud, wachtrij en acties worden niet geëxporteerd, geïmporteerd of opnieuw afgespeeld. Andere gelijkende/onbekende tabellen blijven blokkeren. `_pingme`/`_encloseme` zijn WordPress-taakvlaggen en worden eveneens uitgesloten. `_wp_old_slug` blijft behouden. `_has_fluentform` is een afgeleide cache: de bron-ID's worden niet gekopieerd; Fluent Forms bouwt die tijdens opslaan opnieuw uit de vertaalde shortcode op.

Een volledige lokale export-/checksum-/referentiecontrole van alle elf bestaande voorstellen gaf na deze correctie geen onbekende tabellen of metadata meer. Dit is geen online publicatie of volledige hostingacceptatie. Na de lokale update kan de eerder geblokkeerde publicatie opnieuw bewust gestart worden; de eerdere inventoryfout trad op vóór de remote upload/aanmaak. Installeer voor de nieuwe toegang/activiteitfuncties ook de meegeleverde 0.4-update online.

## Hoofdletters, inline validatie en veilige bestandsnamen

Klantgegevens worden nu vóór verzending direct in het formulier gecontroleerd. Fouten staan per veld, met focus op de eerste fout, `aria-invalid` en foutomschrijving; waarden en de Publiceren-tab blijven behouden. Servervalidatie blijft gelden en toont fouten op dezelfde beheerpagina. Een bestaande online gebruikersnaam en een bestaand e-mailadres geven verschillende meldingen; er is geen voorafgaande belofte over beschikbaarheid. WordPress Multisite normaliseert gebruikersnamen naar kleine letters: dat gebeurt nu vóór onze controle, met uitleg naast het veld. De contactnaam verandert niet.

Een gewone WordPress-bestandsnaamsanitizer kan bijvoorbeeld een onzichtbaar zacht koppelteken behouden. De export zet mediabasenamen daarom expliciet om naar ASCII en controleert **alle** pakketbestanden met de strikte ontvangerregel vóór verzending. Geen versoepeling voor traversal, procentcodes, backslashes of absolute paden. De originele lokale media blijven intact; alleen namen binnen het nieuwe pakket veranderen. Niet-canonieke fontboom-paden stoppen lokaal met een gerichte melding in plaats van online.

Een eerder gevormd pending-pakket wordt nooit onder hetzelfde operatie-ID herschreven. Bij een oude ongeldige naam: controleer eerst Status; annuleer vervolgens uitsluitend die upload onder Beheer met haar operatie-ID en exacte sitenaam; start daarna bewust een nieuwe export/publicatie. De melding uit `prepare` over een onveilig pad wordt gegeven vóór een nieuw remote receiving-journal/site wordt aangemaakt. Controleer de actuele status toch voordat je herstelacties uitvoert. Deze specifieke ASCII-correctie is lokaal; dezelfde 0.4-versie online is wel nodig voor alle nieuwe code/deellink/activiteitfuncties. Er is geen automatische retry, annulering of online deployment uitgevoerd.
