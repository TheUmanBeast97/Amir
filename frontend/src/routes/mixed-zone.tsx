import { createFileRoute, Outlet } from "@tanstack/react-router";

/** La Mixed Zone: tutto XFive Alessandria. La scocca è la stessa di Amir Hub (AppShell), il tema arriva da `data-area`. */
export const Route = createFileRoute("/mixed-zone")({
  component: () => <Outlet />,
});
