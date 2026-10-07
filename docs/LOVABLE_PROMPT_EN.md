# LOVABLE PROMPT — AMIR Team Manager (English)

> How to use: paste PART 1, wait until Lovable finishes, then paste PART 2.
> For more control, split PART 2 in two messages (first "Admin area", then "Graphics studio and stats").
> The API contract (TypeScript types) is at the bottom of this file and is part of PART 1.

---

## PART 1 — Foundations, API layer and public area

Build **"AMIR Team Manager"**: a mobile-first web app (installable as a PWA) to run an amateur football (soccer) team, **AMIR COSTRUZIONI**, which plays in the XFive leagues of Alessandria, Italy (8-a-side and 7-a-side). The team's users are Italian, so **every piece of user-facing text must be in Italian** (labels, buttons, empty states, errors, dates). Code, identifiers and comments stay in English.

A REST backend (Laravel) already exists but is not reachable from here. You build **only the frontend**, against a **fixed API contract** and **mock data**. The real backend will be plugged in later by changing one environment variable.

### Stack and hard rules
- React + Vite + TypeScript + Tailwind + shadcn/ui, React Router, TanStack Query, date-fns (locale `it`, timezone Europe/Rome), `html-to-image` for the shareable graphics, `papaparse` for CSV import.
- **Do NOT use Supabase, Lovable Cloud, Firebase or any other backend, and no external auth provider.** Authentication is a plain email + password form against the contract below.
- Create `src/api/types.ts` by copying EXACTLY the types in the "API CONTRACT" section at the bottom of this prompt. Do not rename or change any field: the real backend answers with these exact names (snake_case).
- Create `src/api/client.ts` with an `ApiClient` interface that has **one method per endpoint** listed in the contract, and two implementations:
  1. `httpClient`: uses `fetch` against `import.meta.env.VITE_API_BASE_URL`, sends `Authorization: Bearer <token>` (token stored in `localStorage` under `amir_token`), unwraps the `{ data }` envelope, and on HTTP 401 clears the token and redirects to `/admin/login`. Validation errors come as HTTP 422 with `{ message, errors: { field: [messages] } }`: show them next to the form fields.
  2. `mockClient`: in-memory data, simulated latency of 200–500 ms, and **mutations really change the mock state** (RSVP, payments, lineup, players, stats…) so the demo is interactive.
  Select the implementation with `VITE_USE_MOCKS` (default `true`). Provide a `.env.example` with `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1` and `VITE_USE_MOCKS=true`.
- One TanStack Query hook per endpoint (`useHome`, `useMatches`, `useStandings`, `useHistory`, …). Always handle loading (skeletons), error and empty states, with Italian messages.
- Money values are integers in **cents**; format them with `Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' })`.
- All timestamps are ISO 8601 with an offset (e.g. `2026-10-15T20:00:00+02:00`); display them in Europe/Rome.

### Visual identity
- Dark theme by default (with a light/dark toggle). Background `#0B0B0D`, surfaces `#151518`, text `#F5F5F5`, **team red `#D61F26`** as primary, amber `#FFC857` for warnings, green `#2FBF71` for wins / success, white for details.
- Headings in **Barlow Condensed** (uppercase, heavy), body in **Inter**, tabular figures for scores and numbers.
- A sporty "match day" look: cards with a diagonal cut, big scores, rounded badges, restrained micro-animations. Touch targets ≥ 44 px, excellent at 375 px width, bottom navigation on mobile and a sidebar on desktop.
- Kit colours: our home kit is red `#D61F26`, away kit white `#FFFFFF`. Always show the crest (`badge_url`) with an elegant placeholder when it is missing.

### Real-world state of the data (important for the UX)
The current season is **2026/2027**. The league **CITTADELLA [Alessandria]** (8-a-side, 10 teams, 18 rounds) has **only one official round published so far**: round 1, *AMIR COSTRUZIONI – VALONS, Thursday 15/10/2026 at 20:00, 100GRIGIO - CAMPO 4*. All the other rounds are **provisional pairings with no date or time** (`status: "to_schedule"`, `is_provisional: true`). Therefore:
- A provisional match shows "Data da definire", is visually muted, carries a **PROVVISORIA** chip, has no countdown and never appears as "next match" while an official dated match exists.
- If `calendar_info.is_complete` is `false`, show a top info banner using `calendar_info.note` (Italian text provided by the API, e.g. "Calendario ufficiale ancora in aggiornamento: XFive ha pubblicato 1 giornate su 18.").
- Standings: positions can be **tied** (the same number repeated). Show them as given. While nobody has played yet (`played === 0` for every row) show "—" instead of the position and a note "La stagione non è ancora iniziata". If `PublicHome.standing` is `null`, hide the "your position" card.
- `HistoryCompetition.final_position` exists **only for leagues**; when it is `null` show no rank badge.

