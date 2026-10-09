import { createFileRoute } from "@tanstack/react-router";
import { PageTitle } from "@/components/ui-kit";

/** Home della Mixed Zone (provvisoria: la pagina vera arriva con le rotte dei dati XFive). */
export const Route = createFileRoute("/mixed-zone/")({
  head: () => ({
    meta: [
      { title: "Mixed Zone - AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Tutto XFive Alessandria: tornei, squadre, giocatori, partite e statistiche.",
      },
      { property: "og:title", content: "Mixed Zone - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Tutto XFive Alessandria." },
    ],
  }),
  component: () => <PageTitle kicker="Mixed Zone" title="Tutto XFive Alessandria" />,
});
