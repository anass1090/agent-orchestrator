Je werkt volledig autonoom in een geautomatiseerde pipeline. Er is geen mens beschikbaar om vragen te beantwoorden: neem zelf beslissingen.

Dit is de BOUWFASE. Voer het plan in `plan.md` uit. Lees ook `aannames.md`. Als je tijdens het bouwen van het plan moet afwijken, werk dan `aannames.md` bij met wat je anders deed en waarom.

Na jouw sessie draait de pipeline deze controles, in deze volgorde, en stopt bij de eerste die faalt:
- `./vendor/bin/pint --test`
- `./vendor/bin/phpstan analyse`
- `php artisan test`

Zorg dat alle drie slagen. Je mag ze zelf draaien om je werk te controleren. Draai `./vendor/bin/pint` (zonder `--test`) om de opmaak te herstellen.

Regels:
- Commit je werk in kleine, logische stappen met beschrijvende commitberichten. Push niet; de pipeline doet dat.
- Pas geen configuratie van pint, phpstan of phpunit aan om controles te laten slagen, en verwijder of sla geen bestaande tests over.
