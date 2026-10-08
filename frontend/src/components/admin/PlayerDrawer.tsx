import { RefreshCw, Trash2, Upload } from "lucide-react";
import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { toast } from "sonner";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import {
  useGenerateScout,
  usePlayers,
  useRemovePhoto,
  useSavePlayer,
  useSaveScout,
  useSyncPlayer,
  useUploadPhoto,
} from "@/api/hooks";
import type { PlayerInput } from "@/api/client";
import type { Player, PlayerRole, PlayerSyncResult, RegistrationStatus } from "@/api/types";
import { PlayerPhoto } from "@/components/player-ui";
import { InfoBanner } from "@/components/ui-kit";
import { regLabel, roleLabel } from "@/lib/format";
import { cn } from "@/lib/utils";
import { Btn, Field, NativeSelect, TextInput, Toggle, firstErr, inputCls, toastError } from "./kit";

type Form = {
  first_name: string;
  last_name: string;
  nickname: string;
  shirt_number: string;
  shirt_number_red: string;
  shirt_number_white: string;
  role: string;
  photo_url: string;
  is_active: boolean;
  in_squad_list: boolean;
  registration_status: RegistrationStatus;
  medical_cert_expires_on: string;
  phone: string;
  email: string;
  birth_date: string;
  xfive_player_id: string;
  notes: string;
};
const empty: Form = {
  first_name: "",
  last_name: "",
  nickname: "",
  shirt_number: "",
  shirt_number_red: "",
  shirt_number_white: "",
  role: "",
  photo_url: "",
  is_active: true,
  in_squad_list: false,
  registration_status: "none",
  medical_cert_expires_on: "",
  phone: "",
  email: "",
  birth_date: "",
  xfive_player_id: "",
  notes: "",
};
const isStaff = (role: string) => role === "dirigente" || role === "allenatore";
const fromPlayer = (p: Player): Form => ({
  first_name: p.first_name,
  last_name: p.last_name,
  nickname: p.nickname ?? "",
  shirt_number: p.shirt_number ?? "",
  // chi aveva un solo numero lo ritrova nella maglia rossa
  shirt_number_red: p.shirt_number_red ?? (isStaff(p.role ?? "") ? "" : (p.shirt_number ?? "")),
  shirt_number_white: p.shirt_number_white ?? "",
  role: p.role ?? "",
  photo_url: p.photo_url ?? "",
  is_active: p.is_active,
  in_squad_list: p.in_squad_list,
  registration_status: p.registration_status,
  medical_cert_expires_on: p.medical_cert_expires_on ?? "",
  phone: p.phone ?? "",
  email: p.email ?? "",
  birth_date: p.birth_date ?? "",
  xfive_player_id: p.xfive_player_id?.toString() ?? "",
  notes: p.notes ?? "",
});

function validate(f: Form): Record<string, string> {
  const e: Record<string, string> = {};
  if (!f.first_name.trim()) e["first_name"] = "Inserisci il nome.";
  if (!f.last_name.trim()) e["last_name"] = "Inserisci il cognome.";
  if (f.email && !/^\S+@\S+\.\S+$/.test(f.email)) e["email"] = "Email non valida.";
  const staff = isStaff(f.role);
  if (staff && f.shirt_number && !/^[AD]$/i.test(f.shirt_number))
    e["shirt_number"] = "Per dirigenti e allenatori usa A o D.";
  if (!staff && f.shirt_number_red && !/^\d{1,2}$/.test(f.shirt_number_red))
    e["shirt_number_red"] = "Numero da 0 a 99.";
  if (!staff && f.shirt_number_white && !/^\d{1,2}$/.test(f.shirt_number_white))
    e["shirt_number_white"] = "Numero da 0 a 99.";
  if (f.xfive_player_id && !/^\d+$/.test(f.xfive_player_id)) e["xfive_player_id"] = "Solo numeri.";
  return e;
}

/** Il messaggio dopo la rilettura di un giocatore da XFive. */
function syncDone(r: PlayerSyncResult) {
  if (r.profile === "not_found") {
    toast.warning("Su XFive non ho trovato un profilo con questo nome: controlla nome e cognome.");
    return;
  }
  if (r.profile === "ambiguous") {
    toast.warning(
      "Su XFive ci sono più profili possibili: serve la data di nascita per distinguerli.",
    );
    return;
  }
  toast.success(
    r.photo
      ? "Dati riletti da XFive, foto aggiornata"
      : "Dati riletti da XFive, la foto era già uguale",
  );
}

