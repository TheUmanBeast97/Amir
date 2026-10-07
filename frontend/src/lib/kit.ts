import type { KitColor, PublicPlayer } from "@/api/types";

export const KIT_LABEL: Record<KitColor, string> = { red: "Rossa", white: "Bianca" };

/** Colori della pastiglia con il numero: rosso pieno con testo bianco, oppure bianco con bordo rosso. */
export const KIT_STYLE: Record<KitColor, { bg: string; fg: string; ring: string }> = {
  red: { bg: "#D61F26", fg: "#FFFFFF", ring: "#FFFFFF" },
  white: { bg: "#F4F4F5", fg: "#111113", ring: "#D61F26" },
};

type ShirtFields = Pick<PublicPlayer, "shirt_number" | "shirt_number_red" | "shirt_number_white">;

/**
 * Numero di maglia con la divisa indicata. Un giocatore può averne due diversi (rosso e bianco):
 * se quello richiesto manca si ripiega sull'altro, così in campo compare sempre qualcosa.
 */
export function shirtFor(p: ShirtFields, kit: KitColor | null | undefined): string | null {
  const red = p.shirt_number_red;
  const white = p.shirt_number_white;
  if (kit === "red") return red ?? p.shirt_number ?? white;
  if (kit === "white") return white ?? p.shirt_number ?? red;
  return p.shirt_number ?? red ?? white;
}

/** Come chiamare un giocatore sulle etichette strette (campo, grafiche): il soprannome, altrimenti l'ultima parola. */
export function shortName(p: Pick<PublicPlayer, "full_name" | "nickname">): string {
  if (p.nickname) return p.nickname;
  const parts = p.full_name.trim().split(/\s+/);
  return parts.length > 1 ? (parts.at(-1) ?? p.full_name) : p.full_name;
}

export const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .map((w) => w[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();
