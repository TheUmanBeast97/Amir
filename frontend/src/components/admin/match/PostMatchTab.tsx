import { Save } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { usePlayers, useSaveStats } from "@/api/hooks";
import type { Id, MatchDetail, MatchPlayerStat } from "@/api/types";
import { InfoBanner, Skeleton } from "@/components/ui-kit";
import { Btn, Field, NativeSelect, TextInput, fieldErrors, inputCls } from "../kit";

type Row = MatchPlayerStat & { name: string };

export function PostMatchTab({ detail }: { detail: MatchDetail }) {
  const players = usePlayers();
  if (players.isPending) return <Skeleton className="mt-3 h-64" />;
  const list = (players.data ?? []).filter(
    (p) =>
      (p.is_active && p.role !== "dirigente" && p.role !== "allenatore") ||
      detail.stats.some((s) => s.player_id === p.id),
  );
  return (
    <PostMatchForm
      detail={detail}
      initial={list.map((p) => {
        const s = detail.stats.find((x) => x.player_id === p.id);
        return {
          player_id: p.id,
          name: p.full_name,
          played: s?.played ?? false,
          goals: s?.goals ?? 0,
          assists: s?.assists ?? 0,
          yellow: s?.yellow ?? 0,
          red: s?.red ?? 0,
          rating: s?.rating ?? null,
          is_mvp: s?.is_mvp ?? false,
        };
      })}
    />
  );
}

function PostMatchForm({ detail, initial }: { detail: MatchDetail; initial: Row[] }) {
  const [rows, setRows] = useState<Row[]>(initial);
  const [referee, setReferee] = useState(detail.referee ?? "");
  const [motm, setMotm] = useState<string>(detail.man_of_the_match_id?.toString() ?? "");
  const [err, setErr] = useState("");
  const save = useSaveStats(detail.match.id);
  const upd = (id: Id, patch: Partial<Row>) =>
    setRows((rs) => rs.map((r) => (r.player_id === id ? { ...r, ...patch } : r)));
  const num = (v: string) => Math.max(0, Math.min(20, Number(v) || 0));

  const submit = () => {
    if (rows.some((r) => r.rating !== null && (r.rating < 1 || r.rating > 10)))
      return setErr("Il voto deve essere tra 1 e 10.");
    if (motm && !rows.find((r) => r.player_id === Number(motm))?.played)
      return setErr("L'uomo partita deve aver giocato.");
    setErr("");
    save.mutate(
      {
        players: rows.map(({ name: _n, ...s }) => ({
          ...s,
          is_mvp: !!motm && s.player_id === Number(motm),
        })),
        referee: referee.trim() || null,
        man_of_the_match_id: motm ? Number(motm) : null,
      },
      {
        onSuccess: () => toast.success("Statistiche salvate"),
        onError: (e) => setErr(Object.values(fieldErrors(e)).flat().join(" ") || e.message),
      },
    );
  };
  const nInput = "h-10 w-12 rounded-md border border-input bg-background text-center text-sm num";

  const fromXfive = detail.stats.some((s) => s.source === "xfive");
  return (
    <div className="space-y-4 pt-3">
      {fromXfive && (
        <InfoBanner>
          Arbitro, presenze, gol e cartellini di questa partita sono stati letti da XFive. Se salvi
          modifiche da qui diventano dati dello staff e l'aggiornamento automatico non li riscrive
          più.
        </InfoBanner>
      )}
      <div className="grid gap-3 md:grid-cols-2">
        <Field label="Arbitro">
          <TextInput value={referee} onChange={(e) => setReferee(e.target.value)} />
        </Field>
        <Field label="Uomo partita">
          <NativeSelect value={motm} onChange={(e) => setMotm(e.target.value)}>
            <option value="">-</option>
            {rows
              .filter((r) => r.played)
              .map((r) => (
                <option key={r.player_id} value={r.player_id}>
                  {r.name}
                </option>
              ))}
          </NativeSelect>
        </Field>
      </div>
      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full min-w-[560px] text-sm">
          <thead className="bg-muted/60 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="px-2 py-2 text-left">Giocatore</th>
              <th>Giocato</th>
              <th>Gol</th>
              <th>Assist</th>
              <th>Gialli</th>
              <th>Rossi</th>
              <th>Voto</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.player_id} className="border-t">
                <td className="max-w-[10rem] truncate px-2 py-1.5 font-semibold">{r.name}</td>
                <td className="text-center">
                  <input
                    type="checkbox"
                    aria-label={`${r.name} ha giocato`}
                    className="h-5 w-5"
                    checked={r.played}
                    onChange={(e) => upd(r.player_id, { played: e.target.checked })}
                  />
                </td>
                {(["goals", "assists", "yellow", "red"] as const).map((k) => (
                  <td key={k} className="text-center">
                    <input
                      type="number"
                      min={0}
                      aria-label={`${k} ${r.name}`}
                      disabled={!r.played}
                      className={nInput}
                      value={r[k]}
                      onChange={(e) => upd(r.player_id, { [k]: num(e.target.value) })}
                    />
                  </td>
                ))}
                <td className="text-center">
                  <select
                    aria-label={`Voto ${r.name}`}
                    disabled={!r.played}
                    className={`${inputCls} h-10 min-h-0 w-16 px-1`}
                    value={r.rating ?? ""}
                    onChange={(e) =>
                      upd(r.player_id, { rating: e.target.value ? Number(e.target.value) : null })
                    }
                  >
                    <option value="">-</option>
                    {Array.from({ length: 19 }, (_, i) => 1 + i * 0.5).map((v) => (
                      <option key={v} value={v}>
                        {v}
                      </option>
                    ))}
                  </select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {err && (
        <p role="alert" className="text-sm text-primary">
          {err}
        </p>
      )}
      <Btn onClick={submit} disabled={save.isPending}>
        <Save className="h-4 w-4" /> {save.isPending ? "Salvataggio…" : "Salva statistiche"}
      </Btn>
    </div>
  );
}
