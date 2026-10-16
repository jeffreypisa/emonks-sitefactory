# Emonks Site Factory

WordPress Multisite-plugin voor lokale Skeletor-concepten en afzonderlijke privépublicatie naar een online voorstelnetwerk. Versie **0.14.2 — hostingpilot vereist**.

Beheer via **Netwerkbeheer → Site Factory → Alle sites**: lokaal concept aanmaken, per site publiceren/vervangen, klanttoegang handmatig delen, e-mail uit/aan, archiveren en herstellen. Geen bulkpublicatie. Nieuwe voorstellen starten met e-mail uit. Accountaanmaak/publicatie versturen geen WordPress-mail, ook wanneer normale site-mail is aangezet.

Nieuw in 0.4: viertekens-previewcodes met raadlimiet, unieke deellinks die de code verplicht houden, aparte redacteurtoegang, echte verticale beheertabs en een beperkt activiteitenlog met optionele beheerdersmeldingen. De standaard WordPress Sites-lijst blijft herkenbaar. Zie [previewcodes en upgradepad](docs/PREVIEW.md).

## Installatie

1. Gebruik PHP 8.1 of nieuwer, WordPress Multisite, InnoDB en een schrijfbare persistente private opslagmap buiten alle webroots.
2. Installeer het eigen **Skeletor-thema** en **ACF Pro** afzonderlijk. Dezelfde Skeletor-versie en gecontroleerde themabestanden moeten op beide netwerken staan. ACF- en Fluent Forms-versienummers hoeven niet gelijk te zijn; de benodigde plugins moeten wel beschikbaar zijn. Installeer Fluent Forms netwerkbreed wanneer de inhoud formulieren bevat; Pro en licenties alleen wanneer nodig. Deze afhankelijkheden worden niet meegeleverd.
3. Bouw de installeerbare zip met `python3 scripts/build-zip.py`. Upload `dist/emonks-site-factory-0.14.0.zip` via Netwerkbeheer → Plugins en activeer netwerkbreed. Of plaats de repository-root als `wp-content/plugins/emonks-site-factory`.
4. Configureer uitsluitend de passende modus in `wp-config.php`, vóór WordPress wordt geladen. Bewaar configuratie, opslag en credentials buiten Git. Zie [installatie, privacy en hostingcontroles](docs/ONLINE.md).
5. Lokaal: open Sites. Online: verbind pas na hostingcontroles en doe eerst een wegwerppilot. Openbare voorstellen blijven een voorstelomgeving, geen live-klantsitemodus.

Lokaal voorbeeld (eigen pad invullen):

```php
define('WP_ENVIRONMENT_TYPE', 'local');
define('EMSF_LOCAL', true);
define('EMSF_STATE_DIR', '/private/persistent/factory-local');
```

Online voorbeeld (eigen pad invullen):

```php
define('EMSF_ONLINE', true);
define('EMSF_STATE_DIR', '/private/persistent/factory-online');
// Alleen na aantoonbare hosting-/cache-/mediacontrole:
define('EMSF_PRIVATE_HOSTING_VERIFIED', true);
```

Gebruik nooit beide modi op hetzelfde netwerk. De online verbinding gebruikt HTTPS en een WordPress-applicatiewachtwoord van een netwerkbeheerder; vul dit alleen in de lokale privéconfiguratie in, volgens de handleiding.

## Repository en package-root

De **repository-root is de plugin-root**: `emonks-site-factory.php`, `includes/`, `assets/` en `shared-schema.json` zijn runtime. `shared-schema.json` bevat de gedeelde veldstructuur; geen ingevulde klantgegevens. `tests/`, `scripts/`, `docs/` en deze README dienen ontwikkeling en overdracht. De zip bevat alleen runtime plus deze README.

De oorspronkelijke lokale research-, content-, bulkbouw-, favicon-, DNS/MAMP- en prospectspecifieke scripts horen bij de lokale factory-tooling en zijn niet opgenomen. De generieke WordPress CLI/build/package-adapters blijven onderdeel van de plugin. Het Skeletor-thema, ACF/Fluent Forms-code, klantmedia, uploads, databases, backups, journals, licenties en configuratiebestanden horen niet in deze repository.

## Gebruik en grenzen

