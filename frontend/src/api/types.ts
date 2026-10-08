// =====================================================================
// AMIR Team Manager - API contract v1 (JSON, snake_case)
// Base URL: VITE_API_BASE_URL  (e.g. http://127.0.0.1:8000/api/v1)
// Every response has the shape { data: ... }. Dates are ISO 8601 with an
// offset (Europe/Rome). Money is always in cents (*_cents).
// Validation errors: HTTP 422 { message, errors: { field: string[] } }.
// =====================================================================

export type Id = number;
export type ISODateTime = string; // "2026-10-15T20:00:00+02:00"
export type ISODate = string; // "2026-10-15"

export type Format = 7 | 8; // players on the pitch (7-a-side / 8-a-side)

// ---------- Teams and competitions ----------
export interface Team {
  id: Id;
  name: string; // "AMIR COSTRUZIONI"
  short_name: string; // "AMIR"
  badge_url: string | null;
  is_own: boolean; // true only for our team
  kit1_color: string | null; // hex, e.g. "#d61f26"
  kit2_color: string | null;
  format: Format | null; // only set for our team
}

export type CompetitionKind =
  "campionato" | "coppa_lega" | "coppa_categoria" | "coppa" | "torneo" | "amichevole";

/** Divisa che indossiamo in una partita: decide quale numero di maglia mostrare. */
export type KitColor = "red" | "white";

export interface Competition {
  id: Id;
  name: string; // "Cittadella [Alessandria]"
  season: string; // "2026/2027"
  kind: CompetitionKind;
  format: Format;
  is_current: boolean; // season in progress
  starts_on: ISODate | null;
  ends_on: ISODate | null;
  total_rounds: number | null;
  scheduled_rounds: number; // rounds with an official date/time
  xfive_url: string | null;
}

// ---------- Matches ----------
// to_schedule = provisional pairing, no official date/time yet
export type MatchStatus = "scheduled" | "to_schedule" | "played" | "postponed" | "cancelled";

export interface Match {
  id: Id;
  competition: Pick<Competition, "id" | "name" | "kind" | "season" | "format">;
  round: number | null;
  round_label: string; // "1ª giornata", "Semifinale"
  home_team: Team;
  away_team: Team;
  kickoff_at: ISODateTime | null;
  venue: string | null; // "100GRIGIO - CAMPO 4"
  home_score: number | null;
  away_score: number | null;
  status: MatchStatus;
  is_provisional: boolean; // true when status === "to_schedule"
  is_own_match: boolean;
  is_friendly: boolean; // amichevole: non conta per storico e statistiche
  our_kit: KitColor | null; // divisa scelta dallo staff
  result: "W" | "D" | "L" | null; // from our team's point of view
  event_id: Id | null; // linked event (attendance / RSVP)
  xfive_url: string | null;
}

export interface StandingRow {
  position: number; // tied teams share the same position
  team: Team;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  goal_diff: number;
  points: number;
}

export interface CalendarInfo {
  competition_id: Id;
  scheduled_rounds: number;
  total_rounds: number | null;
  is_complete: boolean; // false until XFive publishes the whole calendar
  note: string | null; // Italian text, e.g. "Calendario ufficiale ancora in aggiornamento…"
}

// ---------- Players ----------
export type PlayerRole =
  "portiere" | "difensore" | "centrocampista" | "attaccante" | "dirigente" | "allenatore";
export type RegistrationStatus = "none" | "pending" | "approved";

export interface PublicPlayer {
  id: Id;
  full_name: string;
  nickname: string | null;
  shirt_number: string | null; // "A"/"D" for staff
  shirt_number_red: string | null; // numero con la divisa rossa
  shirt_number_white: string | null; // numero con la divisa bianca
  role: PlayerRole | null;
  photo_url: string | null;
  cutout_url: string | null; // la sagoma senza sfondo per la figurina, se lo staff l'ha già ritagliata
  nationality: string | null;
  is_active: boolean; // false = ex giocatore (resta nello storico)
}

