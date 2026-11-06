# Zelfstandige WordPress opleveren

Site Factory 0.25.1 installeert een volledig opleverpakket automatisch op een zelfstandige WordPress-installatie. Deze route maakt geen hosting aan en wijzigt geen DNS.

## Eigen back-up, geen extra herstelkopie

Gebruikersinstructie van 2 november 2026: de beheerder maakt vóór het gebruik van de importplugin zelf een back-up. De importer maakt standaard geen extra herstelkopie. Na upload zijn geen aanvullende inhouds-, verwijderings- of adresbevestigingen nodig. De getoonde bestemmingsinstallatie is leidend voor het adres; de pakketinhoud vervangt de overgedragen website-inhoud en tabellen. De eerdere lege-bestemmingblokkades uit 0.24.x en verplichte bevestigings-/herstelkopiestappen gelden niet meer voor deze zelfstandige import.

De aparte netwerkpublicatie naar sites.emonks.nl en het bijwerken van gedeelde netwerksoftware blijven hun eigen herstelmechanisme gebruiken. Deze wijziging is geen toestemming om dat herstelmechanisme elders te verwijderen.

## Pakket klaarzetten

Open **Site Factory → Alle sites → [site] → Oplevering** op de omgeving met de definitieve inhoud. Vul het geplande HTTPS-adres in en kies **Pakket klaarzetten**. Een geslaagde export wordt als onveranderlijke ZIP met manifest, controlesommen en operatiecode bewaard. Downloaden gebruikt dat opgeslagen pakket. Download daarnaast de actuele tijdelijke importplugin.

Eerdere pakketten blijven ongewijzigd beschikbaar. Verwijderen verbergt alleen het gekozen pakket; dit is 30 dagen herstelbaar. De private pakketlijst is per site gescheiden. Log en details toont de herkomst en softwareversies. Pakketten bevatten klantgegevens en sitegebonden integratiesleutels: bewaar ze privé.

Bestaande volledige 0.24.x-pakketten blijven bruikbaar met importer 0.25.0; een nieuwe export is voor deze aanpassing niet nodig.

## Bestemming voorbereiden

Gebruik een zelfstandige WordPress-installatie met eigen beheerder, PHP 8.1+, ZIP, InnoDB, schrijfbare uploads en voldoende private opslag. Voor een omvangrijke webshop is 512 MB PHP-geheugen aanbevolen. WordPress moet via HTTPS toegankelijk zijn. Een strijdige WP_HOME-constante moet eerst in de hostingconfiguratie worden aangepast.

Scherm website en rechtstreekse media af via de hosting tijdens de installatie. Stel WP_TEMP_DIR bij voorkeur in op een blijvende private map buiten alle webroots. Beschermde albumdownloads komen blijvend naast de WordPress-webroot te staan; bij andere hostingindelingen kan EMSF_PRIVATE_DOWNLOAD_DIR op een eigen private map worden ingesteld. Controleer dat de webserver die map niet rechtstreeks aanbiedt.

Installeer de actuele importplugin. Skeletor en de benodigde plugins komen uit het pakket. Al bestaande pakketsoftware moet qua versie en runtime overeenkomen; een incompatibele installatie geeft een concrete fout. Actieve SiteGround Security Optimizer en Speed Optimizer blijven behouden, inclusief niet overgedragen bestemmingstabellen en instellingen. Netwerkbrede licenties en hostingdiensten moeten zo nodig op de bestemming worden geactiveerd.

## Uploaden en automatisch installeren

Open **Gereedschap → Site opleveren**, kies de grote site-oplevering-ZIP en bevestig de hostingafscherming. Kies **Pakket uploaden**. Kies hier niet de kleine importplugin-ZIP.

Grote pakketten worden via geauthenticeerde WordPress-AJAX-verzoeken in delen van maximaal 4 MiB verstuurd. Bij een onderbreking kun je in dezelfde browser hetzelfde bestand opnieuw kiezen en hervatten vanaf het laatst bevestigde deel. Uploadcodes verlopen na 24 uur; eigen verlopen uploads worden bij een nieuwe upload opgeruimd. Er zijn maximaal drie eigen onvoltooide uploads tegelijk toegestaan. De ontvangen ZIP wordt volledig gecontroleerd en uitgepakt voordat software of inhoud wordt toegepast.

Na upload volgen automatisch:

1. Thema en plugins installeren/activeren.
2. Een nieuw verzoek laden, zodat de geïnstalleerde code beschikbaar is.
3. Inhoud, instellingen, relaties, uploads en downloads overzetten; bestaande overgedragen inhoud vervangen.
4. De technische eindcontrole uitvoeren en de website activeren.

Er verschijnt geen extra herstelkopie- of vervangingskeuze. De knop **Installatie hervatten** blijft beschikbaar wanneer een stap faalt. Een al gecommitteerde operatie wordt niet opnieuw toegepast. Oude foutmeldingen worden na een geslaagde hervatting gewist. Terugnavigeren via browsercache laadt de actuele installatiestatus opnieuw.

Technische controles blijven actief: beheerdersrechten, nonce, pakketprovenance en hashes, software-identiteit, vrije opslag, schrijfbaarheid, klantaccountconflicten en databaseconsistentie. Dit zijn geen extra gebruikersbevestigingen. Een beschadigd pakket of mislukte stap wordt niet stilzwijgend als voltooid gemarkeerd.

De database-import blijft één transactie met een duurzame voltooiingsregistratie binnen dezelfde transactie. Een fout of verbindingsstop vóór commit draait de databaserijen terug. Bestanden en geïnstalleerde software zijn geen onderdeel van die database-rollback; de beheerder gebruikt voor volledig herstel zijn eigen vooraf gemaakte back-up. De importer kopieert/verifieert deterministische doelbestanden opnieuw bij hervatten. Oude niet overgenomen uploadbestanden worden in deze directe route niet via een interne herstelkopie opgeruimd.

