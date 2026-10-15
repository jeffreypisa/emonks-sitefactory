# Afzonderlijke integratietests

Deze tests zijn destructief voor hun eigen testfixtures. Gebruik uitsluitend een nieuw lokaal WordPress Multisite-netwerk met precies één site, een eigen database en een private opslagmap, nooit het actieve factory-netwerk. Het WordPress-pad moet `emsf-integration` bevatten en `EMSF_DISPOSABLE_TEST=yes` is verplicht. De tests weigeren anders te starten. PHP-syntaxcontrole vereist geen database.

Benodigd: PHP 8.1+, WP-CLI, MySQL/MariaDB met InnoDB, WordPress Multisite met submappen, synthetisch netwerk `online.example.test`, superadmin met ID 1, ACF Pro uit eigen gelicentieerde installatie en later Fluent Forms. Maak een minimaal testthema `skeletor` met `style.css` (`Theme Name: Skeletor`, `Version: 1.0.0`) en lege `functions.php`/`index.php`. Gebruik geen klantthema-inhoud. Installeer deze plugin netwerkbreed. Zet `WP_ENVIRONMENT_TYPE=local`, `EMSF_ONLINE=true`, `EMSF_PRIVATE_HOSTING_VERIFIED=true` uitsluitend in deze gesimuleerde omgeving en `EMSF_STATE_DIR` naar een nieuwe private map. Zet `DISABLE_WP_CRON=true`.

Installeer vóór de tests een mu-plugin die uitgaande mail en HTTP onderschept:

```php
<?php
$GLOBALS['test_mail_count'] = 0;
add_filter('pre_wp_mail', function ($pre) {
    $GLOBALS['test_mail_count']++;
    return false;
}, 1);
add_filter('pre_http_request', fn () => new WP_Error('test_offline', 'Test transport blocked'), 1);
```

Gebruik een geïsoleerde testomgeving zonder externe aflevermogelijkheid als extra grens. De account- en mailtests installeren eigen onderscheppende transports. Voer in deze volgorde uit, met `--path=<wegwerp-WordPress-pad> --user=<test-superadmin>` op elke WP-CLI-opdracht:

```sh
EMSF_DISPOSABLE_TEST=yes wp eval-file tests/online-integration.php
EMSF_DISPOSABLE_TEST=yes wp eval-file tests/preview-access.php
EMSF_DISPOSABLE_TEST=yes wp eval-file tests/sites-and-silent-accounts.php
EMSF_DISPOSABLE_TEST=yes wp eval-file tests/client-retry.php
wp plugin activate fluentform --network
EMSF_DISPOSABLE_TEST=yes wp eval-file tests/mail-policy.php
```

Laat Fluent Forms uit tijdens de eerste vier tests. Activeer het pas daarna voor de echte formulier-/notificatiecode. Elke herhaling begint met een vers netwerk en lege opslag, omdat fixtures vaste namen gebruiken. De tests verwijderen uitsluitend fixtures na expliciete bevestigingschecks; de bootstrap blijft verantwoordelijkheid van de tester. Er is bewust geen reset-script dat zomaar een bestaande database wist.

HTTP-fixtures: `http-fixture.php`, `http-router.php`, `http-privacy.py` zijn een aanvullende lokale testopzet met synthetische HTTPS-status en host `online.example.test`. De oorspronkelijke opzet verwacht `/private/tmp/emsf-integration/wordpress`, cookiebestanden erbuiten en een PHP-server op `127.0.0.1:18765`. Pas uitsluitend fixturepaden aan wanneer de testomgeving anders is; stel deze server nooit publiek beschikbaar. Cookies en testopslag zijn niet inbegrepen.

Gevalideerd op 17 september 2026: 74 core assertions, 35 Sites/account assertions, 20 mail assertions met Fluent Forms 6.2.3 en de client-herhaaltest met vijf onderschepte requests. De 13 HTTP-controles zijn in de eerdere publicatiefase uitgevoerd. Echte hosting/TLS/cache, Fluent Forms Pro-routes en het volledige thema vereisen de hostingpilot. CI controleert syntax en packagebouw; premium dependencies en databases worden niet in GitHub Actions geüpload.

Preview HTTP: voer `preview-http-fixture.php` uit met dezelfde WP-CLI-testguard, start de bestaande router uitsluitend op loopback en draai `python3 tests/preview-http.py`. Het synthetische codebestand blijft alleen onder de wegwerpmap (0600). `preview-access.php` heeft 35 gerichte checks; de preview HTTP-suite heeft 28 checks. De core-suite heeft nu 74 checks inclusief codebehoud bij repush/restore; de clienttest controleert ook de online upgrade-eis en dat codes niet in lokale journals belanden.

## Activiteit en echte tabs (0.4)