export interface Player extends PublicPlayer {
  first_name: string;
  last_name: string;
  in_squad_list: boolean; // max 10 "exclusive" players
  registration_status: RegistrationStatus;
  medical_cert_expires_on: ISODate | null;
  phone: string | null;
  email: string | null;
  birth_date: ISODate | null; // personal data: admin only
  xfive_player_id: number | null;
  // da dove viene la foto: null o "xfive" = da XFive (si aggiorna da sola), "upload" = caricata dallo staff, "none" = tolta
  photo_source: "xfive" | "upload" | "none" | null;
  notes: string | null;
  scout_text: string | null; // scheda scout (scritta dall'IA o dallo staff), mostrata sulla pagina del giocatore
  scout_source: "ai" | "template" | "manual" | null;
  scout_generated_at: ISODateTime | null;
  magic_link: string | null; // personal URL for RSVP
}

// ---------- Events and attendance ----------
export type EventType = "match" | "training" | "social";
export type Rsvp = "yes" | "no" | "maybe";

export interface EventSummary {
  yes: number;
  no: number;
  maybe: number;
  pending: number;
}

export interface TeamEvent {
  id: Id;
  type: EventType;
  title: string;
  starts_at: ISODateTime | null;
  venue: string | null;
  match_id: Id | null;
  summary: EventSummary;
  my_rsvp?: Rsvp | null; // only in /me/{token} responses
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
  slot: number; // 1..format (1 = goalkeeper)
  player_id: Id | null;
  label: string; // "POR", "DC", "CC", "ATT"...
  x: number; // 0..100 (% of pitch width)
  y: number; // 0..100 (% of pitch height, 0 = our goal)
}
export interface Lineup {
  match_id: Id;
  formation: string; // "3-3-1"
  slots: LineupSlot[];
  bench: Id[]; // player ids on the bench
  notes: string | null;
  is_published: boolean; // visibile sul sito pubblico
}

// ---------- Match detail (admin) ----------
export interface MatchPlayerStat {
  player_id: Id;
  played: boolean;
  goals: number;
  assists: number;
  yellow: number;
  red: number;
  rating: number | null; // 1..10
  is_mvp: boolean; // stella "miglior giocatore"
  source?: "xfive" | "manual"; // xfive = letto dalla pagina partita, manual = inserito dallo staff
}
export interface HeadToHeadMatch {
  match_id: Id;
  season: string;
  competition_name: string;
  kickoff_at: ISODateTime | null;
  home_team: string;
  away_team: string;
  home_score: number;
  away_score: number;
  result: "W" | "D" | "L";
}
export interface HeadToHead {
  opponent: Team;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  matches: HeadToHeadMatch[];
}
export interface MatchDetail {
  match: Match;
  event: TeamEvent | null;
  responses: EventResponse[];
  lineup: Lineup | null;
  stats: MatchPlayerStat[];
  callups: { player_id: Id; note: string | null }[];
  callups_published: boolean;
  our_kit: KitColor | null;
  referee: string | null;
  man_of_the_match_id: Id | null;
  head_to_head: HeadToHead | null;
}

// ---------- Scheda pubblica di una partita ----------
export interface ReportPlayer {
  player: PublicPlayer;
  goals: number;
  yellow: number;
  red: number;
  mvp: boolean;
}
export interface MatchReport {
  referee: string | null;
  venue: string | null;
  man_of_the_match: PublicPlayer | null;
  players: ReportPlayer[]; // chi era in distinta
  scorers: ReportPlayer[];
  opponent: {
    scorers: { name: string; goals: number }[];
    cards: { name: string; yellow: number; red: number }[];
    players_count: number;
  };
}
export interface PublicMatch {
  match: Match;
  callups: { player: PublicPlayer; note: string | null }[] | null; // null = non pubblicati
  lineup: (Lineup & { players: Record<string, PublicPlayer> }) | null; // null = non pubblicata
  report: MatchReport | null; // solo a partita giocata
  head_to_head: HeadToHead | null;
}

// ---------- Amichevoli ----------
export interface Friendly {
  match: Match;
  event: TeamEvent | null;
}
export interface FriendlyInput {
  opponent_name: string;
  kickoff_at: string; // "2026-11-05 21:00"
  venue?: string | null;
  is_home?: boolean;
  home_score?: number | null;
  away_score?: number | null;
  our_kit?: KitColor | null;
}

