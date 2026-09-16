<?php
/**
 * status_config.php - optional overrides for the shared status system
 * (status_helper.php). This file is OPTIONAL: delete it and the built-in
 * default of 180 seconds (3 minutes) applies to every game.
 *
 * Change the inactivity timeout for ALL games:
 */
$game_inactivity_timeout_seconds = 180;

/**
 * Or set a per-game timeout (games: rps, xo, connect4, mathquiz,
 * snakeladder, yatzy, memory). Uncomment to use:
 */
// $game_inactivity_timeout_map = ['memory' => 120, 'xo' => 150];
