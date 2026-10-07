# AMIR COSTRUZIONI - sito e area staff

Frontend di AMIR Team Manager: sito pubblico (prossima partita, calendario, classifica, rosa, storico)
e area staff (`/admin`). Parla con l'API Laravel nella cartella `../backend`.

## Avvio

```sh
bun install --frozen-lockfile   # solo la prima volta
bun run dev                     # http://localhost:8080
```

Servono il backend acceso (`../avvia-backend.bat`) e un file `.env`:

```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Comandi utili: `bun run build`, `bun run test`, `bun run lint`.

## Tecnologie

TanStack Start, TypeScript, React 19, Tailwind CSS 4, TanStack Query, Recharts, Motion (`motion/react`).

## Movimento

Il linguaggio di movimento sta in due file: `src/lib/motion.ts` (durate, curve, molle, distanze) e
`src/components/motion.tsx` (i pezzi riusabili). Non si scrivono durate o molle a mano nelle pagine.

- `Reveal` (arrivo con risalita, `i` per scaglionare le voci di una lista), `CountUp` (numeri che salgono),
  `RollDigits` (cifre che scorrono, per i conti alla rovescia), `Draw` e `Grow` (barre che si tracciano e crescono),
  `OnView` (monta i grafici Recharts quando entrano nello schermo, così si vedono disegnarsi),
  `ActivePill` (indicatore dei menu che scivola da una voce all'altra), `PageTransition` (passaggio fra pagine).
- Nel sito pubblico a ogni cambio pagina passa una striscia rossa diagonale (stessa inclinazione delle schede);
  nell'area staff c'è solo una dissolvenza breve: lì il movimento serve a dare riscontro, non a stupire.
- Chi ha attivato «riduci le animazioni» nel sistema perde spostamenti e movimenti continui ma tiene dissolvenze e stati
  (`MotionConfig reducedMotion="user"` più un blocco dedicato in `src/styles.css`).
- Un elemento animato con `Reveal`/`motion.*` scrive `opacity` inline: classi come `opacity-60` sullo **stesso** elemento
  vengono annullate. Se serve attenuare una scheda, la classe va su un elemento interno.
- `Pitch` (campo con la formazione) ha `animate` spento di default: si accende solo nella pagina pubblica, perché
  l'esportazione a immagine dell'area staff non deve catturare un'animazione a metà.

## Font

I caratteri (Barlow Condensed e Inter) sono in `public/fonts` e dichiarati in `src/fonts.css`: niente Google Fonts.
Serve per la figurina, perché l'esportazione PNG (`html-to-image`) usa i font solo se arrivano dallo stesso indirizzo
del sito; con font esterni cadrebbe sui caratteri di sistema.

## Figurina

`src/components/player-card.tsx` disegna la figurina del giocatore (600×840, esportata a 1200×1680) e
`src/lib/card-rating.ts` ne calcola il voto da 40 a 95 e il tipo, tutto dalle statistiche della scheda:

| Tipo | Voto |
| --- | --- |
| Bronzo | meno di 52 |
| Argento | 52 - 63 |
| Oro | 64 - 73 |
| Fuoco (nera e rossa) | 74 o più |

Il voto parte da 42 e sale con i gol a partita (corretti sul numero di partite, così 9 gol in 6 partite non valgono come 9 in
20), le presenze, le vittorie della squadra con lui in campo e le volte «migliore in campo»; scende con i cartellini.
Chi ha poche partite ha un voto basso per mancanza di dati, non di valore. Le foto XFive sono miniature da 210 px con una
cornice: sulla carta vengono ingrandite di poco (`scale(1.14)`) per escluderla.