// ---------- Scheda personale di un giocatore ----------
export interface PlayerMatchRow {
  match_id: Id;
  date: ISODate | null;
  season: string;
  competition_name: string;
  kind: CompetitionKind;
  round_label: string | null;
  opponent: Team;
  home_away: "H" | "A";
  score_for: number;
  score_against: number;
  result: "W" | "D" | "L" | null;
  goals: number;
  yellow: number;
  red: number;
  mvp: boolean;
}
export interface PlayerTotals {
  matches: number;
  goals: number;
  goals_per_match: number;
  yellow: number;
  red: number;
  mvp: number;
  wins: number;
  draws: number;
  losses: number;
  win_rate: number;
  points_per_match: number;
  // la squadra con lui in campo: gol subiti e porte inviolate (contano per portieri e difensori)
  conceded: number;
  conceded_per_match: number;
  clean_sheets: number;
  team_conceded_per_match: number; // il riferimento: media della squadra su tutte le partite contate
  competitions: number;
  mvp_points: number; // mvp_points = punti "miglior giocatore" delle classifiche XFive
}
/** Una voce del voto della figurina: numero di partenza, resa da 0 a 1, peso del ruolo e punti portati. */
export interface CardPart {
  key: "conceded" | "clean_sheets" | "goals" | "wins" | "presence" | "mvp";
  label: string;
  value: number;
  reference: number | null;
  unit: "per_match" | "share" | "count";
  score: number;
  weight: number;
  points: number;
}
/** Il voto della figurina (da 80 a 99) con la sua scomposizione, calcolato dal server con pesi diversi per ruolo. */
export interface CardScore {
  ovr: number;
  tier: "bronzo" | "argento" | "oro" | "platino" | "fuoco";
  role: "portiere" | "difensore" | "centrocampista" | "attaccante";
  matches: number;
  confidence: number; // 1 = voto pieno; sotto 10 partite il voto è scalato
  parts: CardPart[];
  penalty: { yellow: number; red: number; max_weight: number; points: number };
}
export interface TeamRecordLine {
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  points_per_match: number;
}
export interface PlayerPage {
  player: PublicPlayer & {
    age: number | null;
    first_season: string | null;
    last_season: string | null;
    seasons_count: number;
  };
  totals: PlayerTotals;
  card: CardScore;
  rank: { appearances: number | null; goals: number | null; of: number };
  by_season: {
    season: string;
    matches: number;
    goals: number;
    yellow: number;
    red: number;
    mvp: number;
    wins: number;
    draws: number;
    losses: number;
  }[];
  by_kind: { kind: CompetitionKind; matches: number; goals: number }[];
  career: {
    competition: Pick<Competition, "id" | "name" | "season" | "kind" | "format">;
    matches: number;
    goals: number;
    yellow: number;
    red: number;
    mvp: number;
    mvp_points: number;
  }[];
  form: PlayerMatchRow[]; // ultime 10, dalla più vecchia
  match_log: PlayerMatchRow[]; // tutte, dalla più recente
  impact: { with: TeamRecordLine; without: TeamRecordLine; sample: number } | null;
  records: {
    debut: PlayerMatchRow | null;
    first_goal: PlayerMatchRow | null;
    most_goals_in_match: PlayerMatchRow | null;
    hat_tricks: number;
    scoring_streak: number;
    clean_appearances: number;
  };
  /** I compagni con cui la squadra rende meglio (almeno 6 partite insieme), dal migliore. */
  partners: {
    player: PublicPlayer;
    played: number;
    won: number;
    drawn: number;
    lost: number;
    points_per_match: number;
  }[];
  /** Le squadre a cui ha segnato di più (fino a 3). */
  victims: { team: Team; goals: number; matches: number }[];
  /** La prossima tappa di presenze e gol (10, 25, 50, 75, 100…); null se le ha superate tutte. */
  milestones: { matches: Milestone | null; goals: Milestone | null };
  /** Dall'ultima presenza: partite di fila a segno e partite di fila senza segnare. */
  streaks: { scoring_now: number; drought_now: number };
  /** La scheda scout, se lo staff l'ha scritta. */
  scout: {
    text: string;
    source: "ai" | "template" | "manual";
    generated_at: ISODateTime | "";
  } | null;
}
export interface Milestone {
  current: number;
  next: number;
  missing: number;
}
export interface ScoutResult {
  text: string;
  source: "ai" | "template";
  model: string | null;
  note: string | null; // perché non c'è l'IA, quando source è "template"
  player: Player;
}
/** Classifica di sempre: tutti i giocatori, anche gli ex. */
export interface CareerRow {
  player: PublicPlayer;
  matches: number;
  goals: number;
  yellow: number;
  red: number;
  mvp: number;
  goals_per_match: number;
  seasons: number;
}

