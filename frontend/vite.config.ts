import path from "node:path";
import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import viteReact from "@vitejs/plugin-react";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import { nitro } from "nitro/vite";
import tsConfigPaths from "vite-tsconfig-paths";
import { IMAGE_WIDTHS } from "./src/lib/img";

// Sito di AMIR COSTRUZIONI (TanStack Start + React + Tailwind).
// `bun run dev` -> http://localhost:8080 ; `bun run build` -> build di produzione (server Node, oppure formato Vercel se c'è VERCEL=1).
// Sul server di Vercel stemmi e foto passano dall'ottimizzatore di immagini (vedi src/lib/img.ts): gli indirizzi consentiti sono
// quelli del nostro server dei dati e del CDN di XFive (le foto che non abbiamo ancora salvato). In locale non c'è nessun ottimizzatore.
const hostOf = (url?: string) => {
  try {
    return new URL(url ?? "").hostname;
  } catch {
    return "";
  }
};
const imageHosts = process.env["VERCEL"]
  ? [hostOf(process.env["VITE_API_BASE_URL"]), "cdn.enjore.com"].filter(Boolean)
  : [];

export default defineConfig(({ command }) => ({
  define: { __IMAGE_HOSTS__: JSON.stringify(imageHosts) },
  server: { host: "::", port: 8080, strictPort: true },
  resolve: {
    alias: { "@": path.resolve(process.cwd(), "src") },
    dedupe: [
      "react",
      "react-dom",
      "react/jsx-runtime",
      "react/jsx-dev-runtime",
      "@tanstack/react-query",
      "@tanstack/query-core",
    ],
  },
  optimizeDeps: {
    include: [
      "react",
      "react-dom",
      "react-dom/client",
      "react/jsx-runtime",
      "react/jsx-dev-runtime",
      "lucide-react",
      "date-fns",
    ],
  },
  plugins: [
    tailwindcss(),
    tsConfigPaths({ projects: ["./tsconfig.json"] }),
    tanstackStart({
      importProtection: {
        behavior: "error",
        client: { files: ["**/server/**"], specifiers: ["server-only"] },
      },
      // punta alla nostra entry SSR con gestione errori (src/server.ts)
      server: { entry: "server" },
    }),
    // in locale un server Node (`bun run build` → `.output`); su Vercel (che imposta VERCEL=1) l'uscita nel formato che Vercel serve
    ...(command === "build"
      ? [
          nitro({
            preset: process.env["VERCEL"] ? "vercel" : "node-server",
            // il server del sito sta a Francoforte, vicino a chi lo usa (di serie sarebbe Washington): la pagina di base non attraversa l'Atlantico
            vercel: {
              functions: { regions: ["fra1"] },
              ...(imageHosts.length > 0
                ? {
                    config: {
                      version: 3 as const,
                      images: {
                        sizes: IMAGE_WIDTHS,
                        domains: imageHosts,
                        remotePatterns: imageHosts.map((hostname) => ({ protocol: "https" as const, hostname })),
                        minimumCacheTTL: 86400,
                        formats: ["image/webp" as const],
                      },
                    },
                  }
                : {}),
            },
            // file che non cambiano: il browser e la rete di Vercel li tengono invece di chiederli ogni volta
            routeRules: {
              "/fonts/**": { headers: { "cache-control": "public, max-age=31536000, immutable" } },
              ...Object.fromEntries(
                [
                  "/stemma-amir.png",
                  "/xfive-logo.png",
                  "/sfondo_match.jpg",
                  "/icon-192.png",
                  "/icon-512.png",
                  "/apple-touch-icon.png",
                  "/favicon.ico",
                  "/favicon-32.png",
                ].map((file) => [file, { headers: { "cache-control": "public, max-age=86400, stale-while-revalidate=604800" } }]),
              ),
            },
          }),
        ]
      : []),
    viteReact(),
  ],
}));