### Public area (no login, shareable on WhatsApp)
1. `/` **Home**: hero with the next official match (crests, opponent, date/time, venue, countdown; if there is no date → "Data da definire"), last played match with its result (W/D/L colour-coded), a mini league table with our row highlighted, the next 5 matches, and the incomplete-calendar banner. A button "Aggiungi il calendario al telefono" (a `webcal://` link / download of `/public/calendar.ics`).
2. `/calendario`: all our matches grouped by round, a competition filter, status chips (Programmata / Provvisoria / Giocata / Rinviata), and a switch "tutte le partite del girone".
3. `/classifica`: full table (P, V, N, Pe, GF, GS, DR, Pt) with our row highlighted and a competition selector; follow the tie rules above.
4. `/rosa`: card grid with photo, shirt number (staff use "A"/"D"), role, nickname. Use only the `PublicPlayer` fields.
5. `/storico` — **History section** (past seasons, read-only):
   - At the top "Il nostro curriculum": all-time record (played, W-D-L, goals for/against, points, goal difference, win %), number of seasons, **best win**, **worst defeat**, **most frequent opponent** (from `HistorySummary`).
   - Then one block per **season** (newest first, e.g. 2025/2026 → 2022/2023) with the season summary and the list of competitions played (league, cups, tournaments), each with a final-position badge (🥇🥈🥉 for the top three, leagues only), format 7/8 and record (W-D-L, GF-GA).
   - `/storico/:competitionId`: competition detail with the final table (our row highlighted) and all our matches with result (W/D/L colour-coded).
   - A simple trend chart (points or win % per season) and a format filter (all / 7-a-side / 8-a-side).
   - A reusable **"Precedenti"** component (`HeadToHead`): head-to-head record against one opponent (overall W-D-L + match list with season and result). Reuse it in the match detail.
6. `/p/:token` **Personal player page** (magic link, no login): upcoming events with three large buttons **"Ci sono / Forse / Non ci sono"** (current choice highlighted, optional note), plus the player's **balance** with the charges to pay and the payments recorded.

### Mock data
Make the mock faithful to reality: team AMIR COSTRUZIONI (format 8), competition "CITTADELLA [Alessandria]" 2026/2027 with exactly these 10 teams: AMIR COSTRUZIONI, CAFFÈ KM0-PALESTRA MEETING, GUALA CLOSURES, IN EXTREMIS, OCCASIONALI FC, POLPEN 2022, REY GOMME, SHQIPONJAT, TORNITURE KARIM, VALONS (approximate home-kit colours: white, white/blue, white/red, blue, blue, black, blue/black, white, black). Only one official round (AMIR–VALONS, 15/10/2026 20:00); the other 17 rounds are provisional. **Use invented player names** (about 16, including a goalkeeper and one staff member with "A"/"D"), sample payments, and an **invented but plausible history** of 4 seasons (2022/2023–2025/2026) mixing 7- and 8-a-side leagues, cups and tournaments. Never use real people's data in the mocks.

---

## PART 2 — Admin area, lineups, payments, stats and graphics

Add the private area under `/admin` (email + password login at `/admin/login`, Bearer token, protected routes). On mobile use a bottom navigation with the main entries; on desktop a sidebar.

