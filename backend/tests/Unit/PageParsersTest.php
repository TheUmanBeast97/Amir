<?php

namespace Tests\Unit;

use App\Services\Xfive\Archive\PageParsers;
use PHPUnit\Framework\TestCase;

/** I lettori delle pagine di XFive usati dall'archivio, su frammenti costruiti come quelli osservati sul sito. */
class PageParsersTest extends TestCase
{
    public function test_the_tournament_list_gives_id_name_sport_team_count_and_dates(): void
    {
        $html = <<<'HTML'
        <a href='https://www.xfivesport.it/it/tournament/192/uispic-champions-league/stream/'><div class="event-tournament-list-box">
          <div class='image'><img src="https://www.xfivesport.it/tpl/img/sport/48.png"></div>
          <div class='data'><div class='name'>UISPIC CHAMPIONS LEAGUE</div><div class='info'>Calcio a 7 | 9 Squadre</div><div class='date'>Dal 5 ottobre al 30 aprile</div></div>
        </div></a>
        <a href='https://www.xfivesport.it/it/tournament/184/friendly/stream/'><div class="event-tournament-list-box">
          <div class='data'><div class='name'>FRIENDLY</div><div class='info'>Calcio a 8 | 15 Squadre</div><div class='date'>Dal 1 settembre</div></div>
        </div></a>
        HTML;

        $list = PageParsers::tournamentList($html);

        $this->assertCount(2, $list);
        $this->assertSame(['id' => 192, 'slug' => 'uispic-champions-league', 'name' => 'UISPIC CHAMPIONS LEAGUE', 'sport' => 'Calcio a 7', 'teams' => 9, 'dates' => 'Dal 5 ottobre al 30 aprile', 'image_url' => 'https://www.xfivesport.it/tpl/img/sport/48.png'], $list[0]);
        $this->assertSame(184, $list[1]['id']);
        $this->assertNull($list[1]['image_url']);
    }

    public function test_the_tournament_header_reads_name_season_sport_and_flyer_and_is_null_elsewhere(): void
    {
        $html = <<<'HTML'
        <div class="tournament-flyer"><img src="https://cdn.enjore.com/wl/xfivesport_it/img/tournament/b/33B5m4Z5lOOaslA6T.jpg" /></div>
        <div class="tournament-info-container">
          <div class="tournament-title wl-font-header">UISPIC LEAGUE SERIE A [Alessandria]</div>
          <div class="category-title wl-font-header"></div>
          <div class="season-title"><a href="https://www.xfivesport.it/it/league/1/xfive/season/4/20222023/tournament-list/">Stagione 2022/2023</a></div>
          <div class="tournament-sport">Calcio a 7 - Maschile</div>
        </div>
        HTML;

        $h = PageParsers::tournamentHeader($html);

        $this->assertSame('UISPIC LEAGUE SERIE A [Alessandria]', $h['name']);
        $this->assertNull($h['category']);
        $this->assertSame(4, $h['season_id']);
        $this->assertSame('2022/2023', $h['season']);
        $this->assertSame('Calcio a 7 - Maschile', $h['sport']);
        $this->assertStringContainsString('33B5m4Z5lOOaslA6T.jpg', $h['flyer_url']);

        $this->assertNull(PageParsers::tournamentHeader('<html><body><h1>Pagina non trovata</h1></body></html>'));
    }

