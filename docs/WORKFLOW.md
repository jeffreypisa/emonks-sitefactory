# Klantopvolging en publicatiecontrole — 0.6.0

## Klanten opvolgen

Open Site Factory → Alle sites. Naast de technische status staat de klantfase. Zoek op sitenaam, filter op fase of vink Vandaag opvolgen / te laat aan. Akkoord en Geen interesse verschijnen niet meer in de lijst met verlopen opvolgdatums.

Open een site → Klantopvolging. Kies Nog benaderen, Eerste mail verstuurd, Interesse ontvangen, Websitevoorstel uitwerken, Vervolgmail met website verstuurd, Aanpassingen bespreken, Akkoord of Geen interesse. Vul contactnotities, afgesproken prijs exclusief btw en een opvolgdatum in. Deze prijs is een contactafspraak; hij verandert niet automatisch de kosten in je voorstelmail.

Na het zelf versturen gebruik je **Eerste mail verstuurd** of **Vervolgmail verstuurd**. Beide momenten hebben een eigen datum. Opgeslagen notities en afspraken blijven behouden. Een herhaalde klik behoudt de datum en zet een latere fase niet terug. Met de fasekeuze kun je de fase zelf corrigeren. Ook het handmatig kiezen van een verstuurdfase legt het bijbehorende moment vast als dat nog ontbreekt. Mails maken, kopiëren, inloggen of bekijken zet nooit automatisch de klantfase.

Oude registraties “Verstuurd” en “Reactie ontvangen” blijven herkenbaar als oude registratie tot je een passende nieuwe fase kiest. Een oude verzenddatum krijgt geen verzonnen mailtype.

Opvolging staat in site-meta, buiten contentpakketten. Herpubliceren en contentherstel overschrijven de gegevens niet. Lokale en online netwerken hebben ieder hun eigen opvolggegevens; er is geen automatische synchronisatie of klantmail/reminderverzending. Werk consequent in het netwerk waar je voorstellen beheert.

## Eerst controleren, dan publiceren

Installeer dezelfde 0.6.0-plugin lokaal en online. Open Publiceren → Publicatie vooraf controleren. Dit maakt tijdelijk een lokaal controlepakket of gebruikt het ongewijzigde pending-pakket, en vraagt de ontvanger om een inhoudelijke controle. Er wordt online geen site aangemaakt en geen uploadoperatie gestart. Lokale controlepakketten worden daarna opgeruimd.

De controle omvat pakketintegriteit, dezelfde geldige themaversie (bestandshashes zijn vanaf 0.11.0 alleen diagnostisch), benodigde ACF/Fluent Forms-functionaliteit, projectmodellen en inhoudstypen/taxonomieën, mediapaden en bestandsgroottes. Pluginversies hoeven nog steeds niet exact gelijk te zijn. Het resultaat toont aantallen en omvang, aandachtspunten en online wijzigingen. Controleer klantgegevens nog steeds bij de eerste publicatie; accountbeschikbaarheid wordt bij de bestaande publicatieroute gecontroleerd.

Bij vervangen ziet de beheerder de revisie van de online site. Voor gedetecteerde wijzigingen of een oudere site zonder nulmeting moet hij de online inhoud bekijken en expliciet bevestigen dat deze vervangen mag worden. Het pakket en de revisie worden opnieuw gecontroleerd vóór upload en vóór daadwerkelijk vervangen. Wijzigt online inhoud na de controle of tijdens upload, dan stopt de operatie vóór backup/import. Annuleer die nog niet toegepaste upload onder Beheer & herstel en voer een nieuwe controle/publicatie uit; herschrijf nooit het bestaande pakket onder hetzelfde operatie-ID.

## Wat wordt gevolgd?

Na succesvolle publicatie wordt een nulmeting vastgelegd. Normale WordPress-wijzigingen aan berichten, pagina's, mediarecords, berichtvelden, termen/categorieën, menu's en relevante thema-/siteopties veranderen de revisie. Bewerkingen van niet-netwerkbeheerders worden als klantbewerkingen herkenbaar gemaakt. Revisies, automatische concepten en bewerkvergrendelingen tellen niet apart mee. Een expliciet teruggezette backup telt als een online wijziging ten opzichte van de vorige publicatie.

Dit is geen volledige databasesynchronisatie of verschilvergelijking. Directe SQL, handmatig vervangen mediabestanden en wijzigingen in eigen plugintabellen (zoals formulierdefinities via een pluginroute) worden niet door deze WordPress-hooks gedetecteerd. Er worden geen online wijzigingen automatisch samengevoegd. De bestaande backup vóór vervangen blijft nodig en behouden. Bestaande sites zonder nulmeting worden niet stilzwijgend als ongewijzigd beschouwd.

## Voortgang en herstel

Tijdens publiceren zie je de werkstappen: controleren, lokaal verzamelen, voorbereiden, bestanden uploaden met teller en online backup/toepassen. De online import is één stap en krijgt geen verzonnen percentage. De browser leest voortgang apart uit; bij hosting met weinig beschikbare PHP-processen kan de teller vertraagd zijn. Zonder JavaScript blijven de bestaande formulieren bruikbaar, zonder live voortgang.

Bij een verbroken verbinding controleer je eerst Status. Wanneer de exacte openstaande operatie online al voltooid blijkt, wordt de lokale pending-status opgeruimd. Een ontvangen maar nog niet toegepast pakket kan worden hervat of geannuleerd. Een mislukte publicatie met backup toont een passende herstelactie. De specifieke model-/teaserherstelknop blijft alleen voor de reeds toegestane fouten beschikbaar. Een onbekende of nog lopende operatie wordt niet automatisch opnieuw toegepast.

Klantaccounts, wachtwoorden, vaste previewcodes, mailstand en opvolgnotities blijven bij vervangen behouden. Niets in deze update verzendt klantmail of publiceert bestaande sites automatisch.