// ---------- Payments ----------
export type ChargeKind = "quota_stagione" | "tesseramento" | "multa" | "arbitro" | "cena" | "altro";

export interface Charge {
  id: Id;
  title: string;
  kind: ChargeKind;
  amount_cents: number; // default amount per player
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
  method: "contanti" | "satispay" | "paypal" | "revolut" | "bonifico" | "altro";
  paid_at: ISODate;
  note: string | null;
}
export interface PlayerCharge {
  id: Id;
  charge: Pick<Charge, "id" | "title" | "kind" | "due_on">;
  player_id: Id;
  amount_cents: number;
  paid_cents: number;
  balance_cents: number; // amount - paid
  is_overdue: boolean;
  payments: Payment[];
}
export interface PlayerBalance {
  player_id: Id;
  player_name: string;
  due_cents: number;
  paid_cents: number;
  balance_cents: number; // > 0 = still owes money
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
  rsvp_yes_rate: number; // 0..1
  goals: number;
  assists: number;
  yellow: number;
  red: number;
  avg_rating: number | null;
}

// ---------- History ----------
export interface RecordLine {
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  points: number;
}
export interface HistoryCompetition {
  id: Id;
  name: string;
  kind: CompetitionKind;
  format: Format;
  season: string;
  final_position: number | null; // final league position; null for cups and tournaments
  teams_count: number | null;
  record: RecordLine; // our team's record
}
export interface HistorySeason {
  season: string; // "2025/2026"
  record: RecordLine;
  competitions: HistoryCompetition[];
  top_scorers: { player: PublicPlayer; goals: number }[]; // primi 3 della stagione
  insights: HistoryInsights;
}