/**
 * La foto del giocatore: quella di XFive (si aggiorna da sola), una caricata dallo staff (resta quella, XFive non la tocca)
 * oppure nessuna. «Aggiorna da XFive» riscarica la foto e rilegge anche profilo e statistiche.
 */
function PhotoPanel({ player }: { player: Player }) {
  const players = usePlayers();
  // dopo un cambio la scheda mostra subito la foto nuova, senza chiudere e riaprire
  const p = players.data?.find((x) => x.id === player.id) ?? player;
  const upload = useUploadPhoto();
  const remove = useRemovePhoto();
  const sync = useSyncPlayer();
  const file = useRef<HTMLInputElement>(null);
  const busy = upload.isPending || remove.isPending || sync.isPending;

  const source =
    p.photo_source === "upload"
      ? "Foto caricata da te: gli aggiornamenti da XFive non la toccano."
      : p.photo_source === "none"
        ? "Nessuna foto: non torna da sola, premi «Aggiorna da XFive» se la vuoi."
        : p.photo_url
          ? "Foto di XFive: si aggiorna da sola quando cambia."
          : "Nessuna foto: XFive non ne ha una, oppure non è ancora stata scaricata.";

  const onFile = (e: ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    e.target.value = "";
    if (!f) return;
    upload.mutate(
      { id: p.id, file: f },
      { onSuccess: () => toast.success("Foto salvata"), onError: toastError },
    );
  };

  return (
    <section
      aria-label="Foto del giocatore"
      className="mx-4 mt-2 flex items-start gap-4 rounded-xl border p-3"
    >
      <PlayerPhoto key={p.photo_url ?? "none"} player={p} size={88} />
      <div className="min-w-0 flex-1 space-y-2">
        <p className="text-xs text-muted-foreground">{source}</p>
        <div className="flex flex-wrap gap-2">
          <input
            ref={file}
            type="file"
            accept="image/png,image/jpeg"
            className="sr-only"
            onChange={onFile}
            aria-label="Scegli la foto"
          />
          <Btn variant="outline" disabled={busy} onClick={() => file.current?.click()}>
            <Upload className="h-4 w-4" /> {upload.isPending ? "Carico…" : "Carica foto"}
          </Btn>
          <Btn
            variant="outline"
            disabled={busy}
            onClick={() =>
              sync.mutate({ id: p.id, photo: true }, { onSuccess: syncDone, onError: toastError })
            }
          >
            <RefreshCw className={cn("h-4 w-4", sync.isPending && "animate-spin")} /> Aggiorna da
            XFive
          </Btn>
          {p.photo_url && (
            <Btn
              variant="ghost"
              disabled={busy}
              onClick={() =>
                remove.mutate(p.id, {
                  onSuccess: () => toast.success("Foto tolta"),
                  onError: toastError,
                })
              }
            >
              <Trash2 className="h-4 w-4" /> Togli
            </Btn>
          )}
        </div>
        <p className="text-[11px] text-muted-foreground">
          «Aggiorna da XFive» rilegge anche profilo e statistiche. JPG o PNG, al massimo 4 MB.
        </p>
      </div>
    </section>
  );
}

/**
 * La scheda scout che compare sulla pagina pubblica del giocatore. «Scrivi con l'IA» la scrive dai suoi numeri veri
 * (con Gemini, o con un modello di testo se manca la chiave) e la salva subito; il testo si può poi correggere.
 */