- Eerste publicatie kan zonder opdrachtgever (standaard: Later instellen), of met een nieuw redacteuraccount. Onder Gebruikers kun je later een account aanmaken en één bestaande gebruiker van die online site als opdrachtgever kiezen, wisselen of loskoppelen. Bestaande netwerkaccounts worden niet automatisch toegevoegd of gereset. Een tijdelijke wachtwoordinstellink kan apart zonder mail worden gemaakt.
- **E-mail uit (voorstel)** blokkeert gewone WordPress-mail (expliciete beheerdersmeldingen onder Activiteit staan hiervan los), onderzochte Fluent Forms-routes en uitgaande WordPress-HTTP-integraties. Inschakelen verstuurt geen achterstallige berichten. Eigen SMTP-sockets, native PHP-mail/cURL en externe queues zijn niet afgedekt: controleer zulke integraties apart.
- Nieuwe online voorstellen en media zijn standaard toegankelijk zonder code. Vink bij eerste publicatie bescherming aan of schakel die later in onder Preview & klanttoegang. Bestaande afscherming blijft behouden. Een viertekenscode biedt lichte voorstelafscherming. Hosting/CDN-cache en private documentroots moeten apart worden gecontroleerd. Noindex is geen toegangsbeveiliging.
- Repush is expliciete inhoudsvervanging met backup, geen bidirectionele synchronisatie. Het thema wordt apart uitgerold. Maximaal 8 MiB per bestand en 256 MiB pakketbestanden; verwerking is synchroon.

## Tests

Zie [testopzet en resultaten](tests/README.md). Getest met PHP 8.3, WordPress Multisite, MySQL 5.7, ACF Pro en Fluent Forms 6.2.3: 74 publicatiecontroles, 35 Sites/accountcontroles, 20 mailcontroles, client-herhaaltest en 13 HTTP-privacycontroles. Daarnaast 35 gerichte previewbeveiligingschecks en 28 preview-HTTP-checks en 27 activiteitcontroles en 22 layout/pakketcontroles en 15 formuliercontroles (269 assertions totaal, plus de client-herhaaltest). Alle mailtransports zijn onderschept. PHP-syntax en de zip-inhoud worden afzonderlijk gecontroleerd. De echte lokale Sites-interface is visueel en functioneel gecontroleerd.

Nog nodig: client → echte HTTPS-hosting, volledige prospectinhoud, cache/CDN-privacy, volledige Skeletor-editor, relevante Pro-integraties, grotere pakketten en herstel na harde serveronderbreking. Deze repository-publicatie installeert niets op hosting en verzendt geen klantmail.

## Nieuw in 0.5

Apart Site Factory-menu met automatisch siteoverzicht, status, aanmaak- en wijzigingsdatum, hervatten en bestaande klantactiviteit. Lokale onlinegegevens zijn een opgeslagen momentopname; vernieuw per site met Status controleren. Previewbezoek is anoniem en wordt niet als bewezen klantbezoek gepresenteerd. Het log blijft beperkt tot 30 dagen/100 gebeurtenissen. Geen nieuwe tracking of automatische mail.

Skeletor heeft vanaf deze uitgave een expliciet versienummer (1.0.0). Verhoog dit bij iedere thema-uitgave en plaats dezelfde uitgave lokaal en online. Vanaf 0.11.0 bepaalt het versienummer of synchronisatie is toegestaan; bestandscontrole is aanvullende informatie. Werk de plugin ook online bij voor versie-informatie bij Status controleren. Bestaande publicatiepakketten blijven ongewijzigd; een verouderd pakket moet na statuscontrole geannuleerd en opnieuw aangemaakt worden.

## Nieuw in 0.5.2

Verschillende ACF- en Fluent Forms-versienummers blokkeren publicatie niet meer. ACF blijft nodig voor import en Fluent Forms wanneer het pakket formulieren bevat. Thema-, pakketformaat- en inhoudscontroles blijven behouden. Sinds 0.5.1 telt Finder-metadata (.DS_Store) niet mee in de themavergelijking.

## Nieuw in 0.5.3

Redundante projectmodellen die gelijk zijn aan de geïnstalleerde themadefinities worden bij export en het lezen van bestaande pakketten overgeslagen. Alleen opslagmetadata en ontbrekende uitgeschakelde/lege ACF AI-standaardvelden worden genormaliseerd. Inhoudelijke verschillen blijven blokkeren vóór projectmodellen worden vervangen. De actuele themadefinities zijn leidend boven de historische gebundelde schema-kopie.

## Nieuw in 0.5.4

Bestaande manifesten blijven ongewijzigd bij inlezen, zodat hervatten dezelfde operatiesleutel behoudt. Modelnormalisatie gebeurt bij export en daadwerkelijke modelimport. Voor de eerdere fout “Projectmodel overschrijft gedeeld thema.” is een expliciete herstelknop toegevoegd: alleen voor dezelfde mislukte eerste publicatie, dezelfde gekoppelde site, zonder aangemaakt klantaccount en met herstelkopie. De oorspronkelijke herstelkopie wordt teruggezet vóór dezelfde inhoud opnieuw wordt geïmporteerd. Alle andere fouttoestanden blijven handmatig herstel vereisen.

## Nieuw in 0.5.5

