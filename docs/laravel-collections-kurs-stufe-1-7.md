# PHP / Laravel Collections – Tiefe Lernrunde (Stufe 1–7)

> Kompakte, eigenständige Referenz aller sieben Stufen. Fokus: **WARUM vor WIE** –
> interne Mechanik, bewusste Entscheidungen, Fallen und Anti-Patterns.
> Roter Faden: **DataPipe** – eine API, die große CSVs memory-safe verarbeitet und
> Reports generiert (Generator → LazyCollection → Aggregation → DB).

**Voraussetzungen (als bekannt angenommen):** Laravel-Grundlagen (Eloquent, Controller,
Service-Klassen), PHP-Dateisystem tief (Streams, Generatoren/`yield`, memory-safe I/O),
funktionale Denkweise aus JavaScript (`map`/`filter`/`reduce`).

---

## Inhalt

1. [Was eine Collection *ist* (das Fundament)](#stufe-1--was-eine-collection-ist)
2. [Die Kern-Transformationen (map, filter, reject, each, reduce)](#stufe-2--die-kern-transformationen)
3. [Umstrukturieren & Gruppieren](#stufe-3--umstrukturieren--gruppieren)
4. [Aggregation](#stufe-4--aggregation)
5. [Pipeline-Denkweise & Higher-Order Messages](#stufe-5--pipeline-denkweise--higher-order-messages)
6. [LazyCollection & der Generator-Zusammenhang](#stufe-6--lazycollection--der-generator-zusammenhang)
7. [Die bewusste Entscheidung, Eloquent & Anti-Patterns](#stufe-7--die-bewusste-entscheidung-eloquent--anti-patterns)
8. [Der ganze Kurs in einem Bild](#der-ganze-kurs-in-einem-bild)

---

## Stufe 1 – Was eine Collection *ist*

### Die Kernaussage
Eine Collection ist **ein Objekt, das ein einziges PHP-Array kapselt** – nicht mehr.
`Illuminate\Support\Collection` hat eine geschützte Eigenschaft `$items` (ein ganz
normales Array) und ~100 Methoden drumherum.

```php
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    protected $items = [];          // hier steckt ein ganz normales PHP-Array

    public function __construct($items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }

    public function all()           // gibt genau dieses Array zurück
    {
        return $this->items;
    }
    // ... map, filter, reduce, groupBy, sum ...
}
```

```
   collect(['a', 'b', 'c'])
              |
              v
  +-----------------------------------+
  | Collection  (ein Objekt)          |
  |   $items = ['a', 'b', 'c']        |  <- nur ein normales PHP-Array
  |   ->map() ->filter() ->sum() ...  |  <- ~100 Methoden drumherum
  +-----------------------------------+
```

### Warum überhaupt? `array_map` gibt es doch
Kein neues Können, sondern **Ergonomie**. Drei Schwächen der nativen Funktionen:

1. **Inkonsistente Argument-Reihenfolge** – `array_map(fn, $arr)` vs. `array_filter($arr, fn)`.
2. **Key-Löcher** – `array_filter` behält die Original-Keys → Lücken → oft `array_values()` nötig.
3. **Keine Verkettung** – verschachtelte Aufrufe liest man von innen nach außen (rückwärts).

```php
// NATIV – von innen nach außen lesen
$result = array_values(array_filter(array_map(fn($n) => $n * 2, $numbers), fn($n) => $n > 20));

// COLLECTION – von oben nach unten, links nach rechts
$result = collect($numbers)
    ->map(fn($n) => $n * 2)
    ->filter(fn($n) => $n > 20)
    ->values()
    ->all();
```

### Interne Mechanik: ein dünner Wrapper
`map` ruft intern das `array_map` auf, das du kennst, und verpackt das Ergebnis in
`new static(...)`:

```php
public function map(callable $callback)
{
    $keys  = array_keys($this->items);
    $items = array_map($callback, $this->items, $keys);   // dein bekanntes array_map
    return new static(array_combine($keys, $items));      // neue Collection -> verkettbar
}
```

- **Derselbe Motor** wie nativ – nur mit besserer Fassade und `($value, $key)` im Callback.
- **`new static`** (nicht `self`) ist der Grund fürs Verketten *und* dafür, dass Unterklassen
  (Eloquent-Collection) ihren eigenen Typ zurückbekommen.

### Erzeugen & Auspacken
```php
use Illuminate\Support\Collection;

$c = collect([1, 2, 3]);          // Normalfall
$c = Collection::make([1, 2, 3]); // identisch
$c = new Collection([1, 2, 3]);

$users = User::all();                        // Eloquent liefert schon eine Collection
$users = User::where('active', true)->get(); // ebenfalls

collect(null)->all();       // []            -> tolerant, kein Fehler
collect('DataPipe')->all(); // ['DataPipe']  -> Skalar wird zu [0 => ...]
```

**`all()` vs. `toArray()`** – eine echte Falle:

| Methode | Was sie tut | Nutze, wenn … |
|---|---|---|
| `all()` | gibt **exakt `$items`** zurück (Objekte bleiben Objekte) | du die Werte/Objekte selbst weiterverwendest |
| `toArray()` | **rekursiv** zu Arrays, ruft `toArray()` auf Models | du reine, verschachtelte Arrays brauchst (JSON/API) |

> ⚠️ `toArray()` auf einer Model-Collection macht aus Models nackte Arrays – Model-Methoden weg.

### Vergleich zu JavaScript
In JS hängen Methoden am Array (`Array.prototype`). In PHP haben Arrays keine Methoden – die
Collection *simuliert* das, indem sie das Array in ein Objekt packt.

| | JavaScript | PHP nativ | Collection |
|---|---|---|---|
| Methode lebt … | am Array | freie Funktion | am Wrapper-Objekt |
| 2. Callback-Arg | Index | (nur value) | **Key** |
| Rückgabe | neues Array | neues Array | neue Collection |

### Immutability (erste Berührung)
Transformationen (`map`, `filter`, …) geben eine **neue** Box zurück, Original bleibt:

```php
$original = collect([1, 2, 3]);
$doubled  = $original->map(fn($n) => $n * 2);
$original->all();  // [1, 2, 3]  <- unverändert
```

> 🚫 **NICHT alle Methoden sind immutable.** `transform()` (mutierender Zwilling von `map`),
> `push`, `put`, `pop`, `shift`, `prepend`, `forget` ändern **in place**.
> ⚠️ Und die Immutability ist **flach**: enthaltene Objekte werden geteilt (→ Stufe 2).

**Mentales Modell:** Objekt mit einem Array drin + ~100 Methoden. Fast jede Methode ist ein
dünner Wrapper um eine `array_*`-Funktion. Gewinn = konsistente API + Verkettung.

---

## Stufe 2 – Die Kern-Transformationen

Die vier Werkzeuge unterscheiden sich in einer Frage: **wie viele Elemente kommen raus, und
was ist der Rückgabewert?**

```
map     * * * * *  ->  # # # # #   gleiche Anzahl, umgeformt
filter  * * * * *  ->  * * *       Teilmenge (Keys bleiben!)
reject  * * * * *  ->  * *         Teilmenge (Gegenteil von filter)
reduce  * * * * *  ->  @           ALLES gefaltet zu EINEM Wert
each    * * * * *  ->  * * * * *   unverändert (+ Seiteneffekt pro Element)
```

### Callback-Signatur: `($value, $key)`
Erst Wert, dann **Key** (nicht Index wie in JS!). Gilt für `map`, `filter`, `reject`, `each`.
Nur `reduce` beginnt mit dem Akkumulator.

```php
collect(['brot' => 2.50, 'milch' => 1.20])->map(fn($preis, $key) => "$key: $preis €");
```

### map – jedes Element umformen
Gleiche Anzahl, Keys bleiben. Bei Skalaren sauber immutable.

```php
collect([1, 2, 3])->map(fn($n) => $n * 2);  // [2, 4, 6]
```

> 🚫 **Anti-Pattern (bei Objekten):** im `map`-Callback das Objekt *mutieren* statt einen neuen
> Wert zurückzugeben – das verändert das Original mit (siehe Referenz vs. Kopie).
> ```php
> $orders->map(function ($order) {
>     $order->total *= 2;   // mutiert das ORIGINAL-Objekt!
>     return $order;
> });
> ```

### filter & reject – auswählen
`filter` behält truthy, **behält Keys** (→ Lücken → oft `values()`).

```php
$zahlen = collect([12, 5, 30, 8, 44]);
$zahlen->filter(fn($n) => $n > 10)->all();          // [0=>12, 2=>30, 4=>44]  Lücken!
$zahlen->filter(fn($n) => $n > 10)->values()->all(); // [12, 30, 44]
```

> ⚠️ Für JSON fast immer `->values()` nachschieben, sonst wird die Liste zum Objekt `{"0":..}`.

`filter()` **ohne Callback** entfernt alle falsy Werte (`0`, `0.0`, `'0'`, `''`, `null`,
`false`, `[]`):

```php
collect([0, 1, null, 3, '', 'a', '0', []])->filter()->values()->all();  // [1, 3, 'a']
```

> 🚫 In PHP sind `0` und `'0'` falsy → die legitime Null fliegt mit raus. Explizit sein:
> `filter(fn($n) => $n !== null)`.

`reject` = Spiegelbild von `filter`, existiert nur für die Lesbarkeit:

```php
$users->filter(fn($u) => ! $u->banned);   // gleichwertig
$users->reject(fn($u) => $u->banned);      // "wirf die Gesperrten raus"
```

### each – ist KEINE Transformation
Läuft für den **Seiteneffekt** und gibt die **unveränderte, selbe** Collection zurück.

```php
public function each(callable $callback)
{
    foreach ($this->items as $key => $item) {
        if ($callback($item, $key) === false) { break; }  // return false = break
    }
    return $this;   // gibt DIESELBE Collection zurück
}
```

> 🚫 Rückgabewert des Callbacks wird ignoriert:
> ```php
> collect([1, 2, 3])->each(fn($n) => $n * 2)->all();  // [1, 2, 3] – NICHT [2,4,6]! Du wolltest map.
> ```
> ✅ `each` ist für: loggen, benachrichtigen, in DB schreiben.
> ```php
> $bestellungen->each(fn($o) => $o->kunde->notify(new VersandMail($o)));
> ```

### Referenz vs. Kopie (die tiefe Falle)
- **Ebene 1 – Skalare/Arrays:** PHP kopiert sie (Copy-on-Write). `map` über Zahlen ist sicher.
- **Ebene 2 – Objekte:** Ein Array speichert nur **Handles**. Kopieren kopiert die Handles, nicht
  die Objekte → beide Collections zeigen auf **dasselbe Objekt**.

```php
$user = new User(['name' => 'Ada']);
$a = collect([$user]);
$b = $a->map(fn($u) => $u);      // neue Collection, DASSELBE Objekt
$b->first()->name = 'Linus';
$a->first()->name;               // 'Linus'  <- auch in $a geändert!
```

> ⚠️ „Neue Collection" ≠ „neue Objekte". In Transformationen **keine Objekte mutieren**, sondern
> neue Werte/Arrays/DTOs zurückgeben. (Wie Java/JS: Primitives by value, Objekte by handle –
> neu ist nur, dass PHP-*Arrays* zur Werte-Seite gehören.)

### reduce – die Akkumulator-Denkweise
Faltet die ganze Collection zu **einem** Wert. Signatur: `reduce(fn($carry, $item, $key), $initial)`.

```
[10, 20, 30]  reduce(fn($carry, $n) => $carry + $n, 0)
 carry=0 -> +10 -> 10 -> +20 -> 30 -> +30 -> 60  => Ergebnis 60
```

```php
collect([10, 20, 30])->reduce(fn($carry, $n) => $carry + $n, 0);  // 60
```

Wann statt `map`+`filter`? Wenn das Ergebnis eine **andere Form** ist (Summe, Lookup, gruppiert):

```php
$orders->reduce(function ($carry, $order) {
    $carry[$order['kunde']] = ($carry[$order['kunde']] ?? 0) + $order['betrag'];
    return $carry;
}, []);
// ['Ada' => 42, 'Linus' => 20]   (Stufe 3/4 macht das mit groupBy+sum lesbarer)
```

> 🚫 **Falle 1:** `return $carry` vergessen → ab 2. Iteration `null`, Ergebnis kaputt.
> ⚠️ **Falle 2:** Startwert weglassen → `$carry` startet als `null`; bei Arrays/Strings kracht es.
> 🔗 `reduce` ist ein **Single-Pass-Fold** → perfekt fürs Streaming über LazyCollection (Stufe 6).

### Welches Werkzeug wann?
| Werkzeug | Frage | Anzahl raus | Rückgabe |
|---|---|---|---|
| `map` | „Forme jedes Element um." | gleich | neue Collection |
| `filter` | „Behalte, welche passen." | ≤ (Keys bleiben) | neue Collection |
| `reject` | „Wirf raus, welche passen." | ≤ (Keys bleiben) | neue Collection |
| `each` | „Tu etwas pro Element." | gleich (unverändert) | dieselbe Collection |
| `reduce` | „Falte alles zu EINEM Wert." | 1 | der Akkumulator |

---

## Stufe 3 – Umstrukturieren & Gruppieren

Diese Methoden verändern die **Form** (Keys, Verschachtelung, Blöcke), nicht nur Werte.
Durchgehendes Beispiel:

```php
$orders = collect([
    ['kunde' => 'Ada',   'land' => 'DE', 'betrag' => 30],
    ['kunde' => 'Linus', 'land' => 'SE', 'betrag' => 20],
    ['kunde' => 'Ada',   'land' => 'DE', 'betrag' => 12],
]);
```

### pluck – eine Spalte herausziehen
```php
$users->pluck('name');        // ['Ada', 'Linus', 'Grace']
$users->pluck('name', 'id');  // [1 => 'Ada', 2 => 'Linus', 3 => 'Grace']
$rows->pluck('kunde.name');   // Dot-Notation für Verschachtelung
```
Funktioniert auf Arrays *und* Models. ⚠️ Mit Key-Argument überschreiben Duplikate lautlos.

### keyBy – neu verschlüsseln (ganzes Element behalten)
```php
$users->keyBy('id');            // [1 => {..Ada..}, 2 => {..Linus..}]
$users->keyBy('id')->get(2);    // O(1)-Nachschlagen
$users->keyBy(fn($u) => 'user_' . $u['id']);
```
> 🚫 **Lautloser Datenverlust:** hält 1 Element pro Key. Bei doppeltem Key gewinnt der letzte.

### groupBy – in Eimer sortieren (Liste pro Key)
```php
$orders->groupBy('land');
// ['DE' => Collection[{Ada 30},{Ada 12}], 'SE' => Collection[{Linus 20}]]
```
Werte sind **Collections** (nicht Arrays) → innen `$gruppe->sum(...)` nutzen.

✅ **Das „gruppieren + summieren" aus Stufe 2 – jetzt elegant:**
```php
$orders->groupBy('land')->map(fn($g) => $g->sum('betrag'))->all();
// ['DE' => 42, 'SE' => 20]
```
Mehrere Ebenen: `$orders->groupBy(['land', 'kunde'])`.

### keyBy vs. groupBy (zentrale Unterscheidung)
```
keyBy('kunde')                 groupBy('kunde')
'Ada'   => {Ada,DE}            'Ada'   => [{Ada,DE},{Ada,DE}]
'Linus' => {Linus,SE}          'Linus' => [{Linus,SE}]
1 Element pro Key               LISTE pro Key
Duplikate überschreiben         behält ALLE
```
**Regel:** eindeutiger Key (ID) → `keyBy`. Kategorie (Land/Status/Monat) → `groupBy`.

### mapWithKeys – Key UND Wert frei wählen
```php
$users->mapWithKeys(fn($u) => [$u['id'] => $u['name']]);   // [1 => 'Ada', ...]
```

Das Re-Key-Trio:

| Methode | Wert | Key | Wann |
|---|---|---|---|
| `pluck('n')` | ein Feld | 0,1,2 | schnelle flache Liste |
| `pluck('n','id')` | ein Feld | ein Feld | einfache Key→Wert-Map |
| `keyBy('id')` | ganzes Element | ein Feld/Callback | Nachschlagen |
| `mapWithKeys` | **frei** | **frei** | beides individuell |

### flatMap – eine Ebene flach machen
```php
$data->map(fn($u) => $u['rollen']);      // [['admin','dev'], ['dev']]  verschachtelt
$data->flatMap(fn($u) => $u['rollen']);  // ['admin','dev','dev']       eine Ebene flach
```

### partition – in zwei teilen (ein Durchlauf)
```php
[$aktive, $inaktive] = $konten->partition(fn($k) => $k['aktiv']);
```
✅ Besser als `filter` + `reject` (zwei Durchläufe, wiederholte Bedingung).

### chunk – in Blöcke schneiden
```php
collect([1,2,3,4,5,6,7])->chunk(3);
// [Collection[1,2,3], Collection[3=>4,4=>5,5=>6], Collection[6=>7]]  Keys bleiben!
```
```php
$rows->chunk(500)->each(fn($batch) => Order::insert($batch->toArray()));  // Batch-Insert
```
> 🚫 `chunk` auf einer normalen Collection spart **keinen Speicher** (alles ist schon im RAM) –
> nur Query-Effizienz. Echte Speicher-Ersparnis erst mit LazyCollection (Stufe 6).
> 🔎 Verwandt: `chunkWhile` (nach Bedingung zwischen aufeinanderfolgenden Elementen).

---

## Stufe 4 – Aggregation

Aggregation faltet viele Werte zu Kennzahlen. Alle Methoden akzeptieren einen Feldnamen oder
einen Callback.

```php
$orders->sum('betrag');                    // 62
$orders->sum(fn($o) => $o['betrag'] * 1.19);
$orders->avg('betrag');                    // 20.67   (Alias: average)
$orders->min('betrag');                    // 12
$orders->max('betrag');                    // 30
$orders->count();                          // 3
```

### countBy – Häufigkeiten zählen
Gibt eine Collection `wert => anzahl` zurück:

```php
$orders->countBy('land');                  // ['DE' => 2, 'SE' => 1]
collect(['a','b','a','c','a'])->countBy(); // ['a' => 3, 'b' => 1, 'c' => 1]
$users->countBy(fn($u) => $u['aktiv'] ? 'aktiv' : 'inaktiv');
```

### Das Report-Muster: gruppieren, dann pro Gruppe verdichten
Der Kern jedes Reports – mehrere Kennzahlen pro Gruppe in einem `map`:

```php
$report = $orders
    ->groupBy('land')
    ->map(fn($g) => [
        'land'   => $g->first()['land'],
        'anzahl' => $g->count(),
        'umsatz' => $g->sum('betrag'),
        'schnitt'=> round($g->avg('betrag'), 2),
        'max'    => $g->max('betrag'),
    ])
    ->values();
// [['land'=>'DE','anzahl'=>2,'umsatz'=>42,...], ['land'=>'SE',...]]
```

> 💡 `sum`/`avg`/`min`/`max`/`count`/`reduce` sind **streamende Terminals**: auf einer
> LazyCollection verbrauchen sie den Stream einmal, halten aber nur ein kleines Ergebnis (O(1)) –
> also memory-safe (Stufe 6). `countBy`/`unique` halten O(verschiedene Werte).
> 🧭 Was SQL besser kann (`SUM`, `AVG`, `COUNT ... GROUP BY`), gehört – wenn möglich – in den
> Query Builder statt in PHP (Stufe 7).

---

## Stufe 5 – Pipeline-Denkweise & Higher-Order Messages

### Reihenfolge bewusst wählen
Aus einzelnen Methoden wird eine lesbare Kette. Die Reihenfolge zählt – aus zwei Gründen:

- **Effizienz:** erst `filter`, dann `map` → du formst nur um, was übrig bleibt.
- **Korrektheit:** filterst du auf Roh- oder auf transformierten Daten? Das ändert das Ergebnis.

```php
// besser: erst reduzieren, dann (teurer) transformieren
$rows->filter(fn($r) => $r['betrag'] > 0)->map(fn($r) => teureUmformung($r));
```

Eine lesbare Kette liest sich wie ein Rezept:

```php
$report = collect($rows)
    ->reject(fn($r) => $r['betrag'] === '')
    ->map(fn($r) => [...$r, 'betrag' => (float) $r['betrag']])
    ->filter(fn($r) => $r['betrag'] > 0)
    ->groupBy('land')
    ->map(fn($g) => $g->sum('betrag'));
```

### Higher-Order Messages (HOM)
Kurzform für Ketten, deren Callback nur eine Methode/Property aufruft. Statt `fn($x) => $x->...`
schreibst du die Methode direkt hinter die Collection-Methode:

```php
$users->each->notify();          // == each(fn($u) => $u->notify())
$books->map->title;              // == map(fn($b) => $b->title)
$users->filter->isActive();      // == filter(fn($u) => $u->isActive())
$orders->sum->betrag;           // == sum(fn($o) => $o->betrag)   (bei Objekten)
```

Intern liefert die Collection einen `HigherOrderCollectionProxy`, der den nächsten Zugriff
(`->title` / `->notify()`) in den passenden Callback übersetzt. HOM funktioniert für Methoden,
die einen Callback nehmen: `map`, `each`, `filter`, `reject`, `sortBy`, `groupBy`, `keyBy`,
`partition`, `sum` u. a.

> ⚠️ HOM auf einer Model-Relation (`$books->map->author`) kann eine **N+1** auslösen – dieselbe
> Falle wie eine `map`-Kette (Stufe 7).

### Ketten debuggen, ohne sie zu zerreißen
- `tap(fn($c) => ...)` – bekommt die Collection, macht einen Seiteneffekt (z. B. `dump`),
  gibt die Collection **unverändert** weiter. Ideal zum Reinschauen mitten in der Kette.
- `dump()` / `dd()` – direkt in der Kette ausgeben (bzw. ausgeben + abbrechen).
- `pipe(fn($c) => ...)` – reicht die **ganze** Collection an einen Callback und gibt dessen
  Ergebnis zurück (für eigene Ketten-Schritte).

```php
$report = collect($rows)
    ->map(fn($r) => normalize($r))
    ->tap(fn($c) => logger()->info('nach normalize', ['n' => $c->count()]))
    ->groupBy('land');
```

> 💡 Faustregel Lesbarkeit: Wird eine Kette zu lang oder zu verschachtelt, brich sie in benannte
> Zwischen-Collections auf – Klarheit schlägt Cleverness.

---

## Stufe 6 – LazyCollection & der Generator-Zusammenhang

### Kernidee: eager vs. lazy
- **Collection (eager):** jede Methode rechnet sofort und legt ein volles Array im Speicher ab.
- **LazyCollection (lazy):** beschreibt nur, *was* passieren soll, und produziert Werte einzeln,
  erst wenn jemand sie abholt.

Das ist exakt dein Generator (`yield`) – nur mit `map`/`filter`/`reduce`-Fassade darüber.

### Was eine LazyCollection IST
Sie umhüllt eine **Quelle** (meist eine Closure, die `yield`t), kein fertiges Array.

```php
class LazyCollection
{
    protected $source;   // Closure, Generator oder Array

    public function getIterator(): Traversable
    {
        if (is_callable($this->source)) {
            return ($this->source)();   // Closure -> jedes Mal frischer Generator!
        }
        // ... sonst Array/Generator
    }
}
```
> 🔑 Closure als Quelle = **wiederholbar**. Rohes Generator-Objekt = **einmalig**.

### Erzeugen
```php
use Illuminate\Support\LazyCollection;

// yieldende Closure (Normalfall)
$zahlen = LazyCollection::make(function () {
    for ($i = 1; $i <= 1_000_000; $i++) { yield $i; }
});

// aus einer Generator-Funktion – WICHTIG: Closure übergeben, nicht das Generator-Objekt
function zeilen(string $pfad): Generator {
    $handle = fopen($pfad, 'r');
    try {
        while (($z = fgetcsv($handle)) !== false) { yield $z; }
    } finally {
        fclose($handle);   // Ressource sicher schließen
    }
}
$csv = LazyCollection::make(fn () => zeilen('gross.csv'));

// aus einer Collection (kein Speichergewinn, nur um lazy weiterzuketten)
$lazy = collect([1, 2, 3])->lazy();
```

### Was „lazy" wirklich heißt (der Beweis)
Nichts läuft bis zum terminalen Verbrauch, und dann fließt **jeder Wert einzeln** durch die
ganze Kette:

```php
$pipeline = LazyCollection::make(function () {
    foreach (range(1, 3) as $n) { echo "erzeuge $n\n"; yield $n; }
})->map(function ($n) { echo "  map $n\n"; return $n * 10; });

echo "--- bis hier NICHTS gelaufen ---\n";
$pipeline->each(fn($n) => print("    verbrauche $n\n"));
```
```
--- bis hier NICHTS gelaufen ---
erzeuge 1
  map 1
    verbrauche 10
erzeuge 2
  map 2
    verbrauche 20
erzeuge 3
  map 3
    verbrauche 30
```
Beweist **(1) Aufschub** und **(2) Element-für-Element**. Lazy denkt **vertikal** (ein Element
durch alle Schritte), eager **horizontal** (alle Elemente durch einen Schritt).

### Speicherprofil
```
EAGER: [10.000] -map-> [10.000] -filter-> [3.000] -each-> fertig
       Spitzen-Speicher: mehrere volle Arrays  (∝ Datenmenge)

LAZY:  e1 -> map -> filter -> each ; e2 -> map -> filter -> each ; ...
       Spitzen-Speicher: EIN Element zur Zeit  (konstant)
```
> 💾 Speicherbedarf einer streamenden Lazy-Pipeline ist **konstant** – egal ob 1.000 oder
> 100.000.000 Zeilen. So verarbeitest du eine 10-GB-CSV mit 256 MB RAM.

### Die „ein Durchlauf"-Falle
```php
function gen() { yield 1; yield 2; yield 3; }

$lazy = LazyCollection::make(gen());   // rohes Generator-OBJEKT
$lazy->count();  // 3
$lazy->count();  // 💥 "Cannot rewind a generator that was already run"

$lazy = LazyCollection::make(fn () => gen());  // Closure -> wiederholbar
$lazy->count();  // 3
$lazy->count();  // 3
```
> ⚠️ „Wiederholbar" heißt: jede erneute Iteration **führt die Quelle erneut aus** (liest die
> Datei/DB noch einmal). → Alles in **einem Durchlauf** erledigen; mehrere Kennzahlen in einem
> `reduce` falten. `remember()` cached Gesehenes (Preis: Speicher).

### Welche Methoden materialisieren
| Kategorie | Beispiele | Speicher |
|---|---|---|
| **Streamende Transformationen** (bleiben lazy) | `map`, `filter`, `reject`, `flatMap`, `take`, `takeWhile`, `skip`, `chunk`, `tapEach` | O(1) (bzw. Blockgröße) |
| **Streamende Terminals** (nur Ergebnis) | `sum`, `avg`, `min`, `max`, `count`, `reduce`, `each`, `first`, `contains` | O(1) |
| **Materialisierende Operationen** (puffern ALLES) | `sort`, `sortBy`, `reverse`, `shuffle`, `groupBy` | **O(n)** – zerstört die Ersparnis |
| **Zustand-merkend** | `unique`, `countBy` | O(verschiedene Werte) |

✅ **Prinzip: „Streame das Große, akkumuliere ins Kleine."** Statt `groupBy` (puffert 1 Eintrag
pro *Zeile*) einen `reduce` mit kleinem Akku (1 Eintrag pro *Gruppe*):

```php
$umsatzProLand = $csv->reduce(function ($acc, $z) {
    $acc[$z['land']] = ($acc[$z['land']] ?? 0) + (float) $z['betrag'];
    return $acc;
}, []);
// klein, egal wie groß die CSV
```

> 💡 Lazy-Spezialitäten: `tapEach` (lazy-`each`, löst selbst keine Iteration aus),
> `takeUntilTimeout()`, `take()` auf einer *unendlichen* Quelle.

### Eloquent: cursor() vs. get()
```php
foreach (Order::where('jahr', 2025)->get()    as $o) { } // eager: alle Models im RAM
foreach (Order::where('jahr', 2025)->cursor() as $o) { } // lazy: 1 Model/Schritt (1 Query)
```
`cursor()` nutzt eine ungepufferte Abfrage, ein Model pro Schritt. (Detail + `lazy()` in Stufe 7.)

### WANN lazy statt normal?
| LazyCollection, wenn … | normale Collection, wenn … |
|---|---|
| Quelle groß/unbegrenzt (Datei, DB-Cursor, API) | Datenmenge klein, passt in den RAM |
| **einmal** durchlaufen und direkt weiterschreiben | **mehrfach** durchlaufen / wahlfreier Zugriff |
| nur Kennzahlen/Seiteneffekte pro Element | `sort`/`groupBy` über den ganzen Satz |
| Speicher ist das Nadelöhr | Ergebnis wiederverwenden/cachen |

> ⚖️ Kein Gratis-Mittagessen: nur ein Durchlauf, kein billiger `$c[42]`, `sort` materialisiert
> ohnehin. Lazy gezielt fürs Streaming, nicht als Default.

---

## Stufe 7 – Die bewusste Entscheidung, Eloquent & Anti-Patterns

### Die vier Werkzeuge nebeneinander
| | `array` | `Collection` | `Generator` | `LazyCollection` |
|---|---|---|---|---|
| Was | Rohdaten im RAM | Wrapper ums Array | Pull-Iterator (`yield`) | Wrapper um Generator/Closure |
| Speicher | alles | alles (+ Objekt) | ein Element | ein Element |
| API | `array_*` | ~100 fluent | nur `foreach` | ~100 fluent |
| Durchläufe | beliebig | beliebig | einmal | einmal (Closure = wiederholbar) |
| Direktzugriff `[i]` | ja | ja | nein | nein |
| `sort`/`groupBy` | im RAM | im RAM | – | materialisiert |
| Ideal für | kleine Daten, Hot-Path | klein–mittel, viel Umformen | eigene Streams | große/unendliche Streams |

Zwei Paare: `array`↔`Collection` (eager), `Generator`↔`LazyCollection` (lazy).

### Entscheidungsmatrix
```
Frage 1: Passt alles locker in den RAM?
Frage 2: Brauche ich verkettbare map/filter/…-Methoden?

              Passt in den RAM?
             /               \
           JA                 NEIN (Streaming)
          /  \                /   \
       NEIN   JA           NEIN    JA
        |      |             |      |
      array Collection   Generator LazyCollection
```

### Eloquent gibt Collections zurück
`get()`/`all()` liefern `Illuminate\Database\Eloquent\Collection` – eine **Unterklasse** der
Support-Collection, mit Model-bewussten Extras:

```php
$users = User::where('active', true)->get();
$users->find(5);          // Model mit id 5 (kein DB-Zugriff)
$users->modelKeys();      // [1, 2, 3, ...]
$users->loadMissing('posts');
$users->fresh();
```
> 🔍 Gibt `map` keine Models mehr zurück, degradiert Eloquent bewusst zur Basis-`Support\Collection`.

### Rows ziehen: get / cursor / lazy / chunk
```php
Order::where(...)->get();      // eager: ALLE Models, 1 Query
Order::where(...)->cursor();   // lazy: 1 Model/Schritt, 1 Query, KEIN eager load
Order::where(...)->lazy();     // lazy: Blöcke (Default 1000), eager load möglich
Order::where(...)->chunk(1000, fn ($rows) => /* ... */);
Order::where(...)->chunkById(1000, fn ($rows) => /* ... */);  // sicher bei Änderungen
```
| Methode | Speicher | Queries | Eager Load? | Wann |
|---|---|---|---|---|
| `get()` | alle Rows | 1 (+ eager) | ja | überschaubare Menge |
| `cursor()` | 1 Model | 1 | **nein** | groß, ohne Relationen |
| `lazy()` | 1 Block | viele | ja | groß, mit Relationen |
| `chunk()` | 1 Block | viele | ja | Massen-Verarbeitung im Closure |
| `chunkById()` | 1 Block | viele | ja | wie chunk, aber Rows werden geändert |

> ⚠️ `cursor()` kann **keine Relationen eager laden** → große Tabelle *mit* Relationen: `lazy()`.

### Die N+1-Falle
Eine Abfrage für die Liste + je eine pro Element:

```php
$books = Book::all();                 // 1 Query
foreach ($books as $book) { echo $book->author->name; }  // + 1 Query PRO Buch = N+1
```
Versteckt sich in Collection-Ketten / HOM:
```php
Book::all()->map(fn($b) => $b->author->name);   // versteckte N+1
Book::all()->map->author;                        // gleiche Falle
```
✅ Fix – eager loading:
```php
$books = Book::with('author')->get();   // 2 Queries INSGESAMT
$books->loadMissing('author');          // nachträglich, falls Collection schon da
```
Aufspüren (Entwicklung):
```php
Model::preventLazyLoading(! app()->isProduction());  // wirft bei ungeladener Relation
```
Streaming + N+1:
```php
foreach (Book::with('author')->cursor() as $b) { $b->author; }  // with() greift NICHT -> N+1
foreach (Book::with('author')->lazy()   as $b) { $b->author; }  // pro Block eager -> sauber
```

### Arbeit in die Datenbank schieben
```php
User::all()->where('active', true)->count();  // ❌ lädt alle, filtert/zählt in PHP
User::where('active', true)->count();         // ✅ DB zählt, nur eine Zahl zurück

User::all()->pluck('email');  // ❌ alle Rows
User::pluck('email');         // ✅ SELECT email FROM users
```
> 🧭 Mengen reduzieren, bevor sie PHP erreichen: `where`, `count`, `sum`, `orderBy`, `groupBy`
> in den Query Builder. Collections für das, was schon im RAM ist oder was SQL nicht elegant kann.

### Anti-Patterns & idiomatische Swaps
| Statt … | … besser | Warum |
|---|---|---|
| `$c->filter($fn)->first()` | `$c->first($fn)` | stoppt beim ersten Treffer |
| `$c->count() > 0` | `$c->isNotEmpty()` | klarer, ohne zu zählen |
| `$c->map($fn)->flatten(1)` | `$c->flatMap($fn)` | ein Schritt |
| `$c->pluck('x')->toArray()` | `$c->pluck('x')->all()` | keine rekursive Wandlung |
| `collect(User::all())` | `User::all()` | ist bereits eine Collection |
| `$c->filter($fn)` (für JSON) | `...->filter($fn)->values()` | sonst Objekt statt Array |
| `Model::all()->where(...)` | `Model::where(...)->get()` | filtert in SQL |

- 🧱 **Collection aufblähen:** großes `get()` nur um dann zu streamen – Streaming am *Einstieg*
  entscheiden (`cursor`/`lazy`/`chunk`).
- 🐢 **Lazy-Ersparnis zerstören:** `sort`/`reverse`/`groupBy` auf LazyCollection puffert alles;
  Mehrfach-Iteration liest die Quelle erneut.
- 🔁 **Jede Kettenmethode ist ein Durchlauf** (eager): 5 Methoden = 5 Durchläufe. Bei großen
  Mengen bündeln (`reduce`) oder lazy streamen (ein Durchlauf).

### DataPipe: der finale Brückenschlag
```
Storage::readStream()  ->  Generator (yield Zeile)        [Stufe 6 / I/O-Wissen]
        |
        v
LazyCollection::make(fn () => ...)                         [Pipeline-API auf dem Stream]
        |  ->filter() ->map()   (streamt, O(1) Speicher)   [Stufe 2/6]
        v
Aggregation
   |- reduce(...)  -> kleine Kennzahlen pro Gruppe          [Stufe 4 · "Kleines akkumulieren"]
   `- chunk(500)->each(insert)  -> Batch-Insert in die DB   [Stufe 3/6]
```

Beispiel – alles in einem Durchlauf, konstanter Speicher:
```php
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Facades\Storage;

$umsatzProLand = LazyCollection::make(function () {
    $stream = Storage::readStream('reports/gross.csv');
    try {
        $header = fgetcsv($stream);
        while (($row = fgetcsv($stream)) !== false) {
            yield array_combine($header, $row);
        }
    } finally {
        fclose($stream);
    }
})
->filter(fn ($z) => $z['betrag'] !== '' && (float) $z['betrag'] > 0)
->reduce(function ($acc, $z) {
    $acc[$z['land']] = ($acc[$z['land']] ?? 0) + (float) $z['betrag'];
    return $acc;
}, []);
```

**Umsetzungs-Checkliste:**
- [ ] Einstieg als Stream (`readStream` + Generator), nie voll laden
- [ ] Quelle als **Closure** an `LazyCollection::make` (wiederholbar)
- [ ] Ressourcen im `finally` schließen
- [ ] Alles in **einem Durchlauf** – keine Doppel-Reads
- [ ] Pro-Gruppe-Aggregation via `reduce` (kleiner Akku), nicht `groupBy`
- [ ] DB-Writes blockweise via `chunk()->each(insert)`
- [ ] Bei DB-Lesen mit Relationen: `lazy()`, nicht `cursor()`
- [ ] Was SQL besser kann, in den Query Builder schieben

---

## Der ganze Kurs in einem Bild

| Stufe | Kernidee | Der eine Satz |
|---|---|---|
| 1 | Was eine Collection ist | Ein Objekt mit einem Array drin und ~100 Methoden drumherum. |
| 2 | Kern-Transformationen | map/filter/reduce formen; `each` ist nur Seiteneffekt; „neue Box" ≠ „neue Objekte". |
| 3 | Umstrukturieren | `keyBy` (1 pro Key) vs. `groupBy` (Liste pro Key); chunk/partition/flatMap ändern die Form. |
| 4 | Aggregation | sum/avg/count/countBy; gruppieren-dann-verdichten ist das Report-Muster. |
| 5 | Pipeline & HOM | Reihenfolge bewusst wählen; `$c->map->name` als lesbare Kurzform. |
| 6 | LazyCollection | Pipeline auf Generatoren: Aufschub + Element-für-Element = konstanter Speicher. |
| 7 | Bewusst wählen | array/Collection/Generator/Lazy passend wählen; N+1 & Materialisierung vermeiden. |

**Das Fundament in fünf Sätzen:**
1. Eine Collection ist Komfort um ein Array; die LazyCollection derselbe Komfort um einen Generator.
2. Transformationen geben neue Boxen zurück, aber enthaltene **Objekte werden geteilt** – nicht in Ketten mutieren.
3. Struktur ändern (keyBy/groupBy/chunk) und verdichten (reduce/sum) sind zwei Denkschritte – meist erst gruppieren, dann verdichten.
4. Bei großen/unbekannten Mengen entscheidet der **Einstieg**: lazy streamen und ins Kleine akkumulieren.
5. Schiebe in die DB, was sie besser kann, und lade Relationen **eager** – sonst zahlst du mit Speicher oder N+1.

---

*Nächster Schritt nach dem Arbeitsprinzip „erst verstehen, dann umsetzen": die Praxis-Runde
**DataPipe umsetzen** entlang der Checkliste aus Stufe 7.*
