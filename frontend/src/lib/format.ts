import type {
  ChargeKind,
  CompetitionKind,
  Match,
  Payment,
  PlayerRole,
  RegistrationStatus,
} from "@/api/types";

const TZ = "Europe/Rome";
const eur = new Intl.NumberFormat("it-IT", { style: "currency", currency: "EUR" });
export const money = (cents: number) => eur.format(cents / 100);

export const fmtDate = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", {
    timeZone: TZ,
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
export const fmtDay = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", {
    timeZone: TZ,
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(new Date(iso.length === 10 ? `${iso}T12:00:00` : iso));
export const fmtDateLong = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", {
    timeZone: TZ,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(iso));
export const fmtTime = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", { timeZone: TZ, hour: "2-digit", minute: "2-digit" }).format(
    new Date(iso),
  );
export const fmtDateTime = (iso: string | null) =>
  iso ? `${fmtDateLong(iso)} · ${fmtTime(iso)}` : "Data da definire";

export type Outcome = "W" | "D" | "L";
export function outcome(m: Match): Outcome | null {
  if (m.result) return m.result;
  if (m.home_score === null || m.away_score === null || !m.is_own_match) return null;
  const us = m.home_team.is_own ? m.home_score : m.away_score;
  const them = m.home_team.is_own ? m.away_score : m.home_score;
  return us > them ? "W" : us === them ? "D" : "L";
}
export const outcomeOf = (us: number, them: number): Outcome =>
  us > them ? "W" : us === them ? "D" : "L";
export const outcomeLabel: Record<Outcome, string> = { W: "V", D: "N", L: "P" };
export const opponent = (m: Match) => (m.home_team.is_own ? m.away_team : m.home_team);

export const roleLabel: Record<PlayerRole, string> = {
  portiere: "Portiere",
  difensore: "Difensore",
  centrocampista: "Centrocampista",
  attaccante: "Attaccante",
  dirigente: "Dirigente",
  allenatore: "Allenatore",
};
export const regLabel: Record<RegistrationStatus, string> = {
  none: "Da richiedere",
  pending: "In attesa",
  approved: "Approvato",
};
export const chargeKindLabel: Record<ChargeKind, string> = {
  quota_stagione: "Quota stagione",
  tesseramento: "Tesseramento",
  multa: "Multa",
  arbitro: "Arbitro",
  cena: "Cena",
  altro: "Altro",
};
export const methodLabel: Record<Payment["method"], string> = {
  contanti: "Contanti",
  satispay: "Satispay",
  paypal: "PayPal",
  revolut: "Revolut",
  bonifico: "Bonifico",
  altro: "Altro",
};
export const todayISO = () => new Date().toISOString().slice(0, 10);

export const kindLabel: Record<CompetitionKind, string> = {
  campionato: "Campionato",
  coppa: "Coppa",
  coppa_lega: "Coppa di Lega",
  coppa_categoria: "Coppa di Categoria",
  torneo: "Torneo",
  amichevole: "Amichevole",
};
/** "15 ott 2026": per i registri partite. */
export const fmtShortDate = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", {
    timeZone: "UTC",
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${iso.slice(0, 10)}T12:00:00Z`));
export const pct = (n: number, digits = 0) => `${(n * 100).toFixed(digits)}%`;
