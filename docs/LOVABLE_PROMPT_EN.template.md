# LOVABLE PROMPT - AMIR Team Manager (English)

> How to use: paste PART 1, wait until Lovable finishes, then paste PART 2.
> For more control, split PART 2 in two messages (first "Admin area", then "Graphics studio and stats").
> The API contract (TypeScript types) is at the bottom of this file and is part of PART 1.

---

## PART 1 - Foundations, API layer and public area

Build **"AMIR Team Manager"**: a mobile-first web app (installable as a PWA) to run an amateur football (soccer) team, **AMIR COSTRUZIONI**, which plays in the XFive leagues of Alessandria, Italy (8-a-side and 7-a-side). The team's users are Italian, so **every piece of user-facing text must be in Italian** (labels, buttons, empty states, errors, dates). Code, identifiers and comments stay in English.

A REST backend (Laravel) already exists but is not reachable from here. You build **only the frontend**, against a **fixed API contract** and **mock data**. The real backend will be plugged in later by changing one environment variable.

### Stack and hard rules
- React + Vite + TypeScript + Tailwind + shadcn/ui, React Router, TanStack Query, date-fns (locale `it`, timezone Europe/Rome), `html-to-image` for the shareable graphics, `papaparse` for CSV import.
- **Do NOT use Supabase, Lovable Cloud, Firebase or any other backend, and no external auth provider.** Authentication is a plain email + password form against the contract below.
- Create `src/api/types.ts` by copying EXACTLY the types in the "API CONTRACT" section at the bottom of this prompt. Do not rename or change any field: the real backend answers with these exact names (snake_case).
- Create `src/api/client.ts` with an `ApiClient` interface that has **one method per endpoint** listed in the contract, and two implementations:
  1. `httpClient`: uses `fetch` against `import.meta.env.VITE_API_BASE_URL`, sends `Authorization: Bearer <token>` (token stored in `localStorage` under `amir_token`), unwraps the `{ data }` envelope, and on HTTP 401 clears the token and redirects to `/admin/login`. Validation errors come as HTTP 422 with `{ message, errors: { field: [messages] } }`: show them next to the form fields.
  2. `mockClient`: in-memory data, simulated latency of 200–500 ms, and **mutations really change the mock state** (RSVP, payments, lineup, players, stats…) so the demo is interactive.
  Select the implementation with `VITE_USE_MOCKS` (default `true`). Provide a `.env.example` with `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1` and `VITE_USE_MOCKS=true`.
- One TanStack Query hook per endpoint (`useHome`, `useMatches`, `useStandings`, `useHistory`, …). Always handle loading (skeletons), error and empty states, with Italian messages.
- Money values are integers in **cents**; format them with `Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' })`.
- All timestamps are ISO 8601 with an offset (e.g. `2026-10-15T20:00:00+02:00`); display them in Europe/Rome.

### Visual identity
- Dark theme by default (with a light/dark toggle). Background `#0B0B0D`, surfaces `#151518`, text `#F5F5F5`, **team red `#D61F26`** as primary, amber `#FFC857` for warnings, green `#2FBF71` for wins / success, white for details.
- Headings in **Barlow Condensed** (uppercase, heavy), body in **Inter**, tabular figures for scores and numbers.
- A sporty "match day" look: cards with a diagonal cut, big scores, rounded badges, restrained micro-animations. Touch targets ≥ 44 px, excellent at 375 px width, bottom navigation on mobile and a sidebar on desktop.
- Kit colours: our home kit is red `#D61F26`, away kit white `#FFFFFF`. Always show the crest (`badge_url`) with an elegant placeholder when it is missing.

### Real-world state of the data (important for the UX)
The current season is **2026/2027**. The league **CITTADELLA [Alessandria]** (8-a-side, 10 teams, 18 rounds) has **only one official round published so far**: round 1, *AMIR COSTRUZIONI - VALONS, Thursday 15/10/2026 at 20:00, 100GRIGIO - CAMPO 4*. All the other rounds are **provisional pairings with no date or time** (`status: "to_schedule"`, `is_provisional: true`). Therefore:
- A provisional match shows "Data da definire", is visually muted, carries a **PROVVISORIA** chip, has no countdown and never appears as "next match" while an official dated match exists.
- If `calendar_info.is_complete` is `false`, show a top info banner using `calendar_info.note` (Italian text provided by the API, e.g. "Calendario ufficiale ancora in aggiornamento: XFive ha pubblicato 1 giornate su 18.").
- Standings: positions can be **tied** (the same number repeated). Show them as given. While nobody has played yet (`played === 0` for every row) show "-" instead of the position and a note "La stagione non è ancora iniziata". If `PublicHome.standing` is `null`, hide the "your position" card.
- `HistoryCompetition.final_position` exists **only for leagues**; when it is `null` show no rank badge.

