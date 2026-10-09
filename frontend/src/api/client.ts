import type {
  AttendanceRow,
  CaptionKind,
  CaptionResponse,
  CareerRow,
  Charge,
  ChargeKind,
  Dashboard,
  DocAnswer,
  EventResponse,
  EventType,
  FinanceSummary,
  Friendly,
  FriendlyInput,
  HeadToHead,
  HistoryCompetitionDetail,
  HistoryOverview,
  Id,
  ImportResult,
  KitColor,
  Lineup,
  LoginResponse,
  Match,
  MatchDetail,
  MatchPlayerStat,
  MeResponse,
  Modulistica,
  Payment,
  Player,
  PlayerBalance,
  PlayerCharge,
  PlayerPage,
  PublicHome,
  PublicMatch,
  PublicPlayer,
  ReminderMessage,
  Rsvp,
  ScoutResult,
  StandingRow,
  SyncRun,
  PlayerSyncResult,
  SyncScope,
  TeamEvent,
  LocalImportResult,
  XfiveAdminCheck,
  XfiveAdminStatus,
  User,
} from "./types";

export const TOKEN_KEY = "amir_token";

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

export type Scope = "own" | "all";
export type PlayerInput = Partial<Omit<Player, "id" | "full_name" | "magic_link">>;
export interface EventInput {
  type: Exclude<EventType, "match">;
  title: string;
  starts_at: string | null;
  venue: string | null;
}
export interface StatsInput {
  players: MatchPlayerStat[];
  referee?: string | null;
  man_of_the_match_id?: Id | null;
}
export interface ChargeInput {
  title: string;
  kind: ChargeKind;
  amount_cents: number;
  due_on: string | null;
}
export interface PaymentInput {
  amount_cents: number;
  method: Payment["method"];
  paid_at: string;
  note?: string | null;
}
export type Tone = "epico" | "ironico" | "sobrio";
export interface CaptionInput {
  tone: Tone;
  kind?: CaptionKind;
  notes?: string;
}
export interface CallupsInput {
  players: { player_id: Id; note?: string | null }[];
  published?: boolean;
}
export interface MatchSettings {
  our_kit?: KitColor | null;
  callups_published?: boolean;
  lineup_published?: boolean;
}