De import registreert het ingebouwde skeletor_teaser-inhoudstype via de originele themaklasse wanneer de netwerkrequest het thema niet heeft geïnitialiseerd. De gerichte herstelroute accepteert ook deze fout vóór inhoudsimport.

Controle 0.5.5: volledig Belle-pakket (193 inhoudsitems, 212 pakketbestanden) geïmporteerd in een geïsoleerde Multisite met het echte thema maar zonder vooraf geladen themahooks. Teasers en hun rechten, alle 141 bijlage-originelen, fonts, private status en herhaald herstel met hetzelfde account gecontroleerd. Testfixture `tests/belle-full-recovery.php` gebruikt `EMSF_TEST_PACKAGE` en uitsluitend een wegwerpnetwerk; externe HTTP en mail moeten geblokkeerd zijn.

## Nieuwe sites: inhoudsmodel en bouwvolgorde

Voor nieuwe sites kiezen we vóór de bouw passende berichttypen en taxonomieën. Gebruik bijvoorbeeld Diensten voor een herhaalbaar dienstenaanbod en Producten met productcategorieën voor een catalogus. Maak niet standaard alles als gewone pagina aan. Unieke bedrijfsinformatie blijft een pagina. Projectmodellen zijn subsitegebonden; bestaande sites worden niet automatisch omgebouwd.

De centrale lokale startgids staat in [Nieuwe site toevoegen](/Users/jeffreypisa/Sites/skeletor/wp-content/themes/skeletor/docs/site-factory/NIEUWE-SITE.md); de beslisregels staan in [Inhoudsmodel](/Users/jeffreypisa/Sites/skeletor/wp-content/themes/skeletor/ai-rules/content/inhoudsmodel.md). Deze lokale documentatie is leidend voor nieuwe prospectbouw; de installatie- en herstelhandleidingen hierboven beschrijven de plugin.

## Nieuw in 0.5.6

Definitief verwijderen gaat onder Beheer & herstel met een rode knop per omgeving, zonder typvelden en zonder vooraf archiveren. Sitenaam en lokale/online scope zijn zichtbaar; de bestaande rechten-, nonce-, scope- en herstelkopiecontroles blijven behouden. Online verwijderen is niet beschikbaar tijdens import/herstel. Er wordt geen site verwijderd door deze update te installeren.

## Nieuw in 0.5.7

Klanttoegang zonder sitenaam overtypen. Een vaste viertekenscode per site, steeds terug te vinden bij Preview & klanttoegang, met één knop om de gewone sitelink en code samen te kopiëren. Opnieuw delen verandert de code niet. Wijzigen of uitzetten staat onder een ingeklapte optie. Bestaande hash-only codes blijven geldig tot een bewuste eenmalige vervanging. Codeweergave vereist deze versie ook online; status en publicatiepakketten bevatten geen code. Zie [handleiding](docs/PREVIEW.md).

## Historisch: 0.5.8 (mailflow vervangen in 0.7.0)

Bij Preview & klanttoegang staat Voorstelmail maken: persoonlijke, bewerkbare mail van Jeffrey (Emonks, Hof van Cartesius in Utrecht), met vaste code, gewone sitelink en persoonlijke wachtwoord-instellink of bestaande inloglink. Vul werkzaamheden, kosten inclusief btw-vermelding en bedenktijd in. De optionele screenshot voeg je zelf toe. Kopiëren verstuurt niets. Alleen de redactionele invoer wordt per site bewaard; codes, mailtekst en instellinks worden niet opgeslagen. Een instellink verloopt uiterlijk na 24 uur, los van de bedenktijd.

## Historisch: 0.5.9 (mailflow vervangen in 0.7.0)

Site Factory → Mailtemplate beheert onderwerp, volledige mail, accountteksten en screenshottekst centraal voor alle subsites van hetzelfde netwerk. Invulcodes worden via knoppen ingevoegd. Standaard werkzaamheden, kosten en bedenktijd worden overgenomen zolang het siteveld leeg is; een eigen sitewaarde heeft voorrang. Bestaande ingevulde waarden blijven behouden. Nieuwe mails gebruiken meteen de actuele template; reeds gekopieerde mails veranderen niet. Lokale en online netwerken hebben ieder hun eigen instellingen, zonder automatische synchronisatie.

## Nieuw in 0.6.0

Klantopvolging met zes fases, notities, afgesproken prijs, opvolgdatum en expliciete knop Mail verstuurd. Het overzicht heeft zoek- en fasefilters plus Vandaag opvolgen / te laat. Technische publicatiestatus blijft apart. Kopiëren of een previewbezoek verandert de klantfase niet. Gegevens worden per site binnen hetzelfde netwerk bewaard.

