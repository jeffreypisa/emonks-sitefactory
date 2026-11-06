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

## Openbare pluginupdates (0.14.0)

`php tests/update-policy.php` test zonder database of netwerk stabiele releases, concepten/prereleases, ontbrekende/verkeerde assets, netwerkfouten en de gerichte uitzondering voor GitHub-leesverzoeken in lokale demo’s. `wp eval-file tests/updater-wordpress.php` controleert de echte updater binnen WordPress met onderschept HTTP-verkeer en tijdelijke updatebestanden.

## Geheugenpiek herstelkopie (0.14.2)

`php tests/snapshot-memory.php` maakt uitsluitend tijdelijke fictieve bestanden en een gesimuleerde database met 80.000 rijen bij 128 MiB. Controleert behoud van inhoud, privébestandsrechten, transactie-afsluiting en opruimen van tijdelijke journals. De oude dubbele JSON-inlezing overschrijdt deze limiet; de nieuwe bestandscontrole blijft eronder. Er worden geen echte sites verwijderd.

## Zelfstandige oplevering (0.16.0)

`php tests/handover-policy.php` test zonder database of netwerk de versievingerafdruk, bron-/doeladresregels, bevoegdheden, goedkeuringsbinding, ZIP-uitpakken en onveilige paden.

De overige handovertests vereisen **afzonderlijke verse wegwerpinstallaties** en `EMSF_DISPOSABLE_TEST=yes`. Gebruik nooit bestaande Factory-sites of klantdatabases. De paden moeten `emsf-integration-handover-` (zelfstandig) of `emsf-integration-handover-source-` (multisite) bevatten. Gebruik een eigen tijdelijke MySQL-server/database, ACF Pro uit de eigen installatie en onderschep alle mail/HTTP met een test-mu-plugin. Activeer ACF Pro, Emonks Dashboard, Fluent Forms en de gebouwde importer op de zelfstandige fixture; gebruik minimaal testthema Skeletor 1.0.0. Stel het doel in op `https://handover.example.test`, verwijder WordPress-voorbeeldinhoud en test uitsluitend synthetische gegevens.

- `handover-integration.php`: bestemming, verkeerde bron/doelkeuze, beschadigde media, echte import, geïnjecteerde fout/rollback, herhaling zonder duplicaten, menu-/term-/media-/URL-relaties, ACF-modellen in de database, rapport en aparte liveganginterface.
- Met `EMSF_TEST_STOP_AFTER_STAGE=yes` stopt die fixture na staging. Voer vervolgens `handover-crash.php` uit in een apart proces: de test beëindigt het PHP-proces bewust met exitcode 73 midden in de databasetransactie. `handover-recover.php` controleert in het volgende proces dat er niets gecommit is en hervat dezelfde operatie.
- Deactiveer de importer en voer `handover-independent.php` in een nieuw proces uit. Deze fixture vereist een zelfstandige installatie met het vaste synthetische doeladres en bewijst dat ACF-typen, taxonomie, waarden en media zonder geladen Factory-klassen werken en de mu-beveiliging blijft bestaan. Activeer daarna de importer opnieuw.
- `handover-release.php` gebruikt dezelfde strikt begrensde synthetische fixture. Controleert de beheerdersproef aan/uit, apart vrijgeven, verwijderen van de mu-beveiliging, indexering en idempotent vrijgeven. De externe acceptatievelden worden hier alleen als testdata bevestigd; dit bewijst geen echte hosting-/mail-/visuele controle.
- `handover-source.php`: nieuw synthetisch voorstel op een wegwerp-Multisite, direct klaarzetten, behoud van oudere momentopnamen na bronwijzigingen, ZIP-export en de werkelijke Oplevering-tab. Het netwerk gebruikt `source.example.test`, private `EMSF_STATE_DIR`, `EMSF_ONLINE=true` en gesimuleerde hostingverificatie.

De integratiefixtures zijn bewust niet algemeen destructief te resetten. Een herhaling begint in een nieuwe lege fixture. Bouw importer en Factory opnieuw na een wijziging aan gedeelde pakketcode. De echte hostingpilot, volledige klantinhoud, mailaflevering en visuele acceptatie blijven afzonderlijke oplevercontroles.

