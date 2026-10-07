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
// =====================================================================
// AMIR Team Manager - API contract v1 (JSON, snake_case)
// Base URL: VITE_API_BASE_URL  (e.g. http://127.0.0.1:8000/api/v1)
// Every response has the shape { data: ... }. Dates are ISO 8601 with an
// offset (Europe/Rome). Money is always in cents (*_cents).
// Validation errors: HTTP 422 { message, errors: { field: string[] } }.
// =====================================================================

export type Id = number;
export type ISODateTime = string; // "2026-10-15T20:00:00+02:00"
export type ISODate = string;     // "2026-10-15"

export type Format = 7 | 8; // players on the pitch (7-a-side / 8-a-side)

// ---------- Teams and competitions ----------
export interface Team {
  id: Id;
  name: string;               // "AMIR COSTRUZIONI"
  short_name: string;         // "AMIR"
  badge_url: string | null;
  is_own: boolean;            // true only for our team
  kit1_color: string | null;  // hex, e.g. "#d61f26"
  kit2_color: string | null;
  format: Format | null;      // only set for our team
}

export type CompetitionKind =
  | "campionato" | "coppa_lega" | "coppa_categoria" | "coppa" | "torneo";

export interface Competition {
  id: Id;
  name: string;               // "Cittadella [Alessandria]"
  season: string;             // "2026/2027"
  kind: CompetitionKind;
  format: Format;
  is_current: boolean;        // season in progress
  starts_on: ISODate | null;
  ends_on: ISODate | null;
  total_rounds: number | null;
  scheduled_rounds: number;   // rounds with an official date/time
  xfive_url: string | null;
}

// ---------- Matches ----------
// to_schedule = provisional pairing, no official date/time yet
export type MatchStatus =
  | "scheduled" | "to_schedule" | "played" | "postponed" | "cancelled";

export interface Match {
  id: Id;
  competition: Pick<Competition, "id" | "name" | "kind" | "season" | "format">;
  round: number | null;
  round_label: string;        // "1ª giornata", "Semifinale"
  home_team: Team;
  away_team: Team;
  kickoff_at: ISODateTime | null;
  venue: string | null;       // "100GRIGIO - CAMPO 4"
  home_score: number | null;
  away_score: number | null;
  status: MatchStatus;
  is_provisional: boolean;    // true when status === "to_schedule"
  is_own_match: boolean;
  result: "W" | "D" | "L" | null; // from our team's point of view
  event_id: Id | null;        // linked event (attendance / RSVP)
  xfive_url: string | null;
}

export interface StandingRow {
  position: number;           // tied teams share the same position
  team: Team;
  played: number; won: number; drawn: number; lost: number;
  goals_for: number; goals_against: number; goal_diff: number;
  points: number;
}

export interface CalendarInfo {
  competition_id: Id;
  scheduled_rounds: number;
  total_rounds: number | null;
  is_complete: boolean;       // false until XFive publishes the whole calendar
  note: string | null;        // Italian text, e.g. "Calendario ufficiale ancora in aggiornamento…"
}

// ---------- Players ----------
export type PlayerRole =
  | "portiere" | "difensore" | "centrocampista" | "attaccante"
  | "dirigente" | "allenatore";
export type RegistrationStatus = "none" | "pending" | "approved";

export interface PublicPlayer {
  id: Id;
  full_name: string;
  nickname: string | null;
  shirt_number: string | null; // "A"/"D" for staff
  role: PlayerRole | null;
  photo_url: string | null;    // served by our API when downloaded from XFive
  nationality: string | null;  // e.g. "Italia" (from the player's XFive profile)
}

export interface Player extends PublicPlayer {
  first_name: string;
  last_name: string;
  is_active: boolean;
  in_squad_list: boolean;            // max 10 "exclusive" players
  registration_status: RegistrationStatus;
  medical_cert_expires_on: ISODate | null;
  phone: string | null;
  email: string | null;
  birth_date: ISODate | null;        // personal data: admin only
  xfive_player_id: number | null;
  notes: string | null;
  magic_link: string | null;         // personal URL for RSVP
}

// ---------- Events and attendance ----------
export type EventType = "match" | "training" | "social";
export type Rsvp = "yes" | "no" | "maybe";

export interface EventSummary {
  yes: number; no: number; maybe: number; pending: number;
}

export interface TeamEvent {
  id: Id;
  type: EventType;
  title: string;
  starts_at: ISODateTime | null;
  venue: string | null;
  match_id: Id | null;
  summary: EventSummary;
  my_rsvp?: Rsvp | null;      // only in /me/{token} responses
}

export interface EventResponse {
  player_id: Id;
  player_name: string;
  rsvp: Rsvp | null;
  attended: boolean | null;
  note: string | null;
  responded_at: ISODateTime | null;
}

