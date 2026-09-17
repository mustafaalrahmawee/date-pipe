# PHP Dateisystem & Streams – Tiefe Lernrunde (Stufe 1–7)

> Kompakte, eigenständige Referenz aller sieben Stufen. Fokus: **WARUM vor WIE** –
> interne Mechanik, bewusste Entscheidungen, Fallen und Anti-Patterns.
> Roter Faden: **DataPipe** – eine API, die große CSVs memory-safe verarbeitet und
> Reports generiert. Die Patterns aus Stufe 7 sind das Fundament für den
> Streaming-Upload (Runde 1) und die Streaming-Verarbeitung (Runde 2).

**Voraussetzungen (als bekannt angenommen):** PHP-Grundlagen, OOP, grundlegende
Laravel-Erfahrung. Der Kurs baut das Dateisystem-Wissen von Grund auf – von den
OS-Syscalls bis zu eigenen Stream-Wrappern.

---

## Inhalt

1. [Fundament: Dateisystem-Grundlagen](#stufe-1--fundament-dateisystem-grundlagen)
2. [Error Handling bei Dateioperationen](#stufe-2--error-handling-bei-dateioperationen)
3. [memory_limit und große Dateien](#stufe-3--memory_limit-und-große-dateien)
4. [Temporäre Dateien](#stufe-4--temporäre-dateien)
5. [PHP Streams im Detail](#stufe-5--php-streams-im-detail)
6. [Stream-Filter und eigene Wrapper](#stufe-6--stream-filter-und-eigene-wrapper)
7. [Alles zusammen: Praxispatterns](#stufe-7--alles-zusammen-praxispatterns)
8. [Der ganze Kurs in einem Bild](#der-ganze-kurs-in-einem-bild)

---

## Stufe 1 – Fundament: Dateisystem-Grundlagen

### Wie PHP das Dateisystem sieht

PHP arbeitet nie direkt mit der Festplatte. Jeder Dateizugriff geht durch mehrere
Schichten. Das zu verstehen erklärt *warum* bestimmte Dinge so funktionieren.

```
  Dein PHP-Code
       |
  PHP-Dateifunktionen  (fopen, fread, fwrite ...)
       |
  PHP Stream Layer     (Abstraktion - dazu mehr in Stufe 5)
       |
  libc / OS Syscalls   (open(), read(), write(), close())
       |
  Kernel / VFS         (Virtual File System des Betriebssystems)
       |
  Dateisystem-Treiber  (ext4, NTFS, HFS+, ZFS ...)
       |
  Hardware             (SSD, HDD, NFS-Mount ...)
```

### Kernkonzept: Resource

Wenn du `fopen()` aufrufst, bekommst du keine Datei zurück – du bekommst eine
**Resource**. Das ist ein interner Verweis auf eine geöffnete Verbindung zwischen
PHP und dem Betriebssystem. Die Resource enthält den aktuellen *Dateizeiger* (wo du
gerade in der Datei bist), den Modus und Metadaten.

```php
$fh = fopen('/tmp/test.txt', 'r');
var_dump($fh);                       // resource(5) of type (stream)
var_dump(get_resource_type($fh));    // "stream" - jede Datei-Resource ist ein Stream!
fclose($fh);
var_dump(get_resource_type($fh));    // "Unknown" - nach fclose ungueltig
```

### Pfade: absolut, relativ, auflösen

Ein **absoluter Pfad** beginnt an der Wurzel. Ein **relativer Pfad** wird vom
aktuellen Arbeitsverzeichnis (CWD) aus aufgelöst – hier lauern Fehler.

```php
echo getcwd();          // z.B. "/var/www/html"
chdir('/tmp');
echo getcwd();          // "/tmp" - derselbe relative Pfad zeigt jetzt woanders hin!
```

**Magische Konstanten für sichere Pfade:**

```php
echo __FILE__;   // "/var/www/html/src/Helper.php"  - voller Pfad der aktuellen Datei
echo __DIR__;    // "/var/www/html/src"             - deren Verzeichnis

// BEST PRACTICE: Pfade relativ zu __DIR__ bauen
$configPath = __DIR__ . '/../config/database.php';
```

**`realpath()` normalisiert** (löst `..`/`.` auf, folgt Symlinks) und gibt bei
nicht existierendem Pfad `false` zurück:

```php
$path = realpath($input);
if ($path === false) {
    throw new \RuntimeException("Pfad existiert nicht: $input");
}
```

### Sicherheit: Path Traversal verhindern

`realpath()` ist dein wichtigstes Werkzeug gegen Path-Traversal. Wenn ein Benutzer
`../../etc/passwd` als Dateinamen liefert:

```php
function safeFilePath(string $userInput, string $baseDir): string
{
    $baseDir  = realpath($baseDir);
    $fullPath = realpath($baseDir . '/' . $userInput);

    if ($fullPath === false) {
        throw new \RuntimeException('Datei nicht gefunden');
    }
    // Liegt der aufgeloeste Pfad INNERHALB des erlaubten Verzeichnisses?
    if (strpos($fullPath, $baseDir) !== 0) {
        throw new \RuntimeException('Zugriff verweigert: ausserhalb des Bereichs');
    }
    return $fullPath;
}
```

### Existenz und Typ prüfen

```php
file_exists($path);    // true fuer Dateien UND Verzeichnisse UND Symlinks
is_file($path);        // NUR reguläre Dateien
is_dir($path);         // NUR Verzeichnisse
is_readable($path);    // Kann der PHP-Prozess lesen?
is_writable($path);    // Kann der PHP-Prozess schreiben?
```

**Falle – der Stat-Cache:** PHP cachet die Ergebnisse von `file_exists()`,
`filesize()`, `filemtime()` etc. Wenn ein anderer Prozess die Datei löscht, sieht
PHP das evtl. nicht.

```php
file_exists('/tmp/data.txt');       // true (gecachet)
// anderer Prozess loescht die Datei ...
file_exists('/tmp/data.txt');       // IMMER NOCH true (aus dem Cache)
clearstatcache();                   // Cache leeren
file_exists('/tmp/data.txt');       // false (jetzt korrekt)
clearstatcache(true, '/tmp/data.txt'); // gezielt fuer eine Datei (effizienter)
```

### fopen() und die Dateimodi im Detail

Das Herzstück. Jeder Modus hat eine präzise Bedeutung und verhält sich anders bei
existierenden und nicht-existierenden Dateien.

| Modus | Lesen | Schreiben | Zeiger | Existiert | Existiert nicht |
|---|---|---|---|---|---|
| `r`  | ja | nein | Anfang | öffnet | Fehler (false) |
| `r+` | ja | ja | Anfang | öffnet | Fehler (false) |
| `w`  | nein | ja | Anfang | **leert die Datei!** | erstellt sie |
| `w+` | ja | ja | Anfang | **leert die Datei!** | erstellt sie |
| `a`  | nein | ja | Ende | behält Inhalt | erstellt sie |
| `a+` | ja | ja | Ende | behält Inhalt | erstellt sie |
| `x`  | nein | ja | Anfang | Fehler (false) | erstellt sie |
| `x+` | ja | ja | Anfang | Fehler (false) | erstellt sie |
| `c`  | nein | ja | Anfang | behält Inhalt | erstellt sie |
| `c+` | ja | ja | Anfang | behält Inhalt | erstellt sie |

**Die wichtigsten Nuancen:**

- `w` **löscht den gesamten Inhalt sofort beim Öffnen** – gefährlich, wenn das
  Skript danach abstürzt.
- `a` (Append) setzt den Zeiger ans Ende, existierender Inhalt bleibt – ideal für
  Logs.
- `x` (Exclusive Create) schlägt fehl, wenn die Datei existiert – nutzt intern das
  atomare `O_EXCL`-Flag, Race-Condition-sicher.
- `c` ist wie `w`, **aber ohne die Datei zu leeren**. Zeiger am Anfang. Wichtig
  für `flock()`: erst öffnen, dann locken, dann entscheiden was geschrieben wird.
  Bei `w` wäre der Inhalt schon weg, bevor du den Lock hast.

**Binär-Flag:** Bei Binärdateien immer `'b'` angeben (`rb`, `wb`) – auf Windows
verhindert das die `\r\n`-Konvertierung; auf Linux schadet es nie.

### Dateizeiger-Navigation

```php
$fh = fopen('data.txt', 'r+');
echo ftell($fh);                 // aktuelle Position
fseek($fh, 10, SEEK_SET);        // absolut ab Anfang
fseek($fh, 5, SEEK_CUR);         // relativ zur aktuellen Position
fseek($fh, -3, SEEK_END);        // relativ zum Ende
rewind($fh);                     // zurueck zum Anfang
ftruncate($fh, 11);              // Datei auf 11 Bytes kuerzen
fclose($fh);
```

**Achtung – `fseek()` ist der Sonderfall:** Es gibt bei Erfolg `0` und bei Fehler
`-1` zurück (nicht `false`). `if (!fseek(...))` interpretiert Erfolg als Fehler!
Immer `=== -1` oder `=== 0` prüfen.

### Dateiberechtigungen (Unix-Modell)

```
  Oktalnotation:  0 7 5 5
                  | | | |
                  | | | +-- Others: 5 = r-x
                  | | +---- Group:  5 = r-x
                  | +------ Owner:  7 = rwx
                  +-------- Spezial (setuid/setgid/sticky)

  4 = Lesen (r)   2 = Schreiben (w)   1 = Ausfuehren (x)
```

```php
// HAEUFIGER FEHLER: Oktalzahl vergessen!
chmod('file.txt', 644);    // FALSCH - 644 dezimal != 0644 oktal
chmod('file.txt', 0644);   // RICHTIG

chmod('script.sh', 0755);   // rwxr-xr-x  - Verzeichnisse/Skripte
chmod('config.php', 0600);  // rw-------  - Secrets (nur Owner)
```

`0777` **nie in Produktion** – jeder Benutzer darf alles. Standard: `0644` für
Dateien, `0755` für Verzeichnisse, `0600` für Secrets.

### Verzeichnisse lesen (vier Wege)

```php
scandir('/dir');                         // Array, ENTHAELT immer "." und ".."
glob('/dir/*.php');                       // Pattern-Matching, volle Pfade
new DirectoryIterator('/dir');            // OOP, speichereffizient
new RecursiveIteratorIterator(            // rekursiv inkl. Unterverzeichnisse
    new RecursiveDirectoryIterator('/dir', RecursiveDirectoryIterator::SKIP_DOTS)
);
```

---

## Stufe 2 – Error Handling bei Dateioperationen

### Das Grundproblem: Warnings statt Exceptions

PHP-Dateifunktionen stammen aus einer Zeit vor Exceptions. Bei Fehlern passieren
**zwei getrennte Dinge gleichzeitig**:

```
  fopen('nicht-da.txt', 'r')
       |
       +--> Rueckgabewert: false        <- den muss dein Code pruefen
       |
       +--> PHP Warning: Failed to open stream: No such file or directory
            (geht an den Error Handler / ins Log)
```

Das Problem: Prüfst du den Rückgabewert nicht, läuft der Code mit `false` weiter –
kryptische Folgefehler:

```php
// ANFAENGER-CODE, der scheinbar "funktioniert"
$fh   = fopen('config.json', 'r');           // false, wenn Datei fehlt
$data = fread($fh, filesize('config.json')); // Warning: not a valid stream
fclose($fh);                                  // Warning
// -> $data ist false, json_decode(false) gibt null -> stille Korruption
```

### Welche Funktion gibt was bei Fehler zurück?

| Funktion | Erfolg | Fehler |
|---|---|---|
| `fopen` | resource | `false` |
| `fread` / `fgets` | string | `false` |
| `fwrite` | int (Bytes) | `false` |
| `fseek` | `0` | `-1` (!) |
| `file_get_contents` | string | `false` |
| `mkdir` / `unlink` / `rename` / `chmod` | `true` | `false` |

### error_get_last() – Was genau ist passiert?

```php
error_clear_last();                  // vorher zuruecksetzen (seit PHP 7.0)
$fh = @fopen($path, 'r');
if ($fh === false) {
    $err = error_get_last()['message'] ?? 'Unbekannt';
    throw new \RuntimeException("fopen fehlgeschlagen: $err");
}
```

### Der @-Operator: warum er gefährlich ist

`@` unterdrückt die Warning-Ausgabe. Verschluckt man sie komplett, versagt der Code
still. Akzeptabel ist `@` **nur** mit sofortiger Prüfung + eigener Exception:

```php
$data = @file_get_contents($f);
if ($data === false) {
    throw new \RuntimeException(error_get_last()['message'] ?? 'Fehler');
}
```

### Die wichtigste Technik: Warnings → Exceptions

Statt jeden Rückgabewert einzeln zu prüfen, wandelst du PHP-Warnings global in
Exceptions um – dann arbeitest du mit `try/catch` wie in jeder modernen Sprache.

```php
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;  // respektiert @ und error_reporting -> Standard-Handler
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Jetzt wirft fopen() bei Fehler eine ErrorException!
try {
    $fh = fopen('nicht-da.txt', 'r');
} catch (\ErrorException $e) {
    echo $e->getMessage();     // die Warning-Nachricht
    echo $e->getSeverity();    // 2 (= E_WARNING)
}
```

`set_error_handler()` fängt **keine** fatalen Fehler (`E_ERROR`, `E_PARSE`) – dafür
gibt es `register_shutdown_function()`. Für Dateioperationen selten relevant.

### try / finally: garantiertes Cleanup

Der `finally`-Block wird **immer** ausgeführt – auch bei Exception, auch bei
`return`. Das verhindert Resource-Leaks:

```php
$fh = null;
try {
    $fh = fopen('data.txt', 'r');
    if ($fh === false) {
        throw new \RuntimeException('Kann Datei nicht oeffnen');
    }
    $data = fread($fh, filesize('data.txt'));
    processData($data);
} finally {
    if (is_resource($fh)) {
        fclose($fh);   // GARANTIERT geschlossen
    }
}
```

`SplFileObject` ist die Ausnahme: Es schließt die Datei automatisch im Destruktor
(wenn das Objekt out of scope geht) und wirft bei Fehler eine `RuntimeException` –
kein manuelles `false`-Prüfen nötig.

### Eigene Exception-Hierarchie

Für größere Projekte lohnt sich eine eigene Struktur – so reagiert der aufrufende
Code gezielt auf Fehlertypen:

```php
class FileSystemException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $path,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
    public function getPath(): string { return $this->path; }
}

class FileNotFoundException    extends FileSystemException {}
class FileNotReadableException extends FileSystemException {}
class FileLockException        extends FileSystemException {}
class DiskFullException        extends FileSystemException {}
```

### Die häufigsten Fehlerquellen

1. **Datei existiert nicht** – `No such file or directory`
2. **Keine Berechtigung** – `Permission denied` (PHP-Prozess z.B. `www-data`)
3. **Verzeichnis existiert nicht** – das *Verzeichnis*, nicht die Datei fehlt
4. **Festplatte voll** – `No space left on device`, `fwrite` gibt `0`/`false`
5. **File-Lock-Konflikt** – `flock` gibt `false` (Non-Blocking)
6. **Symlink-Schleifen** – `Too many levels of symbolic links`
7. **open_basedir-Restriktion** – php.ini schränkt Dateizugriff ein

### File Locking mit flock()

Bei gleichzeitigem Zugriff mehrerer Prozesse verhindert `flock()` Datenkorruption.

```
  LOCK_SH  Shared    - mehrere lesen gleichzeitig, keiner schreibt
  LOCK_EX  Exclusive - nur EINER liest/schreibt, alle anderen warten
  LOCK_UN  Unlock    - fclose() tut das automatisch
  LOCK_NB  Non-Block - kombinierbar: LOCK_EX|LOCK_NB gibt sofort false statt zu warten
```

```php
function atomicJsonUpdate(string $path, callable $updater): void
{
    $fh = fopen($path, 'c+');   // c+ = lesen+schreiben, NICHT leeren
    if ($fh === false) throw new \RuntimeException("Kann $path nicht oeffnen");

    try {
        if (!flock($fh, LOCK_EX)) throw new FileLockException('Lock gescheitert', $path);

        $size = filesize($path);
        $json = $size > 0 ? fread($fh, $size) : '{}';
        $data = json_decode($json, true) ?? [];

        $data = $updater($data);          // Callback transformiert

        ftruncate($fh, 0);                // leeren
        rewind($fh);                      // Zeiger zurueck
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
}
```

**Warum `w` mit `flock()` nicht funktioniert:** `w` leert die Datei **sofort beim
Öffnen – bevor du den Lock holen kannst**. Deshalb `c`/`c+`: öffnen ohne zu leeren,
erst locken, dann entscheiden.

`flock()` ist **kooperativ**: Es wirkt nur, wenn alle zugreifenden Prozesse `flock`
verwenden. Funktioniert nicht auf NFS und nicht auf allen Dateisystemen/Volumes.

---

## Stufe 3 – memory_limit und große Dateien

### Wie PHP Speicher verwaltet

Jede Variable ist intern ein `zval` (C-Struktur mit Wert, Typ, Referenzzähler). Ein
`zval` ist nur 16 Bytes, aber der *Wert* kann beliebig groß sein. Ein 1-GB-String =
16 Bytes zval + 1 GB Daten.

`memory_limit` begrenzt **nur den PHP-eigenen Heap** – nicht Shared Libraries,
Extension-internen Speicher, OS-Puffer oder externe Prozesse.

### Copy-on-Write

PHP kopiert Strings/Arrays nicht sofort bei Zuweisung – erst bei Änderung:

```php
$a = str_repeat('X', 100_000_000);   // 100 MB
$b = $a;                              // KEINE Kopie, nur Referenzzaehler +1
echo memory_get_usage();             // immer noch ~100 MB
$b .= 'Y';                           // JETZT wird kopiert -> ~200 MB
```

### Speicher messen

```php
memory_get_usage();        // aktueller Verbrauch der PHP-Variablen
memory_get_peak_usage();   // Hoechststand seit Skriptstart
memory_get_usage(true);    // was PHP vom OS angefordert hat (hoeher)
```

**Warum `explode()` den Speicher verdreifacht:** Kurzzeitig existieren drei Kopien –
Original-String, `explode`-Arbeitspuffer, Ergebnis-Array. Plus Array-Overhead
(~100 Bytes pro Eintrag). Eine 200-MB-CSV kann so 780 MB Peak verursachen.

### Warum file_get_contents() explodiert

```
  Methode                       Peak-Speicher fuer 500-MB-Datei
  ----------------------------  --------------------------------
  file_get_contents()           ~500 MB   (ganzer Inhalt im RAM)
  file() -> Array aller Zeilen  ~1.5 GB   (Array + Kopien + Overhead)
  fgets() -> Zeile fuer Zeile   ~4 KB     (nur aktuelle Zeile)
  fread() -> 8-KB-Chunks        ~8 KB     (nur aktueller Chunk)
  Generator mit yield           ~4 KB     (nur aktuelle Zeile)
```

**Faustregel:** Datei < 10 % von `memory_limit` -> `file_get_contents()` ok.
Größer oder Größe unbekannt -> immer streamen.

### Zeilenweises Lesen mit fgets()

```php
$fh = fopen('access.log', 'r');
while (($line = fgets($fh)) !== false) {
    processLine($line);     // $line wird jede Iteration ueberschrieben -> alter Speicher frei
}
fclose($fh);
```

**Falle:** Zeilenweises Lesen hilft nur, wenn du die Ergebnisse **nicht alle
sammelst**. `$errors[] = $line` lässt das Array linear wachsen – dann lieber direkt
in eine Output-Datei schreiben.

### Chunk-basiertes Lesen (Binärdaten)

```php
// Datei hashen ohne alles zu laden:
function hashFile(string $path, string $algo = 'sha256'): string
{
    $ctx = hash_init($algo);
    $fh  = fopen($path, 'rb');
    if ($fh === false) throw new \RuntimeException("Kann $path nicht oeffnen");
    try {
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);   // 64-KB-Chunks
            if ($chunk === false) break;
            hash_update($ctx, $chunk);
        }
    } finally {
        fclose($fh);
    }
    return hash_final($ctx);
}
// Peak: ~64 KB, egal ob 100 MB oder 100 GB. (PHP hat dafuer auch hash_file().)
```

Chunk-Größe: `8192` (8 KB) ist der Standard. Reines Durchlesen (Hashing, Kopieren):
64 KB – 1 MB effizienter (weniger Syscalls). Parsing mit Zwischenergebnissen: 4–16 KB.

### Generatoren: yield für Dateiverarbeitung

Die eleganteste Lösung. Ein Generator gibt bei jedem `yield` einen Wert zurück und
*pausiert* – der Speicher für vorherige Werte wird sofort frei.

```php
function readLines(string $path): \Generator
{
    $fh = fopen($path, 'r');
    if ($fh === false) throw new \RuntimeException("Kann $path nicht oeffnen");
    try {
        while (($line = fgets($fh)) !== false) {
            yield rtrim($line, "\n\r");
        }
    } finally {
        fclose($fh);   // auch bei break im foreach -> Generator zerstoert -> finally
    }
}

foreach (readLines('huge.log') as $line) {
    if (str_contains($line, 'ERROR')) echo $line . "\n";
}
```

**Generator-Pipeline** – mehrere Schritte verketten, konstanter Speicher:

```php
function filterLines(iterable $lines, callable $pred): \Generator {
    foreach ($lines as $l) if ($pred($l)) yield $l;
}
function mapLines(iterable $lines, callable $fn): \Generator {
    foreach ($lines as $l) yield $fn($l);
}
function takeLines(iterable $lines, int $limit): \Generator {
    $n = 0; foreach ($lines as $l) { if (++$n > $limit) return; yield $l; }
}

// 10-GB-Log, letzte 100 Fehler als JSON - mit ~8 KB Speicher:
$pipeline = takeLines(
    mapLines(
        filterLines(readLines('access.log'), fn($l) => str_contains($l, ' 500 ')),
        fn($l) => ['ts' => substr($l, 0, 19), 'msg' => trim($l)]
    ),
    100
);
foreach ($pipeline as $error) echo json_encode($error) . "\n";
```

### CSV in Batches (DB-Import)

```php
function batch(iterable $items, int $size): \Generator
{
    $batch = [];
    foreach ($items as $item) {
        $batch[] = $item;
        if (count($batch) >= $size) { yield $batch; $batch = []; }
    }
    if ($batch !== []) yield $batch;
}

// 2 Mio Zeilen in 1000er-Batches -> ~200 KB statt 400 MB:
foreach (batch(readCsv('users.csv'), 1000) as $rows) {
    $pdo->beginTransaction();
    foreach ($rows as $row) { /* insert */ }
    $pdo->commit();
}
```

### Speicherfallen

1. **String-Konkatenation in Schleifen** (`$out .= ...`) -> O(n²) Kopien. Stattdessen
   direkt in Output-Datei schreiben.
2. **json_decode() auf riesigem JSON** -> String + Array gleichzeitig. Streaming-Parser
   nutzen (z.B. `halaxa/json-machine`).
3. **Closures halten Referenzen** -> `use ($hugeData)` verhindert Freigabe trotz `unset`.
4. **array_map auf riesigen Arrays** -> materialisiert alles. Generator-Pipeline nutzen.

---

## Stufe 4 – Temporäre Dateien

### Die vier Mechanismen im Überblick

| Eigenschaft | `php://memory` | `php://temp` | `tmpfile()` | `tempnam()` |
|---|---|---|---|---|
| Speicherort | immer RAM | RAM -> Disk ab Schwelle | immer Disk | immer Disk |
| Belastet memory_limit | ja, voll | nur bis Schwelle | nein | nein |
| Dateipfad verfügbar | nein | nein | ja (Metadaten) | ja (direkt) |
| `rename()` möglich | nein | nein | nein (ungelinkt) | **ja** |
| Cleanup | fclose() | fclose() | fclose()/Skriptende | **manuell** |
| Bei Crash aufgeräumt | ja | ja | ja (OS) | **nein** |

### tmpfile() – automatisches Cleanup (bevorzugt)

```php
$fh = tmpfile();
fwrite($fh, "Temporaere Daten\n");
rewind($fh);
$content = stream_get_contents($fh);
$path = stream_get_meta_data($fh)['uri'];   // Pfad, falls eine Library ihn braucht
fclose($fh);   // Datei wird AUTOMATISCH geloescht
```

Intern: `tmpfile()` erstellt die Datei und **unlinkt sie sofort** – sie existiert nur
noch als offener File Descriptor. Selbst bei Crash räumt das OS auf. Nachteil: kein
`rename()` möglich (schon ungelinkt).

### tempnam() – manuelles Cleanup, aber rename möglich

```php
$path = tempnam(sys_get_temp_dir(), 'myapp_');   // gibt Pfad zurueck
file_put_contents($path, $data);
// ... verarbeiten ...
unlink($path);   // DU musst loeschen! Bei Crash bleibt die Datei.
```

**Falle:** Existiert das Verzeichnis nicht/nicht beschreibbar, fällt `tempnam()`
**still** auf `sys_get_temp_dir()` zurück – die Datei landet woanders als gedacht.

### php://memory vs php://temp

`php://memory` lebt komplett im RAM (schnell, aber zählt voll zum `memory_limit`).
`php://temp` beginnt im RAM und wechselt **automatisch auf Disk** ab einer Schwelle:

```php
$stream = fopen('php://temp/maxmemory:8388608', 'r+');  // 8 MB Schwelle
// < 8 MB: alles im RAM (schnell); >= 8 MB: automatisch Disk (RAM frei)
```

**Faustregel:** Datengröße unbekannt/variabel -> `php://temp`. Sicher klein und
maximale Geschwindigkeit nötig -> `php://memory`. Dateipfad für externe Library nötig
-> `tmpfile()`. `rename()` an Zielort nötig -> `tempnam()`.

### Race Conditions und TOCTOU

**TOCTOU** (Time-of-Check to Time-of-Use): Zwischen `file_exists()`-Prüfung und
`fopen()`-Nutzung kann ein Angreifer einen Symlink einschleusen.

```php
// UNSICHER: Pruefung und Nutzung getrennt
if (!file_exists($path)) {        // Check
    file_put_contents($path, $data); // Use - Luecke dazwischen!
}

// SICHER: atomare Erstellung
$fh = tmpfile();                  // erstellt+oeffnet+unlinkt atomar
// oder: tempnam() nutzt intern O_EXCL
```

**Dateinamen nie vorhersagbar:** `uniqid()` und `mt_rand()` sind vorhersagbar.
Nutze `bin2hex(random_bytes(16))` – oder besser gleich `tmpfile()`/`tempnam()`.

### Das wichtigste Pattern: Atomares Schreiben (temp + rename)

Die Zieldatei ist **nie halb-geschrieben sichtbar** – andere Prozesse sehen entweder
den alten oder den neuen Inhalt, nie etwas dazwischen.

```
  UNSICHER: direkt in Zieldatei schreiben
  fopen('config.json','w')  -> JETZT LEER
  fwrite(...)               -> teilweise geschrieben (anderer Prozess sieht Muell)

  SICHER: temp + atomares rename
  tempnam()  -> /tmp/cfg_X (im selben Verzeichnis!)
  fwrite(...)               -> config.json unberuehrt
  rename(tmp, 'config.json')-> ATOMARER Wechsel (ein Kernel-Aufruf)
```

```php
function atomicWrite(string $path, callable $writer, int $mode = 0644): void
{
    $dir = dirname($path);
    $tmp = tempnam($dir, '.tmp_');   // SELBES Verzeichnis - rename nur dort atomar!
    if ($tmp === false) throw new \RuntimeException("Keine temp-Datei in $dir");

    $fh = null;
    try {
        $fh = fopen($tmp, 'wb');
        if ($fh === false) throw new \RuntimeException('temp nicht oeffnbar');
        $writer($fh);            // Callback schreibt
        fflush($fh);
        fclose($fh);
        $fh = null;
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) throw new \RuntimeException('rename fehlgeschlagen');
        $tmp = null;             // Erfolg -> nicht loeschen
    } finally {
        if ($fh !== null && is_resource($fh)) fclose($fh);
        if ($tmp !== null && file_exists($tmp)) @unlink($tmp);
    }
}
```

**Wichtig:** `rename()` ist nur **atomar auf demselben Dateisystem/Mount**. Deshalb
die temp-Datei im selben Verzeichnis wie das Ziel erstellen. Über Mount-Grenzen macht
PHP intern `copy + unlink` – **nicht atomar**.

### Upload-Verarbeitung

```php
function handleUpload(array $file, string $destDir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) throw new \RuntimeException('Upload-Fehler');
    if (!is_uploaded_file($file['tmp_name'])) throw new \RuntimeException('Kein Upload');

    // MIME echt pruefen (NICHT $file['type'] - vom Client faelschbar!)
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['application/pdf', 'image/jpeg'], true)) {
        throw new \RuntimeException("Typ nicht erlaubt: $mime");
    }

    // NIE den Client-Dateinamen verwenden
    $safeName = bin2hex(random_bytes(16)) . '.dat';
    $destPath = rtrim($destDir, '/') . '/' . $safeName;

    // move_uploaded_file prueft intern, dass es ein echter Upload ist - einzig sichere Wahl
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new \RuntimeException('Verschieben fehlgeschlagen');
    }
    chmod($destPath, 0644);
    return $destPath;
}
```

### Cleanup-Strategien (gestaffelt)

1. **tmpfile()** – automatisch, auch bei Crash (OS räumt auf).
2. **try/finally** – `unlink()` im finally.
3. **register_shutdown_function()** – ein Tracker-Objekt räumt bei Skriptende auf.
4. **Cronjob** – letzte Linie für verwaiste Dateien (nur eigenes Präfix + Alter prüfen).

---

## Stufe 5 – PHP Streams im Detail

### Was ist ein Stream?

Ein Stream ist ein Datenfluss mit **einheitlicher Schnittstelle**. Dieselben
Funktionen (`fread`, `fwrite`, `fgets`) funktionieren über *jede* Datenquelle.

```
  Ein Stream = drei Teile:

  1. WRAPPER  - das "Protokoll" (file://, http://, php://) - waehlt via Schema
  2. RESOURCE - das Handle, das fopen() zurueckgibt (Position, Puffer, Zustand)
  3. CONTEXT  - optionale Konfiguration (HTTP-Header, SSL, Timeouts)

  fopen('http://api.example.com/data', 'r', false, $context)
        \____/ \___________________/  \_/         \______/
       Wrapper        Pfad/URL       Modus        Context
```

Der Nutzen: derselbe Code für Datei, Netzwerk und Speicher – testbar (füttere
Funktionen mit `php://memory` statt echten Dateien) und wiederverwendbar.

```php
function countLines($stream): int {
    $n = 0;
    while (fgets($stream) !== false) $n++;
    return $n;
}
countLines(fopen('file:///var/log/app.log', 'r'));   // lokale Datei
countLines(fopen('https://example.com/data.txt', 'r')); // HTTP
countLines(fopen('php://memory', 'r+'));             // RAM
```

### Die eingebauten Wrapper

| Wrapper | Zweck | Beispiel |
|---|---|---|
| `file://` | lokale Dateien (Standard) | `file:///tmp/x.txt` |
| `php://` | I/O-Kanäle & Speicher | `php://stdin`, `php://temp` |
| `http:// https://` | Web/APIs | `https://api.io/v1` |
| `data://` | Inline-Daten | `data://text/plain,Hi` |
| `glob://` | Verzeichnis-Pattern | `glob://*.php` |
| `compress.zlib://` | gzip transparent | `compress.zlib://x.gz` |
| `phar://` | PHP-Archive | `phar://app.phar/x` |

`stream_get_wrappers()` listet die verfügbaren. `http/https/ftp` brauchen
`allow_url_fopen` (php.ini).

### php:// – die I/O-Kanäle

```php
// CLI-Eingabe (oder Pipe: echo "x" | php script.php)
$name = trim(fgets(STDIN));

// Roher HTTP-Request-Body (fuer JSON-APIs - $_POST reicht dort nicht)
$data = json_decode(file_get_contents('php://input'), true);
// Grosse Bodies streamen statt laden:
stream_copy_to_stream(fopen('php://input','r'), fopen('/tmp/up.bin','wb'));

// In den Ausgabepuffer schreiben (z.B. CSV direkt an den Browser streamen)
$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Preis']);
```

`php://input` ist **nicht** bei `multipart/form-data`-Uploads verfügbar – dort
`$_FILES` nutzen.

### compress.zlib:// – Kompression transparent

```php
// .gz lesen OHNE vorher zu entpacken - Zeilen werden on-the-fly dekomprimiert:
$fh = fopen('compress.zlib:///var/log/access.log.gz', 'r');
while (($line = fgets($fh)) !== false) processLine($line);
fclose($fh);

// Komprimiert schreiben:
$fh = fopen('compress.zlib://output.gz', 'wb');
fwrite($fh, $grosseDaten);   // wird komprimiert gespeichert
fclose($fh);
```

### Stream-Kontexte

Container für Optionen, die das Stream-Verhalten steuern (HTTP-Methode, Header, SSL).

```php
$context = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'timeout'       => 10,
        'ignore_errors' => true,          // Body auch bei 4xx/5xx lesen
        'header'        => "Accept: application/json\r\n",
    ],
    'ssl' => [
        'verify_peer'      => true,       // NIEMALS auf false! (MITM-Risiko)
        'verify_peer_name' => true,
        'cafile'           => '/etc/ssl/certs/ca-bundle.crt',
    ],
]);
$body = file_get_contents('https://api.example.com/status', false, $context);
// HTTP-Header nach dem Request: automatische Variable $http_response_header
```

Für **echte HTTP-Arbeit** cURL/Guzzle nutzen – Streams haben keine ordentliche
Statuscode-Behandlung, kein Retry, keine Connection-Pools.

### Stream-Metadaten

```php
$meta = stream_get_meta_data($fh);
// [seekable] => kann fseek()?  [uri] => Pfad  [timed_out] => Timeout?
//  [eof] => Ende?  [wrapper_data] => HTTP-Header bei http://
if (!$meta['seekable']) { /* Netzwerk-Stream - kein rewind moeglich */ }
```

### Streams kopieren

```php
// Chunk-weise, konstanter Speicher, egal wie gross:
$bytes = stream_copy_to_stream($src, $dst);
$all   = stream_get_contents($fh);   // ab aktueller Position bis Ende
```

### Blocking / Timeouts / Non-Blocking

```php
$fh = fsockopen('example.com', 80, $errno, $errstr, 5);  // Connect-Timeout 5s
stream_set_timeout($fh, 10, 0);                          // Lese-Timeout 10s
$meta = stream_get_meta_data($fh);
if ($meta['timed_out']) throw new \RuntimeException('Timeout');

stream_set_blocking($fh, false);   // Non-Blocking: fread kehrt sofort zurueck
// stream_select() wartet auf den ERSTEN von mehreren Streams (wie select() in C)
```

Für die meisten Web-Apps: nie nötig. Relevant bei CLI-Daemons, gleichzeitigen
Verbindungen, Event-Loops (ReactPHP, Swoole).

---

## Stufe 6 – Stream-Filter und eigene Wrapper

### Was ist ein Stream-Filter?

Ein Filter sitzt **zwischen** Stream und Code und transformiert Daten beim
Durchfluss – ohne Zwischenspeicherung, chunk-weise.

```
  OHNE Filter:  Datei --fread--> Dein Code
  MIT Filter:   Datei --> [FILTER toupper] --> Dein Code
                          transformiert beim Durchfluss

  Dein Code ruft dieselbe fread()-Funktion. Er weiss nicht, dass ein Filter da ist.
```

```php
// 100-GB-Datei transformieren mit konstantem Speicher:
$in  = fopen('huge.txt', 'r');
$out = fopen('out.txt', 'w');
stream_filter_append($in, 'string.toupper');   // Filter dranhaengen
stream_copy_to_stream($in, $out);              // transformiert on-the-fly
fclose($in); fclose($out);
```

### Eingebaute Filter

`stream_get_filters()` listet sie. Die wichtigsten: `string.toupper`,
`string.tolower`, `string.rot13`, `convert.base64-encode/decode`,
`convert.iconv.<von>/<nach>` (Zeichensatz), `zlib.deflate/inflate`.

```php
// Base64 beim Schreiben, Zeichensatz-Konvertierung beim Lesen:
$fh = fopen('encoded.txt', 'w');
stream_filter_append($fh, 'convert.base64-encode');
fwrite($fh, 'Geheime Daten'); fclose($fh);

$fh = fopen('latin1.txt', 'r');
stream_filter_append($fh, 'convert.iconv.ISO-8859-1/UTF-8');
$utf8 = stream_get_contents($fh); fclose($fh);
```

`append` hängt ans Ende der Filter-Kette, `prepend` an den Anfang. Dritter Parameter:
`STREAM_FILTER_READ` / `STREAM_FILTER_WRITE` / `STREAM_FILTER_ALL` (Richtung).

### Eigenen Filter schreiben (php_user_filter)

Daten fließen in **Buckets** (kleine Blöcke). Eine "Bucket Brigade" ist die Kette
dieser Blöcke.

```php
class ReplaceFilter extends \php_user_filter
{
    private string $search;
    private string $replace;

    public function onCreate(): bool   // beim Erstellen
    {
        if (!is_array($this->params)) return false;
        $this->search  = $this->params['search']  ?? '';
        $this->replace = $this->params['replace'] ?? '';
        return $this->search !== '';
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = str_replace($this->search, $this->replace, $bucket->data);
            $consumed += strlen($bucket->data);
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;   // Erfolg. Sonst: PSFS_FEED_ME / PSFS_ERR_FATAL
    }
}

stream_filter_register('replace', ReplaceFilter::class);
$fh = fopen('data.txt', 'r');
stream_filter_append($fh, 'replace', STREAM_FILTER_READ, ['search'=>'foo','replace'=>'bar']);
```

**Zwei Fallen bei eigenen Filtern:**

1. **Zustand über Chunk-Grenzen** – bei einem Streaming-Cipher muss z.B. die
   Schlüsselposition als `$this->keyPos` über mehrere `filter()`-Aufrufe erhalten
   bleiben. PHP ruft `filter()` einmal pro Chunk auf.
2. **Muster über Bucket-Grenzen** – ein mehrstelliges Suchmuster kann über zwei
   Buckets verteilt sein (Bucket endet auf "fo", nächster beginnt mit "o") und wird
   dann von `str_replace` nicht gefunden. Für mehrstellige Muster einen Puffer pflegen.

### Was ist ein Stream-Wrapper?

Während ein Filter Daten *transformiert*, definiert ein Wrapper *woher die Daten
kommen*. Mit einem eigenen Wrapper sprichst du DB, API oder RAM an, als wäre es eine
Datei – und bestehende Libraries (CSV-Parser, `SplFileObject`) funktionieren damit
ohne Änderung.

```
  fopen('mydb://users/42')   -> liest aus der Datenbank!
  fopen('s3://bucket/key')   -> laedt aus S3!
  fopen('cache://session')   -> liest aus Redis!
```

### Das streamWrapper-Interface

| Methode | Ausgelöst durch |
|---|---|
| `stream_open()` | `fopen()` |
| `stream_read()` | `fread()`, `fgets()` |
| `stream_write()` | `fwrite()` |
| `stream_tell()` / `stream_seek()` | `ftell()` / `fseek()` |
| `stream_eof()` | `feof()` |
| `stream_close()` | `fclose()` |
| `stream_stat()` | `fstat()` |
| `url_stat()` | `file_exists()`, `filesize()` |

```php
class ReverseWrapper
{
    public $context;             // PHP setzt das automatisch (Context-Support)
    private string $data;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $content = substr($path, strlen('reverse://'));   // Schema abschneiden
        $this->data = strrev($content);
        return true;
    }
    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool { return $this->position >= strlen($this->data); }
    public function stream_stat(): array { return ['size' => strlen($this->data)]; }
    public function stream_close(): void {}
    // stream_tell/stream_seek analog
}

stream_wrapper_register('reverse', ReverseWrapper::class);
echo file_get_contents('reverse://Hallo Welt');   // "tleW ollaH"
```

Ein **In-Memory-Dateisystem** (`mem://`) mit statischem `$files`-Array plus
`url_stat()` (für `file_exists`/`filesize`) ist genau das, was die Test-Library
**vfsStream** macht – ideal für Tests ohne echte Dateien.

### Wrapper überschreiben (fortgeschritten)

```php
stream_wrapper_unregister('file');
stream_wrapper_register('file', LoggingFileWrapper::class);
// ...
stream_wrapper_restore('file');   // Original wiederherstellen
```

**Endlosschleifen-Falle:** Ruft dein `file://`-Wrapper intern `fopen('file://...')`
auf, ruft er sich selbst auf. Lösung: vor dem echten Zugriff `stream_wrapper_restore`,
Zugriff, dann wieder registrieren. Nur bewusst und lokal begrenzt einsetzen – es
betrifft den *gesamten* Prozess inkl. Framework-Interna.

---

## Stufe 7 – Alles zusammen: Praxispatterns

Keine neuen Konzepte – die Kombination aller Stufen zu produktionsreifen Lösungen.
Diese Patterns sind das direkte Fundament für DataPipe.

### Pattern: Sicherer, memory-safer Datei-Upload

Kombiniert Stufe 1 (Pfade/Rechte), 2 (Error Handling), 4 (temp/atomar), 5 (Streaming).

```php
class UploadException extends \RuntimeException {}
class InvalidFileTypeException extends UploadException {}
class FileTooLargeException extends UploadException {}

final class SecureUploadHandler
{
    public function __construct(
        private readonly string $destDir,
        private readonly array  $allowedMime = ['text/csv', 'text/plain'],
        private readonly int    $maxBytes    = 104_857_600,   // 100 MB
    ) {}

    public function handle(array $file): string
    {
        $this->assertUploadOk($file);      // Upload-Fehlercode pruefen
        $this->assertRealUpload($file);    // is_uploaded_file - Sicherheit
        $this->assertSize($file);          // filesize auf temp-Datei (nicht Client-Wert)
        $this->assertMimeType($file);      // finfo whitelist (nicht $file['type']!)
        return $this->storeAtomically($file);
    }

    private function assertMimeType(array $file): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, $this->allowedMime, true)) {
            throw new InvalidFileTypeException("Typ nicht erlaubt: $mime");
        }
    }

    private function storeAtomically(array $file): string
    {
        if (!is_dir($this->destDir) || !is_writable($this->destDir)) {
            throw new UploadException('Zielverzeichnis nicht beschreibbar');
        }
        $name     = bin2hex(random_bytes(16)) . '.csv';   // nicht erratbar
        $destPath = rtrim($this->destDir, '/') . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new UploadException('Verschieben fehlgeschlagen');
        }
        chmod($destPath, 0644);
        return $destPath;
    }
    // assertUploadOk / assertRealUpload / assertSize analog
}
```

HTTP-Codes: 413 (zu groß), 415 (falscher Typ), 422 (Validierung), 400 (sonst).

### Pattern: Große CSV streamen und verarbeiten

Kombiniert Stufe 3 (Generatoren) und 2 (Error Handling). Konstanter Speicher.

```php
function streamCsv(string $path, string $sep = ','): \Generator
{
    $fh = fopen($path, 'r');
    if ($fh === false) throw new \RuntimeException("Kann nicht oeffnen: $path");
    try {
        $headers = fgetcsv($fh, 0, $sep);
        if ($headers === false) throw new \RuntimeException('Leere Datei');
        $headers  = array_map('trim', $headers);
        $expected = count($headers);
        $line     = 1;
        while (($row = fgetcsv($fh, 0, $sep)) !== false) {
            $line++;
            if (count($row) !== $expected) continue;   // defensiv
            yield $line => array_combine($headers, $row);
        }
    } finally {
        fclose($fh);   // garantiert, auch bei break/Exception
    }
}

$total = 0.0;
foreach (streamCsv('sales.csv') as $line => $row) {
    $total += (float) $row['amount'];
}   // funktioniert bei 1 MB wie bei 10 GB
```

### Pattern: Atomares Schreiben (Export)

Aus Stufe 4 – Zieldatei nie halb-geschrieben sichtbar. (Siehe `atomicWrite()` in
Stufe 4.) Für Report-Exporte zentral. Kernpunkte: temp-Datei im **selben
Verzeichnis**, `rename()` als atomarer Wechsel, Cleanup im `finally`.

### Pattern: CSV-Validierung während des Streamens

Statt beim ersten Fehler abzubrechen, Fehler sammeln (das "tolerieren und zählen"-
Muster – der Nutzer will wissen, *welche* Zeilen kaputt sind).

```php
final class CsvValidationResult
{
    public function __construct(
        public readonly int   $validRows,
        public readonly array $errors,   // [zeile => grund]
    ) {}
    public function isValid(): bool { return $this->errors === []; }
}

function validateCsv(string $path, array $rules): CsvValidationResult
{
    $errors = []; $valid = 0;
    foreach (streamCsv($path) as $line => $row) {
        $rowErrors = [];
        foreach ($rules as $column => $validator) {
            $value = $row[$column] ?? null;
            if ($value === null || !$validator($value)) $rowErrors[] = $column;
        }
        if ($rowErrors === []) $valid++;
        else $errors[$line] = 'Ungueltig: ' . implode(', ', $rowErrors);
    }
    return new CsvValidationResult($valid, $errors);
}

$result = validateCsv('import.csv', [
    'email'  => fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
    'amount' => fn($v) => is_numeric($v) && (float)$v >= 0,
]);
```

### Pattern: Log-Rotation mit File-Locking

Aus Stufe 2 (flock) und 4 (rename). Modus `'c'` (öffnen ohne leeren) ermöglicht
erst-locken-dann-rotieren:

```php
function appendLog(string $path, string $message, int $maxBytes = 10_485_760): void
{
    $fh = fopen($path, 'c');
    if ($fh === false) throw new \RuntimeException("Log nicht oeffnbar: $path");
    try {
        if (!flock($fh, LOCK_EX)) throw new \RuntimeException('Lock fehlgeschlagen');
        $size = fstat($fh)['size'];              // fstat statt filesize (stat-cache)
        if ($size >= $maxBytes) {
            rename($path, $path . '.' . date('YmdHis'));   // rotieren
            ftruncate($fh, 0); rewind($fh);
        } else {
            fseek($fh, 0, SEEK_END);             // append
        }
        fwrite($fh, sprintf("[%s] %s\n", date('c'), $message));
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
}
```

### Pattern: Streaming-Download mit konstantem Speicher

Aus Stufe 5 (`stream_copy_to_stream`, `php://output`):

```php
function streamDownload(string $path, string $filename): void
{
    if (!is_readable($path)) { http_response_code(404); return; }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $in  = fopen($path, 'rb');
    $out = fopen('php://output', 'wb');
    try {
        stream_copy_to_stream($in, $out);   // chunk-weise, auch bei 5 GB
    } finally {
        fclose($in); fclose($out);
    }
}
```

### Brücke zu Laravel: was ändert sich, was bleibt

| Reines PHP | Laravel-Äquivalent | Wann was? |
|---|---|---|
| `fopen/fread/fwrite` | `Storage::disk()->...` | Storage für normale I/O; rohe Streams für memory-safe Verarbeitung |
| `move_uploaded_file` | `$request->file()->store()` | Laravel für Standard-Uploads |
| Eigene Validierung | Form Requests | Form Request für Regeln, Service für Logik |
| Eigene Exceptions | Exceptions + Handler | Laravel rendert Exceptions zu HTTP-Responses |
| `stream_copy_to_stream` | `Storage::readStream` + `response()->stream` | Konzept identisch, andere API |
| Atomares temp+rename | bleibt oft manuell | Laravel garantiert **keine** Atomarität – dein Wissen zählt |

**Der Kernpunkt:** Laravels `Storage`-Fassade *versteckt* das gelernte Wissen. Für
memory-safen Import auf `Storage::readStream()` zurückgreifen (gibt eine rohe
Resource) und mit `fgets`/Generatoren arbeiten – genau wie hier. Atomare Exporte
selbst bauen, weil Laravel das nicht garantiert.

### Checkliste für produktionsreifen Datei-Code

| Prüfpunkt | Stufe |
|---|---|
| Jeden Rückgabewert von fopen/fread/fwrite geprüft? | 2 |
| fclose im finally-Block (garantiert auch bei Exception)? | 2 |
| Große Dateien gestreamt statt geladen (kein file_get_contents auf Uploads)? | 3 |
| Generator statt Array bei unbekannter Zeilenzahl? | 3 |
| MIME mit finfo geprüft, nicht mit `$_FILES['type']`? | 1/4 |
| Dateiname nicht erratbar (random_bytes), nie der Client-Name? | 4 |
| move_uploaded_file statt rename für Uploads? | 4 |
| Atomares Schreiben (temp + rename, selbes Verzeichnis) für Exporte? | 4 |
| Path-Traversal mit realpath + Root-Check verhindert? | 1 |
| Restriktive Berechtigungen (0644 Dateien, 0600 Secrets)? | 1 |
| flock bei gleichzeitigem Zugriff (Logs, Counter)? | 2 |
| temp-Dateien im Fehlerfall aufgeräumt (finally/unlink)? | 4 |
| Passende HTTP-Statuscodes (413, 415, 422, 404)? | 7 |

---

## Der ganze Kurs in einem Bild

```
  Stufe 1  Fundament        fopen-Modi, realpath, Berechtigungen, Resources, Zeiger
     |
  Stufe 2  Error Handling   Warnings statt Exceptions, try/finally, eigene Exceptions,
     |                       flock (kooperativ, c/c+ statt w)
     |
  Stufe 3  memory_limit     zval/Copy-on-Write, warum file_get_contents explodiert,
     |                       fgets/fread-Chunks, GENERATOREN (yield) + Pipelines + batch
     |
  Stufe 4  Temp-Dateien     tmpfile vs tempnam, php://temp, TOCTOU, ATOMARES temp+rename,
     |                       sichere Uploads (finfo, move_uploaded_file, random_bytes)
     |
  Stufe 5  Streams          Wrapper+Resource+Context, php://input, stream_copy_to_stream,
     |                       compress.zlib://, Kontexte (SSL: verify_peer nie false!)
     |
  Stufe 6  Filter & Wrapper Bucket-Brigade (Zustand ueber Chunks!), eigene Wrapper
     |                       (streamWrapper-Interface), In-Memory-FS = vfsStream-Prinzip
     |
  Stufe 7  Praxispatterns   sicherer Upload, Streaming-CSV, atomarer Export, Log-Rotation,
                            Streaming-Download  ->  Fundament fuer DataPipe

  Roter Faden fuer DataPipe:
  Upload (Stufe 7) -> Storage::readStream + Generator (Stufe 3/5) ->
  LazyCollection-Verarbeitung -> atomarer Report-Export (Stufe 4/7)

  Kernprinzip ueberall: memory-safe (streamen statt laden), fail-safe (finally,
  atomar), und ehrliche Grenzen dokumentieren statt Scheinloesungen bauen.
```