`handover-roundtrip.php` importeert een werkelijk met `handover-source.php` gemaakt ZIP-pakket in een opnieuw lege zelfstandige fixture. Geef de private ZIP via `EMSF_HANDOVER_PACKAGE` door; uitsluitend paden onder `emsf-handover-source-state-` worden geaccepteerd. Controleert daadwerkelijke inhoud, bron/doel en noindex.

Gecontroleerd op 27 oktober 2026: 18 beleidscontroles, 33 importcontroles, 14 broncontroles, 3 harde-onderbreking/herstelcontroles, 6 controles zonder actieve importer, 4 livegang-/proefcontroles en 5 volledige overdrachtscontroles. Wegwerptest met WordPress 7.1.3, PHP 8.3 en MySQL 5.7; minimaal fixturethema Skeletor 1.0.0. Dit is geen proef met het volledige productie-Skeletor-thema of een echte klantsite. De nieuwe beheerpanelen zijn aanvullend bekeken als lokaal gerenderde HTML met WordPress- en Factory-stijlen.

## Word-downloads (0.16.1)

`php tests/package-documents.php` controleert zonder database of netwerk DOCX-bestandsnamen, export via de echte bestandsadapter, ongewijzigde bronbytes, pakketvalidatie, corrupte bestanden en geblokkeerde uitvoerbare bestanden/padtraversal. `handover-policy.php` bevat daarnaast een DOCX-bestand in de zelfstandige ZIP-importproef.

## Lokale importmarkeringen (0.16.2)

`php tests/package-known-metadata.php` controleert aanvullend dat uitsluitend `_sdda_logo_hash` en `_sdda_form_hash` bij bijlagen buiten de inhoudsexport blijven. Dezelfde namen op andere berichttypen en overige gelijkende metadata worden niet automatisch genegeerd. Deze markeringen worden niet als over te dragen inhoud toegestaan.

## Reactiecontrole bij oplevering (0.16.3)

`php tests/handover-comments.php` voert de echte inventarisatiequery uit op een afzonderlijke SQLite-geheugendatabase. Controleert lege reacties, goedgekeurd/onbeoordeeld, spam/trash/post-trashed, reacties bij weggegooide berichten, ontbrekende berichten, gemengde aantallen en ongewijzigde bronrijen. De echte WordPress-database wordt niet gebruikt.

## Softwarebundel voor zelfstandige oplevering (0.17.0)

`php tests/delivery-software.php` controleert zonder netwerk of WordPress-database pakketidentiteit, versies, checksums, dubbele componenten, vervalste doelpaden, ZIP-traversal, ontbrekende afhankelijkheden, plaatsing in tijdelijke softwaremappen, idempotent herhalen, bestaande afwijkende/onvolledige installaties en behouden runtime-Composer-metadata.

`delivery-bundle-integration.php` vereist `EMSF_DISPOSABLE_TEST=yes`, een **nieuwe lege** zelfstandige WordPress onder een pad met `emsf-integration-bundle-`, doel `https://handover.example.test`, een eigen wegwerpdatabase en een mu-plugin die alle mail/HTTP onderschept. Begin zonder ACF, Dashboard, Skeletor of Fluent Forms; installeer alleen de gebouwde importer. Geef een vertrouwd privétestpakket met software via `EMSF_BUNDLE_ARCHIVE`. Voer in aparte WordPress-processen de fases `EMSF_BUNDLE_PHASE=stage`, `software`, `content` uit. Deactiveer vervolgens de importer en voer `independent` uit; activeer hem daarna desgewenst weer. Het proefpakket moet een formulier en actieve Fluent Forms Pro bevatten voor deze volledige fixture. Geen bestaande Factory- of klantdatabase gebruiken.

Gecontroleerd op 27 oktober 2026: 20 geïsoleerde softwarecontroles en 16 checks met een echt lokaal bronpakket op een lege wegwerpinstallatie (WordPress 7.1.3, PHP 8.3, MySQL 5.7). Skeletor 2.7.2, ACF Pro 6.8.1, Dashboard 1.1.2, Fluent Forms 6.2.15 en Pro 6.2.2 zijn uit de bundel geïnstalleerd; daarna zijn inhoud en zelfstandige werking zonder importer gecontroleerd. Dit bewijst geen echte hosting/TLS/mailaflevering of volledige visuele klantacceptatie. Het oorspronkelijke bronpakket en bronstatus zijn niet gewijzigd.

## Direct downloaden na klaarzetten (0.17.1)