// ---------- Lineups ----------
export interface LineupSlot {
  slot: number;               // 1..format (1 = goalkeeper)
  player_id: Id | null;
  label: string;              // "POR", "DC", "CC", "ATT"...
  x: number;                  // 0..100 (% of pitch width)
  y: number;                  // 0..100 (% of pitch height, 0 = our goal)
}
export interface Lineup {
  match_id: Id;
  formation: string;          // "3-3-1"
  slots: LineupSlot[];
  bench: Id[];                // player ids on the bench
  notes: string | null;
}

// ---------- Match detail (admin) ----------
export interface MatchPlayerStat {
  player_id: Id;
  played: boolean;
  goals: number; assists: number;
  yellow: number; red: number;
  rating: number | null;      // 1..10
}
export interface HeadToHeadMatch {
  match_id: Id; season: string; competition_name: string;
  kickoff_at: ISODateTime | null;
  home_team: string; away_team: string;
  home_score: number; away_score: number;
  result: "W" | "D" | "L";
}
export interface HeadToHead {
  opponent: Team;
  played: number; won: number; drawn: number; lost: number;
  goals_for: number; goals_against: number;
  matches: HeadToHeadMatch[];
}
export interface MatchDetail {
  match: Match;
  event: TeamEvent | null;
  responses: EventResponse[];
  lineup: Lineup | null;
  stats: MatchPlayerStat[];
  referee: string | null;
  man_of_the_match_id: Id | null;
  head_to_head: HeadToHead | null;
}

// ---------- Payments ----------
export type ChargeKind =
  | "quota_stagione" | "tesseramento" | "multa" | "arbitro" | "cena" | "altro";

export interface Charge {
  id: Id;
  title: string;
  kind: ChargeKind;
  amount_cents: number;       // default amount per player
  due_on: ISODate | null;
  season: string | null;
  assigned_count: number;
  total_due_cents: number;
  total_paid_cents: number;
}
export interface Payment {
  id: Id;
  player_charge_id: Id;
  amount_cents: number;
  method: "contanti" | "satispay" | "paypal" | "bonifico" | "altro";
  paid_at: ISODate;
  note: string | null;
}
export interface PlayerCharge {
  id: Id;
  charge: Pick<Charge, "id" | "title" | "kind" | "due_on">;
  player_id: Id;
  amount_cents: number;
  paid_cents: number;
  balance_cents: number;      // amount - paid
  is_overdue: boolean;
  payments: Payment[];
}
export interface PlayerBalance {
  player_id: Id;
  player_name: string;
  due_cents: number;
  paid_cents: number;
  balance_cents: number;      // > 0 = still owes money
  items: PlayerCharge[];
}
export interface FinanceSummary {
  total_due_cents: number;
  total_paid_cents: number;
  total_outstanding_cents: number;
  players: Omit<PlayerBalance, "items">[];
}

// ---------- Statistics ----------
export interface AttendanceRow {
  player_id: Id;
  full_name: string;
  photo_url: string | null;
  matches_played: number;
  matches_called: number;
  trainings_attended: number;
  events_attended: number;
  rsvp_yes_rate: number;      // 0..1
  goals: number; assists: number;
  yellow: number; red: number;
  avg_rating: number | null;
}

// ---------- Player career (from XFive's public rankings) ----------
export interface PlayerTotals {
  competitions: number;       // competitions the player took part in
  goals: number;
  yellow: number;
  red: number;
  mvp_points: number;         // points in XFive's "Miglior giocatore" ranking
}
export interface PlayerCareerRow {
  competition: Pick<Competition, "id" | "name" | "season" | "kind" | "format">;
  goals: number; yellow: number; red: number;
  mvp_points: number;
}
export interface PlayerProfile {
  player: PublicPlayer;
  totals: PlayerTotals;
  career: PlayerCareerRow[];    // newest season first
}
export interface CareerRow extends PlayerTotals {
  player: PublicPlayer;         // list is sorted by goals, then competitions
}

// ---------- History ----------
export interface RecordLine {
  played: number; won: number; drawn: number; lost: number;
  goals_for: number; goals_against: number; points: number;
}
export interface HistoryCompetition {
  id: Id;
  name: string;
  kind: CompetitionKind;
  format: Format;
  season: string;
  final_position: number | null; // final league position; null for cups and tournaments
  teams_count: number | null;
  record: RecordLine;            // our team's record
}
export interface HistorySeason {
  season: string;                // "2025/2026"
  record: RecordLine;
  competitions: HistoryCompetition[];
}
export interface HistorySummary {
  record: RecordLine;            // all-time totals
  seasons_count: number;
  best_win: HeadToHeadMatch | null;
  worst_defeat: HeadToHeadMatch | null;
  most_frequent_opponent: { team: Team; played: number } | null;
}
export interface HistoryOverview {
  summary: HistorySummary;
  seasons: HistorySeason[];
}
export interface HistoryCompetitionDetail {
  competition: Competition;
  standings: StandingRow[];
  own_matches: Match[];
  all_matches: Match[];
}