### Public area (no login, shareable on WhatsApp)
1. `/` **Home**: hero with the next official match (crests, opponent, date/time, venue, countdown; if there is no date → "Data da definire"), last played match with its result (W/D/L colour-coded), a mini league table with our row highlighted, the next 5 matches, and the incomplete-calendar banner. A button "Aggiungi il calendario al telefono" (a `webcal://` link / download of `/public/calendar.ics`).
2. `/calendario`: all our matches grouped by round, a competition filter, status chips (Programmata / Provvisoria / Giocata / Rinviata), and a switch "tutte le partite del girone".
3. `/classifica`: full table (P, V, N, Pe, GF, GS, DR, Pt) with our row highlighted and a competition selector; follow the tie rules above.
4. `/rosa`: card grid with photo, shirt number (staff use "A"/"D"), role, nickname. Use only the `PublicPlayer` fields.
5. `/storico` - **History section** (past seasons, read-only):
   - At the top "Il nostro curriculum": all-time record (played, W-D-L, goals for/against, points, goal difference, win %), number of seasons, **best win**, **worst defeat**, **most frequent opponent** (from `HistorySummary`).
   - Then one block per **season** (newest first, e.g. 2025/2026 → 2022/2023) with the season summary and the list of competitions played (league, cups, tournaments), each with a final-position badge (🥇🥈🥉 for the top three, leagues only), format 7/8 and record (W-D-L, GF-GA).
   - `/storico/:competitionId`: competition detail with the final table (our row highlighted) and all our matches with result (W/D/L colour-coded).
   - A simple trend chart (points or win % per season) and a format filter (all / 7-a-side / 8-a-side).
   - A reusable **"Precedenti"** component (`HeadToHead`): head-to-head record against one opponent (overall W-D-L + match list with season and result). Reuse it in the match detail.
6. `/p/:token` **Personal player page** (magic link, no login): upcoming events with three large buttons **"Ci sono / Forse / Non ci sono"** (current choice highlighted, optional note), plus the player's **balance** with the charges to pay and the payments recorded.

### Mock data
Make the mock faithful to reality: team AMIR COSTRUZIONI (format 8), competition "CITTADELLA [Alessandria]" 2026/2027 with exactly these 10 teams: AMIR COSTRUZIONI, CAFFÈ KM0-PALESTRA MEETING, GUALA CLOSURES, IN EXTREMIS, OCCASIONALI FC, POLPEN 2022, REY GOMME, SHQIPONJAT, TORNITURE KARIM, VALONS (approximate home-kit colours: white, white/blue, white/red, blue, blue, black, blue/black, white, black). Only one official round (AMIR–VALONS, 15/10/2026 20:00); the other 17 rounds are provisional. **Use invented player names** (about 16, including a goalkeeper and one staff member with "A"/"D"), sample payments, and an **invented but plausible history** of 4 seasons (2022/2023–2025/2026) mixing 7- and 8-a-side leagues, cups and tournaments. Never use real people's data in the mocks.

---

## PART 2 - Admin area, lineups, payments, stats and graphics

Add the private area under `/admin` (email + password login at `/admin/login`, Bearer token, protected routes). On mobile use a bottom navigation with the main entries; on desktop a sidebar.