`handover-source.php` controleert dat klaarzetten direct een downloadbaar pakket oplevert, zonder aparte goedkeuringsactie, en dat gewijzigde broninhoud downloaden nog steeds blokkeert. `handover-policy.php` controleert zowel nieuwe `ready_at`/`ready_by`-pakketten als oude goedgekeurde pakketten en weigert gewijzigde of niet klaargezette pakketten.

Gecontroleerd voor 0.17.1: 15 bronworkflowcontroles (directe download en wijzigingsblokkade), 20 pakketbeleidscontroles en een nieuwe klaarzet-export gelezen met de ongewijzigde 0.17.0-importer. De bronproef gebruikt uitsluitend het synthetische wegwerpnetwerk.

## Consistente controlefeedback (0.17.2)

`php tests/publication-result.php` en dezelfde opdracht met argument `ajax` controleren normale publicaties, preflight en softwarefeedback uit hetzelfde rapport. `node tests/admin-check-feedback.cjs` voert de echte beheer-JavaScriptcode uit in een geïsoleerde DOM-simulatie: een oude foutmelding verdwijnt na software-updaten, het nieuwste resultaat verschijnt, en revisie- en wijzigingsbevestigingen worden tegelijk ververst. Geen WordPress-database of transport gebruikt.

## Downloadgeheugen (0.17.3)

`php tests/archive-download.php` maakt een synthetische ZIP van ruim 60 MiB en verstuurt die met de echte downloadmethode in een apart PHP-proces met 128 MiB geheugenlimiet, circa 88 MiB bezet geheugen en een bestaande uitvoerbuffer. De uitvoer wordt rechtstreeks naar een tijdelijk bestand geschreven; omvang en SHA-256 moeten exact overeenkomen. De test gebruikt geen WordPress-database, netwerk, uploads of klantgegevens.

## Pakketbibliotheek (0.18.0)

`php tests/handover-library.php` controleert historische pakketadoptie, nieuwste-eerst-sortering (ook bij gelijke tijdstempels), afzonderlijke pakketselectie, behoud na bronwijzigingen, verwijderen/ongedaan maken, herkomst en site-isolatie, pad- en symlinkbeveiliging en opruimen van uitsluitend vervallen verwijderde pakketten. `handover-source.php` controleert met de echte export dat twee complete ZIP-momentopnamen bewaard blijven en na bronwijzigingen downloadbaar blijven. Deze werking vervangt de historische wijzigingsblokkade uit 0.17.1.

## Standalone vervanging — 0.19.0

`standalone-replacement.php` vereist een nieuwe zelfstandige fixture met standaard WordPress-inhoud, de bestaande ACF Pro-/Dashboard-/Fluent Forms-testcombinatie en de gebouwde importer. Gebruik uitsluitend `EMSF_DISPOSABLE_TEST=yes`, een afzonderlijke testdatabase en een pad met `emsf-integration-handover-`. De test wijzigt/verwijdert alleen synthetische fixture-inhoud en onderschept mail/HTTP via de bestaande offline mu-plugin. Controleert standaardinhoud tegenover bewerkte inhoud, onveranderde inhoud bij upload, expliciete/stale bevestiging, gepland versus werkelijk domein, correct opgeslagen home, fout/rollback, volledige vervanging van pagina’s/media/formulieren, downloadbare herstelkopie, corruptiedetectie, herstel en behoud van ook later aangemaakte accounts.

Met `EMSF_REPLACEMENT_PHASE=stage-crash` stopt dezelfde fixture vóór een volledige vervanging. Draai daarna `standalone-replacement-crash.php` met `EMSF_REPLACEMENT_PHASE=crash` in een apart proces (verwachte exitcode 73), gevolgd door `EMSF_REPLACEMENT_PHASE=recover`. Dit controleert een echte processtop midden in de databasetransactie, oorspronkelijke inhoud en media, bewaarde herstelkopie, hervatten en terugzetten. Gebruik een verse wegwerpdatabase; nooit klant- of Factory-databases.

