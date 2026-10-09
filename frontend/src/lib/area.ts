import { BarChart3, CalendarDays, Home, Shield, Trophy, Users } from "lucide-react";

/**
 * Le tre aree del sito: Amir Hub (il sito di oggi, rosso), Mixed Zone (tutto XFive, ciano su grafite)
 * e Staff Area (ambra). L'area si ricava dall'indirizzo e finisce in `data-area` su `<html>`, da cui
 * il tema (styles.css) prende i colori.
 */
export type AreaId = "hub" | "mixed" | "staff";

export interface Area {
  id: AreaId;
  name: string;
  tagline: string;
  /** Tre voci che danno l'idea di cosa c'è dentro (nel selettore di area). */
  preview: [string, string, string];
  /** La pagina d'ingresso dell'area. */
  entry: string;
  /** Il colore che la distingue (per portale, carte e particelle). */
  accent: string;
}

export const AREAS: Record<AreaId, Area> = {
  hub: {
    id: "hub",
    name: "Amir Hub",
    tagline: "La casa di AMIR COSTRUZIONI",
    preview: ["Calendario", "Rosa", "Storico"],
    entry: "/",
    accent: "#d4342c",
  },
  mixed: {
    id: "mixed",
    name: "Mixed Zone",
    tagline: "Tutto XFive Alessandria",
    preview: ["Tornei", "Squadre", "Statistiche"],
    entry: "/mixed-zone",
    accent: "#22d3ee",
  },
  staff: {
    id: "staff",
    name: "Staff Area",
    tagline: "Gestione della squadra",
    preview: ["Convocazioni", "Quote", "Grafiche"],
    entry: "/admin",
    accent: "#f59e0b",
  },
};

/** L'ordine in cui le aree si presentano nel selettore. */
export const AREA_ORDER: AreaId[] = ["hub", "mixed", "staff"];

/** Il club AMIR COSTRUZIONI su XFive (per i rimandi fra Mixed Zone e Amir Hub). */
export const OWN_CLUB_ID = 159;

const under = (pathname: string, base: string) =>
  pathname === base || pathname.startsWith(`${base}/`);

/** A quale area appartiene un indirizzo: `/admin...` staff, `/mixed-zone...` mixed, tutto il resto (anche `/p/...`) hub. */
export function areaOf(pathname: string): AreaId {
  if (under(pathname, "/admin")) return "staff";
  if (under(pathname, "/mixed-zone")) return "mixed";
  return "hub";
}

/** Il menu della Mixed Zone (stessa forma di quello di Amir Hub in AppShell). */
export const ZONE_NAV = [
  { to: "/mixed-zone", label: "Home", icon: Home },
  { to: "/mixed-zone/tornei", label: "Tornei", icon: Trophy },
  { to: "/mixed-zone/squadre", label: "Squadre", icon: Shield },
  { to: "/mixed-zone/giocatori", label: "Giocatori", icon: Users },
  { to: "/mixed-zone/partite", label: "Partite", icon: CalendarDays },
  { to: "/mixed-zone/statistiche", label: "Statistiche", icon: BarChart3 },
] as const;
