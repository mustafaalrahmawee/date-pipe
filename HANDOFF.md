# Handoff an den Planungs-Agent — Stand nach Runde 1 (Milestone 1 abgeschlossen)

**Zweck dieses Dokuments:** Der Implementierungs-Agent hat Runde 1 (Memory-safe Streaming-Upload) fertiggestellt, getestet und per externer Evaluation geprüft. Dieses Dokument gibt dir — dem Planungs-Agent — den verbindlichen Stand, die getroffenen Entscheidungen und den offenen Restbestand, damit du die nächste Runde realistisch planen kannst.

**Verwandte Dokumente:**
- `README.md` — Projektvision und 5-Runden-Roadmap (File System → Collections → Testing → Queue → Cache)
- `EVALUATION.md` — externer Code-Report: Datei-Inventar, Request-Flow, Status-Vertrag, 9 Entscheidungen mit Alternativen, reproduzierbares Test-Protokoll (§5), Limitationen (§7)

**Arbeitsweise (aus dem ursprünglichen Briefing, weiter gültig):** kleine abgeschlossene Schritte, Pause mit Erklärung nach jedem Schritt, Design-Entscheidungen dem User VOR der Umsetzung vorlegen, keine ungefragten Extras.

---

## 1. Wo wir stehen

Runde 1 ist funktional komplett und über echtes HTTP verifiziert:

- **Auth** (Unterstützungsschicht): `POST /api/auth/register`, `POST /api/auth/login` — Sanctum Bearer-Token, Form Requests, keine User-Enumeration.
- **Kern-Endpunkt** `POST /api/imports` (geschützt via `auth:sanctum`): nimmt eine CSV entgegen, validiert Inhalt (Größe → finfo-MIME → Header-Sniff) **vor** dem Speichern, legt sie unter `storage/app/private/imports/` mit CSPRNG-Namen ab und erzeugt einen Ownership-Record in der `imports`-Tabelle.
- **Status-Vertrag** (alle verifiziert): 201 Erfolg · 401 unauthentifiziert · 413 zu groß · 415 falscher Typ · 422 Validierung/Header · 400 I/O-Fallback.
- **Beteiligte Schichten:** Route → `StoreImportRequest` → `ImportController` → `ImportUploadService` (+ `ImportUploadResult`-DTO) → `Import`-Model; Fehler-Rendering zentral in `bootstrap/app.php`.
- **Datenbank** (SQLite, lokal): `imports` mit ULID-Primary-Key, FK `user_id` (cascade), `path`, `original_name`, `size_bytes`, `header` (json → array cast). Alle Migrationen gelaufen.
- **Tests:** nur das manuelle Protokoll aus `EVALUATION.md` §5. Die Pest-Suite ist installiert (Pest 5, `composer test`), enthält aber noch nur Skeleton-Tests.

## 2. Verbindliche Entscheidungen (nicht neu diskutieren; Revidieren nur mit begründetem Vorschlag an den User)

1. **Trennlinie Fassade vs. rohe Streams:** Storage-Fassade (`storeAs`, `local`-Disk) für Ablage/Ort; rohe Streams (`finfo`, `fopen`, `fgetcsv`) überall dort, wo Code den *Inhalt* kennen muss. `Storage::readStream()` ist der vorgesehene Einstieg für Runde 2 (Lesen der *gespeicherten* Datei).
2. **Validierung vor Persistenz**, Reihenfolge Größe → MIME → Header, alles auf der PHP-Temp-Datei. Invalide Dateien erreichen `imports/` nie — es gibt keinen Delete-als-Cleanup-Pfad.
3. **Größenprüfung im Service** (413), nicht als `max`-Regel im Form Request; **`mimes`-Regel entfernt** (die Regel lieferte 422 und machte den 415-Pfad tot). Form Request prüft nur die Form (`required`, `file`).
4. **Exception-Hierarchie mit `status()`-Methoden** (`ImportUploadException` 400 → Subklassen 413/415/422), gerendert an genau einer Stelle; `dontReport` für erwartbare Client-Fehler.
5. **Response-Envelope:** Erfolg `{data, message}`; Fehler Laravel-nativ `{message}` / `{message, errors}`. Konsistenz mit dem Framework geht vor Eigenbau.
6. **`imports` als Domain-Tabelle** (Option A) statt generischer `media`-Tabelle mit `morphs`. Die importierte CSV ist Kern-Entität, kein Anhang. Eine generische `files`/`media`-Tabelle **mit** `morphs` ist für die *Report-Exporte* später vorgesehen — dort ist das Muster passend.
7. **`user_id` ist nicht fillable;** Ownership ausschließlich serverseitig via `$user->imports()->create()`. Mass-Assignment- und FK-Schutz verifiziert.
8. **Service bleibt DB-frei** (Testbarkeit); der Controller orchestriert (`store()` → `create()` → Response). Reihenfolge: erst Datei, dann Record (Orphan-Datei ist der akzeptierte Fehlschlag-Fall, nicht der tote Record).
9. **`storeAs()` ist nicht atomar** — bewusst akzeptiert für den Empfangspfad (einzelner Schreiber, keine Leser). Das temp+rename-Pattern (`atomicWrite`) ist für Report-Exporte aufgespart.
10. **Bewusst aufgespart:** `status`/`progress`-Spalten in `imports` (Queue-Runde), Pest-Factory (Test-Runde), Throttling, Token-Revocation/Logout.

