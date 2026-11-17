# Laravel aplikace s možností přepínání konkrétní implementace plánovače

## Předpoklady
- Aplikace používá Laravel jako runtime a vstupní body (artisan, kontejner, konfigurace).

## Implementace
- Doménová vrstva definuje kontrakty a value objekty. Je zcela nezávislá na frameworku, na kterém aplikace běží.
- Konfigurace probíhá přes Laravel. Aplikace by ale mohla běžet i na Symfony: změnil by se pouze způsob, jakým se jednotlivé třídy registrují (bundle místo service provideru). Doména ani tasky by se neměnily.
- Je naznačeno, jak by mohla vypadat implementace Crunz. Registrace tasků je hotová a ověřená proti crunz 3.9, zbytek jsou kostry a v současném stavu není funkční.

## Struktura projektu
```
src/Domain          kontrakty a value objekty, mutex (Lock, LockFactory, LockStoreInterface)
src/Application     registr tasků, RegisterSchedule, porty (SchedulerInterface, …)
src/Infrastructure  Laravel bridge, adaptér Crunz, úložiště mutex (SQLite přes PDO), čtení atributů
app/Tasks           tasky aplikace
```
- Složka `src` je rozdělena podle vrstev. V reálné aplikaci by byla spíše rozdělena podle DDD domén, které by se pak dělily do vrstev. Závislosti vedou jen dovnitř: Infrastructure → Application → Domain.
- Pravidla závislostí mezi vrstvami zatím hlídá jen konvence. Dala by se vynutit automaticky, například architektonickými testy v Pestu (`arch()`) nebo nástrojem Deptrac, spouštěnými v rámci `composer check`.

## Vlastní zámek (mutex)
Laravel používá pro mutex cache s TTL 24 h. Spadlý proces tak může zablokovat task až na den a pozdní uvolnění může smazat cizí zámek. Navržený mutex proto:
- má **token vlastníka**, takže obnovit nebo uvolnit ho může jen ten, kdo ho získal,
- má **TTL nastavitelné pro každý task** (`lockTtl`): po pádu procesu je task blokovaný jen po tuto dobu,
- zapisuje do úložiště **jednou atomickou operací**, což ověřuje test, v němž o mutex soupeří 12 procesů,
- používá **vyměnitelné úložiště** (`LockStoreInterface`). Teď je to SQLite, Redis by byla jedna další třída.

Do Laravelu se mutex zapojuje přes jeho rozšiřovací body: `preventOverlapsUsing()` (`TaskEventMutex`) a binding `SchedulingMutex` (`onOneServer`).

### Konfigurace
Přepínání konkrétní implementace probíhá změnou `'engine' => env('SCHEDULER_ENGINE', 'laravel')` v `config/scheduler.php`.

`SchedulerServiceProvider` je místo, kde framework načítá a registruje objekty pro zvolenou implementaci.

Plánovač se spouští přes Laravel command `app:scheduler:run`, který nezávisí na zvolené implementaci. Pokud je vybrán Laravel, fungují všechny příkazy Laravel scheduleru (`schedule:list`, `schedule:test`, `schedule:work`). V případě Crunz by fungovaly příkazy této knihovny.

Tasky se definují ve složce `app/Tasks`. Task je libovolná třída s metodou `__invoke`, plánování se nastavuje atributem `#[ScheduledTask]`. Atribut lze opakovat, takže jedna třída může běžet podle více cronů:

```php
#[ScheduledTask('* * * * *')]
#[ScheduledTask('*/2 * * * *', lockTtl: 60, onOneServer: true)]
final readonly class ExampleTask { public function __invoke(): void { /* … */ } }
```

Registrace tasku pak probíhá v konkrétní implementaci `SchedulerInterface`. V případě Laravelu se `TaskDefinition` převede na jeho nativní funkce:

```php
$event = ($this->schedule)()->call($task->taskClass)   // Laravel kontejner vytvoří task a zavolá __invoke()
    ->cron($task->cron->expression)
    ->timezone($task->timezone);

if ($task->withoutOverlapping) {
    $event->withoutOverlapping()->preventOverlapsUsing(new TaskEventMutex(/* custom mutex */));
}
if ($task->onOneServer) {
    $event->onOneServer();
}
```

## Spuštění a kontrola
```bash
composer setup                # composer install, .env, klíč aplikace a soubor SQLite ve var/
php artisan schedule:work     # lokálně každou minutu spouští tasky, které jsou na řadě
php artisan schedule:list     # přehled tasků
composer check                # Pint (PSR-12), PHPStan level 10, testy
```
