# CompetitorWatch v0.4

Dashboard di competitive intelligence social. **Solo Apify** come fonte dati
(Instagram, Facebook, YouTube). Storage su **file JSON** (niente SQLite/MySQL).
Report mensili generati via LLM (Claude → OpenAI → Gemini con fallback).

## Requisiti

- PHP **8.2+** con curl, json, mbstring (ZipArchive opzionale, per i backup)
- Apache o LiteSpeed (per i .htaccess) — su nginx vedi sezione dedicata
- HTTPS attivo
- Cron di sistema
- Account Apify (free tier sufficiente: ~$2-3/mese di consumo su $5 di credito)
- Almeno una chiave API LLM (Claude, OpenAI o Gemini)

## Deploy (drag & drop FTP)

1. **Scarica Chart.js** (una tantum, prima dell'upload):
   ```
   curl -o assets/chart.min.js https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js
   ```
2. **Genera la Basic Auth**:
   ```
   htpasswd -cB .htpasswd tuonome
   ```
   (oppure usa un generatore htpasswd online con bcrypt)
3. **Modifica `.htaccess`**: decommenta le 4 righe Basic Auth e inserisci
   il **path assoluto** del file `.htpasswd` sul server
   (lo trovi chiedendo all'hosting o da `install.php` → riga "percorso").
4. **Upload via FTP** dell'intera cartella.
5. **Permessi**: `data/` e `config/` a **755** (o 775 se necessario),
   ricorsivamente.
6. Apri `https://tuodominio/competitorwatch/install.php` nel browser.
7. Dopo l'installazione: **Impostazioni** → token Apify + chiave LLM.
8. Configura il cron (sotto).

## Cron

Una riga di crontab, ogni 10 minuti (CLI, bypassa la Basic Auth by design):

```
*/10 * * * * /usr/bin/php /percorso/assoluto/competitorwatch/cron.php >> /percorso/assoluto/competitorwatch/data/logs/cron_out.log 2>&1
```

Lo scheduling vero (fetch alle 03:00, report il giorno 1) è gestito
internamente. Il cron è solo il "tic". Se il server è giù al momento
previsto, il job parte al primo tic utile. Il report mensile è resiliente:
viene generato anche se il giorno 1 è saltato.

## Sicurezza — checklist post-deploy

- [ ] Basic Auth attiva: aprendo il sito il browser DEVE chiedere credenziali
- [ ] HTTPS: `http://` deve essere rifiutato o redirectato
- [ ] `https://tuodominio/.../data/db/competitors/1.json` → deve dare **403**
- [ ] `https://tuodominio/.../config/config.php` → deve dare **403**
- [ ] `https://tuodominio/.../lib/db.php` → deve dare **403**
- [ ] `install.php` non più raggiungibile (auto-cancellato o inerte)
- [ ] `.htpasswd` non scaricabile via URL

Se uno qualsiasi di questi test fallisce, NON inserire le chiavi API
e contatta l'hosting.

## Architettura storage (niente database server)

```
data/db/{store}/{id}.json     un record = un file JSON
data/db/posts/{YYYY-MM}/      post partizionati per mese
data/db/audit/{YYYY-MM}.jsonl audit log append-only
data/snapshots/{YYYY-MM}/     payload Apify grezzi (dedup via hash)
```

Scritture atomiche: file temporaneo + rename, `flock()` sui contatori.
**Nota NFS**: se `data/` è su NFS, `flock()` è inaffidabile — la pagina
Stato lo segnala.

### Migrazione futura a MySQL

Tutto lo storage passa da `lib/db.php` (interfaccia `db_insert`, `db_find`,
`db_update`, ...). Per migrare: riscrivere solo quel file con PDO MySQL +
uno script di import dei JSON. Nessun'altra parte dell'app cambia.

## Fonte dati: solo Apify

| Piattaforma | Actor di default | Costo indicativo |
|---|---|---|
| Instagram | `apify~instagram-profile-scraper` | ~$1.60/1k risultati |
| Facebook | `apify~facebook-pages-scraper` | ~$5/1k risultati |
| YouTube | `streamers~youtube-scraper` | ~$0.50/1k risultati |

Con 10 concorrenti e fetch giornaliero si resta tipicamente dentro i
$5/mese del free tier Apify. Gli actor sono configurabili in
`config['apify']['actors']` — se Apify ne cambia uno o il formato di
output cambia, aggiorna l'actor e/o il normalizzatore in `lib/providers.php`.

**Nota legale**: gli scraper raccolgono solo dati pubblici, ma restano in
zona grigia rispetto ai ToS Meta, e i dati possono includere dati personali
(GDPR). La retention di default è 3 mesi per i dati grezzi (configurabile).
L'inserimento manuale delle metriche resta disponibile come alternativa
senza scraping (pagina Concorrenti).

*(Le chiamate dirette alle API ufficiali dei social — es. YouTube Data API —
sono state rimosse in v0.3; il codice YouTube è conservato commentato in
fondo a `lib/providers.php`.)*

## LLM

- Router con fallback automatico: primario → secondari
- Budget mensile hard cap (default €30, configurabile) con blocco e alert
- Costi tracciati per chiamata, visibili in dashboard
- I testi dei post concorrenti vengono passati all'LLM dentro blocchi
  `<external_content>` con sanitizzazione anti prompt-injection

## nginx (se il tuo hosting non usa Apache)

I `.htaccess` non funzionano. Chiedi all'hosting di aggiungere:

```nginx
location ~ ^/competitorwatch/(data|config|lib|views)/ { deny all; }
location ~ /\.(htpasswd|htaccess) { deny all; }
location /competitorwatch/ {
    auth_basic "CompetitorWatch";
    auth_basic_user_file /percorso/.htpasswd;
}
```

E verifica la checklist di sicurezza prima di inserire chiavi API.

## Aggiornamenti

Mai sovrascrivere `data/` e `config/` durante un aggiornamento FTP.
Caricare solo: file PHP root, `lib/`, `views/`, `assets/`.

## Troubleshooting

- **"Cron non attivo" nella pagina Stato** → verifica crontab e path PHP CLI
  (`which php` via SSH, oppure chiedi all'hosting)
- **Fetch Apify fallisce con 0 risultati** → handle errato, oppure l'actor è
  in errore: controlla la Issues tab dell'actor su apify.com
- **Timeout Apify** → riduci "Max item per fetch" nelle Impostazioni
- **Grafici non visibili** → hai dimenticato di scaricare Chart.js (punto 1)
- **Logout Basic Auth** → chiudi tutte le finestre del browser

## Novità v0.5 + v0.6

### Controllo operativo
- **Pannello di controllo** (pagina Stato): "Fetch completo ora", "Genera report mese scorso", "Esegui ora" (test), "Svuota coda". I trigger accodano job — niente timeout su shared hosting.
- **Frequenza configurabile** (Impostazioni → Pianificazione): fetch giornaliero o settimanale, ora personalizzabile, report mensile e/o settimanale.

### Alert sui concorrenti
Calcolati gratis a ogni fetch, configurabili in Impostazioni → Soglie alert:
- 📈 Spike engagement (post oltre Nx la media)
- 📊 Picco pubblicazioni (volume settimanale +X%)
- 👥 Variazione follower (oltre Y% in un giorno)
- 🎬 Nuovo formato mai usato prima
- 🔇 Silenzio (nessun post da N giorni)
- #️⃣ Nuovo hashtag ricorrente

### Report intelligente
- **3 template**: Strategico, Operativo (social media manager), Solo numeri
- **Focus personalizzato**: istruzioni extra libere per l'LLM
- **Lingua** configurabile
- **Anteprima prompt** a costo zero: vedi cosa verrebbe inviato + stima token/costo prima di spendere

Nessuna modifica a `config/` o `data/` necessaria: i nuovi parametri hanno default sensati e si configurano da UI.

## Novità v0.7

### Brand proprio (baseline)
- Spunta "Questo è il mio brand" quando aggiungi un'entità in Concorrenti.
- Il brand proprio **non occupa** uno slot dei 10 competitor (max 10 competitor + 1 brand).
- È evidenziato ovunque (⭐), ed è la baseline dei confronti.
- Viene **escluso** dai report aggregati sui concorrenti, così l'analisi competitiva resta pulita.

### Pagina Confronto (analisi quantitativa day-by-day, senza LLM)
Nuova voce di menu **Confronto**:
- **Leaderboard ordinabile**: follower, Δ follower, post, engagement, ER medio, post/settimana — una riga per competitor.
- **Confronto relativo**: ogni KPI mostra +X% / −X% rispetto al tuo brand.
- **Grafico multi-linea**: andamento follower di tutti i competitor sovrapposti, il tuo brand in nero spesso.
- **Filtri**: periodo (7/30/90 giorni) e piattaforma. Tutto bookmarkabile via URL.
- Zero costi: legge i dati già raccolti, nessuna chiamata LLM o Apify.

L'**ER medio** (engagement per post / follower) è il KPI chiave: rende confrontabili account di dimensioni molto diverse.

## Novità v0.8 — Tema scuro + PWA + Dashboard ridisegnata

### Aspetto
- Tema scuro elegante (blu-grigio profondo, non nero puro), tipografia Baloo 2 (titoli) + Inter (corpo).
- Mobile-first: su smartphone la navigazione passa a una barra inferiore fissa con icone; KPI a 2 colonne; tabelle scrollabili.
- Rimosso il nome utente dall'interfaccia.

### PWA (installabile su telefono)
L'app è installabile come applicazione. Su iPhone: Safari → Condividi → "Aggiungi a Home". Su Android: Chrome → menu → "Installa app".
File aggiunti: `manifest.webmanifest`, `sw.js` (service worker per avvio rapido), icone in `assets/` (`icon-192.png`, `icon-512.png`, `icon-maskable.png`, `apple-touch-icon.png`, `icon.svg`).
Nota: la PWA richiede HTTPS (già attivo).

### Dashboard ridisegnata
- **Segnali sui concorrenti** in primo piano (gli alert non letti, ciò che richiede azione).
- **Classifica engagement rate** a colpo d'occhio, col tuo brand evidenziato.
- **Top post del mese** e **ultimi report**.
- **Banner di stato**: avvisa se il cron è fermo, ci sono job falliti, o l'LLM è sospeso.
- Il grafico follower è in un **accordion chiuso** (si espande quando vuoi), dato che si popola nel tempo.

## v1.1

- Tutte le azioni lente (fetch singolo, fetch completo, generazione report) ora **accodano** un job invece di eseguire in diretta: niente più schermo bianco / attese nel browser.
- Ogni azione che accoda dichiara **cosa** accadrà e **quando** (orario stimato del prossimo cron).
- `redirect()` reso resiliente (svuota output buffer, fallback lato client) per eliminare lo schermo bianco residuo.
- `cron.php`: distingue errore permessi da overlap; errore chiaro se manca config in CLI.
- Tabella "Job in coda" mostra "pronto" vs orario programmato.
- Alert convertiti in lista a card (leggibili su mobile).
- Tabelle Report/Confronto sistemate su mobile; label e formati spesa più chiari.

## v1.20 — Confronto storico
- Pre-aggregazione mensile (monthly_stats) salvata a ogni cron: la pagina Confronto scala nel tempo.
- Classifica con variazioni mese-su-mese (frecce trend).
- Grafici storici: follower, ER, share of voice (quota post sul settore).
- Tabella storica completa per mese. Pulsante "Ricostruisci storico" per backfill.

## v1.30 — Fetch batch asincrono (risparmio Apify)
- Un solo run Apify per piattaforma (batch di tutti gli handle) invece di uno per profilo: meno avvii di container.
- Esecuzione asincrona con polling dal cron (no endpoint webhook, nessuna esposizione pubblica): elimina il timeout sincrono a 55s e il rischio di doppio addebito.
- Fault-tolerance: un profilo bloccato non compromette il batch; gli altri vengono salvati, i mancanti loggati.
- Polling con timeout di sicurezza (30 min) e retry rapido (90s) mentre il run è in corso.

## v1.31 — Audit fixes
- [GRAVE] settings.php: salvare un form non azzera più i checkbox degli altri form (alert/report non si disattivano più per errore). Salvataggio granulare per sezione.
- [GRAVE] Pre-aggregazione mensile spostata a 1×/giorno (non a ogni cron) + refresh mirato dopo il fetch: niente più saturazione I/O col crescere dei dati.
- [SICUREZZA] Open redirect bloccato quando app_url è vuoto.
- [SICUREZZA] Chiave Gemini inviata via header x-goog-api-key (non più in query string).
- [ROBUSTEZZA] db_find: confronto type-safe (false non matcha più 0/mancante).
- [ROBUSTEZZA] settings: lint php -l del config prima di sostituirlo (no white-screen da var_export).
- [MINORE] logs: validazione anti-traversal a monte. Rimosso header X-XSS-Protection deprecato. Installer: mbstring non più bloccante (c'è il polyfill).

## v1.32 — Interazioni totali
- Nuova metrica "Interazioni" = Mi piace + commenti + visualizzazioni (views solo per video/reel).
- Esposta nel top-post della dashboard e nella tabella storica del Confronto (per post e per mese).
- Distinta dall'engagement classico (like+commenti) usato per l'ER, che resta invariato.
- Salvataggi e condivisioni NON inclusi: metriche private non esposte da Instagram/Apify.

## v1.40 — Espansione TikTok + Facebook
- Nuove piattaforme: TikTok (clockworks/tiktok-profile-scraper) e Facebook Pagine pubbliche (apify/facebook-pages-scraper, niente cookie).
- Normalizer dedicati: TikTok espone condivisioni e play count; Facebook espone reazioni/commenti/condivisioni.
- Batch async esteso a tutte le piattaforme; distribuzione raggruppa N video per profilo (TikTok) o 1 oggetto pagina (IG/FB), fault-tolerant.
- Metrica "Interazioni" estesa: like + commenti + condivisioni + views (shares 0 su IG, popolati su TikTok/FB).
- Confronto piattaforma-centrico: tab Instagram/TikTok/Facebook, confronto una piattaforma alla volta (metriche non comparabili tra social).
- monthly_stats salva snapshot per-piattaforma oltre all'aggregato.
- Alert già per-piattaforma (citano la piattaforma nel messaggio).

## v1.41 — Bugfix audit
- [BATCH] apify_fetch_dataset ora distingue errore di rete (null, ritentabile) da dataset vuoto: un download fallito non viene più scambiato per "nessun dato", il run già pagato viene recuperato al retry.
- [CONFRONTO] I grafici includono solo i competitor con dati nella piattaforma selezionata (niente più linee piatte a zero per chi non è su quella piattaforma).
- [COERENZA] apify_build_input (fetch singolo legacy) ora supporta anche TikTok.

## v1.42 — UX fixes
- Dashboard: "Top post" e "Classifica engagement" non mostrano più zeri se i dati sono nel mese scorso (fallback al mese precedente, finestra 60gg).
- Confronto mobile: classifica leggibile su schermi stretti (colonne Δ nascoste su mobile, badge "vs te" compatti).

## v1.43 — Trasparenza fetch + fix errore job
- Errore "Handle non trovato": ora indica piattaforma/handle, e un handle eliminato non fa più fallire il job (viene saltato).
- Messaggi di job fallito più chiari (con piattaforma/profilo coinvolto).
- Dashboard "Stato aggiornamenti": ultimo fetch riuscito (piattaforma, ora, profili, nuovi post) + nuovi post negli ultimi 7 giorni.
- Concorrenti: badge freschezza per profilo (verde <48h, giallo <7gg, rosso oltre).
- Stato: cronologia ultimi aggiornamenti (timeline esiti fetch).

## v1.44 — Brand proprio nel Confronto
- Se il brand proprio non ha un profilo sulla piattaforma selezionata, ora compare un avviso esplicito ("aggiungilo in Concorrenti") invece di sparire silenziosamente dalla classifica.
- Distingue il caso "nessun profilo su quella piattaforma" da "profilo presente ma storico non ancora ricostruito".

## v1.45 — Fix fetch non ritentato (bug dati non scaricati)
- [GRAVE] Se il fetch giornaliero falliva (token errato, credito, rete), non veniva più ritentato fino al giorno dopo: jobs_schedule vedeva il job fallito di oggi e saltava. Ora un batch FALLITO viene ricreato nello stesso giorno, così un problema sistemato in giornata riprende subito.
- Mantiene la protezione anti-duplicato per batch pending/done/poll in corso.

## v1.46 — Grafici adattivi (giornaliero/mensile)
- Con meno di 3 mesi di storico, il Confronto mostra l'andamento follower GIORNALIERO (dai dati raccolti finora) invece dei grafici mensili quasi vuoti.
- I grafici mensili (follower storico, ER, share of voice) appaiono automaticamente al raggiungimento di 3 mesi di dati.
- Nota esplicativa per l'utente sul perché e quando cambierà la vista.

## v1.47 — Menu unico + grafico giornaliero più denso
- Navigazione: sostituiti i due menu separati (desktop + bottom-nav mobile, che nascondeva voci) con UN solo menu hamburger che mostra tutte le 8 voci, identico su mobile e desktop. Badge alert sul pulsante.
- Grafico follower giornaliero: area riempita, punti su ogni rilevazione, date sull'asse — non sembra più "vuoto" con pochi giorni di dati.

## v1.47 — Nav orizzontale + grafico follower denso
- Navigazione: barra orizzontale scrollabile con TUTTE le voci sempre visibili (no più menu hamburger che ne nascondeva alcune). Voce attiva sottolineata. Stesso pattern mobile e desktop.
- Grafico follower: ora mostra OGNI rilevazione (densità piena, un punto per giorno) invece di un punto al mese — così con pochi giorni di dati l'andamento è visibile e non sembra vuoto.
- ER e share of voice mensili appaiono con ≥2 mesi di dati, con nota esplicativa.

## v1.48 — Nav scrollabile con frecce e drag
- La barra di navigazione ora è usabile su desktop anche quando le voci sforano: frecce ‹ › ai lati (compaiono solo se serve), drag col mouse, oltre allo scroll touch/trackpad.
- La voce attiva viene portata automaticamente in vista all'apertura della pagina.
- Le frecce si nascondono a inizio/fine scorrimento e quando tutte le voci entrano.

## v1.49 — Edge case navigazione
- Badge alert limitato a "99+" per non allargare la voce di menu con numeri grandi.
- Verificati: frecce coerenti da 320px a 1440px, resize dinamico (rotazione schermo), drag mouse, click vs drag, voce attiva con query string, nessun overflow orizzontale del body.

## v1.50 — Variazione follower esplicita
- Sotto il grafico follower, tabella "Variazione nel periodo rilevato" con follower attuali, delta e giorni per ogni brand: rende leggibile la crescita anche quando il grafico appare piatto (account B2B crescono di poche decine di follower al giorno).