Publicatiecontrole controleert het pakket vóór upload op thema, benodigde functionaliteit, beschikbare inhoudsmodellen, veilige mediapaden en bestandsgroottes. Opnieuw publiceren vereist een actuele online revisie. Gedetecteerde online wijzigingen en oudere sites zonder nulmeting vragen expliciete bevestiging. Een wijziging tijdens upload blokkeert het toepassen vóór backup/import. Standaard WordPress-wijzigingen aan inhoud, velden, categorieën, menu's en relevante siteopties worden gevolgd; directe database-, bestands- of pluginspecifieke tabelwijzigingen vallen daar niet onder.

De browser toont echte werkstappen en bestandsaantallen. Na verbindingsverlies kan Status controleren een al voltooide operatie herkennen en de lokale pending-status afronden. Bestaande herstelroutes blijven beperkt tot passende gevallen; een mislukte site met backup biedt direct Herstelkopie terugzetten. Installeer 0.6.0 ook online vóór de volgende publicatie. Zie [klantopvolging en publiceren](docs/WORKFLOW.md).

## Nieuw in 0.6.1 — SiteGround Optimizer

Op online Site Factory-voorstellen worden SiteGround-minificatie, samenvoegen, uitgesteld JavaScript, fontoptimalisatie, extra lazyloading en bestandscache tijdens de aanvraag uitgeschakeld. De optimizer zet private bestandspaden buiten de WordPress-map anders om in ongeldige webadressen. Originele thema-assets en afgeschermde media blijven intact. Andere sites en opgeslagen optimizerinstellingen veranderen niet. Ook eerder geregistreerde optimizercallbacks worden verwijderd, ongeacht de laadvolgorde van de netwerkplugins.

Installeer deze update online, leeg daarna één keer de SiteGround-cache en herlaad de pagina volledig. Een nieuwe publicatie of nieuwe previewcode is niet nodig. Reeds door de hosting gecachte HTML kan niet door een PHP-plugin worden hersteld voordat die aanvraag WordPress bereikt. Controleer de site zowel ingelogd als met de previewcode.

## Nieuw in 0.6.2 — klantmeldingen

Stel online onder **Site Factory → Meldingen** één ontvangstadres in. Vink bezoek, login/beheer en/of inhoudswijzigingen aan. Nieuwe sites erven dit automatisch; sites zonder eigen beleid ook. Kies “Ook toepassen op alle bestaande voorstelwebsites” om eerdere afwijkende instellingen te vervangen. Per site kan via Activiteit worden afgeweken of teruggekeerd naar de centrale instellingen. Opslaan verstuurt niets; eerdere gebeurtenissen worden niet ingehaald. Lokaal staat een verwijzing naar online, geen tweede afwijkende configuratie.

Per site en gebeurtenissoort maximaal één verzendpoging per uur, ook bij een mislukte of onzekere aflevering. Er is geen wachtrij of automatisch herverzenden; alleen een volgende nieuwe gebeurtenis na het uur kan opnieuw melden. Bezoek is eenmaal per previewsessie; login en dashboard delen dezelfde begrenzing. Inhoudsacties binnen één aanvraag worden bij afsluiten samengevoegd; latere opslagen blijven zichtbaar in het log, ook als de mailbegrenzing geldt. De bestaande klantaccountkoppeling bepaalt wie als klant geldt. Netwerkbeheerders, imports, revisies en autosaves veroorzaken geen inhoudsmelding. Rechtstreekse databasewijzigingen en eigen plugintabellen vallen buiten de standaard WordPress-hooks.

Deze strikt gerichte beheerdersmeldingen mogen door de mail-uitstand, inclusief HTTP-mailtransport gedurende die melding; gewone klant-/formuliermails blijven uit. De online mailvoorziening moet werken. De Activiteit-tab toont aangeboden/mislukt/onzeker; aangeboden is geen bewijs van bezorging. Mails bevatten sitenaam, gebeurtenis, tijd, sitelink en beheerlink, nooit toegangscode, wachtwoord of gewijzigde inhoud. Anonieme preview wordt expliciet als anoniem benoemd.

## Nieuw in 0.6.3 — compacte sitelinks

De grote Lokaal/Online-kaarten zijn vervangen door tekstlinks en een compacte status in de kop van de site. Online kan de lokale werkversie worden geopend via het bronadres uit het gekoppelde publicatiepakket; er wordt geen lokaal adres geraden. Als het oude pakket geen bronadres bevat, ontbreekt die link. Het lokale adres werkt alleen op een computer waar de lokale omgeving bereikbaar is. Dit opent uitsluitend een link; het synchroniseert of publiceert niets.

## Nieuw in 0.7.0 — eerste mail en vervolgmail

Onder **Mails maken** kies je Eerste kennismaking of Vervolg bij interesse. Voornaam, bedrijfsnaam, e-mailadres, eenmalige prijs, maandbedrag en btw-keuze worden per site bewaard en opnieuw gebruikt. Bedragen zijn losse numerieke velden, geen tekstblokken. [kosten] en [maandkosten] krijgen automatisch euroteken en Nederlandse opmaak; [btw] vult de keuze in. Lege bedragen gebruiken de centrale standaard, een expliciete 0 blijft nul.

