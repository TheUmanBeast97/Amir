# Note per chi lavora sul codice

- Tutti i dati passano da `src/api/client.ts` (`ApiClient`, implementazione HTTP verso il backend Laravel): l'indirizzo dell'API è `VITE_API_BASE_URL` nel file `.env`.
- `src/api/types.ts` rispecchia il contratto del backend in snake_case (vedi `../docs/api-types.ts`): non rinominare i campi, il backend risponde con questi nomi.
- Un hook TanStack Query per ogni endpoint in `src/api/hooks.ts`; le pagine gestiscono da sole scheletri di caricamento, errori e stati vuoti.
- Il backend è una API Laravel esterna (cartella `../backend`): nessun altro servizio.
- Routing con TanStack Router (file in `src/routes`).
- Testi per gli utenti in italiano; codice e commenti tecnici in inglese o italiano, ma coerenti nel file.
- L'area staff sta sotto `/admin` (il layout `src/routes/admin.tsx` controlla il token Bearer in `localStorage`); il login è `admin_.login.tsx`, fuori da quel controllo.
- `__root.tsx` non usa la shell pubblica per i percorsi `/admin*`: l'area staff ha la sua barra laterale.
- Componenti condivisi: `src/components/player-ui.tsx` (foto giocatore, numero maglia rosso/bianco, campo con le formazioni), `src/components/stats-ui.tsx` (KPI, riquadri, confronti) e `src/lib/kit.ts` (quale numero mostrare a seconda della divisa).
- Un giocatore ha due numeri (`shirt_number_red`, `shirt_number_white`): si sceglie con la divisa della partita (`our_kit`) tramite `shirtFor()`.
- Convocati e formazione si vedono sul sito solo se lo staff li pubblica (`callups_published`, `lineup.is_published`).
- Non esiste più la modalità demo con dati finti: serve il backend acceso.
