import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { PublicPlayer, PublicSheet } from "@/api/types";
import { TOKEN_KEY } from "@/api/client";
import { OfficialSheet } from "@/components/OfficialSheet";

const getSheetPdf = vi.fn();

vi.mock("@/api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/api/client")>();
  return {
    ...original,
    api: { ...original.api, getSheetPdf: (...a: unknown[]) => getSheetPdf(...a) },
  };
});

// i link verso la scheda del giocatore non servono al test: basta un'àncora
vi.mock("@tanstack/react-router", () => ({
  Link: ({ children, className }: { children: ReactNode; className?: string }) => (
    <a className={className}>{children}</a>
  ),
}));

// l'animazione d'ingresso usa IntersectionObserver, che jsdom non ha: qui basta l'elemento
vi.mock("@/components/motion", () => ({
  Reveal: ({ as: Tag = "div", children }: { as?: "li" | "div"; children: ReactNode }) => (
    <Tag>{children}</Tag>
  ),
}));

const player = {
  id: 7,
  full_name: "Mario Rossi",
  nickname: null,
  photo_url: null,
} as unknown as PublicPlayer;

const sheet: PublicSheet = {
  sent_at: "2026-10-15T20:00:00+02:00",
  players: [
    { number: "1", name: "Luca Bianchi", player: null },
    { number: "7", name: "Rossi Mario", player },
    { number: "10", name: "Gianni Verdi", player: null },
  ],
};

function renderSheet() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <OfficialSheet sheet={sheet} kit="red" matchId={5} />
    </QueryClientProvider>,
  );
}

describe("OfficialSheet (sito pubblico)", () => {
  beforeEach(() => {
    getSheetPdf.mockReset();
    localStorage.clear();
  });
  afterEach(() => localStorage.clear());

  it("elenca i giocatori nell'ordine ricevuto, col numero e il nome", () => {
    renderSheet();
    const rows = screen.getAllByRole("listitem");
    expect(rows).toHaveLength(3);
    expect(rows[0]).toHaveTextContent("1");
    expect(rows[0]).toHaveTextContent("Luca Bianchi");
    // un giocatore abbinato alla rosa si vede col nome della rosa
    expect(rows[1]).toHaveTextContent("Mario Rossi");
    expect(rows[2]).toHaveTextContent("10");
    expect(screen.getByText(/3 giocatori/i)).toBeInTheDocument();
  });

  it("senza accesso dello staff non chiede il PDF e non mostra il tasto", async () => {
    renderSheet();
    await new Promise((r) => setTimeout(r, 20));
    expect(getSheetPdf).not.toHaveBeenCalled();
    expect(screen.queryByText(/PDF distinta/i)).not.toBeInTheDocument();
  });

  it("per lo staff mostra il tasto del PDF col link di XFive", async () => {
    const url = "https://www.xfivesport.it/pdfTeamsSchedule.php?mid=1&gtid=2";
    localStorage.setItem(TOKEN_KEY, "token-di-prova");
    getSheetPdf.mockResolvedValue({ url });
    renderSheet();
    const link = await screen.findByRole("link", { name: /PDF distinta/i });
    expect(link).toHaveAttribute("href", url);
    expect(link).toHaveAttribute("target", "_blank");
    expect(getSheetPdf).toHaveBeenCalledWith(5);
  });

  it("se la distinta non è stata inviata (404) il tasto non compare", async () => {
    localStorage.setItem(TOKEN_KEY, "token-di-prova");
    getSheetPdf.mockRejectedValue(new Error("Nessuna distinta inviata"));
    renderSheet();
    await waitFor(() => expect(getSheetPdf).toHaveBeenCalled());
    expect(screen.queryByText(/PDF distinta/i)).not.toBeInTheDocument();
  });
});
