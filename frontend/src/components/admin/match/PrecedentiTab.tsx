import { Shirt } from "lucide-react";
import type { MatchDetail, Team } from "@/api/types";
import { HeadToHead } from "@/components/HeadToHead";
import { Card } from "@/components/ui-kit";
import { opponent } from "@/lib/format";

const rgb = (hex: string) => {
  const h = hex.replace("#", "");
  const n = parseInt(h.length === 3 ? h.split("").map((c) => c + c).join("") : h, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255] as const;
};
/** Weighted RGB distance (0..~765). */
export function colorDistance(a: string, b: string) {
  const [r1, g1, b1] = rgb(a);
  const [r2, g2, b2] = rgb(b);
  const rm = (r1 + r2) / 2;
  return Math.sqrt((2 + rm / 256) * (r1 - r2) ** 2 + 4 * (g1 - g2) ** 2 + (2 + (255 - rm) / 256) * (b1 - b2) ** 2);
}
export function suggestKit(us: Team, them: Team): { kit: "home" | "away"; color: string | null; reason: string } {
  const home = us.kit1_color, away = us.kit2_color, theirs = them.kit1_color;
  if (!home || !theirs) return { kit: "home", color: home, reason: "Colori dell'avversario non disponibili: usa la prima maglia." };
  const close = colorDistance(home, theirs) < 150;
  if (close && away) return { kit: "away", color: away, reason: `La prima maglia di ${them.name} è simile alla nostra: meglio la seconda maglia.` };
  return { kit: "home", color: home, reason: `Colori ben distinti da ${them.name}: vai con la prima maglia.` };
}

export function PrecedentiTab({ detail }: { detail: MatchDetail }) {
  const m = detail.match;
  const us = m.home_team.is_own ? m.home_team : m.away_team;
  const opp = opponent(m);
  const s = suggestKit(us, opp);
  return (
    <div className="space-y-4 pt-3">
      <Card>
        <h2 className="mb-2 flex items-center gap-2 text-2xl"><Shirt className="h-5 w-5" /> Maglia consigliata</h2>
        <div className="flex items-center gap-3">
          <span className="h-12 w-12 shrink-0 rounded-lg border-2" style={{ background: s.color ?? undefined }} aria-hidden />
          <div>
            <div className="font-display text-xl">{s.kit === "home" ? "Prima maglia" : "Seconda maglia"}</div>
            <p className="text-sm text-muted-foreground">{s.reason}</p>
          </div>
          {opp.kit1_color && <span className="ml-auto h-8 w-8 shrink-0 rounded-md border" style={{ background: opp.kit1_color }} title={`Maglia ${opp.name}`} />}
        </div>
      </Card>
      <HeadToHead teamId={opp.id} />
    </div>
  );
}
