import { QueryClient } from "@tanstack/react-query";
import { createRouter } from "@tanstack/react-router";
import { ApiError } from "./api/client";
import { routeTree } from "./routeTree.gen";

export const getRouter = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        // Un sito che non raggiunge il suo server deve dirlo subito, non restare per mezzo minuto sui riquadri grigi:
        // un solo nuovo tentativo (mai per errori 4xx, che ripetuti darebbero lo stesso esito), poi compare l'errore con «Riprova».
        retry: (failureCount, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && failureCount < 1,
        retryDelay: 1200,
      },
    },
  });

  const router = createRouter({
    routeTree,
    context: { queryClient },
    scrollRestoration: true,
    defaultPreloadStaleTime: 0,
  });

  return router;
};