De eerste mail bevat de gezamenlijk opgestelde kennismaking, prijs en optionele screenshotzin. Deze stap werkt zonder online publicatie, maakt geen previewcode of klantaccount en wijzigt geen instellink. De screenshot voeg je zelf toe. De vervolgmail vereist een geslaagde online publicatie en vult sitelink en vaste code in. Met “Ook toegang tot het beheer meesturen” wordt een wachtwoord-instellink voor een nieuw account gemaakt of de inloglink voor een bestaand account toegevoegd. Zonder vinkje wordt geen instellink gemaakt of vernieuwd.

Onder **Site Factory → Mailtemplate** beheer je beide onderwerpen en teksten, prijsstandaarden, btw en bedenktijd. Nieuwe mails gebruiken de actuele template. Eerder opgeslagen vrije teksten en de vorige template blijven opgeslagen, maar worden niet in het templatescherm getoond; er wordt geen bedrag uit oude proza geraden. Vul dus eenmalig je prijzen in. Lokaal en online houden eigen instellingen. Alleen handmatig kopiëren/versturen; geen automatische e-mail. Zie [mailworkflow](docs/MAILS.md).

## Nieuw in 0.7.2 — standaardprijzen

Mails maken toont de gebruikte standaardprijzen. Afwijkende bedragen staan in een optioneel uitklapblok. Lege velden nemen de centrale prijs over; bestaande afwijkingen blijven bewaard en zichtbaar. Ontbrekende standaardprijzen verwijzen naar Mailtemplates in hetzelfde netwerk. Btw is altijd exclusief; de keuze is uit beide formulieren verwijderd en oude btw-keuzes worden genegeerd.

## Nieuw in 0.7.4 — vrijblijvende uitwerking

De standaard kennismakingsmail biedt bij interesse een vrijblijvende uitwerking naar een eerste website om te bekijken en teksten uit te proberen. Een ongewijzigde eerder opgeslagen standaardtekst wordt automatisch bijgewerkt; eigen aangepaste templates blijven behouden.

## Nieuw in 0.7.5 — fases voor beide mails

Klantopvolging onderscheidt Nog benaderen, Eerste mail verstuurd, Interesse ontvangen, Websitevoorstel uitwerken, Vervolgmail met website verstuurd, Aanpassingen bespreken, Akkoord en Geen interesse. Twee knoppen registreren de mailmomenten met afzonderlijke datums. Herhaald klikken behoudt de datum en zet een latere fase niet terug. De fase kan handmatig worden aangepast. Oude verzend-/reactieregistraties blijven expliciet als oude registratie zichtbaar; het mailtype wordt niet geraden. Mails maken en kopiëren verandert de fase niet.

## Nieuw in 0.8.0 — optionele opdrachtgever

Publiceren en klantaccounts zijn onafhankelijk. Nieuwe publicaties kiezen standaard **Later instellen**. Kies desgewenst **Nieuwe gebruiker aanmaken en koppelen**. Een openstaande publicatie behoudt de oorspronkelijke keuze en gegevens bij hervatten.

Per site biedt **Gebruikers** de actuele online gebruikers, een opdrachtgeverkeuze (inclusief Geen) en het aanmaken van een redacteur, met een apart vinkje om die opdrachtgever te maken. Je kunt ook een gebruiker die via WordPress aan deze site is toegevoegd selecteren. Netwerkbeheerders zijn uitgesloten. Een wissel verandert geen rollen, wachtwoorden of bestaande toegang; intrekken of rollen wijzigen gaat expliciet via WordPress. Gewone accounts worden nooit op basis van een gelijk e-mailadres overgenomen.

De online koppeling is leidend; lokaal worden de actuele gegevens opgehaald bij Gebruikers en Mails maken. Gelijktijdige wijzigingen worden gecontroleerd. Repush wijzigt de opdrachtgever niet. Activiteit blijft aan het oorspronkelijke account gekoppeld. Zonder opdrachtgever werken publicatie, herstel en previewmails; beheertoegang vereist een actuele opdrachtgever. Een oud mailformulier of afwijkend ontvangeradres kan geen instellink voor een nieuwe opdrachtgever genereren. Accountaanmaak en koppelen versturen geen mail.

Installeer **0.8.0 ook online** om zonder opdrachtgever te publiceren en gebruikers vanuit lokaal te beheren. De lokale plugin controleert ondersteuning vóór upload. Oude accountpublicaties blijven ondersteund; bestaande koppelingen hoeven niet gemigreerd te worden.

## Nieuw in 0.9.0 — codebeveiliging optioneel