`standalone-software-replacement.php` controleert dezelfde route op een aparte verse `emsf-integration-handover-software-improvements-`-installatie zonder vooraf geïnstalleerde ACF/Dashboard/Skeletor. Gebruik een synthetisch pakket met software via `EMSF_SOFTWARE_PACKAGE`, blokkeer mail/HTTP en voer de fases `EMSF_SOFTWARE_PHASE=stage`, `software`, `content` en `restore` in afzonderlijke processen uit. Controleert de herstelkopie vóór software-installatie, behouden voorbeeldinhoud na upload/installatie, echte inhoudsvervanging en herstel van ook het oorspronkelijke actieve thema en de plugin-keuzes.

`php tests/importer-archive.php` controleert zonder WordPress/database het werkelijk via de beheerinterface gemaakte importerarchief: alle runtimebestanden bytegelijk aanwezig (inclusief StandaloneContent), gelijke versie en toegangscontrole.

## Importbediening (0.20.0)

`handover-integration.php` controleert dat de importer een afzonderlijke activeringsactie biedt zonder verplichte handmatige kwaliteitschecklist. `handover-release.php` activeert na geslaagde automatische controles zonder checklist, controleert verwijdering van de tijdelijke bezoekers/mail/HTTP/cron-beveiliging, indexering en idempotente herhaling; het rapport registreert geen verzonnen kwaliteitsbevestigingen. `standalone-ux.php` rendert de opeenvolgende schermen in het geïsoleerde WordPress-testproject en controleert actievolgorde, gesloten herstelgegevens en vervangbevestiging.

## Websitemeldingen (0.21.0)

`php tests/website-notices.php` draait zonder WordPress, database of transport. Controleert de afzonderlijke weergaveschakelaar, standaardbehoud, beide echte footerhooks, voortgezette previewregistratie, mail- en formulierblokkade, afzonderlijk beheerformulier en bevoegdheden.

### Websitemeldingen 0.22.0

De zelfstandige `website-notices.php`-controle simuleert nu ook WordPress' no-op bij een eerste boolean `false`, teruglezen na opslag en onafhankelijke sitekeuzes. `website-notices-integration.php` vereist het disposable emsf-integration-netwerk; controleert echte netwerkopties, site-meta, POST-formulier en weergave na cacheverversing, zonder actieve klantinhoud te wijzigen.

Gebruik voor de volledige beheerroute `EMSF_DISPOSABLE_TEST=yes EMSF_NOTICE_FULL_ROUTE=yes wp --skip-plugins=emonks-site-factory eval-file tests/website-notices-integration.php` in het disposable online netwerk met ACF Pro actief. Dit controleert ook Admin → Receiver, de teruggegeven online status en behoud van mail-/activiteitsbeleid.

## Automatische activering 0.23.0

`handover-auto-activate.php` test hervatten van een bevestigde import, afwijzing van verkeerde operaties, HTTPS en rapportfouten, behoud van beveiliging bij fouten, automatische activering en herhalen zonder dubbele inhoud. Gebruik uitsluitend een disposable handover-fixture na `handover-integration.php`. De oude `release()`-API blijft beschikbaar voor de expliciete herstelroute.

`handover-auto-fresh.php` controleert de volledige nieuwe import met onmiddellijke activering; bereid de fixture voor met `EMSF_TEST_STOP_AFTER_STAGE=yes`.

`php tests/handover-comments.php` gebruikt uitsluitend een tijdelijke SQLite-database in het geheugen: controles voor gewone/goedgekeurde/openstaande/verweesde reacties, weggegooide reacties, databasefouten, WooCommerce-bestelnotities, gemengde inhoud en behoud van de bron. Geen WordPress- of klantdatabase nodig.

## Volledige zelfstandige site-export (0.24.0)

`php tests/full-site-policy.php` controleert tabel-/bestandspaden, veilige schemaopbouw zonder SQL uit pakketten uit te voeren, uitsluiting van Factory/transients/queues en behoud van geserialiseerde instellingen bij URL-/padvervanging. Bestaande software-, pakketbeleid- en bibliotheektests blijven van toepassing. De volledige roundtrip gebruikt uitsluitend een afzonderlijke lege WordPress met eigen MySQL-database en een permanente mu-plugin die mail en HTTP blokkeert; stel 512 MB PHP-geheugen in voor het volledige thema plus WooCommerce. Gebruik geen bestaand Factory-netwerk als importbestemming. De private klant-JSON/ZIP-bestanden en testdatabase worden niet in Git opgeslagen.

## Hervatbare upload (0.24.1)