1. **Dashboard** (`GET /dashboard`): next match; **Squad List n/10** with a progress bar (XFive rule: at most 10 "exclusive" players; warn when under or over); registrations (to request / pending / approved); medical certificates expiring within 30 days (already-expired ones in red); money (outstanding + overdue charges); **XFive deadlines** (the `deadlines` list, past ones muted); last XFive sync status with an **"Aggiorna da XFive"** button (`POST /sync/xfive`, then poll `GET /sync/runs`); the `rules` list in a "Regole da ricordare" card.
2. **Players**: table (desktop) / cards (mobile) with search and filters (active, in squad list, registration status, role). A create/edit drawer with every `Player` field. **Import**: paste text or upload a CSV (columns: surname, name, birth date, role, shirt number, phone, email, registration status, squad list, certificate expiry) with a preview, then `POST /players/import` and show `created / updated / skipped / errors`. For each player: copy / share the **personal link** (`magic_link`) via WhatsApp. Show this reminder: "Il tesseramento va richiesto almeno 72 ore prima della partita (mai sotto le 24 ore). Dirigenti e allenatori: nel numero di maglia usa A o D." Deleting a player only deactivates him (the API keeps history); show that in the confirmation text.
3. **Matches**: list of our matches (switch "tutte") and a **match detail** page with tabs:
   - **Presenze** (attendance): counters yes / maybe / no / no answer, a player list with an editable status (the admin can answer on behalf of a player), and a **"Sollecita chi non ha risposto"** button (`POST /events/{id}/remind` → show `text` and open `wa_link`).
   - **Formazione** (lineup): a vertical SVG pitch editor. Formations for format 8: `3-3-1`, `3-2-2`, `2-3-2`, `3-1-3`, `2-4-1`; for format 7: `2-3-1`, `3-2-1`, `2-2-2`, `3-1-2` (the goalkeeper is always slot 1; the number of slots equals the match format). Drag players (preferably those who answered "yes") onto slots and the bench; save with `PUT /matches/{id}/lineup`; "Esporta come immagine" button. Warn when there is no goalkeeper or slots are empty. Surface 422 errors from the API (formation/duplicate/bench).
   - **Dopo la partita** (post-match): for each player played yes/no, goals, assists, yellow, red, rating 1–10; referee; **man of the match**; save with `PUT /matches/{id}/stats` (the list you send is the complete set).
   - **Precedenti**: the `HeadToHead` component against the opponent. Suggest the **kit to wear** by comparing the opponent's kit colours with ours (if their home kit is close to ours, recommend our away kit).
   - Provisional matches only show "In attesa di calendario ufficiale".
4. **Events**: create trainings and social events with date, place and RSVP; after the event tick who actually attended (`attended`). Match events cannot be edited or deleted (the API answers 422).
5. **Payments**: summary (total due / paid / outstanding), a per-player table with a colour-coded balance (green settled, amber partial, red overdue), a **"Nuova voce"** dialog (kind: season fee, registration, fine, referee, dinner, other; amount; due date; all players or a selection), a **"Registra pagamento"** dialog (amount, method cash / Satispay / PayPal / bank transfer, date), deletable payment history, and a **"Sollecito WhatsApp"** button for debtors (text composed client-side, opened with a `wa.me` link). The app **does not collect money**: it is only a ledger.
6. **Team leaderboards** (`GET /stats/attendance`): tabs "Più presenti" (matches played), "Allenamenti", "Marcatori", "Assist", "Voto medio", "Cartellini"; a **podium** with photos for the top three, then the list. Season filter (`season=all` for all-time).
7. **Graphics studio**: create shareable images from real data, using the crest, team colours and theme fonts. Templates: **Match Day** (next match), **Risultato**, **Formazione ufficiale**, **Uomo partita**, **Classifica**, **Precedenti**, **Marcatori**, plus a playful free-text **"Multa del mese"** template. Formats 1:1 (1080×1080), 4:5 and 9:16 (stories). Live preview, PNG export with `html-to-image`, **Web Share API** (`navigator.share` with a file) with a download fallback. Below the preview, a **caption generator** (`POST /matches/{id}/caption`) with a tone selector (epico / ironico / sobrio) and a copy button.
8. **Settings**: latest XFive syncs (`GET /sync/runs`), buttons "Aggiorna calendario" and "Importa storico" (`POST /sync/xfive` with `scope` `current` or `history`), logout.

### Quality bar
Accessibility (contrast, focus, labels), no horizontal scroll at 375 px, loading skeletons, short and friendly empty states, confirmation before destructive actions, validated forms with Italian messages. Do not put real personal data in the mocks. Do not add features that were not requested.

---

## API CONTRACT (copy exactly into `src/api/types.ts`)

```ts
// =====================================================================
// AMIR Team Manager — API contract v1 (JSON, snake_case)
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