## 3. Offener Restbestand (Backlog — in kommende Runden einplanen)

- **Logout/Token-Revocation** (jeder Login akkumuliert aktuell Tokens).
- **Rate-Limiting** (Login, später Upload) via Laravels RateLimiter.
- **Orphan-GC:** Datei gespeichert, DB-Write fehlgeschlagen → verwaiste Datei (harmlos, aber ungeputzt).
- **413-Zonen:** lokal deckelt PHP bei `upload_max_filesize=2M`/`post_max_size=8M`; über HTTP ist der App-413 deshalb lokal nur per Service-Direktaufruf erreichbar. Zone 2–8 M antwortet 422 ("The file failed to upload."). Produktion: ini-Werte an `IMPORT_MAX_UPLOAD_BYTES` angleichen (Details: `EVALUATION.md` Decision 3 + §7).
- **Keine Lese-/Listen-Endpunkte** für Imports (`GET /api/imports`, Detail, Delete) — natürliche Kandidaten für Runde 2/3.
- **Content-Disposition-Filename**, Streaming-Download (`stream_copy_to_stream` + `response()->stream`) — Report-Runde.

## 4. Input für die nächste Runden-Planung (Optionen + Empfehlung)

**Option A — Runde 2 wie geplant (Collections/Parsing):** die gespeicherte Datei via `Storage::readStream()` + Generator zeilenweise verarbeiten (konstanter Speicher), Zeilen validieren/transformieren, in Records persistieren. Anschlusspunkte existieren: `Import.path` + `Import.header` (bereits als Array gespeichert — spart Re-Parsing), `ImportUploadResult` als Quelle.
*Risiko:* Runde 2 baut direkt auf dem ungetesteten `ImportUploadService` auf.

**Option B — Test-Puffer zuerst (Pest-Grün für Runde 1):** die Testfälle aus `EVALUATION.md` §5/§6 als Feature-Tests nachbauen (Auth-Flows, 401/413/415/422/201, Ownership, DB+Disk-Konsistenz; `Storage::fake()` + sqlite `:memory:` sind in `phpunit.xml` vorbereitet).
*Nutzen:* Refactoring-Sicherheit genau vor der Runde, die den Service erweitert; die Roadmap-Reihenfolge verschiebt sich dann zu 1 → 3 → 2 → 4 → 5.

**Empfehlung des Implementierungs-Agents:** Option B zuerst, dann Runde 2. Begründung: Die manuellen Tests sind verifiziert und lassen sich 1:1 automatisieren; Runde 2 refactored voraussichtlich den Service (Header-Logik teilen mit dem Zeilen-Reader) — Tests vorher sind billiger als danach. Finale Entscheidung liegt beim Planungs-Agent und User.

**Für spätere Runden vorgemerkt:** Queue-Runde braucht `status`/`progress` auf `imports` (Migration dann); Export-Runde braucht `atomicWrite` + evtl. die `media`/morphs-Tabelle; beide sind in Decision 9/6 vorbereitet.

## 5. Umgebung (damit der Plan ausführbar ist)

- PHP **nur** über Laravel Herd: `"$USERPROFILE/.config/herd/bin/php.bat"` (nicht im Git-Bash-PATH); `artisan` damit aufrufen.
- Herd-php.ini: `upload_tmp_dir`/`sys_temp_dir` wurden auf den Windows-Temp-Ordner gesetzt (Uploads schlugen sonst auf PHP-Ebene fehl); Limits `upload_max_filesize=2M`, `post_max_size=8M`.
- DB: SQLite (`database/database.sqlite`), Migrationen aktuell; Dev-Test-User `smoke@example.com` / `secret123` existiert.
- Dev-Server: `artisan serve --port=8000`; curl mit `-H "Accept: application/json"` und Bearer-Token.
- Test-Infrastruktur: Pest 5 + Plugin installiert, `composer test` vorhanden, `phpunit.xml` nutzt sqlite `:memory:` — Suite selbst ist noch Skeleton.
- Workflow bisher: ein Commit pro abgeschlossenem Schritt, Push nach User-Bestätigung; Windows/UTF-8/Emoji in Code-Kommentaren vermeiden (Konsistenz mit bestehendem Code).
