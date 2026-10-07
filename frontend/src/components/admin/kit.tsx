import { useState, type ReactNode, type InputHTMLAttributes, type SelectHTMLAttributes } from "react";
import { motion } from "motion/react";
import { toast } from "sonner";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
  AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { ApiError } from "@/api/client";
import { CountUp } from "@/components/motion";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const inputCls = "min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

export function Field({ label, error, children, hint }: { label: string; error?: string | undefined; children: ReactNode; hint?: string }) {
  return (
    <label className="block text-sm font-semibold">
      <span className="mb-1 block">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs font-normal text-muted-foreground">{hint}</span>}
      {error && (
        <motion.span role="alert" className="mt-1 block text-xs font-normal text-primary" initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: dur.fast, ease: ease.out }}>
          {error}
        </motion.span>
      )}
    </label>
  );
}
export function TextInput(props: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={cn(inputCls, props.className)} />;
}
export function NativeSelect(props: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={cn(inputCls, props.className)} />;
}
export function Btn({ variant = "primary", className, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "ghost" | "outline" | "success" }) {
  const v = {
    primary: "bg-primary text-primary-foreground hover:bg-primary/90",
    ghost: "hover:bg-accent",
    outline: "border border-input bg-card hover:bg-accent",
    success: "bg-success text-success-foreground hover:bg-success/90",
  }[variant];
  return <button type="button" {...props} className={cn("press inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60", v, className)} />;
}
export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className="press flex min-h-11 items-center gap-3 text-sm font-semibold">
      <span className={cn("relative h-6 w-10 shrink-0 rounded-full transition-colors duration-200", checked ? "bg-primary" : "bg-muted")}>
        <motion.span className="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-foreground" initial={false} animate={{ x: checked ? 16 : 0 }} transition={spring.snappy} />
      </span>
      {label}
    </button>
  );
}

/** Confirmation dialog wrapper: render the trigger via `children(open)`. */
export function Confirm({ title, description, confirmLabel = "Conferma", onConfirm, children }: {
  title: string; description: string; confirmLabel?: string; onConfirm: () => void; children: (open: () => void) => ReactNode;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      {children(() => setOpen(true))}
      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{title}</AlertDialogTitle>
            <AlertDialogDescription>{description}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Annulla</AlertDialogCancel>
            <AlertDialogAction className="bg-primary text-primary-foreground" onClick={onConfirm}>{confirmLabel}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

export const fieldErrors = (e: unknown): Record<string, string[]> => (e instanceof ApiError ? e.errors : {});
export const firstErr = (e: unknown, k: string) => fieldErrors(e)[k]?.[0];
export const toastError = (e: unknown) => {
  const errs = fieldErrors(e);
  const list = Object.values(errs).flat();
  toast.error(list.length ? list.join(" ") : e instanceof Error ? e.message : "Operazione non riuscita.");
};

export async function copyText(text: string, okMsg = "Copiato negli appunti") {
  try {
    await navigator.clipboard.writeText(text);
    toast.success(okMsg);
  } catch {
    toast.error("Copia non riuscita.");
  }
}
export const waLink = (text: string, phone?: string | null) =>
  `https://wa.me/${phone ? phone.replace(/[^\d]/g, "") : ""}?text=${encodeURIComponent(text)}`;

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: "success" | "warning" | "primary" }) {
  return (
    <div className="rounded-xl bg-background/50 p-3">
      <div className={cn("font-display text-2xl num md:text-3xl", tone === "success" && "text-success", tone === "warning" && "text-warning", tone === "primary" && "text-primary")}>{typeof value === "number" ? <CountUp value={value} duration={0.8} /> : value}</div>
      <div className="text-[11px] uppercase tracking-wider text-muted-foreground">{label}</div>
    </div>
  );
}