Nieuwe publicaties via 0.9.0 zijn standaard voor iedereen met de link te bekijken. **Meteen beschermen met een toegangscode** is optioneel bij de eerste publicatie. Onder **Preview & klanttoegang** kun je later codebeveiliging aanzetten of uitzetten. Uitzetten maakt pagina’s en bijbehorende media toegankelijk zonder code. De aparte optie **Alleen ingelogde gebruikers toegang geven** sluit anonieme toegang volledig.

Bij het kopiëren van de sitelink en bij Mails maken staat een rustige herinnering wanneer de site zonder code toegankelijk is, met een knop om bescherming aan te zetten. De vervolgmail kan zonder code gemaakt worden; dit verandert de toegang niet. Standaardregels zoals `Code: [previewcode]` vervallen dan. Een los token in een eigen tekst wordt `geen code nodig`; controleer eigen formuleringen zoals altijd vóór versturen.

Bestaande beschermde sites, gesloten sites en publicaties van oude clients/openstaande oude operaties worden nooit automatisch openbaar. Repush en inhoudsherstel behouden de toegangskeuze. Nieuwe publicaties vereisen 0.9.0 lokaal én online. Oude clients houden hun gesloten beginstatus. Oude code-sessies vervallen bij uitschakelen; opnieuw inschakelen maakt een nieuwe code.

Publiek kijken geeft geen beheer-, REST-, feed- of schrijfrechten. Archief, import en mislukte publicaties blijven afgesloten. Mailbeleid, no-cache en noindex blijven actief. Openbare bezoeken worden niet als klantactiviteit geregistreerd; bestaande code- en accountregistratie blijft werken. Noindex is geen toegangsbeveiliging.

## 0.10.0 — status en online inhoud ophalen

Het lokale overzicht toont de laatst bekende online status met controletijd. **Online status verversen** controleert de zichtbare gekoppelde sites, zonder inhoud te publiceren of op te halen. Bij een verbindingsfout blijven de laatste gegevens staan met een foutmelding; zonder internet is een actuele online controle niet mogelijk.

Per lokale site staat **Online → lokaal** naast Publiceren. Bevestig de exacte lokale sitenaam om de gekoppelde online inhoud te downloaden en lokaal te vervangen. Eerst worden alle bestanden gecontroleerd en wordt een herstelkopie gemaakt. Terugzetten kan vanuit hetzelfde paneel; ook vóór terugzetten wordt de huidige toestand bewaard. Een onderbroken toepassing blokkeert opnieuw ophalen totdat de herstelkopie is teruggezet.

Dit gebruikt hetzelfde inhoudspakket als publiceren: pagina’s, media, menu’s, site-instellingen, formulierdefinities en projectgebonden ACF-definities. Geen thema- of plugincode, accounts, wachtwoorden of formulierinzendingen. Mail en integraties staan na ophalen lokaal uit. Thema-uitgaven moeten overeenkomen; bij een verschil stopt de overdracht zonder code aan te passen. Bestaande lokale formulierinzendingen blokkeren vervanging. Limieten: 8 MiB per bestand, 256 MiB per pakket. Downloadtoegang vereist dezelfde applicatiewachtwoord/netwerkbeheerbeveiliging als publiceren; snapshot-ID’s zijn brongebonden en één uur geldig.

Installeer **0.10.0 ook op het online netwerk** voor ophalen. Bestaande statuscontroles blijven met oudere ontvangers werken. Er wordt nooit automatisch een site opgehaald bij het openen of verversen van het overzicht.

Lokale WP-Cron-verzoeken naar de eigen `wp-cron.php` zijn uitgezonderd van de integratieblokkering, met redirects uitgeschakeld. Andere integratieverzoeken en e-mail blijven geblokkeerd.

## Themaversies en releasewijzigingen (0.11.0)

Skeletor `style.css` bevat het leidende versienummer. De lokale versie is de verwachte release; het publicatiepakket bewaart die versie. Status toont de online geïnstalleerde versie en de wijzigingen per release uit `releases.json`, inclusief releases die nog niet online staan. Ontbrekende releasegeschiedenis blokkeert geen bestaande geldige thema-uitgave.

Publicatiecontrole, ontvangst en ophalen vereisen dezelfde geldige `major.minor.patch`-versie (eventueel met prerelease). Ontbrekende, ongeldige, oudere én nieuwere versies blokkeren vóór inhoudsvervanging. Bestandshashes blijven diagnostische informatie en blokkeren niet bij gelijke versies. Daarom moet elke themawijziging een nieuwe release krijgen. Een opgeslagen pakket blijft zijn oorspronkelijke versie verwachten; maak na annuleren een nieuw pakket als de verwachte versie is veranderd.

