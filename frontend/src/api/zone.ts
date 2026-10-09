import { useQuery } from "@tanstack/react-query";
import { qs, request } from "./client";
import type {
  ZoneClubPage,
  ZoneClubRef,
  ZoneDay,
  ZoneHeadToHead,
  ZoneHome,
  ZoneId,
  ZoneMatch,
  ZoneMatchPage,
  ZonePlayerPage,
  ZonePlayerRef,
  ZoneRound,
  ZoneSearchResult,
  ZoneStatsBoard,
  ZoneTournament,
  ZoneTournamentPage,
  ZoneTournamentStats,
} from "./zone-types";

/** Filtri delle liste: `undefined` toglie il parametro dall'indirizzo. */
export interface ZoneTournamentsFilter {
  season?: string | undefined;
  sport?: string | undefined;
}
export interface ZonePlayersFilter {
  q?: string | undefined;
  club?: ZoneId | undefined;
}
export interface ZoneMatchesFilter {
  date?: string | undefined;
  tournament?: ZoneId | undefined;
}
export interface ZoneStatsFilter {
  season?: string | undefined;
  sport?: string | undefined;
}

const base = "/public/zone";

/** Il client dell'API pubblica della Mixed Zone: una funzione per indirizzo, tutte su `request()` di client.ts. */
export const zoneApi = {
  home: (season?: string) => request<ZoneHome>(`${base}/home${qs({ season })}`),
  search: (q: string) => request<ZoneSearchResult>(`${base}/search${qs({ q })}`),
  tournaments: ({ season, sport }: ZoneTournamentsFilter = {}) =>
    request<ZoneTournament[]>(`${base}/tournaments${qs({ season, sport })}`),
  tournament: (id: ZoneId) => request<ZoneTournamentPage>(`${base}/tournaments/${id}`),
  tournamentMatches: (id: ZoneId) => request<ZoneRound[]>(`${base}/tournaments/${id}/matches`),
  tournamentStats: (id: ZoneId) => request<ZoneTournamentStats>(`${base}/tournaments/${id}/stats`),
  clubs: (q?: string) => request<ZoneClubRef[]>(`${base}/clubs${qs({ q })}`),
  club: (id: ZoneId, tournament?: ZoneId) =>
    request<ZoneClubPage>(`${base}/clubs/${id}${qs({ tournament })}`),
  clubMatches: (id: ZoneId, tournament?: ZoneId) =>
    request<ZoneMatch[]>(`${base}/clubs/${id}/matches${qs({ tournament })}`),
  headToHead: (a: ZoneId, b: ZoneId) =>
    request<ZoneHeadToHead>(`${base}/clubs/${a}/head-to-head/${b}`),
  players: ({ q, club }: ZonePlayersFilter = {}) =>
    request<ZonePlayerRef[]>(`${base}/players${qs({ q, club })}`),
  player: (id: ZoneId) => request<ZonePlayerPage>(`${base}/players/${id}`),
  matches: ({ date, tournament }: ZoneMatchesFilter = {}) =>
    request<ZoneDay[]>(`${base}/matches${qs({ date, tournament })}`),
  match: (id: ZoneId) => request<ZoneMatchPage>(`${base}/matches/${id}`),
  stats: ({ season, sport }: ZoneStatsFilter = {}) =>
    request<ZoneStatsBoard>(`${base}/stats${qs({ season, sport })}`),
};

/** I dati pubblici cambiano di notte: un minuto di cache in pagina basta e avanza. */
const STALE = 60_000;

// ---- hook, uno per indirizzo; chiave sempre sotto ["zone", ...] così si invalidano tutti insieme ----
export const useZoneHome = (season?: string) =>
  useQuery({
    queryKey: ["zone", "home", season ?? null],
    queryFn: () => zoneApi.home(season),
    staleTime: STALE,
  });
export const useZoneSearch = (q: string) =>
  useQuery({
    queryKey: ["zone", "search", q],
    queryFn: () => zoneApi.search(q),
    enabled: q.trim().length >= 2,
    staleTime: STALE,
  });
export const useZoneTournaments = (f: ZoneTournamentsFilter = {}) =>
  useQuery({
    queryKey: ["zone", "tournaments", f.season ?? null, f.sport ?? null],
    queryFn: () => zoneApi.tournaments(f),
    staleTime: STALE,
  });
export const useZoneTournament = (id: ZoneId) =>
  useQuery({
    queryKey: ["zone", "tournament", id],
    queryFn: () => zoneApi.tournament(id),
    enabled: id > 0,
    retry: false,
    staleTime: STALE,
  });
export const useZoneTournamentMatches = (id: ZoneId) =>
  useQuery({
    queryKey: ["zone", "tournament", id, "matches"],
    queryFn: () => zoneApi.tournamentMatches(id),
    enabled: id > 0,
    staleTime: STALE,
  });
export const useZoneTournamentStats = (id: ZoneId) =>
  useQuery({
    queryKey: ["zone", "tournament", id, "stats"],
    queryFn: () => zoneApi.tournamentStats(id),
    enabled: id > 0,
    staleTime: STALE,
  });
export const useZoneClubs = (q?: string) =>
  useQuery({
    queryKey: ["zone", "clubs", q ?? null],
    queryFn: () => zoneApi.clubs(q),
    staleTime: STALE,
  });
export const useZoneClub = (id: ZoneId, tournament?: ZoneId) =>
  useQuery({
    queryKey: ["zone", "club", id, tournament ?? null],
    queryFn: () => zoneApi.club(id, tournament),
    enabled: id > 0,
    retry: false,
    staleTime: STALE,
  });
export const useZoneClubMatches = (id: ZoneId, tournament?: ZoneId) =>
  useQuery({
    queryKey: ["zone", "club", id, "matches", tournament ?? null],
    queryFn: () => zoneApi.clubMatches(id, tournament),
    enabled: id > 0,
    staleTime: STALE,
  });
export const useZoneHeadToHead = (a: ZoneId, b: ZoneId | undefined) =>
  useQuery({
    queryKey: ["zone", "h2h", a, b ?? null],
    queryFn: () => zoneApi.headToHead(a, b!),
    enabled: a > 0 && !!b,
    staleTime: STALE,
  });
export const useZonePlayers = (f: ZonePlayersFilter = {}) =>
  useQuery({
    queryKey: ["zone", "players", f.q ?? null, f.club ?? null],
    queryFn: () => zoneApi.players(f),
    staleTime: STALE,
  });
export const useZonePlayer = (id: ZoneId) =>
  useQuery({
    queryKey: ["zone", "player", id],
    queryFn: () => zoneApi.player(id),
    enabled: id > 0,
    retry: false,
    staleTime: STALE,
  });
export const useZoneMatches = (f: ZoneMatchesFilter = {}) =>
  useQuery({
    queryKey: ["zone", "matches", f.date ?? null, f.tournament ?? null],
    queryFn: () => zoneApi.matches(f),
    staleTime: STALE,
  });
export const useZoneMatch = (id: ZoneId) =>
  useQuery({
    queryKey: ["zone", "match", id],
    queryFn: () => zoneApi.match(id),
    enabled: id > 0,
    retry: false,
    staleTime: STALE,
  });
export const useZoneStats = (f: ZoneStatsFilter = {}) =>
  useQuery({
    queryKey: ["zone", "stats", f.season ?? null, f.sport ?? null],
    queryFn: () => zoneApi.stats(f),
    staleTime: STALE,
  });
