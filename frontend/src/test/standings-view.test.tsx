import { fireEvent, render, screen } from "@testing-library/react";
import { useEffect } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { StandingRow, Team } from "@/api/types";
import { StandingsTable, StandingsViewToggle } from "@/components/match";
import { useStandingsView } from "@/lib/standings-view";

// le righe entrano con whileInView, che usa IntersectionObserver: jsdom non lo ha
beforeEach(() => {
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
  localStorage.clear();
});
afterEach(() => {
  vi.unstubAllGlobals();
  localStorage.clear();
});

const team = (id: number, name: string): Team =>
  ({ id, name, short_name: name, badge_url: null, is_own: id === 1 }) as unknown as Team;

const rows: StandingRow[] = [
  {
    position: 1,
    team: team(1, "AMIR COSTRUZIONI"),
    played: 5,
    won: 3,
    drawn: 1,
    lost: 1,
    goals_for: 12,
    goals_against: 7,
    goal_diff: 5,
    points: 10,
  },
];

const headers = () => screen.getAllByRole("columnheader").map((h) => h.textContent);
const cells = () => screen.getAllByRole("cell").map((c) => c.textContent);

describe("Classifica: vista compatta ed estesa", () => {
  it("compatta: punti, G, vinte, pareggiate, perse", () => {
    render(<StandingsTable rows={rows} view="compact" />);
    expect(headers()).toEqual(["#", "Squadra", "Pt", "G", "V", "N", "P"]);
    // posizione, squadra, poi 10 punti, 5 partite, 3 vinte, 1 pareggiata, 1 persa
    expect(cells().slice(2)).toEqual(["10", "5", "3", "1", "1"]);
  });

  it("estesa: stesso ordine, con in più gol fatti, subiti e differenza reti", () => {
    render(<StandingsTable rows={rows} view="extended" />);
    expect(headers()).toEqual(["#", "Squadra", "Pt", "G", "V", "N", "P", "GF", "GS", "DR"]);
    expect(cells().slice(2)).toEqual(["10", "5", "3", "1", "1", "12", "7", "+5"]);
  });

  it("l'interruttore segnala la vista scelta e la cambia", () => {
    const onChange = vi.fn();
    render(<StandingsViewToggle value="compact" onChange={onChange} />);
    expect(screen.getByRole("button", { name: "Compatta" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    fireEvent.click(screen.getByRole("button", { name: "Estesa" }));
    expect(onChange).toHaveBeenCalledWith("extended");
  });

  it("la scelta si ricorda nel browser", () => {
    function Probe() {
      const [view, setView] = useStandingsView();
      useEffect(() => {
        if (view === "compact") setView("extended");
      }, [view, setView]);
      return <span>{view}</span>;
    }
    const first = render(<Probe />);
    expect(screen.getByText("extended")).toBeInTheDocument();
    expect(localStorage.getItem("amir_standings_view")).toBe("extended");
    first.unmount();
    render(<Probe />);
    expect(screen.getByText("extended")).toBeInTheDocument();
  });
});