// ---- Record, serie e curiosità (dallo storico, da una stagione o da una competizione) ----
export interface StreakRun {
  length: number;
  from: ISODate | null;
  to: ISODate | null;
}
export type StreakKind = "wins" | "unbeaten" | "losses" | "scoring" | "clean_sheets";
export interface HistoryRecords {
  goals_per_match: number;
  conceded_per_match: number;
  points_per_match: number;
  clean_sheets: number; // partite senza subire reti
  scoreless: number; // partite senza segnare
  best_win: HeadToHeadMatch | null;
  worst_defeat: HeadToHeadMatch | null;
  highest_scoring: HeadToHeadMatch | null; // più reti in totale
  most_scored: HeadToHeadMatch | null;
  most_conceded: HeadToHeadMatch | null;
  hat_tricks_count: number; // 3 o più reti in una partita
  doubles_count: number;
  yellow: number; // dei nostri giocatori, nelle distinte lette
  red: number;
}
/** Il bilancio di un gruppo di partite; "label" è già in italiano (tranne in by_kind: è il tipo di competizione). */
export interface SplitRow extends RecordLine {
  key: string;
  label: string;
}
export interface HistorySplits {
  home_away: { home: RecordLine; away: RecordLine }; // prima e seconda nominata
  by_format: SplitRow[];
  by_kind: SplitRow[];
  by_slot: SplitRow[]; // per orario, solo quelli con almeno 2 partite
  by_weekday: SplitRow[];
  by_month: SplitRow[]; // da settembre ad agosto
}
export interface GroupedRecord extends RecordLine {
  name: string;
  yellow?: number;
  red?: number;
} // arbitro o campo
export interface HatTrick {
  player: PublicPlayer;
  goals: number;
  match: HeadToHeadMatch;
}
export interface PlayerRecord {
  player: PublicPlayer;
  season: string;
  value: number;
}
export interface RosterRow {
  player: PublicPlayer;
  apps: number;
  goals: number;
  mvp: number;
  yellow: number;
  red: number;
  goals_per_match: number;
}
export interface HistoryInsights {
  records: HistoryRecords;
  streaks: Record<StreakKind, StreakRun>;
  splits: HistorySplits;
  referees: GroupedRecord[]; // chi ha arbitrato di più per primo; con i cartellini dei nostri
  venues: GroupedRecord[];
  hat_tricks: HatTrick[]; // le più belle, fino a 15
  player_records: {
    goals: PlayerRecord | null;
    apps: PlayerRecord | null;
    mvp: PlayerRecord | null;
  }; // in una stagione
  roster: RosterRow[]; // vuoto su tutto lo storico (c'è l'albo d'oro)
}
export interface AwardTeam {
  team: Team;
  value: number;
  played: number;
}
/** Solo nei campionati: nelle coppe la classifica a punti non dice chi ha vinto. */
export interface CompetitionAwards {
  first_place: AwardTeam; // value = punti
  best_attack: AwardTeam; // value = reti fatte (miglior media a partita)
  best_defence: AwardTeam; // value = reti subite (minor media a partita)
  own_position: number | null;
  teams_count: number;
}
export interface HistorySummary {
  record: RecordLine; // all-time totals
  seasons_count: number;
  best_win: HeadToHeadMatch | null;
  worst_defeat: HeadToHeadMatch | null;
  most_frequent_opponent: { team: Team; played: number } | null;
}
export interface HistoryOpponent extends RecordLine {
  team: Team;
}
export interface HistoryOverview {
  summary: HistorySummary;
  seasons: HistorySeason[];
  opponents: HistoryOpponent[]; // bilancio contro ogni avversaria, le più incontrate per prime
  insights: HistoryInsights; // su tutto lo storico
}
export interface HistoryCompetitionDetail {
  competition: Competition;
  standings: StandingRow[];
  own_matches: Match[];
  all_matches: Match[];
  awards: CompetitionAwards | null;
  insights: HistoryInsights;
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
  upcoming: Match[]; // next 5 (official first, then provisional)
  recent: Match[]; // last 5 played
}
export interface Deadline {
  date: ISODate;
  title: string;
}
export interface DashboardLeader {
  player: PublicPlayer;
  value: number;
}
export interface DashboardOverview {
  season: string;
  record: RecordLine; // stagione in corso
  form: ("W" | "D" | "L" | null)[]; // ultime 5 partite ufficiali, dalla più vecchia
  leaders: { goals: DashboardLeader[]; matches: DashboardLeader[]; mvp: DashboardLeader[] }; // di sempre, rosa attuale
  next_match_prep: {
    callups_count: number;
    callups_published: boolean;
    lineup_saved: boolean;
    lineup_published: boolean;
    rsvp: EventSummary | null;
  } | null;
  players_count: number;
  former_players_count: number;
  friendlies_upcoming: number;
}
export interface Dashboard {
  overview: DashboardOverview;
  next_match: Match | null;
  next_event: TeamEvent | null;
  squad_list: { count: number; limit: number; players: PublicPlayer[] };
  registrations: { none: number; pending: number; approved: number };
  certificates_expiring: { player: PublicPlayer; expires_on: ISODate }[];
  birthdays: { player: PublicPlayer; date: ISODate; turns: number; days_left: number }[]; // prossimi 45 giorni, solo area staff
  finance: { outstanding_cents: number; overdue_count: number };
  deadlines: Deadline[];
  sync: { last_run_at: ISODateTime | null; status: "ok" | "error" | "never" };
  rules: string[];
}