export interface ApiClient {
  login(email: string, password: string): Promise<LoginResponse>;
  logout(): Promise<void>;
  me(): Promise<User>;
  // public
  getHome(): Promise<PublicHome>;
  getMatches(p: { competition_id?: Id | undefined; scope?: Scope }): Promise<Match[]>;
  getPublicMatch(id: Id): Promise<PublicMatch>;
  getStandings(competition_id?: Id): Promise<StandingRow[]>;
  getRoster(): Promise<PublicPlayer[]>;
  getPlayerPage(id: Id): Promise<PlayerPage>;
  getCareer(): Promise<CareerRow[]>;
  getHistory(): Promise<HistoryOverview>;
  getHistoryCompetition(id: Id): Promise<HistoryCompetitionDetail>;
  getHeadToHead(team_id: Id): Promise<HeadToHead>;
  calendarIcsUrl(): string;
  // admin
  getDashboard(): Promise<Dashboard>;
  getPlayers(): Promise<Player[]>;
  createPlayer(v: PlayerInput): Promise<Player>;
  updatePlayer(id: Id, v: PlayerInput): Promise<Player>;
  deletePlayer(id: Id): Promise<void>;
  importPlayers(rows: Record<string, string>[]): Promise<ImportResult>;
  /** Scrive e salva la scheda scout del giocatore (IA o modello di testo). */
  generateScout(id: Id): Promise<ScoutResult>;
  /** Salva la scheda scout corretta a mano; un testo vuoto la toglie. */
  saveScout(id: Id, text: string): Promise<Player>;
  /** Salva la sagoma senza sfondo (PNG) per la figurina del giocatore. */
  uploadCutout(id: Id, file: File): Promise<Player>;
  /** Foto caricata dallo staff (JPG o PNG): da qui in poi vale questa, XFive non la tocca più. */
  uploadPhoto(id: Id, file: File): Promise<Player>;
  /** Toglie la foto; non torna da sola dagli aggiornamenti. */
  removePhoto(id: Id): Promise<Player>;
  /** Rilegge da XFive profilo, foto e statistiche di un giocatore; con photo la foto si riscarica anche al posto di una caricata. */
  syncPlayer(id: Id, photo?: boolean): Promise<PlayerSyncResult>;
  getAdminMatches(p: { competition_id?: Id | undefined; scope?: Scope }): Promise<Match[]>;
  getMatchDetail(id: Id): Promise<MatchDetail>;
  updateMatch(id: Id, v: MatchSettings): Promise<MatchDetail>;
  saveCallups(id: Id, v: CallupsInput): Promise<MatchDetail>;
  saveLineup(id: Id, l: Omit<Lineup, "match_id">): Promise<Lineup>;
  saveStats(id: Id, v: StatsInput): Promise<MatchDetail>;
  caption(id: Id, v: CaptionInput): Promise<CaptionResponse>;
  getFriendlies(): Promise<Friendly[]>;
  createFriendly(v: FriendlyInput): Promise<Friendly>;
  updateFriendly(id: Id, v: Partial<FriendlyInput>): Promise<Friendly>;
  deleteFriendly(id: Id): Promise<void>;
  getEvents(): Promise<TeamEvent[]>;
  createEvent(v: EventInput): Promise<TeamEvent>;
  updateEvent(id: Id, v: Partial<EventInput>): Promise<TeamEvent>;
  deleteEvent(id: Id): Promise<void>;
  getEventResponses(id: Id): Promise<EventResponse[]>;
  setEventResponse(
    id: Id,
    player_id: Id,
    v: { rsvp?: Rsvp | null; attended?: boolean | null; note?: string | null },
  ): Promise<EventResponse>;
  remind(id: Id): Promise<ReminderMessage>;
  getCharges(): Promise<Charge[]>;
  createCharge(v: ChargeInput): Promise<Charge>;
  deleteCharge(id: Id): Promise<void>;
  assignCharge(id: Id, v: { player_ids?: Id[]; amount_cents?: number }): Promise<Charge>;
  addPayment(player_charge_id: Id, v: PaymentInput): Promise<PlayerCharge>;
  deletePayment(id: Id): Promise<void>;
  getFinanceSummary(): Promise<FinanceSummary>;
  getPlayerBalance(player_id: Id): Promise<PlayerBalance>;
  getAttendance(season: string): Promise<AttendanceRow[]>;
  getDocuments(): Promise<Modulistica>;
  askDocuments(question: string): Promise<DocAnswer>;
  /** Il file originale di un documento (serve il login, quindi si scarica e si apre da qui). */
  getDocumentFile(slug: string): Promise<Blob>;
  /** Tutti i dati dell'app in un solo file (database SQLite): serve il login. */
  downloadBackup(): Promise<Blob>;
  /** Sostituisce tutti i dati con quelli di un backup; `confirm` deve essere "RIPRISTINA". */
  restoreBackup(file: File, confirm: string): Promise<{ restored: boolean }>;
  /** Porta online le info dei giocatori e i pagamenti del gestionale sul computer (database.sqlite o un backup), senza sostituire nulla. */
  importLocalData(file: File): Promise<LocalImportResult>;
  syncXfive(scope: SyncScope): Promise<SyncRun>;
  getSyncRuns(): Promise<SyncRun[]>;
  getXfiveAdminStatus(): Promise<XfiveAdminStatus>;
  checkXfiveAdmin(): Promise<XfiveAdminCheck>;
  // player link
  getMe(token: string): Promise<MeResponse>;
  setMyRsvp(
    token: string,
    event_id: Id,
    v: { rsvp: Rsvp; note?: string | null },
  ): Promise<TeamEvent>;
}

// ---------------- HTTP implementation ----------------
const BASE =
  (import.meta.env["VITE_API_BASE_URL"] as string | undefined) ?? "http://127.0.0.1:8000/api/v1";
const getToken = () => (typeof window === "undefined" ? null : localStorage.getItem(TOKEN_KEY));