// ---------- Public / Dashboard ----------
export interface PublicHome {
  team: Team;
  competition: Competition | null;
  calendar_info: CalendarInfo | null;
  next_match: Match | null;
  last_match: Match | null;
  standing: StandingRow | null; // null until somebody has played
  standings: StandingRow[];
  upcoming: Match[];            // next 5 (official first, then provisional)
  recent: Match[];              // last 5 played
}
export interface Deadline { date: ISODate; title: string; }
export interface Dashboard {
  next_match: Match | null;
  next_event: TeamEvent | null;
  squad_list: { count: number; limit: number; players: PublicPlayer[] };
  registrations: { none: number; pending: number; approved: number };
  certificates_expiring: { player: PublicPlayer; expires_on: ISODate }[];
  finance: { outstanding_cents: number; overdue_count: number };
  deadlines: Deadline[];
  sync: { last_run_at: ISODateTime | null; status: "ok" | "error" | "never" };
  rules: string[];
}

// ---------- Auth / player area / sync ----------
export interface User { id: Id; name: string; email: string; }
export interface LoginResponse { token: string; user: User; }
export interface MeResponse {
  player: PublicPlayer;
  upcoming_events: TeamEvent[];   // each with my_rsvp
  balance: PlayerBalance;
}
export interface SyncRun {
  id: Id;
  scope: "current" | "history";
  status: "running" | "ok" | "error";
  started_at: ISODateTime;
  finished_at: ISODateTime | null;
  stats: Record<string, number>;
  error: string | null;
}
export interface ReminderMessage {
  text: string;                  // WhatsApp-ready text to paste
  wa_link: string;               // https://wa.me/?text=...
  missing: PublicPlayer[];
}
export interface CaptionResponse { text: string; }
export interface ImportResult {
  created: number; updated: number; skipped: number; errors: string[];
}

// ---------- Endpoints ----------
// AUTH
//  POST /auth/login            {email,password}            -> LoginResponse
//  POST /auth/logout                                       -> {}
//  GET  /auth/me                                           -> User
// PUBLIC (no login)
//  GET  /public/home                                       -> PublicHome
//  GET  /public/matches?competition_id=&scope=own|all      -> Match[]
//  GET  /public/standings?competition_id=                  -> StandingRow[]
//  GET  /public/roster                                     -> PublicPlayer[]
//  GET  /public/calendar.ics                               -> text/calendar
//  GET  /public/history                                    -> HistoryOverview
//  GET  /public/history/competitions/{id}                  -> HistoryCompetitionDetail
//  GET  /public/head-to-head/{teamId}                      -> HeadToHead
//  GET  /public/players/{id}                               -> PlayerProfile
//  GET  /public/players/{id}/photo                         -> image/png | image/jpeg (404 if none)
//  GET  /public/badges/{teamId}                            -> image/png | image/jpeg (Team.badge_url points here)
//  GET  /public/stats/career                               -> CareerRow[]
// ADMIN (Authorization: Bearer <token>)
//  GET  /dashboard                                         -> Dashboard
//  GET/POST /players, GET/PATCH/DELETE /players/{id}       -> Player(s)  (DELETE deactivates the player)
//  POST /players/import   {rows: Record<string,string>[]}  -> ImportResult
//  GET  /matches?competition_id=&scope=own|all             -> Match[]
//  GET  /matches/{id}                                      -> MatchDetail
//  GET/PUT /matches/{id}/lineup                            -> Lineup | null
//  PUT  /matches/{id}/stats {players: MatchPlayerStat[], referee?, man_of_the_match_id?} -> MatchDetail
//  POST /matches/{id}/caption {tone:"epico"|"ironico"|"sobrio"} -> CaptionResponse
//  GET/POST /events, PATCH/DELETE /events/{id}             -> TeamEvent  (match events are read-only)
//  GET  /events/{id}/responses                             -> EventResponse[]
//  PUT  /events/{id}/responses/{playerId} {rsvp?,attended?,note?} -> EventResponse
//  POST /events/{id}/remind                                -> ReminderMessage
//  GET/POST /charges, GET/DELETE /charges/{id}             -> Charge(s)
//  POST /charges/{id}/assign {player_ids?: Id[], amount_cents?} -> Charge
//  POST /player-charges/{id}/payments {amount_cents,method,paid_at,note?} -> PlayerCharge
//  DELETE /payments/{id}                                   -> {}
//  GET  /finance/summary                                   -> FinanceSummary
//  GET  /finance/players/{id}                              -> PlayerBalance
//  GET  /stats/attendance?season=2026/2027|all             -> AttendanceRow[]
//  POST /sync/xfive {scope:"current"|"history"}            -> SyncRun (HTTP 202, poll GET /sync/runs)
//  GET  /sync/runs                                         -> SyncRun[]
// PLAYER (personal link, no login)
//  GET  /me/{token}                                        -> MeResponse
//  POST /me/{token}/events/{eventId}/rsvp {rsvp,note?}     -> TeamEvent
```
