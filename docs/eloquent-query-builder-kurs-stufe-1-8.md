# Eloquent ORM & Query Builder – Tiefe Lernrunde (Stufe 1–8)

> Kompakte, eigenständige Referenz aller acht Stufen. Fokus: **WARUM vor WIE** –
> interne Mechanik, bewusste Entscheidungen, Fallen und Anti-Patterns.
> Roter Faden: **DataPipe** – eine API, die große CSVs memory-safe verarbeitet und
> Reports generiert. Die zentrale Frage dieser Runde:
> **Der Query Builder gibt rohe `stdClass`/Arrays zurück, Eloquent gibt eine
> `Collection` von Model-Objekten zurück – wann nutze ich was, und warum?**

**Voraussetzungen (als bekannt angenommen):** Laravel-Grundlagen (Migrations,
Controller, Service-Klassen), PHP-Dateisystem tief (Streams, Generatoren/`yield`,
memory-safe I/O), Collections tief (`map`/`filter`/`reduce`, `groupBy`, Pipeline,
`LazyCollection` + Generatoren). ORM-Konzepte aus Java (Hibernate/JPA) grob bekannt.
PDO **nicht** tief – nur *wissen*, dass es die unterste Schicht ist.

---

## Inhalt

1. [Die drei Schichten: PDO → Query Builder → Eloquent](#stufe-1--die-drei-schichten)
2. [Query Builder tief: welches SQL entsteht, was zurückkommt](#stufe-2--query-builder-tief)
3. [Eloquent tief: Model als Active Record & Hydration](#stufe-3--eloquent-tief-model--hydration)
4. [Die Grenze: Query Builder vs. Eloquent – die Entscheidungsregeln](#stufe-4--die-grenze-query-builder-vs-eloquent)
5. [Relationen intern: welche Queries wirklich entstehen](#stufe-5--relationen-intern)
6. [Die N+1-Falle & Eager Loading](#stufe-6--die-n1-falle--eager-loading)
7. [Speicher: get() vs. cursor() vs. lazy() vs. chunk()](#stufe-7--speicher-get-vs-cursor-vs-lazy-vs-chunk)
8. [Model-Mechanik & Sicherheit: Casts, Accessors, Mass Assignment, Transaktionen, rohes SQL](#stufe-8--model-mechanik--sicherheit)
9. [Der ganze Kurs in einem Bild](#der-ganze-kurs-in-einem-bild)

---

## Stufe 1 – Die drei Schichten

### Die Kernaussage
Eloquent ist kein eigenständiges System, sondern die **oberste von drei gestapelten
Schichten**. Jede Schicht fügt genau eine Sache hinzu und delegiert nach unten.
Wenn du weißt, *welche* Schicht was tut, weißt du auch, *wo* du hin musst, wenn
eine Schicht dir im Weg steht.

```
  Dein Code:  User::where('active', true)->get()
       |
  +----------------------------------------------------------+
  | Eloquent  (Illuminate\Database\Eloquent)                 |
  |   + Model-Objekte statt Arrays (Hydration)               |
  |   + Relationen, Casts, Accessors, Events, Scopes         |
  |   + Mass-Assignment-Schutz (fillable/guarded)            |
  |   -> gibt Eloquent\Collection<Model> zurueck             |
  +----------------------------------------------------------+
       |  delegiert an ->
  +----------------------------------------------------------+
  | Query Builder  (Illuminate\Database\Query\Builder)       |
  |   + fluent API: where/join/groupBy/having/aggregate      |
  |   + baut SQL-String + bindings-Array                     |
  |   -> gibt Support\Collection<stdClass> zurueck           |
  +----------------------------------------------------------+
       |  ruft auf ->
  +----------------------------------------------------------+
  | PDO  (PHP Data Objects)                                  |
  |   + Prepared Statements (SQL + bindings getrennt)        |
  |   + Treiber-Abstraktion (mysql/pgsql/sqlite)             |
  |   -> gibt rohe Zeilen (arrays / stdClass) zurueck        |
  +----------------------------------------------------------+
       |
  Datenbank
```

### Was jede Schicht *hinzufügt* (und wegnimmt)

| Schicht | Fügt hinzu | Rückgabe | Kostet dich |
|---|---|---|---|
| **PDO** | Prepared Statements, Treiber-Abstraktion | rohe Zeilen | rohes SQL selbst schreiben |
| **Query Builder** | fluent SQL-Baukasten, Aggregation, Joins | `Support\Collection<stdClass>` | keine Domain-Logik, keine Relationen |
| **Eloquent** | Model-Objekte, Relationen, Casts, Schutz | `Eloquent\Collection<Model>` | Hydration-Overhead (CPU + RAM) |

> 🧭 **Merksatz:** Nach *unten* gehen heißt *Komfort gegen Kontrolle/Leistung tauschen*.
> Eloquent für Domain-Arbeit, Query Builder für Masse/Aggregation, rohes SQL nur für
> das, was der Builder nicht kann.

### PDO – die eine Sache, die du wissen musst: Prepared Statements
Du lernst PDO nicht tief. Aber *diese* Mechanik erklärt, warum Laravel gegen
SQL-Injection sicher ist. Ein Prepared Statement trennt **SQL-Struktur** von
**Werten**:

```php
// SO NICHT (String-Verkettung) - klassische SQL-Injection-Luecke:
"SELECT * FROM users WHERE email = '" . $input . "'"
//  Eingabe:  ' OR '1'='1   ->  liefert ALLE User

// SO macht es PDO (und damit jeder Query/Eloquent-Aufruf):
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');  // Struktur zuerst
$stmt->execute([$input]);  // Werte SPAETER, separat -> nie als SQL interpretiert
```

Die DB parst das Statement **einmal** mit `?`-Platzhaltern und behandelt die Werte
danach als reine Daten – niemals als ausführbares SQL. Jedes `where('email', $x)`
in Laravel wird zu genau so einem `?` + Binding. **Deshalb ist der Query Builder
per Default sicher, und deshalb ist String-Interpolation in `whereRaw`/`selectRaw`
gefährlich.**

### Java-Brücke
- **PDO** ≈ JDBC (`PreparedStatement`, `ResultSet`).
- **Query Builder** ≈ jOOQ / ein typsicherer SQL-DSL – nah am SQL, kein Objektmapping.
- **Eloquent** ≈ Hibernate/JPA im *Active-Record*-Stil: das Model kennt seine
  eigene Persistenz (`$user->save()`), während JPA *Data-Mapper* ist (ein
  `EntityManager` persistiert für dich). Merke: In Eloquent trägt **das Model
  selbst** die `save()`-Verantwortung.

---

## Stufe 2 – Query Builder tief

### Der Einstieg: `DB::table()`
Der Query Builder ist ein **Objekt, das schrittweise einen SQL-String + ein
bindings-Array zusammenbaut** und erst bei einer „Terminal-Methode"
(`get`/`first`/`count`/…) tatsächlich die DB anspricht.

```php
use Illuminate\Support\Facades\DB;

$rows = DB::table('records')
    ->where('amount', '>', 100)
    ->orderBy('created_at', 'desc')
    ->get();       // <- HIER wird erst die Query ausgefuehrt
```

`$rows` ist eine **`Illuminate\Support\Collection`** (dieselbe Box aus der
Collections-Runde!) – gefüllt mit **`stdClass`-Objekten**, einem pro Zeile:

```php
$rows->first();          // stdClass { id: 1, amount: "150.00", ... }
$rows->first()->amount;  // "150.00"  (Property-Zugriff auf stdClass)
$rows->sum('amount');    // geht - es ist ja eine Collection
```

> 🧠 **Das ist der halbe rote Faden:** Query Builder → schon eine Collection, aber
> von *rohen* `stdClass`. Keine Casts, keine Relationen, keine Domain-Methoden.

### Was für SQL entsteht (das Mentalmodell)
Jede Kettenmethode hängt ein SQL-Fragment an. Du solltest bei jeder Query „sehen",
welches SQL rauskommt:

```php
DB::table('records')
    ->select('import_id', DB::raw('SUM(amount) as total'))
    ->where('amount', '>', 0)
    ->groupBy('import_id')
    ->having('total', '>', 1000)
    ->orderBy('total', 'desc')
    ->get();
```
```sql
SELECT import_id, SUM(amount) as total
FROM records
WHERE amount > ?          -- Binding: [0]
GROUP BY import_id
HAVING total > ?          -- Binding: [1000]
ORDER BY total DESC
```

**Bindings sichtbar machen** (Debugging):
```php
$q = DB::table('records')->where('amount', '>', 0);
$q->toSql();        // "select * from `records` where `amount` > ?"
$q->getBindings();  // [0]
DB::table('records')->where('amount','>',0)->dd();   // dump & die inkl. SQL
```

### WHERE / JOIN / GROUP BY / HAVING – die Bausteine
```php
// WHERE-Varianten
->where('status', 'done')                    // = done
->where('amount', '>=', 100)                 // Operator explizit
->whereIn('import_id', [1, 2, 3])            // IN (?, ?, ?)
->whereNull('deleted_at')
->whereBetween('amount', [10, 100])
->where(function ($q) {                       // geklammerte Gruppe: (a OR b)
    $q->where('a', 1)->orWhere('b', 2);
})

// JOIN
DB::table('records')
    ->join('imports', 'records.import_id', '=', 'imports.id')
    ->leftJoin('users', 'imports.user_id', '=', 'users.id')
    ->select('records.*', 'users.name as user_name')
    ->get();

// Aggregate (Terminal - geben Skalare zurueck, KEINE Collection):
DB::table('records')->count();            // int
DB::table('records')->sum('amount');      // "12345.00" (string bei decimal)
DB::table('records')->avg('amount');
DB::table('records')->max('amount');
```

> ⚠️ **`WHERE` vs. `HAVING`:** `WHERE` filtert *Zeilen vor* der Gruppierung,
> `HAVING` filtert *Gruppen nach* der Aggregation. Auf ein `SUM()` kannst du nur
> mit `HAVING` filtern.

### `first()` / `value()` / `pluck()` – kleinere Rückgaben
```php
DB::table('users')->where('id', 1)->first();   // eine stdClass ODER null
DB::table('users')->where('id', 1)->value('email');  // nur der Wert: "a@b.de"
DB::table('users')->pluck('email');            // Collection: ['a@b.de', ...]
DB::table('users')->pluck('email', 'id');      // Collection: [1 => 'a@b.de', ...]
```

### Roh, aber sicher: Raw mit Bindings
Wenn du SQL-Ausdrücke brauchst, die der Builder nicht kennt (`SUM`, `DATE()`,
DB-Funktionen), nutze `Raw` – **immer mit `?`-Platzhaltern, nie mit Interpolation:**

```php
->selectRaw('SUM(amount) as total, COUNT(*) as n')
->whereRaw('DATE(created_at) = ?', [$date])     // ✅ Binding
->havingRaw('SUM(amount) > ?', [1000])

// ❌ NIEMALS - Injection-Luecke:
->whereRaw("DATE(created_at) = '$date'")
```

### DataPipe-Bezug
Genau hier lebt eure Architektur-Entscheidung *„Aggregation großer Datenmengen:
Query Builder / SQL statt Collections"*. Der Report summiert Millionen `records` –
das gehört in die DB, nicht in PHP:

```php
// Report-Kernzahl - die DB rechnet, PHP bekommt eine Handvoll Zeilen zurueck:
$summe = DB::table('records')
    ->where('import_id', $importId)
    ->sum('amount');   // eine Zahl, kein Laden von Millionen Rows
```

---

## Stufe 3 – Eloquent tief: Model & Hydration

### Ein Model ist ein Query Builder mit Verkleidung
`User::where('active', true)` ruft **keine** statische Methode auf – es gibt keine
`where()`-Methode auf der Model-Klasse. Stattdessen:

```
User::where(...)                                     [statischer Aufruf]
   |  __callStatic faengt ab
   v
(new User)->newQuery()          -> Eloquent\Builder  [kennt das Model]
   |  ->where(...)  ist nicht auf Eloquent\Builder ->
   v  __call leitet weiter an
Query\Builder->where(...)                            [baut das SQL, Stufe 2]
```

Der **Eloquent\Builder umschließt den Query\Builder** und leitet unbekannte
Methoden per `__call` an ihn weiter. Deshalb kannst du auf einem Model *alles*
benutzen, was der Query Builder kann – plus die Eloquent-Extras.

### Der eine echte Unterschied: `get()` liefert Model-Objekte
```php
$qbRows  = DB::table('users')->get();  // Support\Collection<stdClass>
$models  = User::get();                // Eloquent\Collection<User>

$qbRows->first()->email;   // stdClass-Property
$models->first()->email;   // Model-Attribut (durch __get -> $attributes)
$models->first()->save();  // Model kann sich SELBST persistieren
```

### Hydration – wie aus einer Zeile ein Objekt wird
„Hydration" ist der Vorgang **DB-Zeile → Model-Objekt**. Wichtig: Der
**Konstruktor wird dabei NICHT normal durchlaufen** – kein Mass-Assignment, kein
`fillable`-Check. Eloquent nutzt einen eigenen Pfad (`newFromBuilder`), der die
Attribute *direkt* setzt:

```
  DB liefert:  ['id' => 1, 'email' => 'a@b.de', 'amount' => '150.000000']
       |
  Eloquent\Builder::getModels()
       |  fuer jede Zeile:
       v
  $model = new User;                    // leeres Model
  $model->setRawAttributes($row, true)  // $attributes = $row   (kein Cast beim Setzen!)
  $model->exists = true                 // "existiert in DB"
  $model->$original = $row              // Kopie fuer "dirty"-Vergleich
       |
       v
  Eloquent\Collection<User>
```

Zwei Konsequenzen, die man beim Docs-Lesen oft übersieht:

1. **`$original` ist die Basis für Change-Tracking.** `save()` schreibt nur, was
   sich gegenüber `$original` geändert hat:
   ```php
   $u = User::find(1);
   $u->isDirty();          // false - nichts geaendert
   $u->name = 'Neu';
   $u->isDirty('name');    // true
   $u->getDirty();         // ['name' => 'Neu']
   $u->save();             // UPDATE users SET name = ? WHERE id = ?  (nur name!)
   ```
2. **Casts greifen beim *Lesen*, nicht beim rohen Setzen.** Die DB liefert immer
   Strings (`"150.000000"`); der Cast (Stufe 8) wandelt beim Zugriff über `__get`.

### Attribute leben in `$attributes`, nicht als echte Properties
```php
$u = User::find(1);
$u->email;   // -> __get('email') -> $this->attributes['email']  (+ evtl. Cast/Accessor)
$u->foo;     // nicht in $attributes, keine Relation -> null (+ evtl. Warnung)
```
Ein Model hat also keine deklarierten `public $email`-Felder – alles läuft über
das magische `__get`/`__set` auf das interne `$attributes`-Array. **Das ist die
Basis für Casts, Accessors und Relationen.**

### `find` / `findOrFail` / `firstWhere`
```php
User::find(1);            // Model oder null
User::findOrFail(1);      // Model oder wirft ModelNotFoundException (-> 404)
User::firstWhere('email', $e);
User::where('active', 1)->first();
```

### Java-Brücke
Hydration ≈ was Hibernate beim Laden einer Entity tut: Row → gemanagtes Objekt mit
Snapshot für Dirty-Checking. Eloquents `$original` ist dieser Snapshot. Unterschied:
In Eloquent (Active Record) ruft **das Model** `save()`; in JPA committet die
Session/der `EntityManager` gemanagte Entities automatisch.

---

## Stufe 4 – Die Grenze: Query Builder vs. Eloquent

### Die zwei Rückgabetypen nebeneinander (der rote Faden, verdichtet)

```
  DB::table('records')->get()          User::get() / Record::get()
         |                                    |
         v                                    v
  Support\Collection                   Eloquent\Collection
    [ stdClass, stdClass, ... ]          [ Model, Model, ... ]
         |                                    |
   nur Daten:                          Daten PLUS:
     $row->amount  (roh "150.00")        $rec->amount   (Cast -> "150.000000")
                                         $rec->import    (Relation, lazy)
                                         $rec->save()    (Persistenz)
                                         $rec->isDirty() (Change-Tracking)
                                         + load(), loadMissing(), find(), fresh()
```

`Eloquent\Collection` **erbt** von `Support\Collection` – du hast also alle
`map`/`filter`/`reduce` **plus** modellbezogene Methoden (`load`, `loadMissing`,
`modelKeys`, `find`, `fresh`). Umgekehrt weiß `Support\Collection` nichts von Models.

### Was Hydration *kostet*
Für jede Zeile baut Eloquent ein Objekt, setzt Attribute, legt `$original` an,
verkabelt Events. Bei 10 Zeilen egal – bei **500.000 Zeilen** ist das messbar RAM
und CPU, den der Query Builder nicht ausgibt (er reicht `stdClass` fast roh durch).

```
  1.000.000 Rows lesen:
    DB::table(...)->get()   -> ~ schlanke stdClass   (weniger RAM/CPU)
    Model::all()            -> 1.000.000 Model-Objekte (Hydration-Overhead)
```

### Die Entscheidungsregeln (konkret)

| Situation | Nimm | Warum |
|---|---|---|
| Einzelne Entity bearbeiten/speichern | **Eloquent** | Relationen, Casts, `save()`, Events |
| Domain-Logik, Business-Regeln | **Eloquent** | Model ist der richtige Ort dafür |
| Relationen laden (`import->records`) | **Eloquent** | Query Builder kennt keine Relationen |
| Report-Aggregation über Millionen Rows | **Query Builder** | DB rechnet, keine Hydration |
| Bulk-`SELECT` nur zum Anzeigen/Export | **Query Builder** | kein Objekt-Overhead nötig |
| Massen-`INSERT`/`UPDATE` (Bulk) | **Query Builder** | `insert()` feuert keine Events, ist schnell |
| Komplexe Joins/Subqueries/Window-Funktionen | **Query Builder / Raw** | näher am SQL |
| Was Eloquent gar nicht kann | **`DB::select()`** (Stufe 8) | letzte Instanz |

> 🧭 **Faustregel:** *„Arbeite ich mit **Objekten meiner Domäne**, oder mit
> **Zahlen/Zeilen als Daten**?"* Ersteres → Eloquent. Letzteres → Query Builder.

> ⚠️ **Falle:** `Model::all()->where(...)` lädt **alle** Rows als Models und
> filtert in PHP. Fast immer gemeint: `Model::where(...)->get()` – filtert in SQL.
> (Genau das Anti-Pattern aus der Collections-Runde, jetzt auf Model-Ebene.)

### DataPipe-Bezug
- `records` **einzeln** validieren/anzeigen, `import`-Relation brauchen → Eloquent.
- Report: `SUM/COUNT/GROUP BY` über alle `records` eines Imports → **Query Builder**
  (eure verbindliche Entscheidung). Kein `Record::all()->sum()`.
- Bulk-Insert der geparsten Zeilen → `DB::table('records')->insert($chunk)` (schnell,
  kein Model-Overhead, keine Events) – passt zu eurem Chunk-Insert-Pattern.

---

## Stufe 5 – Relationen intern

### Eine Relation ist ein vordefinierter Query Builder
Eine Relationsmethode gibt ein **Relation-Objekt** zurück, das intern nichts
anderes ist als ein Query Builder mit schon gesetztem `WHERE` auf den Fremdschlüssel.

```php
class Import extends Model
{
    public function records()          // hasMany
    {
        return $this->hasMany(Record::class);
        // implizit: Record::where('import_id', $this->id)
    }
    public function user()             // belongsTo (die Gegenseite)
    {
        return $this->belongsTo(User::class);
        // implizit: User::where('id', $this->user_id)->first()
    }
}
```

### Welche Queries wirklich entstehen

```
  hasMany:  $import->records
      SELECT * FROM records WHERE import_id = ?        [? = $import->id]

  belongsTo:  $record->import
      SELECT * FROM imports WHERE id = ? LIMIT 1       [? = $record->import_id]

  belongsToMany (z.B. User <-> Role ueber role_user):
      SELECT roles.*, role_user.user_id
      FROM roles
      JOIN role_user ON roles.id = role_user.role_id
      WHERE role_user.user_id = ?
```

### Methode vs. dynamische Property – der subtile, wichtige Unterschied
```php
$import->records();   // () -> gibt den RELATION-Builder zurueck (noch KEINE Query!)
$import->records;     //    -> fuehrt aus, cached, gibt Eloquent\Collection zurueck
```
- `->records()` (mit Klammern) → du kannst **weiterbauen** und erst dann ausführen:
  ```php
  $import->records()->where('amount', '>', 100)->count();  // gezielt in SQL
  ```
- `->records` (ohne Klammern) → **lazy load**: führt einmalig die Query aus, das
  Ergebnis wird **auf dem Model gecacht** (zweiter Zugriff = keine neue Query).

> ⚠️ Genau dieser bequeme `->records`-Zugriff ist die **Quelle der N+1-Falle**
> (Stufe 6), sobald er in einer Schleife über viele Parents steht.

### Foreign-Key-Konventionen (und wann explizit)
| Relation | Erwarteter FK | Explizit überschreiben |
|---|---|---|
| `belongsTo(User::class)` | `user_id` auf *dieser* Tabelle | `belongsTo(User::class, 'owner_id')` |
| `hasMany(Record::class)` | `import_id` auf *records* | `hasMany(Record::class, 'imp_id')` |
| `belongsToMany(Role::class)` | Pivot `role_user` (alphabetisch) | `belongsToMany(Role::class, 'user_roles')` |

### DataPipe-Bezug
```php
// User 1..* Import 1..* Record
class User   { function imports() { return $this->hasMany(Import::class); } }
class Import { function user()    { return $this->belongsTo(User::class); }
              function records()  { return $this->hasMany(Record::class); } }
class Record { function import()  { return $this->belongsTo(Import::class); } }
```
Ownership serverseitig über die Relation (eure Entscheidung: `user_id`/`import_id`
nicht `fillable`) heißt konkret:
```php
// NICHT $import = Import::create($request->all()) mit user_id aus dem Request,
// SONDERN ueber die Relation - user_id wird serverseitig gesetzt:
$import = $request->user()->imports()->create(['filename' => $name]);
//        ^ user_id kommt aus der Relation, nie aus Client-Input
```

---

## Stufe 6 – Die N+1-Falle & Eager Loading

### Was N+1 ist
Eine Query für die Liste (**1**) + eine Query pro Element für die Relation (**N**):

```php
$imports = Import::all();                 // 1 Query: SELECT * FROM imports
foreach ($imports as $import) {
    echo $import->user->name;             // + 1 Query PRO Import -> N Queries
}                                          // Gesamt: 1 + N
```
Bei 500 Imports: **501 Queries**. Das ist der häufigste reale Performance-Bug in
Laravel-Apps – und ein *garantiertes* Interview-Thema.

### Warum es sich versteckt
Der Zugriff `$import->user` sieht wie ein harmloser Property-Zugriff aus – ist aber
ein lazy DB-Call. In Collection-Ketten (dein frisches Wissen!) ist er noch besser
getarnt:

```php
Import::all()->map(fn ($i) => $i->user->name);  // versteckte N+1
Import::all()->map->user;                         // Higher-Order-Message, gleiche Falle
```

### Der Fix: Eager Loading mit `with()`
```php
$imports = Import::with('user')->get();
foreach ($imports as $import) { echo $import->user->name; }  // 0 zusaetzliche Queries
```

Was `with('user')` intern tut – **2 Queries statt 1+N**:
```
  1)  SELECT * FROM imports
  2)  SELECT * FROM users WHERE id IN (?, ?, ?, ...)   [alle user_id auf einmal]
      -> Eloquent matcht die User in PHP zurueck an die Imports
```

```
  LAZY (N+1):                         EAGER (with):
  imports  ---SELECT--> DB            imports  ---SELECT--------> DB
    |-> user (SELECT) --> DB            |                          |
    |-> user (SELECT) --> DB            +---SELECT ... IN (...)---> DB
    |-> user (SELECT) --> DB            (1 + 1 = 2 Queries, egal wie viele Imports)
    ... 1 + N Queries
```

### Die Werkzeuge im Überblick
```php
Import::with('user')->get();               // eager beim Query-Bau
Import::with('user.company')->get();       // verschachtelt (Import->User->Company)
Import::with(['records' => fn ($q) =>      // eager MIT Einschraenkung
    $q->where('amount', '>', 0)])->get();

$imports->load('records');                 // nachtraeglich auf schon geladener Collection
$imports->loadMissing('user');             // nur laden, falls noch nicht geladen
Import::withCount('records')->get();        // records_count ohne die Rows zu laden
```

### Aufspüren in der Entwicklung
```php
// In AppServiceProvider::boot() - wirft eine Exception bei ungeladener Relation:
Model::preventLazyLoading(! app()->isProduction());
```
> 🧭 So wird jede versehentliche N+1 in Dev zum harten Fehler statt zum stillen
> Performance-Leck in Prod.

### Zusammenhang mit Speicher (Vorschau auf Stufe 7)
```php
foreach (Import::with('user')->cursor() as $i) { $i->user; }  // ❌ with greift NICHT -> N+1
foreach (Import::with('user')->lazy()   as $i) { $i->user; }  // ✅ pro Block eager
```
> ⚠️ **`cursor()` kann keine Relationen eager laden.** Streaming *mit* Relationen
> → `lazy()` (das genau die Falle vermeidet – siehe Stufe 7).

### DataPipe-Bezug
Der `records`-Endpunkt (Liste der Records eines Imports mit `import`- oder
`user`-Info) ist der klassische N+1-Kandidat. Lösung: `Record::with('import')`.
Für Zähler im Report `Import::withCount('records')` statt pro Import zu zählen.

---

## Stufe 7 – Speicher: get() vs. cursor() vs. lazy() vs. chunk()

### Die Kernfrage
Alle vier lesen dieselben Rows – aber **wie viel steht gleichzeitig im RAM?**
Das ist exakt die Generator-/LazyCollection-Denkweise aus deiner letzten Runde,
jetzt auf DB-Reads angewandt.

```
  get()      [ ============ ganze Ergebnismenge im RAM ============ ]   O(n)
  cursor()   [ 1 Model ]  ->  yield  ->  [ 1 Model ]  -> ...            O(1) PHP-seitig
  lazy(500)  [ 500 ]  ->  [ 500 ]  ->  [ 500 ]  ...  (Bloecke)          O(chunk)
  chunk(500) wie lazy, aber Callback-Stil                               O(chunk)
```

### Die vier im Detail

```php
// get(): alles laden. Klein/mittel, du brauchst die ganze Collection.
$all = Record::where('import_id', $id)->get();   // Eloquent\Collection, alles im RAM

// cursor(): LazyCollection ueber einen Generator, EIN Model zur Zeit.
foreach (Record::where('import_id', $id)->cursor() as $rec) {
    // rec ist ein voll hydriertes Model, aber nur eins lebt gleichzeitig
}   // ⚠️ KEINE Relationen eager -> Relation im Loop = N+1

// lazy(): chunked unter der Haube, gibt eine LazyCollection zurueck.
Record::where('import_id', $id)->lazy(1000)->each(function ($rec) {
    // pro Block 1000 gelesen, dann freigegeben; with() greift pro Block
});

// chunk(): Callback pro Block. Klassisch fuer Massen-Verarbeitung.
Record::where('import_id', $id)->chunk(1000, function ($records) {
    foreach ($records as $rec) { /* ... */ }
});
```

### Der `chunkById`-Fallstrick
```php
// ❌ chunk() beim gleichzeitigen VERAENDERN der Filterspalte verschiebt die Seiten:
Record::where('processed', false)->chunk(1000, function ($rows) {
    $rows->each->update(['processed' => true]);   // Zeilen "rutschen" aus dem Filter
});
// ✅ chunkById() paginiert ueber den Primaerschluessel - stabil bei Updates:
Record::where('processed', false)->chunkById(1000, function ($rows) {
    $rows->each->update(['processed' => true]);
});
```

### Entscheidungstabelle

| Methode | Rückgabe | RAM | Relationen eager? | Einsatz |
|---|---|---|---|---|
| `get()` | `Eloquent\Collection` | O(n) | ✅ `with()` | kleine/mittlere Menge, ganze Liste gebraucht |
| `cursor()` | `LazyCollection` | O(1) PHP | ❌ | riesig, **ohne** Relationen, ein Durchlauf |
| `lazy()` | `LazyCollection` | O(chunk) | ✅ pro Block | riesig, **mit** Relationen, Pipeline-Stil |
| `chunk()` | – (Callback) | O(chunk) | ✅ pro Block | Massen-Verarbeitung, Callback-Stil |
| `chunkById()` | – (Callback) | O(chunk) | ✅ pro Block | Massen-**Update** derselben Menge |

> 🧭 **Merksatz:** Große Menge **ohne** Relationen → `cursor()`. Große Menge **mit**
> Relationen → `lazy()`/`chunk()`. Ganze Liste klein → `get()`.

> ⚠️ `cursor()` ist PHP-seitig O(1), aber der DB-Treiber puffert je nach Konfiguration
> u. U. das ganze Result im Client. Die Ersparnis liegt im PHP-Heap (keine n Models),
> nicht zwingend im DB-Puffer.

### DataPipe-Bezug
- CSV-**Import** → schreibst du (Generator → LazyCollection → `chunk`-Insert), das
  ist die *Schreib*-Seite aus der Collections-Runde.
- Records eines Imports **exportieren/durchlaufen** → **`cursor()`** wenn ohne
  Relation, **`lazy()`** wenn du pro Record den `import`/`user` brauchst.
- Nie `Record::all()` bei großen Imports – das ist `get()` über Millionen Rows.

---

## Stufe 8 – Model-Mechanik & Sicherheit

### Casts – roher DB-String → typisierter Wert
Die DB liefert immer Strings. Der Cast wandelt **beim Zugriff** (`__get`):

```php
class Record extends Model
{
    protected $casts = [
        'amount'     => 'decimal:6',   // "150.000000" - STRING mit 6 Nachkommastellen
        'meta'       => 'array',       // JSON-Spalte <-> PHP-Array
        'created_at' => 'datetime',    // -> Carbon-Objekt
        'active'     => 'boolean',     // 0/1 -> false/true
    ];
}
```

```
  DB-Spalte 'amount' = "150.000000"  (VARCHAR/DECIMAL, immer String aus PDO)
        |  Zugriff $record->amount  ->  __get  ->  Cast 'decimal:6'
        v
  "150.000000"   (String, exakt 6 Nachkommastellen - NICHT float!)
```

> ⚠️ **`decimal:6` gibt einen String zurück, keinen float** – genau richtig für eure
> Geld-Regel. Aber: **Rechnen** darf man damit trotzdem nicht per `+`/`*` in PHP-
> float-Arithmetik. Der Cast bewahrt nur die *Darstellung*; für Arithmetik weiter
> BigDecimal (eure Entscheidung: Geld nie über float). Der Cast ersetzt BigDecimal
> **nicht**, er verhindert nur den float-Verlust beim Lesen/Serialisieren.

### Accessors & Mutators – abgeleitete/aufbereitete Attribute
Moderne Syntax (Laravel 9+) über `Attribute`:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Model
{
    // Accessor: $user->full_name  (existiert nicht als Spalte)
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->first_name} {$this->last_name}",
        );
    }
    // Mutator: beim Setzen normalisieren
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $v) => strtolower(trim($v)),
        );
    }
}
$user->email = '  A@B.DE ';   // wird als "a@b.de" gespeichert
$user->full_name;              // "Max Muster" - berechnet, keine Spalte
```
> 🧭 Casts = *typisieren* (Spalte ↔ PHP-Typ). Accessors/Mutators = *transformieren*
> (Logik beim Lesen/Schreiben). Oft kombiniert.

### Mass Assignment – die Sicherheitslogik (fillable/guarded)
Das Problem, gegen das es schützt: Ein Client schickt Felder, die er nicht setzen
dürfte (z. B. `is_admin`, `user_id`), und `create($request->all())` würde sie blind
übernehmen – **„Mass Assignment Vulnerability"**.

```php
class Import extends Model
{
    protected $fillable = ['filename', 'size'];   // NUR diese per create()/fill()
    // Alternative (Blocklist): protected $guarded = ['id', 'user_id'];
}

// fillable/guarded greifen NUR bei Mass Assignment:
Import::create($request->all());   // user_id im Request wird IGNORIERT (nicht fillable)
$import->fill([...]);              // dito

// Direkte Zuweisung UMGEHT den Schutz (bewusst, serverseitig):
$import->user_id = $request->user()->id;   // ✅ erlaubt - kein Mass Assignment
$import->save();

// forceFill / forceCreate umgehen bewusst - mit Vorsicht:
$import->forceFill(['user_id' => 1]);
```

```
  Request  ->  create($request->all())
                    |
              fillable-Filter:  behalte nur ['filename','size']
                    |            verwerfe user_id, is_admin, ...
                    v
              INSERT nur mit erlaubten Feldern
```

> ⚠️ **`$guarded = []` (leer) schaltet den Schutz komplett ab** – alles ist
> fillable. Nur in kontrollierten Fällen. Für DataPipe gilt eure Regel:
> **`user_id`/`import_id` nicht fillable**, serverseitig über die Relation gesetzt
> (Stufe 5). `fillable` und die Relation-Zuweisung sind zwei Hälften desselben
> Ownership-Schutzes.

### Transaktionen – Alles-oder-nichts
```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($import, $chunks) {
    foreach ($chunks as $chunk) {
        DB::table('records')->insert($chunk);   // Bulk-Insert
    }
    $import->update(['status' => 'done']);
});   // Exception irgendwo drin -> ALLES wird zurueckgerollt (Rollback)
```

```
  BEGIN
    INSERT ... (chunk 1)   ok
    INSERT ... (chunk 2)   ok
    INSERT ... (chunk 3)   -> EXCEPTION
  ROLLBACK   -> chunk 1 & 2 verschwinden auch. DB bleibt konsistent.
```

- **Auto-Commit/Rollback:** `DB::transaction(Closure)` committet am Ende, rollt bei
  jeder Exception zurück – kein manuelles Fehlerhandling nötig.
- **Deadlock-Retries:** zweites Argument = Anzahl Versuche:
  `DB::transaction(fn () => ..., 3)`.
- **Manuell**, wenn du Kontrolle brauchst:
  ```php
  DB::beginTransaction();
  try { /* ... */ DB::commit(); }
  catch (\Throwable $e) { DB::rollBack(); throw $e; }
  ```
> ⚠️ Nur transaktionsfähige Engines (InnoDB, nicht MyISAM). Lange Transaktionen
> halten Locks – Bulk-Insert in **Chunks** innerhalb *einer* Transaktion ist ok,
> aber die Transaktion nicht künstlich offen halten.

### Wann rohes SQL (`DB::select`) unvermeidlich ist
Der Query Builder deckt ~95 % ab. Für den Rest – Window-Funktionen, CTEs,
DB-spezifische Features, hochoptimierte Abfragen – gehst du eine Schicht tiefer:

```php
$rows = DB::select(
    'SELECT import_id, SUM(amount) OVER (PARTITION BY import_id) AS running
     FROM records WHERE amount > ?',
    [0]                                  // ✅ Bindings - nie interpolieren
);
// $rows = array<stdClass>  (wie Query Builder, aber du schreibst das SQL)

DB::statement('...');   // fuer DDL / etwas ohne Rueckgabe
DB::insert('...', [...]);
DB::update('...', [...]);
```
> 🧭 Reihenfolge des Runtergehens: **Eloquent → Query Builder → `DB::select` mit
> Bindings**. Jede Stufe tiefer nur, wenn die darüber es nachweislich nicht kann.
> Und selbst ganz unten: **immer Prepared Statements** (Stufe 1) – rohes SQL heißt
> *nicht* String-Verkettung.

### DataPipe-Bezug (alles zusammen)
```php
// Import verarbeiten - Casts, Mass-Assignment-Schutz, Transaktion, Bulk-Insert:
DB::transaction(function () use ($request, $rows) {
    $import = $request->user()->imports()->create([   // user_id serverseitig (Relation)
        'filename' => $request->file('csv')->getClientOriginalName(),
    ]);                                               // filename ist fillable

    $rows->chunk(500)->each(function ($chunk) use ($import) {
        DB::table('records')->insert(                 // Query Builder: schnell, kein Event
            $chunk->map(fn ($r) => [
                'import_id' => $import->id,            // FK gesetzt, nicht aus Client
                'name'      => $r['name'],
                'email'     => strtolower($r['email']),
                'amount'    => $r['amount'],           // decimal-Spalte
                'row_index' => $r['index'],
            ])->all()
        );
    });
});
```

---

## Der ganze Kurs in einem Bild

| Stufe | Kernidee | Der eine Satz |
|---|---|---|
| 1 | Drei Schichten | PDO (Prepared Statements) → Query Builder (SQL-Baukasten) → Eloquent (Objekte); nach unten = Komfort gegen Kontrolle. |
| 2 | Query Builder | Baut SQL + Bindings, gibt `Support\Collection<stdClass>` zurück; Aggregation gehört in die DB. |
| 3 | Eloquent & Hydration | `get()` liefert Model-Objekte; Hydration setzt Attribute direkt + `$original` fürs Dirty-Tracking. |
| 4 | Die Grenze | „Objekte meiner Domäne" → Eloquent; „Zahlen/Zeilen als Daten" → Query Builder; Hydration kostet RAM/CPU. |
| 5 | Relationen | Eine Relation ist ein Query Builder mit gesetztem FK-`WHERE`; `->rel` (lazy, cached) vs. `->rel()` (weiterbauen). |
| 6 | N+1 & Eager Loading | Relation im Loop = 1+N Queries; `with()` macht 2; `preventLazyLoading` deckt es in Dev auf. |
| 7 | Speicher | `get` (alles) · `cursor` (O(1), keine Relationen) · `lazy`/`chunk` (Blöcke, mit Relationen). |
| 8 | Model-Mechanik | Casts typisieren (`decimal:6` → String, kein float-Verlust); `fillable` schützt Mass Assignment; `DB::transaction` = alles-oder-nichts. |

**Das Fundament in fünf Sätzen:**
1. Eloquent sitzt auf dem Query Builder, der auf PDO – jede Query, die du schreibst,
   endet als Prepared Statement mit getrennten Bindings (deshalb sicher).
2. Der Query Builder gibt **rohe `stdClass` in einer `Support\Collection`** zurück,
   Eloquent gibt **Model-Objekte in einer `Eloquent\Collection`** – der Unterschied
   ist Hydration (Objekte, Casts, Relationen, Change-Tracking) und ihr Preis.
3. Wähle nach der Frage *„arbeite ich mit Domänen-Objekten oder mit Daten?"*:
   Domäne/Relationen/Persistenz → Eloquent; Masse/Aggregation → Query Builder.
4. Relationen sind bequem, aber `->rel` in einer Schleife ist die N+1-Falle – lade
   **eager** (`with`), und bei großen Mengen entscheidet der Einstieg (`cursor`
   ohne, `lazy` mit Relationen).
5. Das Model schützt und typisiert dich (`fillable`, Casts) und Transaktionen halten
   die DB konsistent – aber Geld bleibt trotz `decimal:6`-Cast Sache von BigDecimal.

---

*Nächster Schritt nach dem Arbeitsprinzip „erst verstehen, dann umsetzen": die
Praxis-Runde **DataPipe umsetzen** – records/imports-Relationen sauber modellieren,
N+1 im Report/records-Endpunkt vermeiden, bewusst Eloquent vs. Query Builder für die
Aggregation wählen.*
