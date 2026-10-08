import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import {
  api,
  type CallupsInput,
  type CaptionInput,
  type ChargeInput,
  type EventInput,
  type MatchSettings,
  type PaymentInput,
  type PlayerInput,
  type Scope,
  type StatsInput,
} from "./client";
import type { FriendlyInput, Id, Lineup, Rsvp, SyncScope } from "./types";

// ---- public ----
export const useHome = () => useQuery({ queryKey: ["home"], queryFn: () => api.getHome() });
export const useMatches = (p: { competition_id?: Id | undefined; scope?: Scope }) =>
  useQuery({ queryKey: ["matches", p], queryFn: () => api.getMatches(p) });
export const useStandings = (competition_id?: Id) =>
  useQuery({
    queryKey: ["standings", competition_id],
    queryFn: () => api.getStandings(competition_id),
  });
export const useRoster = () => useQuery({ queryKey: ["roster"], queryFn: () => api.getRoster() });
export const usePlayerPage = (id: Id) =>
  useQuery({ queryKey: ["player-page", id], queryFn: () => api.getPlayerPage(id), retry: false });
export const useCareer = () => useQuery({ queryKey: ["career"], queryFn: () => api.getCareer() });
export const usePublicMatch = (id: Id) =>
  useQuery({
    queryKey: ["public-match", id],
    queryFn: () => api.getPublicMatch(id),
    enabled: id > 0,
    retry: false,
  });
export const useHistory = () =>
  useQuery({ queryKey: ["history"], queryFn: () => api.getHistory() });
export const useHistoryCompetition = (id: Id) =>
  useQuery({ queryKey: ["history-competition", id], queryFn: () => api.getHistoryCompetition(id) });
export const useHeadToHead = (teamId: Id | undefined) =>
  useQuery({
    queryKey: ["h2h", teamId],
    queryFn: () => api.getHeadToHead(teamId!),
    enabled: !!teamId,
  });
export const useMe = (token: string) =>
  useQuery({ queryKey: ["me", token], queryFn: () => api.getMe(token), retry: false });
export const useSetMyRsvp = (token: string) => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { event_id: Id; rsvp: Rsvp; note: string | null }) =>
      api.setMyRsvp(token, v.event_id, { rsvp: v.rsvp, note: v.note }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["me", token] }),
  });
};

// ---- auth ----
export const useLogin = () =>
  useMutation({
    mutationFn: (v: { email: string; password: string }) => api.login(v.email, v.password),
  });
export const useAuthMe = () =>
  useQuery({ queryKey: ["auth-me"], queryFn: () => api.me(), retry: false });

// ---- admin ----
const useInvalidate = () => {
  const qc = useQueryClient();
  return (...keys: QueryKey[]) =>
    Promise.all(keys.map((k) => qc.invalidateQueries({ queryKey: k })));
};
export const useDashboard = () =>
  useQuery({ queryKey: ["dashboard"], queryFn: () => api.getDashboard() });
export const usePlayers = () =>
  useQuery({ queryKey: ["players"], queryFn: () => api.getPlayers() });
export const useSavePlayer = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id?: Id; data: PlayerInput }) =>
      v.id ? api.updatePlayer(v.id, v.data) : api.createPlayer(v.data),
    onSuccess: () => inv(["players"], ["dashboard"], ["roster"]),
  });
};
/** Scrive la scheda scout (l'IA se c'è la chiave, altrimenti un modello) e la salva sul giocatore. */
export const useGenerateScout = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.generateScout(id),
    onSuccess: (_r, id) => inv(["players"], ["player-page", id]),
  });
};
/** Salva il testo della scheda scout corretto a mano; un testo vuoto la toglie. */
export const useSaveScout = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id: Id; text: string }) => api.saveScout(v.id, v.text),
    onSuccess: (_r, v) => inv(["players"], ["player-page", v.id]),
  });
};
export const useDeletePlayer = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.deletePlayer(id),
    onSuccess: () => inv(["players"], ["dashboard"], ["roster"]),
  });
};
// ---- foto del giocatore: caricata dallo staff, tolta, o riletta da XFive insieme a profilo e statistiche ----
export const useUploadPhoto = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id: Id; file: File }) => api.uploadPhoto(v.id, v.file),
    onSuccess: (_r, v) => inv(["players"], ["roster"], ["home"], ["player-page", v.id]),
  });
};
export const useRemovePhoto = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.removePhoto(id),
    onSuccess: (_r, id) => inv(["players"], ["roster"], ["home"], ["player-page", id]),
  });
};
export const useSyncPlayer = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id: Id; photo?: boolean }) => api.syncPlayer(v.id, v.photo ?? false),
    onSuccess: (_r, v) => inv(["players"], ["roster"], ["home"], ["career"], ["player-page", v.id]),
  });
};
export const useImportPlayers = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (rows: Record<string, string>[]) => api.importPlayers(rows),
    onSuccess: () => inv(["players"], ["dashboard"]),
  });
};
export const useAdminMatches = (p: { competition_id?: Id | undefined; scope?: Scope }) =>
  useQuery({ queryKey: ["admin-matches", p], queryFn: () => api.getAdminMatches(p) });
export const useMatchDetail = (id: Id) =>
  useQuery({
    queryKey: ["match-detail", id],
    queryFn: () => api.getMatchDetail(id),
    enabled: id > 0,
  });
