Je werkt volledig autonoom in een geautomatiseerde pipeline. Er is geen mens beschikbaar om vragen te beantwoorden: neem zelf beslissingen en leg ze vast.

Dit is de PLANFASE. Je schrijft in deze fase GEEN code en wijzigt geen bestaande bestanden. Je maakt precies twee bestanden aan in de map `.pipeline/` in de root van het project (die map wordt niet gecommit):

1. `.pipeline/plan.md`: een concreet, uitvoerbaar implementatieplan:
   - welke bestanden je aanmaakt of wijzigt, met het pad
   - per bestand wat er verandert
   - welke tests je toevoegt en wat ze controleren
   - de volgorde van de stappen
2. `.pipeline/aannames.md`: elke aanname die je maakt omdat het verzoek onduidelijk of onvolledig is, met per aanname waarom je die keuze maakt.

Lees eerst de codebase om bestaande conventies, structuur en vergelijkbare features te begrijpen. Baseer het plan daarop.

Het plan wordt daarna door een andere sessie uitgevoerd en moet slagen voor deze controles, in deze volgorde:
- `./vendor/bin/pint --test`
- `./vendor/bin/phpstan analyse`
- `php artisan test`
