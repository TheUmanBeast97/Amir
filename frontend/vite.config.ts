import path from "node:path";
import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import viteReact from "@vitejs/plugin-react";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import { nitro } from "nitro/vite";
import tsConfigPaths from "vite-tsconfig-paths";

// Sito di AMIR COSTRUZIONI (TanStack Start + React + Tailwind).
// `bun run dev` -> http://localhost:8080 ; `bun run build` -> build di produzione (server Node, oppure formato Vercel se c'è VERCEL=1).
export default defineConfig(({ command }) => ({
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
    ...(command === "build" ? [nitro({ preset: process.env["VERCEL"] ? "vercel" : "node-server" })] : []),
    viteReact(),
  ],
}));