export const useSaveLineup = (id: Id) => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (l: Omit<Lineup, "match_id">) => api.saveLineup(id, l),
    onSuccess: () => inv(["match-detail", id], ["public-match", id], ["dashboard"]),
  });
};
export const useSaveCallups = (id: Id) => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: CallupsInput) => api.saveCallups(id, v),
    onSuccess: () => inv(["match-detail", id], ["public-match", id], ["dashboard"]),
  });
};
export const useUpdateMatch = (id: Id) => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: MatchSettings) => api.updateMatch(id, v),
    onSuccess: () => inv(["match-detail", id], ["public-match", id], ["dashboard"], ["home"]),
  });
};
export const useSaveStats = (id: Id) => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: StatsInput) => api.saveStats(id, v),
    onSuccess: () =>
      inv(
        ["match-detail", id],
        ["public-match", id],
        ["attendance"],
        ["career"],
        ["player-page"],
        ["history"],
      ),
  });
};
export const useCaption = () =>
  useMutation({
    mutationFn: (v: CaptionInput & { id: Id }) => {
      const { id, ...rest } = v;
      return api.caption(id, rest);
    },
  });
export const useFriendlies = () =>
  useQuery({ queryKey: ["friendlies"], queryFn: () => api.getFriendlies() });
export const useSaveFriendly = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id?: Id; data: Partial<FriendlyInput> }) =>
      v.id ? api.updateFriendly(v.id, v.data) : api.createFriendly(v.data as FriendlyInput),
    onSuccess: () =>
      inv(["friendlies"], ["dashboard"], ["home"], ["matches"], ["admin-matches"], ["events"]),
  });
};
export const useDeleteFriendly = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.deleteFriendly(id),
    onSuccess: () =>
      inv(["friendlies"], ["dashboard"], ["home"], ["matches"], ["admin-matches"], ["events"]),
  });
};
export const useEvents = () => useQuery({ queryKey: ["events"], queryFn: () => api.getEvents() });
export const useEventResponses = (id: Id | null) =>
  useQuery({
    queryKey: ["event-responses", id],
    queryFn: () => api.getEventResponses(id!),
    enabled: id !== null,
  });
export const useSaveEvent = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id?: Id; data: EventInput }) =>
      v.id ? api.updateEvent(v.id, v.data) : api.createEvent(v.data),
    onSuccess: () => inv(["events"], ["dashboard"]),
  });
};
export const useDeleteEvent = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.deleteEvent(id),
    onSuccess: () => inv(["events"], ["dashboard"]),
  });
};
export const useSetEventResponse = (eventId: Id) => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { player_id: Id; rsvp?: Rsvp | null; attended?: boolean | null }) =>
      api.setEventResponse(eventId, v.player_id, v),
    onSuccess: () =>
      inv(["event-responses", eventId], ["events"], ["match-detail"], ["attendance"]),
  });
};
export const useRemind = () => useMutation({ mutationFn: (id: Id) => api.remind(id) });
export const useCharges = () =>
  useQuery({ queryKey: ["charges"], queryFn: () => api.getCharges() });
export const useFinanceSummary = () =>
  useQuery({ queryKey: ["finance"], queryFn: () => api.getFinanceSummary() });
export const usePlayerBalance = (id: Id | null) =>
  useQuery({
    queryKey: ["balance", id],
    queryFn: () => api.getPlayerBalance(id!),
    enabled: id !== null,
  });
const financeKeys: QueryKey[] = [["finance"], ["charges"], ["balance"], ["dashboard"]];
export const useCreateCharge = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: async (v: ChargeInput & { player_ids?: Id[] }) => {
      const { player_ids, ...data } = v;
      const c = await api.createCharge(data);
      return api.assignCharge(c.id, player_ids ? { player_ids } : {});
    },
    onSuccess: () => inv(...financeKeys),
  });
};
export const useDeleteCharge = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.deleteCharge(id),
    onSuccess: () => inv(...financeKeys),
  });
};
export const useAddPayment = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (v: { id: Id; data: PaymentInput }) => api.addPayment(v.id, v.data),
    onSuccess: () => inv(...financeKeys),
  });
};
export const useDeletePayment = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (id: Id) => api.deletePayment(id),
    onSuccess: () => inv(...financeKeys),
  });
};
export const useAttendance = (season: string) =>
  useQuery({ queryKey: ["attendance", season], queryFn: () => api.getAttendance(season) });
// la modulistica cambia solo quando si aggiorna il backend: si può tenere in cache a lungo
export const useDocuments = () =>
  useQuery({ queryKey: ["documents"], queryFn: () => api.getDocuments(), staleTime: 10 * 60_000 });
export const useAskDocuments = () =>
  useMutation({ mutationFn: (question: string) => api.askDocuments(question) });
export const useXfiveAdminStatus = () =>
  useQuery({ queryKey: ["xfive-admin"], queryFn: () => api.getXfiveAdminStatus() });
export const useCheckXfiveAdmin = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: () => api.checkXfiveAdmin(),
    onSuccess: () => inv(["xfive-admin"]),
  });
};
export const useSyncRuns = (poll = false) =>
  useQuery({
    queryKey: ["sync-runs"],
    queryFn: () => api.getSyncRuns(),
    refetchInterval: poll ? 1500 : false,
  });
export const useSync = () => {
  const inv = useInvalidate();
  return useMutation({
    mutationFn: (scope: SyncScope) => api.syncXfive(scope),
    // un aggiornamento cambia partite, classifica, rosa e immagini: tutto si rilegge
    onSuccess: () =>
      inv(
        ["sync-runs"],
        ["xfive-admin"],
        ["dashboard"],
        ["players"],
        ["roster"],
        ["matches"],
        ["admin-matches"],
        ["standings"],
        ["home"],
        ["history"],
        ["career"],
      ),
  });
};