// ---------- Auth / player area / sync ----------
export interface User {
  id: Id;
  name: string;
  email: string;
}
export interface LoginResponse {
  token: string;
  user: User;
}
export interface MeResponse {
  player: PublicPlayer;
  upcoming_events: TeamEvent[]; // each with my_rsvp
  balance: PlayerBalance;
}
/** current = calendario e risultati, history = storico, details = referti delle partite, media = stemmi e foto, stats = statistiche dei giocatori, admin = rosa e tesseramenti dall'area amministrazione di XFive, players = come admin ma crea anche i giocatori che da noi mancano */
export type SyncScope =
  "current" | "history" | "details" | "media" | "stats" | "roster" | "admin" | "players";
/** Esito della rilettura da XFive di un solo giocatore (POST /players/{id}/sync). */
export interface PlayerSyncResult {
  player: Player;
  profile: "matched" | "not_found" | "ambiguous";
  photo: boolean; // true = la foto è cambiata
  stats_rows: number;
}
export interface SyncRun {
  id: Id;
  scope: SyncScope;
  status: "running" | "ok" | "error";
  started_at: ISODateTime;
  finished_at: ISODateTime | null;
  stats: Record<string, number>;
  error: string | null;
}
/** L'accesso all'area amministrazione di XFive con l'account dello staff (spento finché non lo si accende sul server). */
export interface XfiveAdminStatus {
  configured: boolean;
  enabled: boolean;
  blocked_until: ISODateTime | null;
  players_synced: number;
  last_run: {
    status: "running" | "ok" | "error";
    started_at: ISODateTime;
    finished_at: ISODateTime | null;
    stats: Record<string, number>;
    error: string | null;
  } | null;
}
export interface XfiveAdminCheck {
  ok: boolean;
  state:
    | "ok"
    | "not_configured"
    | "disabled"
    | "blocked"
    | "login_failed"
    | "unreachable"
    | "unexpected_page";
  message: string;
}
/** Quello che è successo portando online le info dei giocatori e i pagamenti del gestionale sul computer. */
export interface LocalImportResult {
  players_matched: number;
  players_filled: number;
  fields_filled: number;
  players_ambiguous: number;
  players_not_found: number;
  charges_created: number;
  player_charges_created: number;
  payments_created: number;
  payments_already_there: number;
  finance_skipped: number;
}
export interface ReminderMessage {
  text: string; // WhatsApp-ready text to paste
  wa_link: string; // https://wa.me/?text=...
  missing: PublicPlayer[];
}
export type CaptionKind =
  | "matchday"
  | "risultato"
  | "formazione"
  | "motm"
  | "classifica"
  | "precedenti"
  | "marcatori"
  | "multa";
export interface CaptionResponse {
  text: string;
  source: "ai" | "template"; // template = IA non disponibile (chiave mancante o errore)
  model: string | null;
  note: string | null; // Italian hint when source is "template"
}

// ---- Modulistica XFive (area staff) ----
export type DocCategory =
  "guida" | "regolamento" | "tesseramento" | "privacy" | "contratti" | "convenzioni";
