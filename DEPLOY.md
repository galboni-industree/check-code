# Deploy — CompetitorWatch v1.40

## Pacchetto già pulito
Questo zip NON contiene `config/`, `data/`, né `.htpasswd`: i tuoi file
sensibili sul server non vengono toccati. Carica tutto il resto via FTP nella
cartella dell'app, sovrascrivendo.

## Passi

1. **Upload FTP** di tutto il contenuto dello zip (sovrascrivi i file esistenti).

2. **Hard refresh** del browser (Ctrl+Shift+R / Cmd+Shift+R).
   Il service worker è passato a cw-v21: senza hard refresh il browser
   terrebbe in cache la versione vecchia di CSS/JS.

3. **Ricostruisci lo storico** (una volta sola, importante in questa versione):
   vai in Confronto → "Ricostruisci storico".
   Serve perché v1.40 introduce gli snapshot mensili PER PIATTAFORMA: il
   pulsante li popola dai dati già raccolti. Senza, le tab TikTok/Facebook
   restano vuote finché non passa il primo ciclo di cron.

## Nuove piattaforme (TikTok + Facebook)

Per iniziare a monitorarle:
1. Concorrenti → scegli un competitor → aggiungi un profilo (handle),
   selezionando TikTok o Facebook dal menu piattaforma.
   - TikTok: usa lo username senza @ (es. "prinoth").
   - Facebook: usa il nome pagina come appare nell'URL
     facebook.com/NOMEPAGINA (solo Pagine pubbliche, niente profili privati).
2. Verifica in Impostazioni che il token Apify sia "presente".
   Gli actor TikTok/Facebook sono già configurati di default; non servono
   chiavi aggiuntive oltre al token Apify.
3. Al primo fetch utile (cron delle 8:00, o "Fetch completo ora" da Stato)
   i dati arrivano. Poi Confronto → "Ricostruisci storico" per allineare.

## Note operative

- **Fetch batch**: ora parte UN solo run Apify per piattaforma (non uno per
  profilo). Più economico e niente timeout. I job partono al cron.
- **Costo TikTok** è più alto di Instagram (circa 3x per risultato), ma con
  pochi video/profilo al giorno resta in pochi centesimi/mese.
- **Primo fetch reale TikTok/Facebook**: gli scraper sono tarati sui campi
  documentati. Se un profilo risultasse "senza dati" al primo giro, controlla
  in Log: il sistema è tollerante agli errori (un profilo problematico non
  blocca gli altri), quindi al massimo si ritara un nome-campo.

## Cron (invariato)
La schedulazione (*/10 * * * *) e il fetch giornaliero alle 8:00 restano
come già configurati. Nessuna modifica al crontab necessaria.
