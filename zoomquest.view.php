<?php
/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * ZoomQuest implementation: © Your Name
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * zoomquest.view.php
 */

require_once(APP_BASE_PATH . "view/common/game.view.php");

class view_zoomquest_zoomquest extends game_view
{
    protected function getGameName()
    {
        return "zoomquest";
    }

    function build_page($viewArgs)
    {
        // Nothing to build - UI is handled by JavaScript
    }
}
