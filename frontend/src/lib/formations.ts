import type { Format, LineupSlot } from "@/api/types";

export const FORMATIONS: Record<Format, { name: string }[]> = {
  8: ["3-3-1", "3-2-2", "2-3-2", "3-1-3", "2-4-1"].map((name) => ({ name })),
  7: ["2-3-1", "3-2-1", "2-2-2", "3-1-2"].map((name) => ({ name })),
};

const lineLabels = (lines: number, idx: number) =>
  idx === 0 ? "DIF" : idx === lines - 1 ? "ATT" : "CC";

/** Empty slots for a formation. Slot 1 is always the goalkeeper; y = 0 is our goal. */
export function slotsFor(formation: string): LineupSlot[] {
  const lines = formation.split("-").map(Number);
  const slots: LineupSlot[] = [{ slot: 1, player_id: null, label: "POR", x: 50, y: 8 }];
  lines.forEach((count, li) => {
    const y = 28 + (li * 60) / Math.max(1, lines.length - 1);
    for (let i = 0; i < count; i++) {
      slots.push({ slot: slots.length + 1, player_id: null, label: lineLabels(lines.length, li), x: Math.round(((i + 1) * 100) / (count + 1)), y: Math.round(y) });
    }
  });
  return slots;
}