function ScoutEditor({ player }: { player: Player }) {
  const gen = useGenerateScout();
  const save = useSaveScout();
  const [text, setText] = useState(player.scout_text ?? "");
  const [note, setNote] = useState<string | null>(null);
  useEffect(() => {
    setText(player.scout_text ?? "");
    setNote(null);
  }, [player.id]); // eslint-disable-line react-hooks/exhaustive-deps
  const busy = gen.isPending || save.isPending;

  return (
    <section aria-label="Scheda scout" className="mx-4 mb-6 space-y-2 rounded-xl border p-3">
      <h3 className="text-lg">Scheda scout</h3>
      <p className="text-xs text-muted-foreground">
        Compare sulla pagina pubblica del giocatore. Si scrive dai suoi numeri veri; puoi
        correggerla a mano.
      </p>
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        rows={5}
        maxLength={700}
        className={`${inputCls} py-2`}
        aria-label="Testo della scheda scout"
        placeholder="Ancora nessuna scheda: premi «Scrivi con l'IA»."
      />
      {note && <InfoBanner>{note}</InfoBanner>}
      <div className="flex flex-wrap gap-2">
        <Btn
          disabled={busy}
          onClick={() =>
            gen.mutate(player.id, {
              onSuccess: (r) => {
                setText(r.text);
                setNote(r.note);
                toast.success(
                  r.source === "ai"
                    ? "Scheda scritta dall'IA e salvata"
                    : "Scheda salvata (modello di testo)",
                );
              },
              onError: toastError,
            })
          }
        >
          {gen.isPending ? "Scrivo…" : text ? "Riscrivi con l'IA" : "Scrivi con l'IA"}
        </Btn>
        <Btn
          variant="outline"
          disabled={busy || text.trim() === (player.scout_text ?? "")}
          onClick={() =>
            save.mutate(
              { id: player.id, text },
              {
                onSuccess: () => {
                  setNote(null);
                  toast.success(text.trim() ? "Scheda salvata" : "Scheda tolta");
                },
                onError: toastError,
              },
            )
          }
        >
          Salva testo
        </Btn>
        {text && (
          <Btn
            variant="ghost"
            disabled={busy}
            onClick={() => {
              setText("");
              save.mutate(
                { id: player.id, text: "" },
                { onSuccess: () => toast.success("Scheda tolta"), onError: toastError },
              );
            }}
          >
            Togli
          </Btn>
        )}
      </div>
    </section>
  );
}