    public function test_the_standings_zip_the_name_table_with_the_numbers_table(): void
    {
        $html = <<<'HTML'
        <h4 class="section-head">Girone A</h4>
        <div class="tables-container">
          <div class="left-table">
            <div class="tables-header tables-row"><div class='col-pos tables-pos'>#</div><div class='col-name tables-main'>Squadra</div></div>
            <div class="tables-body tables-row even"><div class="col-pos tables-pos"><small>1</small></div><div class='col-name tables-main'><img src="https://cdn.enjore.com/b/159.png"> <div class="participant-name">AMIR COSTRUZIONI</div></div></div>
            <div class="tables-body tables-row odd"><div class="col-pos tables-pos"><small>2</small></div><div class='col-name tables-main'><img src="https://cdn.enjore.com/b/3516.png"> <div class="participant-name">CAFFÈ KM0</div></div></div>
          </div>
          <div class="right-table"><div>
            <div class='tables-header tables-row'><div class='col-data' title="Punti Classifica">Pt</div><div class='col-data' title="Partite Disputate">G</div><div class='col-data' title="Gol Fatti">F</div></div>
            <div class="tables-body tables-row even"><div class="col-data"><small>6</small></div><div class="col-data"><small>2</small></div><div class="col-data"><small>7</small></div></div>
            <div class="tables-body tables-row odd"><div class="col-data"><small>3</small></div><div class="col-data"><small>2</small></div><div class="col-data"><small>4</small></div></div>
          </div></div>
        </div>
        HTML;

        $tables = PageParsers::standings($html);

        $this->assertCount(1, $tables);
        $this->assertSame('Girone A', $tables[0]['group']);
        $this->assertSame([['key' => 'Pt', 'label' => 'Punti Classifica'], ['key' => 'G', 'label' => 'Partite Disputate'], ['key' => 'F', 'label' => 'Gol Fatti']], $tables[0]['columns']);
        $this->assertSame(['position' => 1, 'name' => 'AMIR COSTRUZIONI', 'badge_url' => 'https://cdn.enjore.com/b/159.png', 'values' => ['Pt' => 6, 'G' => 2, 'F' => 7]], $tables[0]['rows'][0]);
        $this->assertSame('CAFFÈ KM0', $tables[0]['rows'][1]['name']);
        $this->assertSame(3, $tables[0]['rows'][1]['values']['Pt']);
    }

    public function test_the_team_list_gives_tournament_team_ids_names_and_badges(): void
    {
        $html = '<div class="t-participants-container"><a class="participant-element" href="https://www.xfivesport.it/it/team/3604/amir-costruzioni/"><div class="participant-single-container"><img class="img-circle" src="https://cdn.enjore.com/b/159.png"><div class="participant-detail"><div>AMIR COSTRUZIONI</div><div class="last-5-container"></div></div></div></a>'
            .'<a class="participant-element" href="https://www.xfivesport.it/it/team/3613/valons/"><div class="participant-single-container"><img class="img-circle" src="https://cdn.enjore.com/b/9.png"><div class="participant-detail"><div>VALONS</div></div></div></a></div>';

        $teams = PageParsers::teamList($html);

        $this->assertSame([
            ['id' => 3604, 'slug' => 'amir-costruzioni', 'name' => 'AMIR COSTRUZIONI', 'badge_url' => 'https://cdn.enjore.com/b/159.png'],
            ['id' => 3613, 'slug' => 'valons', 'name' => 'VALONS', 'badge_url' => 'https://cdn.enjore.com/b/9.png'],
        ], $teams);
    }

    public function test_the_team_page_gives_club_staff_and_roster_with_numbers_roles_photos_and_country(): void
    {
        $html = <<<'HTML'
        <div class="team-header"><img src="https://cdn.enjore.com/wl/xfivesport_it/img/team/badge/s/159P0F4VqfzqtoIt0V.png"/>
          <div class="team-name wl-font-header"><h3 class="wl-font-header">AMIR COSTRUZIONI</h3></div></div>
        <div class="col-xs-24 team-staff-container"><div class="info">Presidente: Vladimir Filli</div><div class="info">Allenatore: Edoardo Fracchia</div>
          <a id="linkToHistory" href="https://www.xfivesport.it/it/team-h/159/amir-costruzioni/">Storico Squadra</a></div>
        <div id="team-roster-container">
          <div class="col-xs-24 col-sm-12"><a href="https://www.xfivesport.it/it/player/62631/edoardo-giovanni-fracchia/"><div class="player-container"><img class="round-img" src="https://cdn.enjore.com/wl/xfivesport_it/img/player/q/2364-CzIcFg6oB41epMZoZqnZ.png" />
            <div class="player-info"><span class="player-name">Fracchia Edoardo Giovanni</span><br /><span class="player-role">Centrocampista</span><br /><img class="player-country-img" title="Italia" src="/IT.gif"><div class="player-number"> 10 </div></div></div></a></div>
          <div class="col-xs-24 col-sm-12"><a href="https://www.xfivesport.it/it/player/62629/filippo-ferrando/"><div class="player-container"><img class="round-img" src="https://cdn.enjore.com/p/2157.png" />
            <div class="player-info"><span class="player-name">Ferrando Filippo</span><br /><span class="player-role"></span><br /></div></div></a></div>
        </div>
        HTML;

        $team = PageParsers::teamPage($html);

        $this->assertSame('AMIR COSTRUZIONI', $team['name']);
        $this->assertStringContainsString('/img/team/badge/', $team['badge_url']);
        $this->assertSame(159, $team['club_id']);
        $this->assertSame('amir-costruzioni', $team['club_slug']);
        $this->assertSame(['Presidente' => 'Vladimir Filli', 'Allenatore' => 'Edoardo Fracchia'], $team['staff']);
        $this->assertCount(2, $team['players']);
        $this->assertSame(['id' => 62631, 'slug' => 'edoardo-giovanni-fracchia', 'name' => 'Fracchia Edoardo Giovanni', 'role' => 'Centrocampista', 'number' => '10', 'photo_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/player/q/2364-CzIcFg6oB41epMZoZqnZ.png', 'country' => 'Italia'], $team['players'][0]);
        $this->assertNull($team['players'][1]['number']);
        $this->assertNull($team['players'][1]['role']);

        $this->assertNull(PageParsers::teamPage('<html><body>niente</body></html>'));
    }

