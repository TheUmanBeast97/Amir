# LOVABLE PROMPT — PART 3: real crests and photos, player pages, career stats

> Paste this after PART 1 and PART 2. It only adds screens for data the backend now provides; it does not change anything that already works.

The backend now serves **real team crests and player photos** (downloaded from XFive and served by our own API) plus **per-player career statistics** derived from XFive's public rankings. Extend the app as described below. All user-facing text stays in Italian.

## 1. Contract changes (copy into `src/api/types.ts`)
- Add `nationality: string | null;` to `PublicPlayer` (so `Player` inherits it).
- Add the following new types, exactly as written:

```ts
// ---------- Player career (from XFive's public rankings) ----------
export interface PlayerTotals {
  competitions: number;       // competitions the player took part in
  goals: number;
  yellow: number;
  red: number;
  mvp_points: number;         // points in XFive's "Miglior giocatore" ranking
}
export interface PlayerCareerRow {
  competition: Pick<Competition, "id" | "name" | "season" | "kind" | "format">;
  goals: number; yellow: number; red: number;
  mvp_points: number;
}
export interface PlayerProfile {
  player: PublicPlayer;
  totals: PlayerTotals;
  career: PlayerCareerRow[];    // newest season first
}
export interface CareerRow extends PlayerTotals {
  player: PublicPlayer;         // list is sorted by goals, then competitions
}
```

- Add two methods to `ApiClient`, with an HTTP and a mock implementation (invented data in the mock):
  - `getPlayerProfile(id: Id): Promise<PlayerProfile>` → `GET /public/players/{id}`
  - `getCareerStats(): Promise<CareerRow[]>` → `GET /public/stats/career`
  Create hooks `usePlayerProfile(id)` and `useCareerStats()`.

## 2. Crests and photos
- Render `Team.badge_url` and `PublicPlayer.photo_url` exactly as returned. They are absolute URLs served by our own API (PNG/JPEG, CORS enabled). Never hotlink to xfivesport.it and never rewrite these URLs.
- Every `<img>` for a crest or a photo must have `crossOrigin="anonymous"` so that `html-to-image` can export the graphics, and an `onError` fallback to the existing placeholder. A `null` URL also shows the placeholder.
- In the Graphics studio, use the real crests (our crest is large, 500×500: keep it sharp) and, in "Uomo partita" and "Formazione ufficiale", the real player photos in round frames.

## 3. Player page `/rosa/:playerId`
- Make every card in `/rosa` link here.
- Hero: round photo, full name, shirt number, role, nationality (show a flag emoji for "Italia" 🇮🇹 and a neutral globe for anything else).
- Four stat cards from `totals`: **Tornei disputati**, **Gol**, **Gialli / Rossi**, **Punti MVP**. Add a small tooltip on "Tornei disputati": "Tornei a cui il giocatore risulta iscritto (XFive non pubblica le presenze)".
- Career table from `career`, grouped by season (newest first): competition name, 7/8 format chip, goals, yellow, red, MVP points. Highlight the best season by goals. Empty state: "Nessun dato da XFive per ora".
- Provide a back link to `/rosa`.

## 4. All-time scorers
- Add a "Marcatori di sempre" tab inside `/storico` (and a link in the navigation under Storico) using `useCareerStats()`.
- A podium with photos for the top three, then a list with columns Gol, Tornei, Gialli, Rossi, MVP. Each row links to the player page. Rows with `competitions === 0` go at the bottom, muted.
- In `/rosa` cards, show a small chip with the player's all-time goals when available.

## 5. Admin
- In the Players drawer, show nationality and photo as read-only information coming from XFive (no new endpoints).

## Quality
No new dependencies, no horizontal scroll at 375 px, loading skeletons and empty states in Italian, accessible alt texts ("Stemma di AMIR COSTRUZIONI", "Foto di Mario Rossi"). Mock data must use invented names only.