/** player: undefined = closed, null = new, Player = edit */
export function PlayerDrawer({
  player,
  onClose,
}: {
  player: Player | null | undefined;
  onClose: () => void;
}) {
  const [f, setF] = useState<Form>(empty);
  const [errs, setErrs] = useState<Record<string, string>>({});
  const save = useSavePlayer();
  useEffect(() => {
    setF(player ? fromPlayer(player) : empty);
    setErrs({});
    save.reset();
  }, [player]); // eslint-disable-line react-hooks/exhaustive-deps
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errs[k] ?? firstErr(save.error, k);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const v = validate(f);
    setErrs(v);
    if (Object.keys(v).length) return;
    const n = (s: string) => s.trim() || null;
    const staff = isStaff(f.role);
    const red = staff ? null : n(f.shirt_number_red);
    const white = staff ? null : n(f.shirt_number_white);
    const data: PlayerInput = {
      first_name: f.first_name.trim(),
      last_name: f.last_name.trim(),
      nickname: n(f.nickname),
      // "shirt_number" resta come numero generico (la sigla A/D per lo staff, altrimenti il primo numero inserito)
      shirt_number: staff ? n(f.shirt_number.toUpperCase()) : (red ?? white),
      shirt_number_red: red,
      shirt_number_white: white,
      role: (f.role || null) as PlayerRole | null,
      photo_url: n(f.photo_url),
      is_active: f.is_active,
      in_squad_list: f.in_squad_list,
      registration_status: f.registration_status,
      medical_cert_expires_on: n(f.medical_cert_expires_on),
      phone: n(f.phone),
      email: n(f.email),
      birth_date: n(f.birth_date),
      xfive_player_id: f.xfive_player_id ? Number(f.xfive_player_id) : null,
      notes: n(f.notes),
    };
    save.mutate(player ? { id: player.id, data } : { data }, {
      onSuccess: () => {
        toast.success(player ? "Giocatore aggiornato" : "Giocatore aggiunto");
        onClose();
      },
    });
  };

  return (
    <Sheet open={player !== undefined} onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>{player ? `Modifica ${player.full_name}` : "Nuovo giocatore"}</SheetTitle>
        </SheetHeader>
        {player && <PhotoPanel player={player} />}
        <form onSubmit={submit} noValidate className="space-y-3 p-4">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Nome *" error={err("first_name")}>
              <TextInput
                value={f.first_name}
                onChange={(e) => set("first_name", e.target.value)}
                autoComplete="off"
              />
            </Field>
            <Field label="Cognome *" error={err("last_name")}>
              <TextInput
                value={f.last_name}
                onChange={(e) => set("last_name", e.target.value)}
                autoComplete="off"
              />
            </Field>
            <Field label="Soprannome">
              <TextInput value={f.nickname} onChange={(e) => set("nickname", e.target.value)} />
            </Field>
            {isStaff(f.role) ? (
              <Field
                label="Sigla"
                error={err("shirt_number")}
                hint="A (allenatore) o D (dirigente)"
              >
                <TextInput
                  value={f.shirt_number}
                  onChange={(e) => set("shirt_number", e.target.value)}
                  maxLength={1}
                />
              </Field>
            ) : (
              <>
                <Field
                  label="Numero maglia rossa"
                  error={err("shirt_number_red")}
                  hint="La divisa rossa"
                >
                  <TextInput
                    inputMode="numeric"
                    value={f.shirt_number_red}
                    onChange={(e) => set("shirt_number_red", e.target.value)}
                    maxLength={2}
                  />
                </Field>
                <Field
                  label="Numero maglia bianca"
                  error={err("shirt_number_white")}
                  hint="Se cambia con la divisa bianca"
                >
                  <TextInput
                    inputMode="numeric"
                    value={f.shirt_number_white}
                    onChange={(e) => set("shirt_number_white", e.target.value)}
                    maxLength={2}
                  />
                </Field>
              </>
            )}
            <Field label="Ruolo">
              <NativeSelect value={f.role} onChange={(e) => set("role", e.target.value)}>
                <option value="">-</option>
                {(Object.keys(roleLabel) as PlayerRole[]).map((k) => (
                  <option key={k} value={k}>
                    {roleLabel[k]}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label="Tesseramento">
              <NativeSelect
                value={f.registration_status}
                onChange={(e) => set("registration_status", e.target.value as RegistrationStatus)}
              >
                {(Object.keys(regLabel) as RegistrationStatus[]).map((k) => (
                  <option key={k} value={k}>
                    {regLabel[k]}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label="Data di nascita">
              <TextInput
                type="date"
                value={f.birth_date}
                onChange={(e) => set("birth_date", e.target.value)}
              />
            </Field>
            <Field label="Scadenza certificato">
              <TextInput
                type="date"
                value={f.medical_cert_expires_on}
                onChange={(e) => set("medical_cert_expires_on", e.target.value)}
              />
            </Field>
            <Field label="Telefono">
              <TextInput
                type="tel"
                value={f.phone}
                onChange={(e) => set("phone", e.target.value)}
              />
            </Field>
            <Field label="Email" error={err("email")}>
              <TextInput
                type="email"
                value={f.email}
                onChange={(e) => set("email", e.target.value)}
              />
            </Field>
            <Field label="ID giocatore XFive" error={err("xfive_player_id")}>
              <TextInput
                inputMode="numeric"
                value={f.xfive_player_id}
                onChange={(e) => set("xfive_player_id", e.target.value)}
              />
            </Field>
            <Field label="URL foto">
              <TextInput
                type="url"
                value={f.photo_url}
                onChange={(e) => set("photo_url", e.target.value)}
              />
            </Field>
          </div>
          <Toggle
            checked={f.in_squad_list}
            onChange={(v) => set("in_squad_list", v)}
            label="In Squad List (max 10)"
          />
          <Toggle checked={f.is_active} onChange={(v) => set("is_active", v)} label="Attivo" />
          <Field label="Note">
            <textarea
              value={f.notes}
              onChange={(e) => set("notes", e.target.value)}
              rows={3}
              className={`${inputCls} py-2`}
            />
          </Field>
          {save.isError && !Object.keys(errs).length && (
            <p role="alert" className="text-sm text-primary">
              {save.error.message}
            </p>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <Btn variant="outline" onClick={onClose}>
              Annulla
            </Btn>
            <Btn type="submit" disabled={save.isPending}>
              {save.isPending ? "Salvataggio…" : "Salva"}
            </Btn>
          </div>
        </form>
        {player && !isStaff(player.role ?? "") && <ScoutEditor player={player} />}
      </SheetContent>
    </Sheet>
  );
}