    public function test_the_club_page_lists_global_player_profiles_and_the_docs_page_lists_cdn_documents(): void
    {
        $club = PageParsers::clubPage('<h3>AMIR COSTRUZIONI</h3><a href="https://www.xfivesport.it/it/player-info/2365/stiven-berberi/">Stiven Berberi</a><a href="https://www.xfivesport.it/it/player-info/2365/stiven-berberi/">di nuovo</a><a href="https://www.xfivesport.it/it/player-info/3431/augusto-boccia/">Augusto Boccia</a>');
        $this->assertSame('AMIR COSTRUZIONI', $club['name']);
        $this->assertSame([['id' => 2365, 'slug' => 'stiven-berberi', 'name' => 'Stiven Berberi'], ['id' => 3431, 'slug' => 'augusto-boccia', 'name' => 'Augusto Boccia']], $club['players']);

        $docs = PageParsers::docs('<a href="https://cdn.enjore.com/wl/xfivesport_it/doc/tournament_doc/187-QbTp99bQ01-norme-tecniche.pdf">Norme tecniche</a><a href="https://www.xfivesport.it/it/docs/187/x/">Modulistica</a><a href="https://cdn.enjore.com/wl/xfivesport_it/doc/tournament_doc/187-QbTp99bQ01-norme-tecniche.pdf">doppione</a>');
        $this->assertSame([['url' => 'https://cdn.enjore.com/wl/xfivesport_it/doc/tournament_doc/187-QbTp99bQ01-norme-tecniche.pdf', 'title' => 'Norme tecniche']], $docs);
    }

    public function test_image_paths_stay_inside_the_images_folder(): void
    {
        $this->assertSame('team/badge/q/159P0F4VqfzqtoIt0V.png', \App\Services\Xfive\Archive\XfiveArchiver::imagePath('https://cdn.enjore.com/wl/xfivesport_it/img/team/badge/q/159P0F4VqfzqtoIt0V.png'));
        $this->assertSame('player/q/x.png', \App\Services\Xfive\Archive\XfiveArchiver::imagePath('https://cdn.enjore.com/wl/xfivesport_it/img/../../img/player/q/../../..//q/x.png'));
        $this->assertSame('a_b.png', \App\Services\Xfive\Archive\XfiveArchiver::imagePath('https://cdn.enjore.com/img/a%20b.png'));
        $this->assertNull(\App\Services\Xfive\Archive\XfiveArchiver::imagePath('https://cdn.enjore.com/wl/x/doc/file.pdf'));
        $this->assertNull(\App\Services\Xfive\Archive\XfiveArchiver::imagePath('https://cdn.enjore.com/img/../..'));
    }

    public function test_latin1_pages_are_repaired(): void
    {
        $broken = mb_convert_encoding('<div class="tournament-title">CAFFÈ KM0</div>', 'Windows-1252', 'UTF-8');
        $this->assertSame('CAFFÈ KM0', PageParsers::tournamentHeader($broken)['name']);
    }
}
