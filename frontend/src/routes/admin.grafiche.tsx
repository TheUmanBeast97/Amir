import { createFileRoute } from "@tanstack/react-router";
import { Copy, Download, Share2, Sparkles } from "lucide-react";
import { useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { toPng } from "html-to-image";
import {
  useCaption,
  useCareer,
  useHeadToHead,
  useHome,
  useMatchDetail,
  usePlayers,
} from "@/api/hooks";
import type { Tone } from "@/api/client";
import type {
  CaptionKind,
  CaptionResponse,
  KitColor,
  LineupSlot,
  Match,
  Player,
} from "@/api/types";
import { Card, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Btn, Field, TextInput, copyText, inputCls, toastError } from "@/components/admin/kit";
import { fmtDateLong, fmtTime, kindLabel, opponent } from "@/lib/format";
import { slotsFor } from "@/lib/formations";
import { KIT_STYLE, initials, shirtFor, shortName } from "@/lib/kit";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/grafiche")({
  head: () => ({
    meta: [
      { title: "Studio grafiche - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Crea immagini da condividere: match day, risultati, formazione, classifica e altro, con didascalie scritte dall'IA.",
      },
      { property: "og:title", content: "Studio grafiche - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Immagini social con i dati veri della squadra." },
    ],
  }),
  component: Studio,
});

const TEMPLATES = [
  ["matchday", "Match Day"],
  ["risultato", "Risultato"],
  ["formazione", "Formazione ufficiale"],
  ["motm", "Uomo partita"],
  ["classifica", "Classifica"],
  ["precedenti", "Precedenti"],
  ["marcatori", "Marcatori"],
  ["multa", "Multa del mese"],
] as const satisfies readonly (readonly [CaptionKind, string])[];
type TplId = (typeof TEMPLATES)[number][0];
const SIZES = { "1:1": [1080, 1080], "4:5": [1080, 1350], "9:16": [1080, 1920] } as const;
type SizeId = keyof typeof SIZES;

/** Le grafiche usano sempre lo sfondo stadio, con un velo scuro per tenere leggibile il testo. */
const BACKDROP =
  "linear-gradient(180deg, rgba(0,0,0,.45) 0%, rgba(0,0,0,.18) 38%, rgba(0,0,0,.62) 100%), url(/sfondo_match.jpg)";

/** Intestazione di ogni grafica: "CAMPIONATO / LA CITTADELLA XFIVE" con il logo di XFive. */
function headline(c: { name: string; kind: Match["competition"]["kind"] } | null): {
  top: string;
  main: string;
} {
  if (!c) return { top: "Campionato", main: "XFive Alessandria" };
  const clean = c.name
    .replace(/\[.*?\]/g, "")
    .replace(/\s+/g, " ")
    .trim()
    .toUpperCase();
  if (c.kind === "campionato") return { top: "Campionato", main: `LA ${clean} XFIVE` };
  if (c.kind === "amichevole") return { top: "Partita amichevole", main: "AMIR COSTRUZIONI" };
  return { top: kindLabel[c.kind], main: clean };
}

function Badge({ team, size }: { team: Match["home_team"]; size: number }) {
  return team.badge_url ? (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: "50%",
        background: "#fff",
        padding: size * 0.07,
        boxShadow: "0 12px 40px rgba(0,0,0,.55)",
      }}
    >
      <img
        src={team.badge_url}
        alt=""
        crossOrigin="anonymous"
        style={{ width: "100%", height: "100%", objectFit: "contain" }}
      />
    </div>
  ) : (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: "50%",
        display: "grid",
        placeItems: "center",
        fontSize: size * 0.36,
        border: "6px solid rgba(255,255,255,.85)",
        background: team.kit1_color ?? "#333",
        color: team.kit1_color && /^#(f|e|d)/i.test(team.kit1_color) ? "#111" : "#fff",
      }}
      className="font-display"
    >
      {initials(team.name)}
    </div>
  );
}