Aanvullend: `activity.php` uitvoeren met dezelfde wegwerpbeveiliging als `preview-access.php`. Dit test anonieme bronvermelding, bekende klantlogin, werkelijke dashboard-renderhook, sessiededuplicatie, superadminuitsluiting, pauze, ontvangerbevestiging, mail-off-uitzondering, transportfout zonder herhaling en retentie. De laatste mailfilter onderschept ieder transport. `preview-http.py` controleert tevens geen registratie bij kale deellink/codepagina, wel één registratie na geldige code en gerenderde inhoud, geen dubbeltelling bij reload/media. Het fixturethema moet `wp_footer()` aanroepen.

Totaal: 74 publicatie-, 35 Sites/account-, 35 previewbeveiligings-, 13 bestaande HTTP-, 28 preview-HTTP-, 20 mail- en 27 activiteit- en 22 layout/pakket- en 15 formuliervalidatieassertions = 269, plus de client-herhaaltest. De Sites-test controleert bovendien zichtbaarheid van slechts één paneel, actieve tab en lege ontvanger. Browsercontrole test klik/toetsenbord, mobiel en terugkeer na opslaan.

`package-current-layouts.php` voegt 22 gerichte controles toe: de exacte runtimequeuetabellen, geen queuepayload/replay, onbekende gelijkende tabel en ongeldige veldvorm blijven blokkeren, disabled/renamed-layouts en uitlijning blijven intact, legacy hover/rating blijven begrensd, projectspecifieke relationships en Fluent Forms-shortcodes/cache krijgen doel-ID's. Alleen de tijdelijke testdatabase wordt gemuteerd. De echte elf lokale sites zijn uitsluitend geëxporteerd en geverifieerd; nul inventoryblokkades, geen remote create/retry.

`customer-validation.php`: 15 controles voor elk veld, checkbox, ongeldige types, normalisatie van hoofdletters, invoerbehoud, zelfde tab en serverfout zonder transport. De browserfixture onderschept iedere verzending; alle vier veldfouten en een geldige invoer zijn via de UI getest. `client-retry.php` begint nu bij `Admin::run` en valideert de customer-payload zoals de ontvanger dat doet; een oud Unicode-pakket wordt vóór upload geweigerd terwijl operatie en manifest identiek blijven. Alle werkelijke paden van de elf lokale proefexports zijn ook door de ontvangerregel gecontroleerd.

## Vaste previewcode (0.5.7)

`persistent-preview.php`: 23 controles in de geïsoleerde testomgeving voor vaste code zonder naambevestiging, gewone URL, versleutelde opslag, opnieuw ophalen/aanmaken zonder rotatie, frontendtoegang zonder login, blokkade van beheer/API/feed, beheerbevoegdheden, weergave en kopieerknop, nonce/sitebinding, status zonder code, behoud in archief, migratie van oude codes/deellinks, expliciete rotatie/intrekking en ongewijzigd mailbeleid. De browsercontrole gebruikt een gerenderde synthetische beheerpagina; geen echte klantcode wordt gepubliceerd.

## Voorstelmail (0.5.8)

`mail-draft.php` (0.7.0): 13 controles voor eerste mail vóór publicatie, blokkade van vervolgmail zonder publicatie, vaste code, optionele accounttoegang, werkende instellink, bestaande accounts, veilige opslag, rechten, escaping en geen verzonden mail.

`mail-template.php` (0.7.0): 15 controles voor beide templates, behoud van oude tekst, gestructureerde bedragen/btw, overerving, nulbedragen, ongeldige invulcodes en toegangscodes in de eerste mail, niet-recursieve vervanging, rechten en centrale UI.

## Opvolging en publiceren (0.6.0)

`workflow.php`: 24 checks met unieke fictieve sites. Read-only remote controle, thema/model/mediafouten vóór upload, nulmeting en oude sites, idempotente publicatie, opvolgvelden/datumvalidatie/bevoegdheden, klantwijziging en bewuste bevestiging, wijziging tijdens upload zonder overschrijven, geslaagde vervanging met behoud van toegang en opvolging, herstel, filterweergave en voortgang. `workflow-client.php` test statusreconciliatie na verloren antwoord en revisiegebonden UI. `client-retry.php` is bijgewerkt voor de nieuwe check-route en opnieuw uitgevoerd met onderschept transport. De browserflow is getest met een lokale simulatie, zonder echte publicatie. De uitgebreide historische core-suite vereist nog steeds een vers testnetwerk; de vervangpayloads zijn aangepast aan de revisiecontrole.

## SiteGround private assets (0.6.1)

`optimizer.php` draait uitsluitend op de disposable emsf-integration-installatie. Stel `EMSF_DISPOSABLE_TEST=yes` en `EMSF_SG_PLUGIN` in op een echte lokale `sg-cachepress.php`; skip de normale Factory-plugin en het thema bij WP-CLI eval-file. Getest met SiteGround Optimizer 7.4.5. Controleert callbacks uit de echte optimizer, beide laadvolgordes, originele script- en stylesheetadressen, afbeeldingen zonder SG-lazyloading, private media-URL en behoud van opgeslagen instellingen. De netwerkinstallatie en hostingcache moeten daarnaast online worden gecontroleerd.

