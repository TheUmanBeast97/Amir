import { Link } from "@tanstack/react-router";
import type { Id, KitColor, PublicSheet, PublicSheetPlayer } from "@/api/types";
import { Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { SheetPdfButton } from "@/components/SheetPdfButton";
import { KIT_LABEL, KIT_STYLE } from "@/lib/kit";
import { cn } from "@/lib/utils";

/** Il numero grande nella tinta della divisa, con il bordo inclinato come un tabellone. */
function NumberPlate({ number, kit }: { number: string; kit: KitColor | null }) {
  const s = KIT_STYLE[kit ?? "red"];
  return (
    <span
      className="relative grid w-[4.25rem] shrink-0 place-items-center self-stretch pr-2 font-display text-[2.6rem] leading-none num md:w-24 md:text-6xl"
      style={{
        background: s.bg,
        color: s.fg,
        clipPath: "polygon(0 0, 100% 0, calc(100% - 14px) 100%, 0 100%)",
      }}
    >
      {number}
    </span>
  );
}

function SheetRow({ row, kit, i }: { row: PublicSheetPlayer; kit: KitColor | null; i: number }) {
  const body = (
    <>
      <NumberPlate number={row.number} kit={kit} />
      {row.player ? (
        <PlayerPhoto player={row.player} size={52} className="ml-3 border border-white/15" />
      ) : (
        <span aria-hidden className="ml-3 h-[52px] w-[52px] shrink-0 rounded-full bg-white/5" />
      )}
      <span className="min-w-0 flex-1 px-3 py-2.5">
        <span className="block truncate font-display text-xl leading-tight text-white md:text-3xl">
          {row.player?.full_name ?? row.name}
        </span>
        {row.player?.nickname && (
          <span className="block truncate text-[11px] font-semibold uppercase tracking-[0.2em] text-white/55">
            {row.player.nickname}
          </span>
        )}
      </span>
    </>
  );
  const cls =
    "group flex items-stretch overflow-hidden rounded-lg border border-white/10 bg-white/[0.04] transition-colors";
  return (
    <Reveal as="li" i={i} x={-24} y={0}>
      {row.player ? (
        <Link
          to="/rosa/$playerId"
          params={{ playerId: String(row.player.id) }}
          className={cn(cls, "lift hover:border-white/30 hover:bg-white/[0.09]")}
        >
          {body}
        </Link>
      ) : (
        <div className={cls}>{body}</div>
      )}
    </Reveal>
  );
}

/** La distinta ufficiale inviata a XFive, in ordine di numero: sostituisce i convocati sul sito. */
export function OfficialSheet({
  sheet,
  kit,
  matchId,
}: {
  sheet: PublicSheet;
  kit: KitColor | null;
  matchId: Id;
}) {
  return (
    <section
      aria-labelledby="sheet-title"
      className="card-cut relative isolate overflow-hidden rounded-xl bg-[#0b0b0d] p-4 text-white md:p-8"
    >
      <div
        aria-hidden
        className="absolute inset-0 -z-10 bg-[radial-gradient(120%_80%_at_0%_0%,rgba(214,31,38,0.35),transparent_55%)]"
      />
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="text-xs font-bold uppercase tracking-[0.25em] text-primary">
            Ufficiale · {sheet.players.length} giocatori
            {kit ? ` · Divisa ${KIT_LABEL[kit].toLowerCase()}` : ""}
          </div>
          <h2 id="sheet-title" className="font-display text-4xl leading-none md:text-6xl">
            Distinta
          </h2>
        </div>
        <SheetPdfButton matchId={matchId} />
      </header>
      <ol className="grid gap-2 md:grid-cols-2 md:gap-3">
        {sheet.players.map((row, i) => (
          <SheetRow key={`${row.number}-${row.name}`} row={row} kit={kit} i={i} />
        ))}
      </ol>
    </section>
  );
}