Gebruik in de gedeelde Skeletor-map `python3 scripts/theme-release.py --bump patch --change "Concrete wijziging"` (minor voor functionaliteit, major voor incompatibele wijzigingen). Dit werkt `style.css`, `package.json`, `releases.json` en `CHANGELOG.md` samen bij. Controleer met `python3 scripts/theme-release.py --check --base <basis-ref>`. De themarepository heeft hiervoor een CI-controle. Installeer plugin 0.11.0 lokaal én online: oudere ontvangers kunnen nog op bestandshashes blokkeren. Deze wijziging publiceert geen thema of site automatisch.

Gerichte tests zonder database: `php tests/theme-releases.php`. Voor bestaande integratietests blijft de wegwerpomgeving uit `tests/README.md` verplicht.

## Centrale controle en themamappen (0.12.0)

Online verbinding biedt afzonderlijke lokale en online themamappen, bijvoorbeeld lokaal `skeletor` en online `skeletor-master`. De mapnaam is onafhankelijk van de vaste interne pakketidentiteit en kan later veranderen. Stel bestaande online sites daarna expliciet in op het bedoelde thema; er wordt niets automatisch hernoemd.

Bij Publiceren voert **Alles controleren** één controle uit met afzonderlijke resultaten voor verbinding, thema/beschikbaarheid, themaversie, Site Factory-pluginversie, ACF Pro, hosting en inhoud/media. Resultaten en vervolgstappen verschijnen op dezelfde pagina. Thema- en pluginversies moeten overeenkomen. Verschillende ACF Pro-versies zijn toegestaan wanneer beide installaties actief zijn. Vanaf 0.13.0 staan hier echte updateknoppen wanneer de ontvanger dit ondersteunt. Status controleren installeert niets. Een oude online plugin toont eerst het advies om Site Factory bij te werken. Status lezen blijft mogelijk bij een ontbrekend thema of ACF Pro; publicatie blijft geblokkeerd. Installeer deze pluginuitgave lokaal én online.


## Online software bijwerken vanuit lokaal (0.13.0)

Na **Alles controleren** kun je het lokale thema of de lokale Site Factory-plugin naar online sturen. Dit vervangt gedeelde code voor **alle sites op het online netwerk**, los van inhoudspublicatie. De ingestelde online themamap blijft behouden, ook bij lokaal `skeletor` en online `skeletor-master`. Thema wordt netwerkbreed beschikbaar gemaakt; bestaande sites wisselen niet automatisch van thema. Pluginactivatie blijft behouden.

Een online plugin ouder dan 0.13.0 moet één keer handmatig worden bijgewerkt: **Lokale pluginzip downloaden**, daarna **Pluginzip online installeren** en de bestaande plugin vervangen. Daarna opnieuw Alles controleren. De knoppen zijn voortaan echte lokale overdrachten; de handmatige links worden expliciet zo benoemd.

De overdracht gebruikt de bestaande HTTPS-verbinding en het applicatiewachtwoord van de netwerkbeheerder. Software wordt in blokken naar private opslag gestuurd, met grootte-, SHA-256-, pad- en versiecontrole vóór installatie via WordPress. Runtimepakketten bevatten geen lokale tools, prospectdossiers, uploads, credentials of node_modules. Maximum: 256 MiB gecomprimeerd en uitgepakt. Nieuwere online uitgaven worden niet teruggezet. Direct schrijven via WordPress is vereist; anders blijft handmatig installeren beschikbaar.

Vóór vervanging wordt de bestaande software gekopieerd naar de private opslag, onder `software-<upload-id>-backup`; het bijbehorende journal bewaart de status. Bij een gewone installatiefout worden de vorige bestanden teruggezet. Na een harde serveronderbreking moet de beheerder deze herstelkopie zo nodig handmatig terugplaatsen. Herstelkopieën blijven bewaard; verwijder ze bewust na controle. Een losse nieuwe statusaanvraag bevestigt de geïnstalleerde versie na installatie. Dit installeert geen ACF Pro en publiceert geen site-inhoud.

## Controle zonder voortijdige inhoudsexport (0.13.1)

Alles controleren haalt eerst de omgevingsgegevens op. Bij een ongeschikt of verouderd online netwerk wordt nog geen inhoudspakket samengesteld; de resultaten en updateopties verschijnen meteen. Pas bij geslaagde omgevingscontroles wordt inhoud en media gecontroleerd. Controle en publicatie vragen de standaard WordPress-geheugenlimiet voor beheer aan. Een onderbroken controle meldt dat er geen publicatie gestart is. Dit verhelpt de lokale geheugenfout bij Volksuniversiteit (9 oktober 2026, 128 MiB tijdens opties-export).

## Updates via WordPress en GitHub Releases