## Klantmeldingen (0.6.2)

`notifications.php`: run via WP-CLI eval-file in de disposable omgeving met `EMSF_DISPOSABLE_TEST=yes`, normale Factory-plugin en thema overgeslagen. Alle mail wordt onderschept. Controleert centraal erven, overrides, rechten, ontvanger, echte post/meta-hooks, samenvoegen, herhaling na één uur, import-/beheerderuitsluiting en mail-uitstand.

## Mailmomenten in opvolging (0.7.5)

`follow-up-mails.php`: negen controles voor afzonderlijke fases/datums, behoud van afspraken, herhaalde klik zonder terugzetten, oude registraties zonder verzonnen mailtype, beheerrechten en genereren zonder verzendregistratie. Alleen disposable testomgeving.

## Optionele opdrachtgever (0.8.0)

`optional-customer.php`: 28 integratiecontroles voor eerste publicatie zonder account, herhaling en legacy-herstel, preview, later aanmaken met/zonder koppeling, rol, wisselen/loskoppelen zonder toegangsverlies, accountgebonden historie, verkeerde site/bevoegdheden, gelijktijdige wijziging, mailontvanger en instellink, repush en gebruikersformulier. `optional-customer-client.php`: vijf controles met onderschept HTTP voor capability-check vóór upload, accountvrij hervatten met identiek pakket en operatie, en remote gebruikersbeheer met bijgewerkte lokale momentopname.

Bestaande workflow (24), mailconcept (13), klantvalidatie (15) en client-herhaaltests ook uitgevoerd. Mailfixture gebruikt nu het daadwerkelijke accountadres voor mails met beheertoegang. PHP- en JavaScript-syntax gecontroleerd. Visuele browsercontrole van de lokale HTML-fixture kon niet worden uitgevoerd door het browserbeleid voor lokale bestanden.

## Optionele codebeveiliging (0.9.0)

`optional-preview.php`: 31 checks voor expliciete openbare eerste publicatie, frontend/media, afgeschermde routes en schrijfacties, onbeschikbare toestanden, noindex, code aan/uit en sessie-intrekking, mail zonder automatische beveiligingswijziging, herinneringen, behoud bij repush/herstel, oude clients en meteen beschermd publiceren. `optional-customer-client.php` controleert daarnaast openbare default en onveranderlijke toegangskeuze bij hervatten (zes checks). De bestaande preview-access (35), persistent-preview (23) en mail-draft (13) zijn opnieuw gecontroleerd.

Aanvullend acht echte HTTP-verzoeken tegen een geïsoleerde localhost-WordPress-server: openbare pagina GET/HEAD en media 200; gesloten voorstel, REST, feed, beheer en POST 403; no-cache/noindex-headers behouden. HTTPS werd in deze lokale router gesimuleerd; geen echte hosting/cache/CDN-test.

`pull.php` (0.10.0): 18 controles in het wegwerpnetwerk voor naambevestiging vóór transport, corrupte media vóór vervanging, echte mediaoverdracht, lokale URL en mailstand, backup en terugzetten, automatische rollback na importfout, brongebonden/verlopen download-ID’s, behoud van gecachte status bij offline, beperkte cron-uitzondering en compacte overzichtsweergave. Alle HTTP-verzoeken worden onderschept. Uitvoeren met `--skip-plugins=emonks-site-factory` als in de testomgeving een andere pluginuitgave actief is. `workflow-client.php` controleert tevens bestaande statusreconciliatie en publicatieformulieren.

## Releasecontrole (0.11.0)

`php tests/theme-releases.php` test zonder database of netwerk versieverschillen, gelijke versie met afwijkende hash, ontbrekende/ongeldige versies, prereleases, releasegeschiedenis en escaped beheerweergave. De bestaande workflowfixture gebruikt nu een afwijkend versienummer als preflightfout.

## Centrale controle en themamappen (0.12.0)

`php tests/environment-checks.php` test verschillende lokale/online mapnamen, eigen namen, padvalidatie, onafhankelijke ACF-/themaresultaten, oudere en nieuwere thema-/pluginversies, oude ontvangers, actieve gekoppelde thema’s en escaped resultaatweergave. `publication-result.php` controleert de centrale resultaatweergave en AJAX-respons. Statusdiagnostiek is ook bruikbaar wanneer ACF Pro of het gekozen thema ontbreekt; schrijven blijft achter preflight en versiecontrole.

## Software bijwerken (0.13.0)

`php tests/software-update.php` werkt uitsluitend in tijdelijke mappen, zonder WordPress-database of HTTP-transport. Test pakketinhoud, padbeveiliging, versiecontrole, uploadvolgorde, bronbinding, onvolledige upload, gewijzigde online versie, herstelkopie, geslaagde installatie en herstel bij een installatiefout. De WordPress-installer is hierbij gesimuleerd. De echte hostinginstallatie, netwerkactivatie, bestandstoegang en een update van de draaiende plugin blijven onderdeel van de online pilot.