export type DocKind = "guida" | "regolamento" | "modulo" | "informativa" | "promo" | "listino";
export interface DocAmount {
  label: string;
  amount: string;
  cents: number | null;
}
export interface XfiveDocument {
  slug: string;
  number: number; // 1..15, come nel menu Modulistica di XFive
  title: string;
  category: DocCategory;
  kind: DocKind;
  file: string;
  mime: string;
  size_bytes: number | null;
  source_url: string; // copia originale sul sito XFive
  when: string; // quando serve
  purpose: string;
  summary: string;
  key_points: string[];
  amounts: DocAmount[];
  actions: string[]; // cosa fare per noi
  fill_in: boolean; // da compilare e firmare
}
export interface Sanction {
  code: string; // "1", "3A", "8B"…
  area: "disciplina" | "rinuncia" | "tesseramento" | "organizzazione" | "cauzione" | "danni";
  title: string;
  amount: string;
  cents_min: number | null;
  cents_max: number | null;
  extra: string; // es. "+ sconfitta a tavolino 3-0"
}
export interface TechnicalRule {
  n: string;
  topic: string;
  text: string;
}
export interface MatchCheckItem {
  text: string;
  tip: string;
}
export interface PriceItem {
  group: string;
  label: string;
  amount: string;
  cents: number | null;
  doc: string;
}
export interface DocAlert {
  level: "warning" | "info";
  title: string;
  text: string;
  docs: string[];
}
export interface Modulistica {
  updated_on: string;
  source: string;
  documents: XfiveDocument[];
  sanctions: Sanction[];
  technical_rules: TechnicalRule[];
  registration_checklist: string[];
  match_checklist: MatchCheckItem[];
  match_checklist_footer: string;
  price_list: PriceItem[];
  alerts: DocAlert[];
}
export interface DocPassage {
  doc: string;
  doc_title: string;
  label: string;
  text: string;
}
export interface DocAnswer {
  answer: string | null; // scritta dall'IA; vuota se l'IA non c'è o non risponde
  source: "ai" | "search";
  model: string | null;
  note: string | null;
  passages: DocPassage[]; // i passaggi dei documenti più pertinenti, sempre allegati
}
export interface ImportResult {
  created: number;
  updated: number;
  skipped: number;
  errors: string[];
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
//  GET  /public/matches/{id}                               -> PublicMatch (convocati/formazione solo se pubblicati)
//  GET  /public/players/{id}                               -> PlayerPage (anche ex giocatori)
//  GET  /public/stats/career                               -> CareerRow[] (classifica di sempre)
// ADMIN (Authorization: Bearer <token>)
//  GET  /dashboard                                         -> Dashboard
//  GET/POST /players, GET/PATCH/DELETE /players/{id}       -> Player(s)  (DELETE deactivates the player)
//  POST /players/import   {rows: Record<string,string>[]}  -> ImportResult
//  POST /players/{id}/scout                                -> ScoutResult  (scrive e salva la scheda scout: IA o modello di testo)
//  PUT  /players/{id}/scout {text}                         -> Player       (testo corretto a mano; vuoto = la toglie)
//  POST /players/{id}/cutout (file PNG)                    -> Player       (la sagoma senza sfondo per la figurina; GET /public/players/{id}/cutout la serve)
//  GET  /matches?competition_id=&scope=own|all             -> Match[]
//  GET  /matches/{id}                                      -> MatchDetail
//  GET/PUT /matches/{id}/lineup                            -> Lineup | null   (PUT accepts is_published)
//  PATCH /matches/{id} {our_kit?, callups_published?, lineup_published?} -> MatchDetail
//  PUT  /matches/{id}/callups {players:[{player_id,note?}], published?} -> MatchDetail
//  PUT  /matches/{id}/stats {players: MatchPlayerStat[], referee?, man_of_the_match_id?} -> MatchDetail
//  POST /matches/{id}/caption {tone:"epico"|"ironico"|"sobrio", kind?: CaptionKind, notes?} -> CaptionResponse
//  GET/POST /friendlies, PUT/DELETE /friendlies/{matchId}  -> Friendly(ies)  (amichevoli, non contano nelle statistiche)
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
//  GET  /documents                                         -> Modulistica  (i 15 documenti XFive analizzati, sanzioni, regole, checklist, costi)
//  GET  /documents/{slug}/file                             -> il PDF o l'immagine originale (serve il Bearer: si scarica come blob)
//  POST /documents/ask {question}                          -> DocAnswer
//  POST /sync/xfive {scope: SyncScope}                     -> SyncRun (in locale HTTP 202 e poi GET /sync/runs; sul server HTTP 200 con l'esito, stats.remaining > 0 = c'è ancora da fare, si richiama)
//  GET  /sync/runs                                         -> SyncRun[]
//  POST /backup/import-local (file)                        -> LocalImportResult (unisce info giocatori e pagamenti di un database.sqlite o di un backup, senza sostituire nulla)
//  GET  /xfive-admin                                       -> XfiveAdminStatus (nessuna richiesta a XFive)
//  POST /xfive-admin/check                                 -> XfiveAdminCheck (fa un accesso vero a XFive)
// PLAYER (personal link, no login)
//  GET  /me/{token}                                        -> MeResponse
//  POST /me/{token}/events/{eventId}/rsvp {rsvp,note?}     -> TeamEvent