function Face({ p, size, kit }: { p: Player; size: number; kit: KitColor | null }) {
  const num = shirtFor(p, kit);
  const s = KIT_STYLE[kit ?? "red"];
  return (
    <div style={{ position: "relative", width: size, height: size }}>
      {p.photo_url ? (
        <img
          src={p.photo_url}
          alt=""
          crossOrigin="anonymous"
          style={{
            width: size,
            height: size,
            borderRadius: "50%",
            objectFit: "cover",
            objectPosition: "top",
            border: `${Math.max(4, size / 24)}px solid #fff`,
            boxShadow: "0 10px 30px rgba(0,0,0,.6)",
            background: "#222",
          }}
        />
      ) : (
        <div
          className="font-display"
          style={{
            width: size,
            height: size,
            borderRadius: "50%",
            display: "grid",
            placeItems: "center",
            fontSize: size * 0.38,
            background: "#2a2a2f",
            border: `${Math.max(4, size / 24)}px solid #fff`,
          }}
        >
          {initials(p.full_name)}
        </div>
      )}
      {num && (
        <div
          className="font-display"
          style={{
            position: "absolute",
            right: -size * 0.04,
            bottom: -size * 0.02,
            width: size * 0.36,
            height: size * 0.36,
            borderRadius: "50%",
            display: "grid",
            placeItems: "center",
            fontSize: size * 0.2,
            background: s.bg,
            color: s.fg,
            boxShadow: `0 0 0 ${size / 40}px ${s.ring}`,
          }}
        >
          {num}
        </div>
      )}
    </div>
  );
}

function Poster({
  w,
  h,
  head,
  title,
  children,
}: {
  w: number;
  h: number;
  head: { top: string; main: string };
  title: string;
  children: ReactNode;
}) {
  return (
    <div
      style={{
        width: w,
        height: h,
        backgroundColor: "#0b0b0d",
        backgroundImage: BACKDROP,
        backgroundSize: "cover",
        backgroundPosition: "center",
        color: "#fff",
        padding: 80,
        display: "flex",
        flexDirection: "column",
        fontFamily: "Inter, sans-serif",
        position: "relative",
        overflow: "hidden",
      }}
    >
      <div style={{ display: "flex", alignItems: "center", gap: 30 }}>
        <img
          src="/xfive-logo.png"
          alt=""
          crossOrigin="anonymous"
          style={{ height: 150, width: "auto", filter: "drop-shadow(0 4px 18px rgba(0,0,0,.6))" }}
        />
        <div>
          <div
            style={{
              fontSize: 28,
              fontWeight: 700,
              letterSpacing: 12,
              textTransform: "uppercase",
              opacity: 0.85,
            }}
          >
            {head.top}
          </div>
          <div
            className="font-display"
            style={{
              fontSize: head.main.length > 22 ? 52 : 70,
              lineHeight: 1.02,
              textTransform: "uppercase",
            }}
          >
            {head.main}
          </div>
        </div>
      </div>
      <div
        className="font-display"
        style={{
          marginTop: 56,
          fontSize: 130,
          lineHeight: 0.95,
          textTransform: "uppercase",
          textShadow: "0 6px 30px rgba(0,0,0,.6)",
        }}
      >
        {title}
      </div>
      <div
        style={{
          flex: 1,
          display: "flex",
          flexDirection: "column",
          justifyContent: "center",
          marginTop: 30,
        }}
      >
        {children}
      </div>
      <img
        src="/stemma-amir.png"
        alt=""
        style={{
          position: "absolute",
          right: 56,
          bottom: 48,
          width: 120,
          filter: "drop-shadow(0 4px 14px rgba(0,0,0,.6))",
        }}
      />
    </div>
  );
}