De browserstatus kan proxy-/PHP-fouten tonen, maar hostinglimieten voor uitvoeringstijd, geheugen en schijfruimte blijven van toepassing. Maximale pakketomvang: 16 GiB uitgepakt en 20.000 gegevensdelen; software maximaal 256 MiB gecomprimeerd en uitgepakt, maximaal 25.000 entries per softwarezip.

## Wat gaat mee?

Volledige export (emonks-full-site-v1) bevat de eigen subsite-tabellen met behoud van oorspronkelijke IDs, uploads met afgeleiden, lokale fonts, huidige Nederlandse/andere actieve taalbestanden en beschermde WooCommerce-downloads. Ook producten, varianten, voorraad, prijzen en acties, verzending/betaling, orders/refunds, bestelregels, notities, downloadrechten, redirectregels, Fluent Forms-formulieren/inzendingen en sitegebonden integraties zoals Mailchimp gaan mee. Projectmodellen worden als zelfstandige ACF-databaseconfiguratie opgeslagen.

Het pakket bevat de runtime van het thema, actieve siteplugins en benodigde actieve netwerkplugins. Site Factory zelf gaat niet mee. Subsite-klant-/redacteuraccounts gaan met hun profielgegevens en wachtwoordhash mee; bron-/netwerkbeheerders, login-sessies en applicatiewachtwoorden niet. Bestaande beheerderaccounts op de bestemming blijven behouden; een accountbotsing die niet eenduidig kan worden geïmporteerd geeft een technische fout.

Niet overgedragen: andere sites, globale netwerktabellen, Factory-toegang/publicatieverbindingen, prospectdossiers, hostingconfiguratie, netwerkbrede licenties, transients, oude cron-/Action Scheduler-/Fluent Forms-taakqueues en tijdelijke WooCommerce-sessies/reserveringen. URLs en paden worden met behoud van geserialiseerde waarden aangepast. Niet ondersteunde mu-plugins, drop-ins, uitvoerbare uploads of tabelstructuren geven bij export een concrete fout.

Oudere inhoudspakketten (emonks-site-package) blijven bruikbaar met hun bestaande inhoudsadapter. Publiceren naar het voorstelnetwerk gebruikt deze beperkte adapter en is geen volledige webshopmigratie.

## Afronden

Na een geslaagde technische controle wordt de tijdelijke importer-guard verwijderd en worden bezoekers, mail, integraties, cron en indexering vrijgegeven. De hostingafscherming en DNS beheer je zelf. Controleer op de werkelijke hosting pagina’s, beheer, redirects, checkout, daadwerkelijke betalingen en nieuwsbriefinschrijving. Het automatische rapport is geen verklaring dat zulke externe controles zijn uitgevoerd.

Download het rapport, ruim desgewenst de tijdelijke pakketbestanden op en verwijder de tijdelijke importplugin. De website blijft zelfstandig werken. Beschermde downloads staan buiten tijdelijke pakketopslag. Een eventueel al bestaande interne herstelkopie uit een oudere importer blijft afzonderlijk beschikbaar; er wordt geen nieuwe gemaakt en die oudere kopie blokkeert de directe installatie niet.

## Release en verificatie

Bouw met python3 scripts/build-zip.py en python3 scripts/build-importer.py. Plugin- en importerversie moeten gelijk zijn. Dit is Site Factory-pluginwerk; Skeletor krijgt geen themawijziging of nieuwe themaversie.

0.24.0 is op twee wegwerpdatabases met het volledige StropStrikkers-pakket getest: 62 tabellen, 1.309 uploads, 11 beschermde bestanden, 22.181 vergeleken oorspronkelijke rijen, 84 businesschecks en 58 controles zonder actieve importplugin. 0.24.1 voegde hervatbare uploads toe (26 gerichte checks en 13 lokale HTTP-checks met een 12 MB-ZIP op een 5 MB-serverlimiet). De echte hosting toonde daarna de te strenge leegteblokkades, die in 0.24.2/0.24.3 gericht werden aangepast.

0.25.0 volgt de nieuwe expliciete keuze voor een eigen vooraf gemaakte back-up en automatische installatie. Een echte WordPress/MySQL-test vervangt bestaande inhoud zonder bevestigingsvelden of interne kopieën, injecteert een fout om transactionele rollback te controleren en rondt daarna via hervatten automatisch af. De browserfase wordt met gerichte JavaScript-tests gecontroleerd op automatisch starten, verse verzoeken, foutweergave, idempotent hervatten en browsercache. Er zijn geen mails, betalingen of Mailchimp-inschrijvingen verstuurd. De nieuwste versie is hiermee niet als getest op de werkelijke SiteGround-hosting aangemerkt.

0.25.1 vernieuwt na het vastleggen van de volledige import de WooCommerce-attributencache en producttransients, inclusief varianten, prijzen en kinderen. WooCommerce kan bij installatie vóór de tabelimport een lege attributenlijst cachen; alleen de objectcache wissen is daarvoor onvoldoende. Een bestaande export blijft bruikbaar. Reeds geïnstalleerde sites kunnen dezelfde correctie uitvoeren via WooCommerce → Status → Tools → WooCommerce transients → Transients wissen, gevolgd door het legen van de hosting-paginacache. Sla producten hiervoor niet opnieuw op en importeer geen bestellingen opnieuw. De correctie is op de live StropStrikkers-installatie gecontroleerd: Maat en de vijf hoodievarianten zijn weer zichtbaar.
