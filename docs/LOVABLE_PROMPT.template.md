# PROMPT PER LOVABLE - AMIR Team Manager

> Come usarlo: incolla la PARTE 1, aspetta che Lovable finisca, poi incolla la PARTE 2.
> Se vuoi andare più cauto, dividi la PARTE 2 in due messaggi (prima "Area admin", poi "Grafiche e statistiche").

---

## PARTE 1 - Fondamenta, API layer e area pubblica

Costruisci "AMIR Team Manager": una web app mobile-first (installabile come PWA) per gestire una squadra di calcio amatoriale, **AMIR COSTRUZIONI**, che gioca nei campionati XFive di Alessandria (calcio a 8 e a 7). Tutta l'interfaccia è **in italiano**. Il backend esiste già (API REST Laravel) ma non è ancora collegabile: tu costruisci solo il frontend, contro un **contratto API fisso** e dati **mock**.

### Stack e regole tecniche (obbligatorie)
- React + Vite + TypeScript + Tailwind + shadcn/ui, React Router, TanStack Query, date-fns (locale `it`, fuso Europe/Rome), `html-to-image` per le grafiche, `papaparse` per importare CSV.
- **NON usare Supabase, Lovable Cloud, Firebase né altri backend.** Nessuna integrazione di autenticazione esterna.
- Crea `src/api/types.ts` copiando ESATTAMENTE i tipi riportati in fondo a questo prompt (sezione "CONTRATTO API"). Non rinominare né cambiare campi: il backend reale risponde con questi nomi (snake_case).
- Crea `src/api/client.ts` con un'interfaccia `ApiClient` che ha **un metodo per ogni endpoint** elencato nel contratto e due implementazioni:
  1. `httpClient`: usa `fetch` verso `import.meta.env.VITE_API_BASE_URL`, header `Authorization: Bearer <token>` (token in `localStorage` chiave `amir_token`), scarta il wrapper `{ data }`, su 401 cancella il token e porta a `/admin/login`.
  2. `mockClient`: dati in memoria, ritardo simulato 200–500 ms, e le mutazioni (RSVP, pagamenti, formazione, giocatori…) modificano davvero lo stato mock così la demo è interattiva.
  Seleziona l'implementazione con `VITE_USE_MOCKS` (default `true`). Metti in `.env.example` sia `VITE_API_BASE_URL=http://localhost:8000/api/v1` sia `VITE_USE_MOCKS=true`.
- Un hook TanStack Query per ogni endpoint (`useHome`, `useMatches`, `useStandings`, `useHistory`, …). Gestisci sempre stati di caricamento (skeleton), errore e vuoto, con messaggi in italiano.
- Importi: sono in centesimi, mostrali con `Intl.NumberFormat('it-IT', {style:'currency', currency:'EUR'})`.

### Identità visiva
- Tema scuro di default (con toggle chiaro/scuro). Sfondo `#0B0B0D`, superfici `#151518`, testo `#F5F5F5`, **rosso squadra `#D61F26`** (primario), ambra `#FFC857` per avvisi, verde `#2FBF71` per vittorie/ok, bianco per dettagli.
- Titoli in **Barlow Condensed** (maiuscolo, pesante), testo in **Inter**, numeri/punteggi con cifre tabulari.
- Look sportivo da "match day": card con taglio diagonale, grandi punteggi, badge arrotondati, micro-animazioni sobrie. Bersagli touch ≥ 44px, ottimo a 375px di larghezza, bottom-navigation su mobile e sidebar su desktop.
- Colori maglia: la nostra squadra ha maglia 1 rossa `#D61F26`, maglia 2 bianca `#FFFFFF`. Mostra sempre lo stemma (`badge_url`) con un placeholder elegante se manca.

### Stato reale dei dati (importante per l'UX)
La stagione corrente è **2026/2027**. Il girone **CITTADELLA [Alessandria]** (calcio a 8, 10 squadre, 18 giornate) ha **un solo turno ufficiale**: la 1ª giornata, *AMIR COSTRUZIONI - VALONS, giovedì 15/10/2026 ore 20:00, 100GRIGIO - CAMPO 4*. Le altre giornate sono abbinamenti **provvisori senza data né ora** (`status: "to_schedule"`, `is_provisional: true`). Quindi:
- Una partita provvisoria mostra "Data da definire", ha stile attenuato e un chip **PROVVISORIA**; non ha countdown e non entra nel calendario telefonico.
- Se `calendar_info.is_complete` è `false`, mostra in alto un banner informativo: "Calendario ufficiale in aggiornamento: XFive ha pubblicato {scheduled_rounds} giornate su {total_rounds}".
- Non trattare mai una partita provvisoria come "prossima partita" ufficiale se esiste una partita con data.