/** Una chiamata JSON al backend che risponde `{ data }`; usata anche dal client della Mixed Zone (zone.ts). */
export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  const token = getToken();
  if (token) headers["Authorization"] = `Bearer ${token}`;
  let res: Response;
  try {
    res = await fetch(`${BASE}${path}`, { ...init, headers });
  } catch {
    // per chi gestisce il sito: l'indirizzo che il sito sta provando è scritto nella console del browser
    console.error(
      `Il sito non raggiunge il server dei dati (${BASE}). Controlla VITE_API_BASE_URL e che il backend sia online.`,
    );
    throw new ApiError(0, "Il server dei dati non risponde. Riprova tra poco.");
  }
  if (res.status === 401) {
    localStorage.removeItem(TOKEN_KEY);
    if (location.pathname.startsWith("/admin") && !location.pathname.startsWith("/admin/login"))
      location.href = "/admin/login";
    throw new ApiError(401, "Sessione scaduta. Accedi di nuovo.");
  }
  if (res.status === 422) {
    const body = (await res.json()) as { message: string; errors?: Record<string, string[]> };
    throw new ApiError(422, body.message, body.errors ?? {});
  }
  if (!res.ok)
    throw new ApiError(
      res.status,
      res.status === 404 ? "Risorsa non trovata." : "Errore del server.",
    );
  if (res.status === 204) return undefined as T;
  const body = (await res.json()) as { data: T };
  return body.data;
}
/** Parametri di ricerca: i valori `undefined` spariscono dall'indirizzo. */
export const qs = (o: Record<string, string | number | boolean | undefined>) => {
  const s = new URLSearchParams();
  Object.entries(o).forEach(([k, v]) => v !== undefined && s.set(k, String(v)));
  const str = s.toString();
  return str ? `?${str}` : "";
};
const post = (body: unknown, method = "POST"): RequestInit => ({
  method,
  body: JSON.stringify(body),
});

