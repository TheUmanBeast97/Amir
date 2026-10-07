import { createFileRoute, Outlet, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { TOKEN_KEY } from "@/api/client";
import { AdminShell } from "@/components/admin/AdminShell";

export const Route = createFileRoute("/admin")({
  head: () => ({ meta: [{ name: "robots", content: "noindex" }] }),
  component: AdminLayout,
});

function AdminLayout() {
  const navigate = useNavigate();
  const [ok, setOk] = useState(false);
  useEffect(() => {
    if (!localStorage.getItem(TOKEN_KEY)) navigate({ to: "/admin/login", replace: true });
    else setOk(true);
  }, [navigate]);
  if (!ok) return <div className="min-h-screen" aria-busy="true" />;
  return <AdminShell><Outlet /></AdminShell>;
}