### Area pubblica (senza login, condivisibile via WhatsApp)
1. `/` **Home**: hero con la prossima partita (stemmi, avversario, data/ora, campo, countdown; se non c'è data → "Data da definire"), ultima partita giocata con esito (V/N/P colorato), mini-classifica con la nostra riga evidenziata, prossime 5 partite, banner calendario incompleto. Pulsante "Aggiungi il calendario al telefono" (link `webcal://` / download di `/public/calendar.ics`).
2. `/calendario`: tutte le partite della squadra raggruppate per giornata, filtro per competizione, chip stato (Programmata / Provvisoria / Giocata / Rinviata), switch "tutte le partite del girone".
3. `/classifica`: tabella completa (P, V, N, Pe, GF, GS, DR, Pt), nostra riga evidenziata, selettore competizione. Le posizioni possono essere **a pari merito** (stesso numero ripetuto): mostrale così. Finché una squadra ha `played === 0` e nessuno ha giocato, al posto del numero mostra "-" e una nota "La stagione non è ancora iniziata". Se `PublicHome.standing` è `null`, nascondi la card "la tua posizione".
4. `/rosa`: griglia di card con foto, numero maglia (per lo staff "A"/"D"), ruolo, soprannome. Usa solo i campi di `PublicPlayer`.
5. `/storico` - **sezione Storico** (dati delle stagioni passate, in sola lettura):
   - In alto "Il nostro curriculum": record di sempre (partite, V-N-P, gol fatti/subiti, punti, differenza reti, % vittorie), numero stagioni, **miglior vittoria**, **peggior sconfitta**, **avversario più incontrato** (da `HistorySummary`).
   - Poi una sezione per **stagione** (più recente per prima, ad esempio 2025/2026 → 2022/2023) con il riepilogo della stagione e l'elenco delle competizioni giocate (campionato, coppe, tornei) con badge di **posizione finale** (🥇🥈🥉 per i primi tre; la posizione esiste solo per i campionati: se `final_position` è `null`, nessun badge), formato 7/8, record (V-N-P, GF-GS).
   - `/storico/:competitionId`: dettaglio torneo con classifica finale (nostra riga evidenziata) e tutte le nostre partite con risultato (V/N/P colorato).
   - Un grafico semplice dell'andamento (punti o % vittorie per stagione) e un filtro per formato (tutto / a 7 / a 8).
   - Componente riutilizzabile **"Precedenti"** (`HeadToHead`): scontri diretti con un avversario (V-N-P complessivi + elenco partite con stagione e risultato). Usalo nel dettaglio partita.
6. `/p/:token` **Pagina personale del giocatore** (link magico, nessun login): prossimi eventi con tre grandi pulsanti **"Ci sono / Forse / Non ci sono"** (stato corrente evidenziato, campo note opzionale), e il suo **saldo** con l'elenco delle quote da pagare e dei pagamenti registrati.

### Dati mock richiesti
Rendi il mock fedele alla realtà: squadra AMIR COSTRUZIONI (formato 8), competizione "CITTADELLA [Alessandria]" 2026/2027 con queste 10 squadre: AMIR COSTRUZIONI, CAFFÈ KM0-PALESTRA MEETING, GUALA CLOSURES, IN EXTREMIS, OCCASIONALI FC, POLPEN 2022, REY GOMME, SHQIPONJAT, TORNITURE KARIM, VALONS (colori maglia 1 indicativi: bianco, bianco/blu, bianco/rosso, blu, blu, nero, blu/nero, bianco, nero). Una sola giornata ufficiale (AMIR–VALONS, 15/10/2026 20:00), le altre 17 provvisorie. **Giocatori con nomi inventati** (una quindicina, incluso un portiere e uno staff con "A"/"D"), pagamenti d'esempio, e uno **storico inventato ma verosimile** di 4 stagioni (2022/2023–2025/2026) con campionati a 7 e a 8, coppe e tornei. Non usare dati personali reali.

---

## PARTE 2 - Area admin, formazioni, pagamenti, statistiche e grafiche

Aggiungi l'area riservata sotto `/admin` (login email+password su `/admin/login`, token Bearer, route protette). Su mobile bottom-nav con le voci principali; su desktop sidebar.

1. **Dashboard** (`GET /dashboard`): prossima partita; **Squad List n/10** con barra di avanzamento (regola XFive: massimo 10 giocatori "esclusivi", segnala se mancano/si superano); tesseramenti (da richiedere / in attesa / approvati); certificati medici in scadenza (30 giorni); incassi (da incassare + quote scadute); **scadenze XFive** (elenco `deadlines`); stato dell'ultimo aggiornamento da XFive con pulsante **"Aggiorna da XFive"** (`POST /sync/xfive`); elenco `rules` in una card "Regole da ricordare".
2. **Giocatori**: tabella (desktop) / card (mobile) con ricerca e filtri (attivi, in squad list, stato tesseramento, ruolo). Drawer di creazione/modifica con tutti i campi di `Player`. **Importazione**: incolla testo o carica CSV (colonne: cognome, nome, data_nascita, ruolo, numero, telefono, email) con anteprima e invio a `POST /players/import`. Per ogni giocatore: copia/condividi il **link personale** (`magic_link`) via WhatsApp. Mostra promemoria: "Il tesseramento va richiesto almeno 72 ore prima della partita (mai sotto le 24 ore). Dirigenti e allenatori: nel numero di maglia usa A o D."
3. **Partite**: elenco delle nostre partite (switch "tutte") e **dettaglio partita** con tab:
   - **Presenze**: contatori sì/forse/no/senza risposta, elenco giocatori con stato modificabile (admin può impostare la risposta al posto del giocatore), pulsante **"Sollecita chi non ha risposto"** (`POST /events/{id}/remind` → mostra `text` e apri `wa_link`).
   - **Formazione**: editor su campo verticale SVG. Moduli per formato 8: `3-3-1`, `3-2-2`, `2-3-2`, `3-1-3`, `2-4-1`; per formato 7: `2-3-1`, `3-2-1`, `2-2-2`, `3-1-2` (il portiere è sempre lo slot 1). Trascina i giocatori (preferibilmente quelli con RSVP "sì") sugli slot e in panchina; salva con `PUT /matches/{id}/lineup`. Pulsante "Esporta come immagine". Se manca un portiere o gli slot non sono pieni, avvisa.
   - **Dopo la partita**: per ogni giocatore giocato sì/no, gol, assist, gialli, rossi, voto 1–10; arbitro; **uomo partita**; salva con `PUT /matches/{id}/stats`.
   - **Precedenti**: componente `HeadToHead` contro l'avversario. Suggerisci la **maglia da indossare** confrontando i colori maglia dell'avversario con i nostri (se la maglia 1 avversaria è simile alla nostra, consiglia la 2).
   - Le partite provvisorie mostrano solo "In attesa di calendario ufficiale".
4. **Eventi**: crea allenamenti e cene con data, luogo, RSVP; dopo l'evento spunta chi era presente (`attended`).
5. **Pagamenti**: riepilogo (totale dovuto / incassato / da incassare), tabella per giocatore con saldo colorato (verde a posto, ambra parziale, rosso scaduto), dialog **"Nuova voce"** (tipo: quota stagione, tesseramento, multa, arbitro, cena, altro; importo; scadenza; assegna a tutti o a una selezione), dialog **"Registra pagamento"** (importo, metodo contanti/Satispay/PayPal/bonifico, data), storico pagamenti eliminabile, pulsante **"Sollecito WhatsApp"** per i morosi (testo composto nel client). L'app **non incassa soldi**: è solo un registro.
6. **Classifiche squadra** (`GET /stats/attendance`): tab "Più presenti" (partite giocate), "Allenamenti", "Marcatori", "Assist", "Voto medio", "Cartellini"; **podio** con foto per i primi tre, poi lista. Filtro stagione.
7. **Studio grafiche**: crea immagini condivisibili da dati reali, con stemma, colori squadra e font del tema. Template: **Match Day** (prossima partita), **Risultato**, **Formazione ufficiale**, **Uomo partita**, **Classifica**, **Precedenti**, **Marcatori**, più un template scherzoso **"Multa del mese"** a testo libero. Formati 1:1 (1080×1080), 4:5, 9:16 (storie). Anteprima live, export PNG con `html-to-image`, **Web Share API** (`navigator.share` con file) con fallback al download. Sotto l'anteprima, un generatore di **didascalie** (`POST /matches/{id}/caption`) con scelta del tono (epico / ironico / sobrio) e pulsante copia.
8. **Impostazioni**: elenco degli ultimi aggiornamenti da XFive (`GET /sync/runs`), pulsanti "Aggiorna calendario" e "Importa storico" (`POST /sync/xfive` con `scope` `current` o `history`), logout.

### Qualità
Accessibilità (contrasti, focus, label), nessuno scroll orizzontale a 375px, skeleton di caricamento, empty state simpatici ma brevi, conferma prima di azioni distruttive, form validati con messaggi in italiano. Non inserire dati personali reali nei mock. Non aggiungere funzioni non richieste.

---

## CONTRATTO API (copia esatta in `src/api/types.ts`)

```ts
{{TYPES}}
```