`php tests/upload-transport.php` draait zonder database in een eigen tijdelijke map. Controleert privéopslag, beheerder-/sitebinding, bestandsgrootte, deelvolgorde, idempotente retries, gewijzigde retry-inhoud, herstel van een niet vastgelegde staart, onvolledige afronding, voltooiingsstatus en verloop/opruimen. De daadwerkelijke multipart-route is daarnaast lokaal getest met een 12 MB synthetische ZIP op PHP met uploadlimiet 5 MB, geforceerde onderbreking en SHA-256-vergelijking. Hostingproxy/WAF en browserfilepicker blijven apart te testen op de bestemming.

## Hostinggegevens op lege bestemming (0.24.2)

`php tests/full-site-hosting.php`: bekende SiteGround-logtabellen blijven ongelezen/ongewijzigd bij de leegtecontrole, maar een botsing met een inkomende tabel blijft blokkerend. De test weigert onbekende tabellen, bestellingen en inzendingen. Vanaf 0.24.3 mag bestaande WordPress-inhoud door naar de expliciete vervangingskeuze; full-site-policy test dat die inhoud niet als leeg of als ongewijzigde voorbeelden mag worden behandeld. Actieve hostingplugins worden exact op pluginpad behouden; er is geen brede naam-/prefixuitzondering.

## Automatische installatie met eigen back-up (0.25.0)

Gebruikersinstructie 2 november 2026 vervangt de verplichte interne herstelkopie en bevestigingsroute. `node tests/direct-import-ui.cjs` controleert het automatisch starten van beide afzonderlijke verzoeken, foutweergave, hervatten en bfcache. `direct-import-integration.php` is een destructieve eigen WordPress-testfixture met blokkerende mail/HTTP-mu-plugin, eigen MySQL, ACF Pro, Dashboard en een minimaal Skeletor 1.0.0-thema; alleen uitvoeren met EMSF_DISPOSABLE_TEST=yes en een pad met emsf-integration-direct-. Test softwareinstallatie en gegevensvervanging zonder interne kopieën, een geïnjecteerde rollback, hervatten, automatisch activeren en geen dubbelen. De oudere backup-/bevestigingsverwachtingen uit historische vervangingstests zijn geen huidige standaard meer.

## Multisite-downloadlinks (0.25.2)

`php tests/full-site-media-urls.php` controleert zonder WordPress/database/netwerk uploadlinks via netwerk- en subsiteadres, HTML, geserialiseerde velden en JSON, behoud van externe links, gewone paginalinks, lokale paden, sites in submappen/met poort en bytegelijk overzetten van een PDF. Aanleiding: het Stropstrikkers-pakket van 2 november 2026 bevatte de maattabel wel, maar combineerde een netwerk-uploadadres met subsite-links in productteksten.

## Online beheerthemamap (0.25.3)

`php tests/online-admin-theme.php` controleert de echte Admin → Receiver-voorcontrole zonder database of netwerk. Reproduceert de ontbrekende standaardmap en test afwijkende actieve themamappen per site, negeren van aangeleverde themakeuzes, blokkeren van ontbrekende/ongeldige thema’s, beheerder- en sitevalidatie vóór thema-opvraging en behoud van de lokale remote-instelling.

## Inloggen via wp-admin (0.25.4)

`php tests/admin-login.php` en `php tests/admin-login.php rest` voeren de echte privacygate uit met synthetische WordPress-verzoeken. Controleert login via wp-admin bij publieke/code/gesloten voorstellen, detailpagina’s, de rechtstreeks bereikbare loginpagina, behoud van AJAX/REST/admin-post/uploadafscherming, toegang voor siteleden, weigering voor ingelogde niet-leden, offline status en de publieke/gesloten frontend. Zonder database of transport; de standaard WordPress auth_redirect-functie wordt als grens onderschept.

## Menukoppelingen na import (0.25.5)

`php tests/menu-locations.php /pad/naar/wordpress` draait zonder database of netwerk met de echte WordPress-functies `_wp_menus_changed()` en `wp_map_nav_menu_locations()`. Dertien checks reproduceren het overschrijven met oude menu-ID’s, controleren behoud van header/mobiel/footer, lege en gedeeltelijke toewijzingen, herhaalde callbacks en behoud van overige thema-activatie. De import ruimt uitsluitend `theme_switch_menu_locations` op binnen de bestaande importtransactie. Geen volledige publicatie uitgevoerd op een klantwebsite voor deze regressietest.