1. **Dashboard** (`GET /dashboard`): next match; **Squad List n/10** with a progress bar (XFive rule: at most 10 "exclusive" players; warn when under or over); registrations (to request / pending / approved); medical certificates expiring within 30 days (already-expired ones in red); money (outstanding + overdue charges); **XFive deadlines** (the `deadlines` list, past ones muted); last XFive sync status with an **"Aggiorna da XFive"** button (`POST /sync/xfive`, then poll `GET /sync/runs`); the `rules` list in a "Regole da ricordare" card.
2. **Players**: table (desktop) / cards (mobile) with search and filters (active, in squad list, registration status, role). A create/edit drawer with every `Player` field. **Import**: paste text or upload a CSV (columns: surname, name, birth date, role, shirt number, phone, email, registration status, squad list, certificate expiry) with a preview, then `POST /players/import` and show `created / updated / skipped / errors`. For each player: copy / share the **personal link** (`magic_link`) via WhatsApp. Show this reminder: "Il tesseramento va richiesto almeno 72 ore prima della partita (mai sotto le 24 ore). Dirigenti e allenatori: nel numero di maglia usa A o D." Deleting a player only deactivates him (the API keeps history); show that in the confirmation text.
3. **Matches**: list of our matches (switch "tutte") and a **match detail** page with tabs:
   - **Presenze** (attendance): counters yes / maybe / no / no answer, a player list with an editable status (the admin can answer on behalf of a player), and a **"Sollecita chi non ha risposto"** button (`POST /events/{id}/remind` → show `text` and open `wa_link`).
   - **Formazione** (lineup): a vertical SVG pitch editor. Formations for format 8: `3-3-1`, `3-2-2`, `2-3-2`, `3-1-3`, `2-4-1`; for format 7: `2-3-1`, `3-2-1`, `2-2-2`, `3-1-2` (the goalkeeper is always slot 1; the number of slots equals the match format). Drag players (preferably those who answered "yes") onto slots and the bench; save with `PUT /matches/{id}/lineup`; "Esporta come immagine" button. Warn when there is no goalkeeper or slots are empty. Surface 422 errors from the API (formation/duplicate/bench).
   - **Dopo la partita** (post-match): for each player played yes/no, goals, assists, yellow, red, rating 1–10; referee; **man of the match**; save with `PUT /matches/{id}/stats` (the list you send is the complete set).
   - **Precedenti**: the `HeadToHead` component against the opponent. Suggest the **kit to wear** by comparing the opponent's kit colours with ours (if their home kit is close to ours, recommend our away kit).
   - Provisional matches only show "In attesa di calendario ufficiale".
4. **Events**: create trainings and social events with date, place and RSVP; after the event tick who actually attended (`attended`). Match events cannot be edited or deleted (the API answers 422).
5. **Payments**: summary (total due / paid / outstanding), a per-player table with a colour-coded balance (green settled, amber partial, red overdue), a **"Nuova voce"** dialog (kind: season fee, registration, fine, referee, dinner, other; amount; due date; all players or a selection), a **"Registra pagamento"** dialog (amount, method cash / Satispay / PayPal / bank transfer, date), deletable payment history, and a **"Sollecito WhatsApp"** button for debtors (text composed client-side, opened with a `wa.me` link). The app **does not collect money**: it is only a ledger.
6. **Team leaderboards** (`GET /stats/attendance`): tabs "Più presenti" (matches played), "Allenamenti", "Marcatori", "Assist", "Voto medio", "Cartellini"; a **podium** with photos for the top three, then the list. Season filter (`season=all` for all-time).
7. **Graphics studio**: create shareable images from real data, using the crest, team colours and theme fonts. Templates: **Match Day** (next match), **Risultato**, **Formazione ufficiale**, **Uomo partita**, **Classifica**, **Precedenti**, **Marcatori**, plus a playful free-text **"Multa del mese"** template. Formats 1:1 (1080×1080), 4:5 and 9:16 (stories). Live preview, PNG export with `html-to-image`, **Web Share API** (`navigator.share` with a file) with a download fallback. Below the preview, a **caption generator** (`POST /matches/{id}/caption`) with a tone selector (epico / ironico / sobrio) and a copy button.
8. **Settings**: latest XFive syncs (`GET /sync/runs`), buttons "Aggiorna calendario" and "Importa storico" (`POST /sync/xfive` with `scope` `current` or `history`), logout.

### Quality bar
Accessibility (contrast, focus, labels), no horizontal scroll at 375 px, loading skeletons, short and friendly empty states, confirmation before destructive actions, validated forms with Italian messages. Do not put real personal data in the mocks. Do not add features that were not requested.

---

## API CONTRACT (copy exactly into `src/api/types.ts`)

```ts
{{TYPES}}
```
