import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MatchDetail, SheetPlayer, SheetState } from "@/api/types";
import { SheetTab } from "@/components/admin/match/SheetTab";

const getSheet = vi.fn();
const saveSheet = vi.fn();

vi.mock("@/api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/api/client")>();
  return {
    ...original,
    api: {
      ...original.api,
      getSheet: (...a: unknown[]) => getSheet(...a),
      saveSheet: (...a: unknown[]) => saveSheet(...a),
      getXfiveSession: async () => ({
        connected: true,
        email_hint: "c***@example.com",
        expires_at: "2026-10-15T20:00:00+02:00",
        blocked_until: null,
      }),
    },
  };
});

const player = (over: Partial<SheetPlayer>): SheetPlayer => ({
  xfive_id: 1,
  player_id: 1,
  name: "Mario Rossi",
  birth_date: null,
  called_up: false,
  selected: false,
  xfive_selected: false,
  number: null,
  suggested_number: null,
  history: [],
  ...over,
});

const state = (over: Partial<SheetState>): SheetState => ({
  connected: true,
  open: true,
  reason: null,
  match_id: 5,
  xfive_match_id: 14584,
  pdf_url: null,
  note: null,
  players: [],
  last_sent: null,
  ...over,
});

const detail = { match: { id: 5 } } as unknown as MatchDetail;

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SheetTab detail={detail} />
    </QueryClientProvider>,
  );
}

const sendButton = () => screen.getByRole("button", { name: /Rivedi e invia a XFive/i });
const numberInput = (name: string) => screen.getByLabelText(`Numero di maglia di ${name}`);

describe("SheetTab", () => {
  beforeEach(() => {
    getSheet.mockReset();
    saveSheet.mockReset();
  });

  it("parte dai convocati con l'ultimo numero usato e permette di inviare", async () => {
    getSheet.mockResolvedValue(
      state({
        players: [
          player({
            xfive_id: 1,
            name: "Mario Rossi",
            called_up: true,
            selected: true,
            suggested_number: "10",
            history: [{ number: "10", count: 3, last_used_at: "2026-10-08T21:00:00+02:00" }],
          }),
          player({ xfive_id: 2, player_id: 2, name: "Luca Bianchi", suggested_number: "7" }),
        ],
      }),
    );
    renderTab();

    await waitFor(() => expect(numberInput("Mario Rossi")).toHaveValue("10"));
    expect(numberInput("Luca Bianchi")).toHaveValue("7");
    expect(screen.getByText("convocato")).toBeInTheDocument();
    expect(sendButton()).toBeEnabled();
  });

  it("segnala i numeri ripetuti e blocca l'invio finché non si correggono", async () => {
    getSheet.mockResolvedValue(
      state({
        players: [
          player({ xfive_id: 1, name: "Mario Rossi", selected: true, suggested_number: "10" }),
          player({
            xfive_id: 2,
            player_id: 2,
            name: "Luca Bianchi",
            selected: true,
            suggested_number: "10",
          }),
        ],
      }),
    );
    renderTab();

    await waitFor(() => expect(screen.getByText(/numeri di maglia ripetuti/i)).toBeInTheDocument());
    expect(sendButton()).toBeDisabled();

    fireEvent.change(numberInput("Luca Bianchi"), { target: { value: "7" } });

    await waitFor(() => expect(sendButton()).toBeEnabled());
    expect(screen.queryByText(/numeri di maglia ripetuti/i)).not.toBeInTheDocument();
  });

  it("un numero già usato si riprende con un tocco e mette il giocatore in distinta", async () => {
    getSheet.mockResolvedValue(
      state({
        players: [
          player({
            name: "Mario Rossi",
            selected: false,
            suggested_number: "10",
            history: [
              { number: "10", count: 3, last_used_at: "2026-10-08T21:00:00+02:00" },
              { number: "4", count: 1, last_used_at: "2026-09-20T21:00:00+02:00" },
            ],
          }),
        ],
      }),
    );
    renderTab();

    const older = await screen.findByRole("button", { name: /^4\s*×1/ });
    fireEvent.click(older);

    expect(numberInput("Mario Rossi")).toHaveValue("4");
    expect(screen.getByRole("button", { name: /Togli Mario Rossi/ })).toBeInTheDocument();
  });

  it("fuori dalla finestra di XFive mostra il motivo e non permette di inviare", async () => {
    getSheet.mockResolvedValue(
      state({
        open: false,
        reason: "La distinta non si può aprire adesso su XFive.",
        players: [player({ name: "Mario Rossi", selected: true, suggested_number: "10" })],
      }),
    );
    renderTab();

    expect(await screen.findByText(/non si può aprire adesso su XFive/)).toBeInTheDocument();
    expect(numberInput("Mario Rossi")).toHaveValue("10");
    expect(sendButton()).toBeDisabled();
  });
});
