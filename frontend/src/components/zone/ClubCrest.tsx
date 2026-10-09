import { useState } from "react";
import type { ZoneClubRef } from "@/api/zone-types";
import { OWN_CLUB_ID } from "@/lib/area";
import { sized } from "@/lib/img";
import { cn } from "@/lib/utils";
import { clubInitials } from "@/lib/zone";

/**
 * Lo stemma di un club XFive dentro un cerchio bianco (come `Crest` di Amir Hub); se l'immagine manca o
 * non carica restano le iniziali. Il nostro club (AMIR) ha il bordo del colore dell'area.
 */
export function ClubCrest({
  club,
  size = 40,
  className,
}: {
  club: ZoneClubRef;
  size?: number;
  className?: string;
}) {
  const [broken, setBroken] = useState(false);
  const ours = club.id === OWN_CLUB_ID;
  if (club.badge_url && !broken)
    return (
      <span
        className={cn(
          "grid shrink-0 place-items-center overflow-hidden rounded-full bg-white shadow-md ring-1 ring-black/10",
          ours && "ring-2 ring-primary",
          className,
        )}
        style={{ width: size, height: size, padding: size * 0.08 }}
      >
        <img
          src={sized(club.badge_url, size)}
          alt={`Stemma di ${club.name}`}
          loading="lazy"
          onError={() => setBroken(true)}
          className="h-full w-full object-contain"
        />
      </span>
    );
  return (
    <span
      aria-label={club.name}
      className={cn(
        "grid shrink-0 place-items-center overflow-hidden rounded-full border-2 font-display num",
        ours
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-secondary text-secondary-foreground",
        className,
      )}
      style={{ width: size, height: size, fontSize: size * 0.38 }}
    >
      {clubInitials(club.name) || "?"}
    </span>
  );
}
