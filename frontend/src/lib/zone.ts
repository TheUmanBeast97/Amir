import type { ZoneId, ZoneMatch, ZoneTournament } from "@/api/zone-types";
import { fmtDateTime, type Outcome } from "./format";

/**
 * Piccole trasformazioni dei dati XFive per le pagine della Mixed Zone: etichette, date, esiti.
 * Qui non c'è grafica, così si provano con vitest.
 */

/** «Calcio a 8 - Maschile» → «Calcio a 8»; il genere resta solo se non è quello maschile. */
export function sportLabel(sport: string): string {
  const [name = sport, gender] = sport.split(" - ").map((s) => s.trim());
  if (!gender || gender.toLowerCase() === "maschile") return name;
  return `${name} · ${gender}`;
}

/** «2026/2027» → «26/27», per le etichette strette (grafici, chip). */
export const seasonShort = (season: string) => season.replace(/^20(\d\d)\/20(\d\d)$/, "$1/$2");

/**
 * Quando si gioca: la data completa se c'è, altrimenti quello che XFive ha scritto («lun 12/10 21:00»),
 * altrimenti «Data da definire».
 */
export function kickoffLabel(m: Pick<ZoneMatch, "kickoff_at" | "kickoff_raw">): string {
  if (m.kickoff_at) return fmtDateTime(m.kickoff_at);
  return m.kickoff_raw?.trim() || "Data da definire";
}

/** Lo stato di un torneo, in italiano e con il colore del tema (mai rosso esplicito). */
export const TOURNAMENT_STATUS: Record<ZoneTournament["status"], { label: string; cls: string }> = {
  ongoing: { label: "In corso", cls: "bg-success/15 text-success" },
  incoming: { label: "In arrivo", cls: "bg-primary/15 text-primary" },
  previous: { label: "Concluso", cls: "bg-muted text-muted-foreground" },
};

/** Ordine dei tornei in una lista: prima quelli in corso, poi in arrivo, infine conclusi. */
export const statusOrder: Record<ZoneTournament["status"], number> = {
  ongoing: 0,
  incoming: 1,
  previous: 2,
};

/** Testo senza accenti e minuscolo, per cercare «caffe» e trovare «CAFFÈ». */
export const normalize = (s: string) =>
  s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/\s+/g, " ").trim();

/** Le iniziali di un club per lo stemma di riserva («CAFFÈ KM0-PALESTRA MEETING» → «CK»). */
export function clubInitials(name: string): string {
  const words = name
    .replace(/[^\p{L}\p{N} ]/gu, " ")
    .split(/\s+/)
    .filter(Boolean);
  return words
    .slice(0, 2)
    .map((w) => w[0] ?? "")
    .join("")
    .toUpperCase();
}

/** L'esito di una partita giocata dal punto di vista di un club; null se non è giocata o il club non c'entra. */
export function outcomeFor(m: ZoneMatch, clubId: ZoneId | null | undefined): Outcome | null {
  if (!clubId || !m.played || m.home_score === null || m.away_score === null) return null;
  const home = m.home.id === clubId;
  const away = m.away.id === clubId;
  if (!home && !away) return null;
  const us = home ? m.home_score : m.away_score;
  const them = home ? m.away_score : m.home_score;
  return us > them ? "W" : us === them ? "D" : "L";
}

/** Colori della forma (V, N, P) nella Mixed Zone: niente rosso, la sconfitta è grigia. */
export const FORM_COLOR: Record<Outcome, string> = {
  W: "var(--success)",
  D: "var(--warning)",
  L: "color-mix(in oklab, var(--foreground) 35%, transparent)",
};

/** Oggi nel fuso di Roma, come «AAAA-MM-GG» (le partite si cercano per giorno). */
export const todayRome = () =>
  new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Rome" }).format(new Date());

/** Sposta una data «AAAA-MM-GG» di n giorni (anche negativi), senza problemi di fuso. */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  const t = Date.UTC(y ?? 1970, (m ?? 1) - 1, (d ?? 1) + n);
  return new Date(t).toISOString().slice(0, 10);
}

/** «AAAA-MM-GG» → «lunedì 12 ottobre» per le intestazioni dei giorni. */
export const dayLabel = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", {
    timeZone: "UTC",
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(`${iso}T12:00:00Z`));

/** Il nome di un giocatore come lo scrive XFive («Fracchia Edoardo Giovanni»): per le etichette strette resta il cognome. */
export const surnameOf = (name: string) => name.trim().split(/\s+/)[0] ?? name;
