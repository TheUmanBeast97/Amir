/**
 * Il contratto dell'API pubblica della Mixed Zone (`/api/v1/public/zone/...`).
 * Il backend risponde con esattamente questi campi (dentro `{ data }`); `kickoff_at` è in ISO 8601.
 */

export type ZoneId = number;
export interface ZoneSeason {
  id: ZoneId;
  label: string;
  tournaments: number;
}
export interface ZoneClubRef {
  id: ZoneId | null;
  name: string;
  badge_url: string | null;
}
export interface ZoneTournamentRef {
  id: ZoneId;
  name: string;
  season: string;
  sport: string;
  format: number | null;
}
export interface ZoneTournament extends ZoneTournamentRef {
  slug: string;
  season_id: ZoneId;
  gender: string | null;
  category: string | null;
  flyer_url: string | null;
  teams_count: number | null;
  dates: string | null;
  status: "ongoing" | "incoming" | "previous";
  played: number;
  total: number; // partite giocate / in calendario
}
export interface ZoneMatch {
  id: ZoneId;
  tournament: ZoneTournamentRef;
  round: number | null;
  round_label: string | null;
  home: ZoneClubRef;
  away: ZoneClubRef;
  kickoff_at: string | null;
  kickoff_raw: string | null;
  venue: string | null;
  home_score: number | null;
  away_score: number | null;
  played: boolean;
  has_report: boolean;
}
export interface ZoneStandingRow {
  position: number;
  club: ZoneClubRef;
  values: Record<string, number>;
  form: ("W" | "D" | "L")[];
}
export interface ZoneStandings {
  group: string | null;
  columns: { key: string; label: string }[];
  rows: ZoneStandingRow[];
}
export interface ZoneStatRow {
  position: number;
  name: string;
  team: string | null;
  photo_url: string | null;
  player_id: ZoneId | null;
  values: number[];
}
export interface ZoneStatTable {
  type: "score" | "top-player" | "discipline";
  columns: string[];
  rows: ZoneStatRow[];
}
export interface ZoneDocument {
  url: string;
  title: string | null;
}
export interface ZoneTeam {
  id: ZoneId;
  name: string;
  badge_url: string | null;
  club: ZoneClubRef;
  staff: Record<string, string>;
}
export interface ZoneTeamPlayer {
  tpid: ZoneId;
  player_id: ZoneId | null;
  name: string;
  role: string | null;
  number: string | null;
  photo_url: string | null;
  country: string | null;
}
export interface ZoneHome {
  seasons: ZoneSeason[];
  season: string;
  latest: ZoneMatch[];
  upcoming: ZoneMatch[];
  tournaments: { sport: string; items: ZoneTournament[] }[];
  totals: { tournaments: number; clubs: number; players: number; matches: number; reports: number };
}
export interface ZoneSearchResult {
  clubs: ZoneClubRef[];
  players: { id: ZoneId; name: string; photo_url: string | null }[];
  tournaments: ZoneTournamentRef[];
}
export interface ZoneTournamentPage {
  tournament: ZoneTournament;
  standings: ZoneStandings[];
  teams: ZoneTeam[];
  documents: ZoneDocument[];
  latest: ZoneMatch[];
  upcoming: ZoneMatch[];
}
export interface ZoneRound {
  round: number | null;
  label: string;
  matches: ZoneMatch[];
}
export interface ZoneTournamentStats {
  tables: ZoneStatTable[];
  from_reports: { scorers: ZoneStatRow[]; mvp: ZoneStatRow[]; cards: ZoneStatRow[] } | null;
}
export interface ZoneClubSeason {
  season: string;
  tournaments: {
    tournament: ZoneTournamentRef;
    position: number | null;
    teams: number | null;
    team_id: ZoneId | null;
  }[];
}
export interface ZoneClubPage {
  club: ZoneClubRef;
  seasons: ZoneClubSeason[];
  roster: { team_id: ZoneId; tournament: ZoneTournamentRef; players: ZoneTeamPlayer[] } | null;
  record: {
    played: number;
    won: number;
    drawn: number;
    lost: number;
    goals_for: number;
    goals_against: number;
    biggest_win: ZoneMatch | null;
    best_streak: number;
  };
  latest: ZoneMatch[];
}
export interface ZoneHeadToHead {
  played: number;
  wins_a: number;
  wins_b: number;
  draws: number;
  matches: ZoneMatch[];
}
export interface ZonePlayerRef {
  id: ZoneId;
  name: string;
  photo_url: string | null;
  nationality: string | null;
}
export interface ZonePlayerPage {
  player: ZonePlayerRef & {
    age: number | null;
    clubs: { club: ZoneClubRef; tournaments: ZoneTournamentRef[] }[];
  };
  totals: {
    matches: number;
    goals: number;
    yellow: number;
    red: number;
    mvp: number;
    wins: number;
    draws: number;
    losses: number;
    goals_per_match: number;
  };
  by_season: {
    season: string;
    matches: number;
    goals: number;
    yellow: number;
    red: number;
    mvp: number;
  }[];
  partners: { player: ZonePlayerRef; played: number; won: number; points_per_match: number }[];
  victims: { club: ZoneClubRef; goals: number; matches: number }[];
  recent: (ZoneMatch & { goals: number; mvp: boolean })[];
}
export interface ZoneMatchPlayer {
  tpid: ZoneId;
  player_id: ZoneId | null;
  name: string;
  goals: number;
  yellow: number;
  red: number;
  mvp: boolean;
  photo_url: string | null;
}
export interface ZoneMatchPage {
  match: ZoneMatch;
  referee: string | null;
  home: { lineup: ZoneMatchPlayer[] };
  away: { lineup: ZoneMatchPlayer[] };
}
export interface ZoneStatsBoard {
  seasons: ZoneSeason[];
  sports: string[];
  scorers: ZoneStatRow[];
  appearances: ZoneStatRow[];
  cards: ZoneStatRow[];
  mvp: ZoneStatRow[];
  best_teams: { club: ZoneClubRef; played: number; won: number; points_per_match: number }[];
  best_defenses: { club: ZoneClubRef; played: number; conceded_per_match: number }[];
}
export interface ZoneDay {
  date: string;
  matches: ZoneMatch[];
}
export interface ZoneStatus {
  tournaments: number;
  clubs: number;
  teams: number;
  players: number;
  matches: number;
  reports: number;
  sections: { section: string; synced_at: string | null; counts: Record<string, number> }[];
}