/** Invia un file (e qualche campo) a un endpoint che risponde {data}; gli errori di convalida diventano ApiError 422 con i messaggi del server. */
async function postFile<T>(
  path: string,
  fields: Record<string, string | File>,
  failure: string,
): Promise<T> {
  const token = getToken();
  const body = new FormData();
  for (const [name, value] of Object.entries(fields)) body.append(name, value);
  let res: Response;
  try {
    // niente Content-Type: lo mette il browser, con il confine del modulo
    res = await fetch(`${BASE}${path}`, {
      method: "POST",
      headers: {
        Accept: "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body,
    });
  } catch {
    throw new ApiError(0, "Impossibile contattare il server. Controlla la connessione.");
  }
  if (res.status === 401) throw new ApiError(401, "Sessione scaduta. Accedi di nuovo.");
  if (res.status === 422) {
    const b = (await res.json()) as { message: string; errors?: Record<string, string[]> };
    throw new ApiError(422, b.message, b.errors ?? {});
  }
  if (!res.ok) throw new ApiError(res.status, failure);
  return ((await res.json()) as { data: T }).data;
}

export const api: ApiClient = {
  login: (email, password) => request("/auth/login", post({ email, password })),
  logout: () => request("/auth/logout", { method: "POST" }),
  me: () => request("/auth/me"),
  getHome: () => request("/public/home"),
  getMatches: ({ competition_id, scope }) =>
    request(`/public/matches${qs({ competition_id, scope })}`),
  getPublicMatch: (id) => request(`/public/matches/${id}`),
  getStandings: (competition_id) => request(`/public/standings${qs({ competition_id })}`),
  getRoster: () => request("/public/roster"),
  getPlayerPage: (id) => request(`/public/players/${id}`),
  getCareer: () => request("/public/stats/career"),
  getHistory: () => request("/public/history"),
  getHistoryCompetition: (id) => request(`/public/history/competitions/${id}`),
  getHeadToHead: (id) => request(`/public/head-to-head/${id}`),
  calendarIcsUrl: () => `${BASE.replace(/^https?:/, "webcal:")}/public/calendar.ics`,
  getDashboard: () => request("/dashboard"),
  getPlayers: () => request("/players"),
  createPlayer: (v) => request("/players", post(v)),
  updatePlayer: (id, v) => request(`/players/${id}`, post(v, "PATCH")),
  deletePlayer: (id) => request(`/players/${id}`, { method: "DELETE" }),
  importPlayers: (rows) => request("/players/import", post({ rows })),
  generateScout: (id) => request(`/players/${id}/scout`, { method: "POST" }),
  saveScout: (id, text) => request(`/players/${id}/scout`, post({ text }, "PUT")),
  uploadCutout: (id, file) => postFile(`/players/${id}/cutout`, { file }, "Sagoma non salvata."),
  uploadPhoto: (id, file) => postFile(`/players/${id}/photo`, { file }, "Foto non salvata."),
  removePhoto: (id) => request(`/players/${id}/photo`, { method: "DELETE" }),
  syncPlayer: (id, photo = false) => request(`/players/${id}/sync`, post({ photo })),
  getAdminMatches: ({ competition_id, scope }) =>
    request(`/matches${qs({ competition_id, scope })}`),
  getMatchDetail: (id) => request(`/matches/${id}`),
  updateMatch: (id, v) => request(`/matches/${id}`, post(v, "PATCH")),
  saveCallups: (id, v) => request(`/matches/${id}/callups`, post(v, "PUT")),
  saveLineup: (id, l) => request(`/matches/${id}/lineup`, post(l, "PUT")),
  saveStats: (id, v) => request(`/matches/${id}/stats`, post(v, "PUT")),
  caption: (id, v) => request(`/matches/${id}/caption`, post(v)),
  getFriendlies: () => request("/friendlies"),
  createFriendly: (v) => request("/friendlies", post(v)),
  updateFriendly: (id, v) => request(`/friendlies/${id}`, post(v, "PUT")),
  deleteFriendly: (id) => request(`/friendlies/${id}`, { method: "DELETE" }),
  getEvents: () => request("/events"),
  createEvent: (v) => request("/events", post(v)),
  updateEvent: (id, v) => request(`/events/${id}`, post(v, "PATCH")),
  deleteEvent: (id) => request(`/events/${id}`, { method: "DELETE" }),
  getEventResponses: (id) => request(`/events/${id}/responses`),
  setEventResponse: (id, pid, v) => request(`/events/${id}/responses/${pid}`, post(v, "PUT")),
  remind: (id) => request(`/events/${id}/remind`, { method: "POST" }),
  getCharges: () => request("/charges"),
  createCharge: (v) => request("/charges", post(v)),
  deleteCharge: (id) => request(`/charges/${id}`, { method: "DELETE" }),
  assignCharge: (id, v) => request(`/charges/${id}/assign`, post(v)),
  addPayment: (id, v) => request(`/player-charges/${id}/payments`, post(v)),
  deletePayment: (id) => request(`/payments/${id}`, { method: "DELETE" }),
  getFinanceSummary: () => request("/finance/summary"),
  getPlayerBalance: (id) => request(`/finance/players/${id}`),
  getAttendance: (season) => request(`/stats/attendance${qs({ season })}`),
  getDocuments: () => request("/documents"),
  askDocuments: (question) => request("/documents/ask", post({ question })),
  getDocumentFile: async (slug) => {
    const token = getToken();
    let res: Response;
    try {
      res = await fetch(`${BASE}/documents/${encodeURIComponent(slug)}/file`, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
    } catch {
      throw new ApiError(0, "Impossibile contattare il server. Controlla la connessione.");
    }
    if (res.status === 401) throw new ApiError(401, "Sessione scaduta. Accedi di nuovo.");
    if (!res.ok)
      throw new ApiError(
        res.status,
        res.status === 404 ? "File non trovato." : "Errore del server.",
      );
    return res.blob();
  },
  downloadBackup: async () => {
    const token = getToken();
    let res: Response;
    try {
      res = await fetch(`${BASE}/backup`, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
    } catch {
      throw new ApiError(0, "Impossibile contattare il server. Controlla la connessione.");
    }
    if (res.status === 401) throw new ApiError(401, "Sessione scaduta. Accedi di nuovo.");
    if (!res.ok) throw new ApiError(res.status, "Non riesco a preparare il backup.");
    return res.blob();
  },
  restoreBackup: (file, confirm) =>
    postFile("/backup/restore", { file, confirm }, "Ripristino non riuscito."),
  importLocalData: (file) =>
    postFile("/backup/import-local", { file }, "Importazione non riuscita."),
  syncXfive: (scope) => request("/sync/xfive", post({ scope })),
  getSyncRuns: () => request("/sync/runs"),
  getXfiveAdminStatus: () => request("/xfive-admin"),
  checkXfiveAdmin: () => request("/xfive-admin/check", post({})),
  getMe: (token) => request(`/me/${token}`),
  setMyRsvp: (token, eid, v) => request(`/me/${token}/events/${eid}/rsvp`, post(v)),
};
