<?php

return [

    /*
    |--------------------------------------------------------------------------
    | La nostra squadra
    |--------------------------------------------------------------------------
    | club_id        = id del club su XFive (è il "tmid" dell'area amministrazione
    |                  e la classe "team-159" nei calendari pubblici).
    | public_team_id = id della pagina /it/team/{id}/ nel torneo corrente.
    */
    'own' => [
        'club_id' => 159,
        'public_team_id' => 3604,
        'name' => 'AMIR COSTRUZIONI',
        'short_name' => 'AMIR',
        'format' => 8,
        'kit1_color' => '#d61f26',
        'kit2_color' => '#ffffff',
    ],

    /*
    |--------------------------------------------------------------------------
    | XFive (sorgente dati in sola lettura)
    |--------------------------------------------------------------------------
    */
    'xfive' => [
        'base_url' => env('XFIVE_BASE_URL', 'https://www.xfivesport.it'),
        'league_id' => 1,
        // il contatto da lasciare a XFive va in .env (XFIVE_USER_AGENT), non nel codice: il repository è pubblico
        'user_agent' => env('XFIVE_USER_AGENT', 'AmirTeamManager/0.1 (gestionale squadra)'),
        'throttle_ms' => (int) env('XFIVE_THROTTLE_MS', 1200),
        'timeout' => 25,

        'current_season' => '2026/2027',
        // id stagione XFive => etichetta (lo storico parte dal 2022/2023)
        'seasons' => [
            8 => '2026/2027',
            7 => '2025/2026',
            6 => '2024/2025',
            5 => '2023/2024',
            4 => '2022/2023',
        ],
        // tornei della stagione corrente da tenere sincronizzati
        // 187 = CITTADELLA [Alessandria] (calcio a 8)
        'current_tournaments' => [187],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tornei che NON contano in storico, statistiche e presenze
    |--------------------------------------------------------------------------
    | Nel 2025/26 la squadra faceva anche la Serie A a 8 di 100GRIGIO: non si
    | conta. 139 = Serie A [Alessandria]; 158 = Coppa Di Lega Serie A [Alessandria].
    | Le partite restano nel database ma sono nascoste ai conteggi.
    */
    'excluded_tournaments' => [139, 158],

    /*
    |--------------------------------------------------------------------------
    | Testi scritti dall'IA (didascalie per i social, schede dei giocatori): Gemini
    |--------------------------------------------------------------------------
    | Serve GEMINI_API_KEY nel file .env del backend (chiave gratuita da Google AI Studio).
    | Senza chiave si usano i modelli di testo di riserva, così lo Studio grafiche funziona
    | comunque. Sul piano gratuito Google può usare i contenuti inviati per migliorare i suoi
    | prodotti: qui partono solo dati pubblici di XFive (squadre, giocatori, risultati).
    */
    'ai' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            // modello gratuito consigliato; più veloce e leggero: "gemini-3.5-flash-lite".
            // Il 7/10/2026 "gemini-3.8-flash" rispondeva 503 (domanda troppo alta) o andava in timeout,
            // mentre 3.5-flash e 3.5-flash-lite rispondevano in 1-3 secondi: per questo non è il predefinito.
            'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
            // se il modello principale è saturo o ha finito i tentativi al minuto (5 sul piano gratuito) si prova questo,
            // che ha una quota a parte; lasciare vuoto per non avere un secondo tentativo
            'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash-lite'),
            // quanto "ragiona" prima di rispondere: per una didascalia basta "low" (vuoto = predefinito del modello)
            'thinking' => env('GEMINI_THINKING', 'low'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'timeout' => 40,
        ],
    ],

    // Dati noti dalla comunicazione ufficiale (PDF "Programma stagione c8 '26-'27").
    // Servono finché XFive non pubblica tutte le date.
    'competition_overrides' => [
        187 => [
            'starts_on' => '2026-10-12',
            'ends_on' => '2027-03-19',
            'total_rounds' => 18,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Regole e scadenze (dalle comunicazioni XFive di settembre/ottobre 2026)
    |--------------------------------------------------------------------------
    */
    // dove gira il frontend: serve a costruire i link personali dei giocatori (/p/{token})
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:8080'),

    // Aggiornamenti da XFive lanciati dal sito o dalle esecuzioni pianificate
    'sync' => [
        // sul server (Vercel) dopo la risposta non si può più lavorare: l'aggiornamento si fa mentre la richiesta è aperta
        'inline' => filter_var(env('AMIR_SYNC_INLINE', env('VERCEL') ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),
        // secondi a disposizione di un aggiornamento "a pezzi" (details, media, stats): una richiesta sul server dura poco
        'budget' => (int) env('AMIR_SYNC_BUDGET', 40),
    ],

    // sul server, al primo avvio, prepara da solo il database se non lo è (vedi DatabaseBootstrap)
    'self_migrate' => filter_var(env('AMIR_SELF_MIGRATE', env('VERCEL') ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),

    // primo amministratore sul server (vedi amir:deploy): l'email e l'IMPRONTA della password (php artisan amir:hash), mai la password
    'admin' => [
        'email' => env('AMIR_ADMIN_EMAIL'),
        'hash' => env('AMIR_ADMIN_HASH'),
    ],

    // Lettura dell'area amministrazione di XFive (rosa, tesseramenti, certificati, Squad List) con il tuo account.
    // Spenta finché non si imposta XFIVE_ADMIN_ENABLED=1. Email e password stanno solo nelle variabili protette del server,
    // mai nel database né nel codice; a ogni lettura si fa un accesso nuovo e non si conserva nessuna sessione.
    'xfive_admin' => [
        'enabled' => filter_var(env('XFIVE_ADMIN_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'email' => env('XFIVE_ADMIN_EMAIL'),
        'password' => env('XFIVE_ADMIN_PASSWORD'),
        // dopo un accesso rifiutato non si riprova per questo tempo: una password sbagliata ripetuta potrebbe far bloccare l'account
        'cooldown_minutes' => (int) env('XFIVE_ADMIN_COOLDOWN_MINUTES', 120),
    ],

    // segreto delle esecuzioni pianificate (Vercel lo manda come «Authorization: Bearer ...»); vuoto = rotte disattivate
    'cron_secret' => env('CRON_SECRET'),

    'squad_list_limit' => 10,
    'cert_warning_days' => 30,

    'rules' => [
        'Squad List: 10 giocatori esclusivi per squadra. Se non la depositi, XFive la genera d\'ufficio con i primi 10 in ordine alfabetico non già presenti in altre squad list.',
        'Il tesseramento è garantito con 72 ore di anticipo. Le richieste con meno di 24 ore non vengono accettate.',
        'Un giocatore tesserato appare in verde nell\'app XFive: solo così è disponibile per la distinta.',
        'Dirigenti e allenatori: nel campo numero di maglia usa "A" o "D" (non l\'etichetta dal menu). Altrimenti in distinta non compaiono le foto e non si accede al campo.',
        'I contratti ai giocatori sono facoltativi.',
    ],

    'deadlines' => [
        ['date' => '2026-10-05', 'title' => 'Scadenza deposito Squad List (10 esclusivi): dopo XFive la genera d\'ufficio'],
        ['date' => '2026-10-09', 'title' => 'Ultimo giorno per cancellare giocatori dalle rose; chi gioca da lunedì va inserito, tesserato e pagato entro oggi'],
        ['date' => '2026-10-12', 'title' => 'Inizio stagione calcio a 8'],
        ['date' => '2026-12-11', 'title' => 'Fine andata campionato girone CITTADELLA (9 giornate)'],
        ['date' => '2026-12-14', 'title' => 'Coppa di Categoria: 1ª fase, andata (14-18 dicembre)'],
        ['date' => '2027-01-18', 'title' => 'Inizio ritorno campionato (18 gennaio - 19 marzo)'],
        ['date' => '2027-03-22', 'title' => 'Coppa di Lega girone CITTADELLA (22 marzo - 16 aprile)'],
        ['date' => '2027-04-19', 'title' => 'Fase finale Coppa di Categoria (19 aprile - 7 maggio)'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Colori maglia degli avversari del girone CITTADELLA 2026/27
    | (dalla grafica ufficiale "Divise 1 e 2"); chiave = nome in minuscolo.
    |--------------------------------------------------------------------------
    */
    'kits' => [
        'amir costruzioni' => ['#d61f26', '#ffffff'],
        'caffè km0-palestra meeting' => ['#ffffff', null],
        'guala closures' => ['#ffffff', '#9ca3af'],
        'in extremis' => ['#ffffff', '#f5c518'],
        'occasionali fc' => ['#1d4ed8', '#ffffff'],
        'polpen 2022' => ['#1d4ed8', '#ffffff'],
        'rey gomme' => ['#111111', '#ffffff'],
        'shqiponjat' => ['#1e3a8a', null],
        'torniture karim' => ['#ffffff', null],
        'valons' => ['#111111', null],
    ],
];
