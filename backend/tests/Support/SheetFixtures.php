<?php

namespace Tests\Support;

/**
 * Esempi sintetici (nomi inventati) delle risposte di XFive sulla distinta, con la stessa struttura di quelle vere:
 * l'elenco partite (op=1) con l'azione `setSchedule(partita, squadra)`, il modulo (op=2) e l'esito del salvataggio (op=3).
 */
final class SheetFixtures
{
    /**
     * L'elenco partite di XFive: la distinta è apribile solo per le partite in $open (id partita => id squadra).
     *
     * @param  array<int, int>  $open
     */
    public static function schedule(array $open): string
    {
        $rows = [];
        foreach ([14584, 14602, 14610] as $mid) {
            $action = isset($open[$mid]) ? '<div class="goToSchedule" onclick="setSchedule('.$mid.','.$open[$mid].')"></div>' : '';
            $rows[] = ['DT_RowId' => "row_{$mid}", 0 => "AMIR COSTRUZIONI vs ALFA {$mid}", 1 => 'CITTADELLA', 2 => '1', 3 => '15/10/2026 20:00', 4 => 'Da disputare', 5 => $action];
        }

        return (string) json_encode(['draw' => 1, 'recordsTotal' => 3, 'recordsFiltered' => 3, 'data' => $rows]);
    }

    /**
     * Il modulo della distinta (dentro il JSON di op=2).
     *
     * @param  array<int, array{0:int, 1:string, 2:string, 3:bool, 4:string}>  $players  id XFive, «Cognome Nome», nascita d/m/Y, scelto, numero
     */
    public static function form(array $players, int $mid = 14584, int $gtid = 3604): string
    {
        $rows = '';
        foreach ($players as $i => [$id, $name, $birth, $checked, $number]) {
            $rows .= '<div class="row '.($i % 2 ? 'even' : 'odd').'"><div class="col-xs-10"><div class="form-group"><div class="checkbox playerInfo-container"><label>'
                .'<input type="checkbox" name="players[]" value="'.$id.'"'.($checked ? ' checked="checked"' : '').'>'
                .'<div class="playerInfo"><img src="x.png"><div><span class="name">'.$name.'</span><span class="birthday">'.$birth.'</span></div></div></label></div></div></div>'
                .'<div class="col-xs-4"><div class="form-group playerDocs"></div></div><div class="col-xs-5"><div class="form-group"><label></label></div></div>'
                .'<div class="col-xs-5"><div class="form-group"><label>N. Maglia</label>'
                .'<input type="text" class="form-control jerseyNumber" name="number'.$id.'" value="'.$number.'"'.($checked ? '' : ' disabled="disabled"').'></div></div></div>';
        }

        $html = '<div class="form-container"><h4>Distinta</h4><form id="schedule-form" method="post">'
            .'<input type="hidden" name="op" value="3"><input type="hidden" name="mid" value="'.$mid.'"><input type="hidden" name="gtid" value="'.$gtid.'">'
            .'<div class="row"><div class="col-xs-24"><div class="form-group"><label>AMIR COSTRUZIONI</label><div class="checkbox"><label>Seleziona/deseleziona tutto'
            .'<input type="checkbox" name="checkall"></label></div></div></div><div class="clearfix"></div>'.$rows.'</div>'
            .'<textarea name="note"></textarea>'
            .'<div class="row"><div class="col-xs-24"><div class="form-group"><label></label><button class="btn btn-success btn-block" type="submit">SALVA</button></div></div></div>'
            .'</form></div>';

        return (string) json_encode(['html' => $html]);
    }
}
