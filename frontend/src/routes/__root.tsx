import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  Outlet,
  Link,
  createRootRouteWithContext,
  useRouter,
  useRouterState,
  HeadContent,
  Scripts,
  type ErrorComponentProps,
} from "@tanstack/react-router";
import { useEffect, type ReactNode } from "react";

import { AppShell } from "../components/AppShell";
import { AreaPortalHost } from "../components/area/AreaPortal";
import { MotionRoot } from "../components/motion";
import { Toaster } from "../components/ui/sonner";
import { areaOf } from "../lib/area";
import appCss from "../styles.css?url";

function NotFoundComponent() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-7xl font-bold text-foreground">404</h1>
        <h2 className="mt-4 text-xl font-semibold text-foreground">Pagina non trovata</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          La pagina che cerchi non esiste o è stata spostata.
        </p>
        <div className="mt-6">
          <Link
            to="/"
            className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Torna alla home
          </Link>
        </div>
      </div>
    </div>
  );
}

function ErrorComponent({ error, reset }: ErrorComponentProps) {
  console.error(error);
  const router = useRouter();

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-xl font-semibold tracking-tight text-foreground">
          La pagina non si è caricata
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Qualcosa è andato storto. Riprova o torna alla home.
        </p>
        <div className="mt-6 flex flex-wrap justify-center gap-2">
          <button
            onClick={() => {
              router.invalidate();
              reset();
            }}
            className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Riprova
          </button>
          <a
            href="/"
            className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent"
          >
            Torna alla home
          </a>
        </div>
      </div>
    </div>
  );
}

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1" },
      { name: "theme-color", content: "#0B0B0D" },
      { name: "apple-mobile-web-app-capable", content: "yes" },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
    links: [
      {
        rel: "stylesheet",
        href: appCss,
      },
      { rel: "icon", href: "/favicon.ico", sizes: "any" },
      { rel: "icon", href: "/favicon-32.png", type: "image/png", sizes: "32x32" },
      { rel: "apple-touch-icon", href: "/apple-touch-icon.png" },
      { rel: "manifest", href: "/manifest.webmanifest" },
      // font in locale (vedi src/fonts.css): i due che servono subito si caricano in anticipo
      {
        rel: "preload",
        as: "font",
        type: "font/woff2",
        href: "/fonts/barlow-condensed-800-latin.woff2",
        crossOrigin: "anonymous",
      },
      {
        rel: "preload",
        as: "font",
        type: "font/woff2",
        href: "/fonts/inter-400-700-latin.woff2",
        crossOrigin: "anonymous",
      },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
  errorComponent: ErrorComponent,
});

function RootShell({ children }: { children: ReactNode }) {
  return (
    <html lang="it" suppressHydrationWarning>
      <head>
        <HeadContent />
        {/* prima del primo disegno: il tema chiaro salvato e l'area dall'indirizzo (così la Mixed Zone non lampeggia di rosso) */}
        <script
          dangerouslySetInnerHTML={{
            __html: `try{var d=document.documentElement,p=location.pathname;d.dataset.area=(p==="/admin"||p.indexOf("/admin/")===0)?"staff":(p==="/mixed-zone"||p.indexOf("/mixed-zone/")===0)?"mixed":"hub";if(localStorage.getItem("amir_theme")==="light")d.classList.add("light")}catch(e){}`,
          }}
        />
      </head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  );
}

function RootComponent() {
  const { queryClient } = Route.useRouteContext();
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const area = areaOf(pathname);
  const isAdmin = area === "staff";

  // a ogni cambio di indirizzo l'area finisce su <html>: da lì il tema prende i colori
  useEffect(() => {
    document.documentElement.dataset["area"] = area;
  }, [area]);

  return (
    <QueryClientProvider client={queryClient}>
      <MotionRoot>
        {/* Required: nested routes render here. Removing <Outlet /> breaks all child routes. */}
        {isAdmin ? (
          <Outlet />
        ) : (
          <AppShell>
            <Outlet />
          </AppShell>
        )}
        <Toaster position="top-center" richColors />
        {/* il portale «Cambia area» sta qui, fuori dalle scocche: così dura i suoi tre secondi anche quando la scocca cambia */}
        <AreaPortalHost />
      </MotionRoot>
    </QueryClientProvider>
  );
}