function Versus({ m, score }: { m: Match; score?: boolean }) {
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: "1fr auto 1fr",
        alignItems: "center",
        gap: 30,
        textAlign: "center",
      }}
    >
      {[m.home_team, m.away_team].map((t, i) => (
        <div
          key={t.id}
          style={{
            gridColumn: i === 0 ? 1 : 3,
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            gap: 20,
          }}
        >
          <Badge team={t} size={230} />
          <div
            className="font-display"
            style={{ fontSize: 46, lineHeight: 1, textShadow: "0 4px 18px rgba(0,0,0,.7)" }}
          >
            {t.name}
          </div>
        </div>
      ))}
      <div
        className="font-display"
        style={{
          gridColumn: 2,
          gridRow: 1,
          fontSize: score ? 160 : 90,
          textShadow: "0 6px 30px rgba(0,0,0,.7)",
        }}
      >
        {score ? `${m.home_score ?? 0}-${m.away_score ?? 0}` : "VS"}
      </div>
    </div>
  );
}

/** Formazione a righe (portiere, difesa, centrocampo, attacco) con le foto: si adatta a ogni formato. */
function LineupBoard({
  slots,
  players,
  kit,
}: {
  slots: LineupSlot[];
  players: Player[];
  kit: KitColor | null;
}) {
  const rows = [...new Set(slots.map((s) => s.y))]
    .sort((a, b) => b - a)
    .map((y) => slots.filter((s) => s.y === y).sort((a, b) => a.x - b.x));
  const face = rows.length >= 5 ? 150 : 175;
  return (
    <div
      style={{
        display: "flex",
        flexDirection: "column",
        justifyContent: "space-around",
        gap: 26,
        flex: 1,
        borderRadius: 34,
        padding: "30px 20px",
        background: "linear-gradient(rgba(14,76,36,.55), rgba(14,76,36,.35))",
        border: "4px solid rgba(255,255,255,.35)",
      }}
    >
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: "flex", justifyContent: "space-evenly", alignItems: "flex-start" }}
        >
          {row.map((s) => {
            const p = players.find((x) => x.id === s.player_id);
            return (
              <div
                key={s.slot}
                style={{
                  display: "flex",
                  flexDirection: "column",
                  alignItems: "center",
                  width: 230,
                }}
              >
                {p ? (
                  <Face p={p} size={face} kit={kit} />
                ) : (
                  <div
                    style={{
                      width: face,
                      height: face,
                      borderRadius: "50%",
                      border: "5px dashed rgba(255,255,255,.6)",
                      display: "grid",
                      placeItems: "center",
                      fontSize: 40,
                    }}
                  >
                    {s.label}
                  </div>
                )}
                <div
                  style={{
                    marginTop: 14,
                    fontSize: 36,
                    fontWeight: 800,
                    textTransform: "uppercase",
                    background: "rgba(0,0,0,.65)",
                    padding: "2px 18px",
                    borderRadius: 999,
                    maxWidth: 230,
                    overflow: "hidden",
                    textOverflow: "ellipsis",
                    whiteSpace: "nowrap",
                  }}
                >
                  {p ? shortName(p) : "-"}
                </div>
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}

function Studio() {
  const home = useHome();
  const players = usePlayers();
  const career = useCareer();
  const [tpl, setTpl] = useState<TplId>("matchday");
  const [size, setSize] = useState<SizeId>("1:1");
  const [fineName, setFineName] = useState("");
  const [fineText, setFineText] = useState(
    "Per essersi presentato con due scarpe diverse. Sinistra da calcetto, destra da tennis.",
  );
  const [tone, setTone] = useState<Tone>("epico");
  const [notes, setNotes] = useState("");
  const [caption, setCaption] = useState("");
  const [capInfo, setCapInfo] = useState<Pick<CaptionResponse, "source" | "model" | "note"> | null>(
    null,
  );
  const cap = useCaption();
  const node = useRef<HTMLDivElement>(null);

  const next = home.data?.next_match ?? null;
  const last = home.data?.last_match ?? null;
  const detail = useMatchDetail(last?.id ?? 0);
  const nextDetail = useMatchDetail(next?.id ?? 0);
  const h2h = useHeadToHead(next ? opponent(next).id : undefined);
  const [W, H] = SIZES[size];
  const all = players.data ?? [];
  const byId = (id: number | null) => all.find((x) => x.id === id);
  const name = (id: number | null) => {
    const p = byId(id);
    return p ? (p.nickname ?? p.full_name) : "-";
  };
  const useNext = tpl === "matchday" || tpl === "precedenti" || tpl === "formazione";
  const captionMatch = (useNext ? next : last) ?? next ?? last;
  const comp =
    (useNext ? (next ?? last) : (last ?? next))?.competition ?? home.data?.competition ?? null;
  const head = headline(comp);

  const render = (): ReactNode => {
    if (home.isPending) return null;
    switch (tpl) {
      case "matchday":
        return next ? (
          <Poster w={W} h={H} head={head} title="Match Day">
            <Versus m={next} />
            <div
              style={{
                textAlign: "center",
                marginTop: 60,
                fontSize: 40,
                textShadow: "0 4px 18px rgba(0,0,0,.7)",
              }}
            >
              <div className="font-display" style={{ fontSize: 76, textTransform: "capitalize" }}>
                {next.kickoff_at
                  ? `${fmtDateLong(next.kickoff_at)} · ${fmtTime(next.kickoff_at)}`
                  : "Data da definire"}
              </div>
              <div style={{ opacity: 0.85 }}>{next.venue}</div>
            </div>
          </Poster>
        ) : (
          <Empty>Nessuna prossima partita ufficiale.</Empty>
        );
      case "risultato":
        return last ? (
          <Poster
            w={W}
            h={H}
            head={head}
            title={
              last.result === "W" ? "Vittoria!" : last.result === "D" ? "Pareggio" : "Sconfitta"
            }
          >
            <Versus m={last} score />
          </Poster>
        ) : (
          <Empty>Nessuna partita giocata.</Empty>
        );
      case "formazione": {
        const lu = nextDetail.data?.lineup;
        if (!next || !lu)
          return (
            <Empty>Salva prima la formazione della prossima partita (Partite → Formazione).</Empty>
          );
        const slots = lu.slots.length ? lu.slots : slotsFor(lu.formation);
        return (
          <Poster w={W} h={H} head={head} title="Formazione">
            <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 18, opacity: 0.9 }}>
              vs {opponent(next).name} · {lu.formation}
            </div>
            <LineupBoard slots={slots} players={all} kit={nextDetail.data?.our_kit ?? "red"} />
            {lu.bench.length > 0 && (
              <div style={{ marginTop: 22, fontSize: 30, opacity: 0.9, maxWidth: W - 360 }}>
                Panchina: {lu.bench.map(name).join(", ")}
              </div>
            )}
          </Poster>
        );
      }
      case "motm": {
        const id = detail.data?.man_of_the_match_id ?? null;
        const p = byId(id);
        if (!last || !id || !p)
          return <Empty>Nessun uomo partita registrato per l'ultima gara.</Empty>;
        const st = detail.data?.stats.find((s) => s.player_id === id);
        return (
          <Poster w={W} h={H} head={head} title="Uomo partita">
            <div
              style={{
                textAlign: "center",
                display: "flex",
                flexDirection: "column",
                alignItems: "center",
                gap: 20,
              }}
            >
              <div style={{ fontSize: 36, opacity: 0.85 }}>
                vs {opponent(last).name} · {last.home_score}-{last.away_score}
              </div>
              <Face p={p} size={Math.min(420, H * 0.28)} kit={detail.data?.our_kit ?? "red"} />
              <div
                className="font-display"
                style={{ fontSize: 110, lineHeight: 1, textShadow: "0 6px 26px rgba(0,0,0,.7)" }}
              >
                {p.full_name}
              </div>
              {st && (
                <div style={{ fontSize: 40, opacity: 0.9 }}>
                  {st.goals} gol · {st.assists} assist{st.rating ? ` · voto ${st.rating}` : ""}
                </div>
              )}
            </div>
          </Poster>
        );
      }
      case "classifica": {
        const rows = home.data?.standings ?? [];
        return (
          <Poster w={W} h={H} head={head} title="Classifica">
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {rows.slice(0, size === "1:1" ? 8 : 10).map((r) => (
                <div
                  key={r.team.id}
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 24,
                    fontSize: 36,
                    padding: "10px 24px",
                    borderRadius: 14,
                    background: r.team.is_own ? "rgba(214,31,38,.9)" : "rgba(0,0,0,.45)",
                    fontWeight: r.team.is_own ? 800 : 500,
                  }}
                >
                  <span className="font-display" style={{ width: 50 }}>
                    {rows.every((x) => x.played === 0) ? "–" : r.position}
                  </span>
                  <span
                    style={{
                      flex: 1,
                      whiteSpace: "nowrap",
                      overflow: "hidden",
                      textOverflow: "ellipsis",
                    }}
                  >
                    {r.team.name}
                  </span>
                  <span className="font-display">{r.points}</span>
                </div>
              ))}
            </div>
          </Poster>
        );
      }
      case "precedenti": {
        if (!next || !h2h.data)
          return <Empty>Nessun dato sui precedenti con il prossimo avversario.</Empty>;
        const d = h2h.data;
        return (
          <Poster w={W} h={H} head={head} title="Precedenti">
            <div style={{ fontSize: 40, fontWeight: 700, marginBottom: 24 }}>
              vs {d.opponent.name}
            </div>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(3,1fr)",
                gap: 24,
                textAlign: "center",
              }}
            >
              {[
                ["Vittorie", d.won, "#2FBF71"],
                ["Pareggi", d.drawn, "#FFC857"],
                ["Sconfitte", d.lost, "#FF4B52"],
              ].map(([l, v, c]) => (
                <div
                  key={l as string}
                  style={{ background: "rgba(0,0,0,.5)", borderRadius: 24, padding: 30 }}
                >
                  <div
                    className="font-display"
                    style={{ fontSize: 150, color: c as string, lineHeight: 1 }}
                  >
                    {v}
                  </div>
                  <div style={{ fontSize: 32, letterSpacing: 4, textTransform: "uppercase" }}>
                    {l}
                  </div>
                </div>
              ))}
            </div>
            <div style={{ marginTop: 40, fontSize: 36, textAlign: "center", opacity: 0.9 }}>
              Gol: {d.goals_for} fatti · {d.goals_against} subiti in {d.played} partite
            </div>
          </Poster>
        );
      }
      case "marcatori": {
        const top = [...(career.data ?? [])]
          .filter((r) => r.goals > 0 && r.player.is_active)
          .sort((a, b) => b.goals - a.goals)
          .slice(0, 6);
        return (
          <Poster w={W} h={H} head={head} title="Marcatori">
            <div
              style={{
                fontSize: 32,
                letterSpacing: 6,
                textTransform: "uppercase",
                color: "#FFC857",
                marginBottom: 14,
              }}
            >
              Classifica di sempre
            </div>
            {top.length === 0 ? (
              <div style={{ fontSize: 44, opacity: 0.85 }}>I gol arriveranno! ⚽</div>
            ) : (
              top.map((r, i) => {
                const pl = byId(r.player.id);
                return (
                  <div
                    key={r.player.id}
                    style={{
                      display: "flex",
                      alignItems: "center",
                      gap: 24,
                      fontSize: 44,
                      padding: "10px 0",
                      borderBottom: "2px solid rgba(255,255,255,.15)",
                    }}
                  >
                    <span
                      className="font-display"
                      style={{ width: 60, color: i === 0 ? "#FFC857" : "#fff" }}
                    >
                      {i + 1}
                    </span>
                    {pl ? <Face p={pl} size={84} kit={null} /> : <span style={{ width: 84 }} />}
                    <span style={{ flex: 1 }}>{r.player.full_name}</span>
                    <span className="font-display" style={{ fontSize: 64 }}>
                      {r.goals}
                    </span>
                  </div>
                );
              })
            )}
          </Poster>
        );
      }
      case "multa":
        return (
          <Poster
            w={W}
            h={H}
            head={{ top: "Il tribunale dello spogliatoio", main: head.main }}
            title="Multa del mese"
          >
            <div
              style={{
                transform: "rotate(-4deg)",
                border: "8px solid #FFC857",
                borderRadius: 30,
                padding: 50,
                textAlign: "center",
                background: "rgba(0,0,0,.45)",
              }}
            >
              <div style={{ fontSize: 34, letterSpacing: 8, color: "#FFC857" }}>CONDANNATO</div>
              <div className="font-display" style={{ fontSize: 120, lineHeight: 1 }}>
                {fineName || "???"}
              </div>
              <div style={{ fontSize: 40, marginTop: 24 }}>{fineText}</div>
            </div>
          </Poster>
        );
    }
  };

  const toBlob = async () => {
    if (!node.current) throw new Error("Anteprima non pronta");
    const opts = { width: W, height: H, pixelRatio: 1, cacheBust: true };
    try {
      return await toPng(node.current, opts);
    } catch {
      return await toPng(node.current, { ...opts, skipFonts: true });
    }
  };
  const download = async () => {
    try {
      const url = await toBlob();
      const a = document.createElement("a");
      a.href = url;
      a.download = `amir-${tpl}-${size.replace(":", "x")}.png`;
      a.click();
    } catch {
      toast.error("Esportazione non riuscita.");
    }
  };
  const share = async () => {
    try {
      const url = await toBlob();
      const file = new File([await (await fetch(url)).blob()], `amir-${tpl}.png`, {
        type: "image/png",
      });
      if (navigator.canShare?.({ files: [file] }))
        await navigator.share(caption ? { files: [file], text: caption } : { files: [file] });
      else {
        await download();
        toast("Condivisione non supportata: immagine scaricata.");
      }
    } catch (e) {
      if ((e as Error).name !== "AbortError") toast.error("Condivisione non riuscita.");
    }
  };
  const generate = () => {
    if (!captionMatch) return;
    const extra = [
      tpl === "multa" ? `Multa del mese a ${fineName || "un compagno"}: ${fineText}` : "",
      notes.trim(),
    ]
      .filter(Boolean)
      .join(". ");
    cap.mutate(
      { id: captionMatch.id, tone, kind: tpl, ...(extra ? { notes: extra } : {}) },
      {
        onSuccess: (r) => {
          setCaption(r.text);
          setCapInfo({ source: r.source, model: r.model, note: r.note });
        },
        onError: toastError,
      },
    );
  };

  const scale = 340 / W;
  const content = render();
  return (
    <div>
      <PageTitle kicker="Area staff" title="Studio grafiche" />
      {home.isError && <ErrorState error={home.error} onRetry={() => home.refetch()} />}
      <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
        <div className="space-y-4">
          <Card>
            <h2 className="mb-2 text-xl">Modello</h2>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {TEMPLATES.map(([id, l]) => (
                <button
                  key={id}
                  type="button"
                  aria-pressed={tpl === id}
                  onClick={() => setTpl(id)}
                  className={cn(
                    "min-h-11 rounded-lg border px-2 text-sm font-semibold",
                    tpl === id
                      ? "border-primary bg-primary/15 text-foreground"
                      : "bg-secondary text-muted-foreground",
                  )}
                >
                  {l}
                </button>
              ))}
            </div>
            <h2 className="mb-2 mt-4 text-xl">Formato</h2>
            <div className="flex gap-2">
              {(Object.keys(SIZES) as SizeId[]).map((s) => (
                <button
                  key={s}
                  type="button"
                  aria-pressed={size === s}
                  onClick={() => setSize(s)}
                  className={cn(
                    "min-h-11 flex-1 rounded-lg border text-sm font-semibold num",
                    size === s
                      ? "border-primary bg-primary/15"
                      : "bg-secondary text-muted-foreground",
                  )}
                >
                  {s}
                  {s === "9:16" ? " storia" : ""}
                </button>
              ))}
            </div>
            {tpl === "multa" && (
              <div className="mt-4 grid gap-3">
                <Field label="Nome del multato">
                  <TextInput
                    value={fineName}
                    onChange={(e) => setFineName(e.target.value)}
                    maxLength={24}
                  />
                </Field>
                <Field label="Motivazione">
                  <textarea
                    value={fineText}
                    onChange={(e) => setFineText(e.target.value)}
                    rows={3}
                    maxLength={160}
                    className={`${inputCls} py-2`}
                  />
                </Field>
              </div>
            )}
          </Card>
          <Card>
            <h2 className="mb-1 text-xl">Didascalia</h2>
            <p className="mb-3 text-xs text-muted-foreground">
              La scrive l'IA (Gemini) partendo dai dati veri della partita (risultato, marcatori,
              precedenti…): niente di inventato. Rileggila sempre prima di pubblicare.
            </p>
            <div className="flex flex-wrap items-end gap-2">
              <div role="group" aria-label="Tono" className="flex gap-1">
                {(["epico", "ironico", "sobrio"] as Tone[]).map((t) => (
                  <button
                    key={t}
                    type="button"
                    aria-pressed={tone === t}
                    onClick={() => setTone(t)}
                    className={cn(
                      "min-h-11 rounded-lg px-3 text-sm font-semibold capitalize",
                      tone === t
                        ? "bg-primary text-primary-foreground"
                        : "bg-secondary text-muted-foreground",
                    )}
                  >
                    {t}
                  </button>
                ))}
              </div>
              <Btn variant="outline" disabled={!captionMatch || cap.isPending} onClick={generate}>
                <Sparkles className={cn("h-4 w-4", cap.isPending && "animate-pulse")} />{" "}
                {cap.isPending ? "Scrivo…" : caption ? "Riscrivi" : "Genera con l'IA"}
              </Btn>
            </div>
            <div className="mt-3">
              <Field
                label="Indicazioni extra (facoltative)"
                hint="Es. ringrazia i tifosi, cita il capitano, ricorda l'aperitivo dopo la partita"
              >
                <TextInput
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  maxLength={300}
                />
              </Field>
            </div>
            <textarea
              aria-label="Testo della didascalia"
              value={caption}
              onChange={(e) => setCaption(e.target.value)}
              rows={5}
              className={`${inputCls} mt-3 py-2`}
              placeholder="Genera una didascalia o scrivila tu."
            />
            {capInfo && (
              <p
                className={cn(
                  "mt-1 text-xs",
                  capInfo.source === "ai" ? "text-success" : "text-warning",
                )}
                role="status"
              >
                {capInfo.source === "ai"
                  ? `✨ Scritta dall'IA${capInfo.model ? ` (${capInfo.model})` : ""}.`
                  : capInfo.note}
              </p>
            )}
            <Btn
              variant="ghost"
              className="mt-1"
              disabled={!caption}
              onClick={() => copyText(caption)}
            >
              <Copy className="h-4 w-4" /> Copia
            </Btn>
          </Card>
        </div>
        <div className="space-y-3">
          <div
            className="mx-auto overflow-hidden rounded-xl border"
            style={{ width: W * scale, height: H * scale }}
          >
            {home.isPending ? (
              <Skeleton className="h-full w-full" />
            ) : (
              <div
                style={{
                  transform: `scale(${scale})`,
                  transformOrigin: "top left",
                  width: W,
                  height: H,
                }}
              >
                <div ref={node}>{content}</div>
              </div>
            )}
          </div>
          <div className="flex justify-center gap-2">
            <Btn onClick={share}>
              <Share2 className="h-4 w-4" /> Condividi
            </Btn>
            <Btn variant="outline" onClick={download}>
              <Download className="h-4 w-4" /> Scarica PNG
            </Btn>
          </div>
        </div>
      </div>
    </div>
  );

  function Empty({ children }: { children: ReactNode }) {
    return (
      <div
        style={{
          width: W,
          height: H,
          display: "grid",
          placeItems: "center",
          padding: 120,
          textAlign: "center",
          fontSize: 54,
          backgroundColor: "#151518",
          backgroundImage: BACKDROP,
          backgroundSize: "cover",
          color: "#fff",
        }}
      >
        {children}
      </div>
    );
  }
}