Vanaf 0.14.0 controleert de ingebouwde Plugin Update Checker 5.7 op stabiele releases in [jeffreypisa/emonks-sitefactory](https://github.com/jeffreypisa/emonks-sitefactory). De repository is openbaar; er is geen token of extra updaterplugin nodig. WordPress toont updates op de pluginpagina en bij Dashboard → Updates. Bij Multisite werk je plugins bij via Netwerkbeheer. Automatische updates worden niet ongevraagd ingeschakeld.

Installeer deze eerste versie met updater één keer handmatig met `dist/emonks-site-factory-0.14.0.zip`. Eerdere versies herkennen GitHub-updates nog niet. Behoud het hoofdbestand `emonks-site-factory.php` en vervang dezelfde bestaande plugin; activeer geen tweede kopie ernaast. De updater behoudt bij volgende updates de bestaande pluginmap, ook als die van de repositorynaam afwijkt.

Nieuwe releases maken:

1. Verhoog het versienummer in de pluginheader; werk de wijzigingsnotities bij en voer `php tests/update-policy.php` en de overige relevante tests uit.
2. Commit en push de wijziging. Maak daarna een tag `vX.Y.Z` die exact bij het pluginversienummer hoort en push die tag.
3. De GitHub Actions-workflow `Publish WordPress release` controleert de tag, test de updater en bouwt/publiceert het installatiebestand als GitHub Release. Bekijk of de workflow geslaagd is. Ontbreekt een release of de bijbehorende installatie-zip, dan verschijnt geen update in WordPress.

De updater gebruikt uitsluitend gepubliceerde stabiele releases met een passende installatie-zip. Een gewone branchpush, een losse tag, een conceptrelease, een prerelease of de automatisch gegenereerde GitHub-broncode-zip wordt niet als update gebruikt. WordPress controleert periodiek; via de extra link voor updatecontrole op de pluginpagina kun je eerder controleren. GitHub-storingen en API-limieten kunnen de controle tijdelijk verhinderen.

De MIT-licentie en bronbestanden van Plugin Update Checker staan onder `includes/plugin-update-checker`. Bewerk die externe bibliotheek niet handmatig; vervang haar bij een toekomstige bibliotheekupdate door een gecontroleerde officiële uitgave.

## GitHub-updates (0.14.0)

Updater toegevoegd voor de gewone WordPress-pluginpagina, naast de bestaande lokale softwareoverdracht. De lokale integratieblokkering laat uitsluitend leesverzoeken naar de update-endpoints van emonks Dashboard en Site Factory door. De releaseworkflow levert een runtimepakket met de updaterbibliotheek en haar licentie.

Gebruik de Git-repository als ontwikkelbron. Installeer de release-zip op de WordPress-omgevingen die je via de pluginpagina wilt bijwerken; een WordPress-update vervangt de pluginmap en kan daardoor een daarin aanwezige `.git`-map verwijderen.

## Eén lokale bron voor Skeletor en sites.test

Gebruikersafspraak van 15 oktober 2026: houd de code van Skeletor en sites.test fysiek op één plek, net als het gedeelde thema. De Git-repositories onder `/Users/jeffreypisa/Sites/skeletor/wp-content/plugins/emonks-dashboard` en `emonks-sitefactory` zijn de enige ontwikkelbron. De pluginmappen op `/Users/jeffreypisa/Sites/site-factory/wp-content/plugins` verwijzen daar met symbolische koppelingen naartoe. De oude bronlocatie `/Users/jeffreypisa/Sites/sitefactory` blijft als koppeling naar de Site Factory-repository beschikbaar voor bestaande scripts. Maak geen aparte runtimekopieën voor deze lokale installaties. Instellingen en inhoud blijven per WordPress-installatie of site in de database opgeslagen.

Git-werkmappen worden door de updater uitgesloten van WordPress-updatemeldingen en automatische pluginupdates, zodat WordPress de gedeelde bron en Git-geschiedenis niet vervangt. Pas lokale code aan of haal wijzigingen op in de centrale repository. Normale installaties uit een release-zip bevatten geen `.git` en gebruiken wel de gewone WordPress-updater.

Lokale Git-werkmappen (ook worktrees met een `.git`-bestand) voeren geen periodieke of handmatige GitHub-updatecontrole uit. De controlelink en bijbehorende meldingen verdwijnen; oude geplande controles worden opgeruimd. Normale release-installaties blijven iedere twaalf uur controleren.

### 0.14.1

- Schakel updatecontroles volledig uit voor de gedeelde lokale Git-bron, inclusief controlelink en foutmeldingen.

### 0.14.2

- Herstelkopieën controleren opgeslagen JSON met SHA-256 zonder de volledige databasekopie opnieuw te decoderen. Dit verlaagt de geheugenpiek vóór verwijderen of vervangen. Back-ups vragen de WordPress-beheergeheugenlimiet aan. Onvolledige schrijfacties worden geweigerd vóór de definitieve journalwissel.
