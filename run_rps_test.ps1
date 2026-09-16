# PowerShell test runner for RPS backend
# Creates a room, joins as player2, performs a round, ends the game,
# and prints the full log and _latest snapshot after each step.

$createJson = php -r 'parse_str("action=create", $_GET); include "rps_backend.php";' | Out-String
$create = $createJson | ConvertFrom-Json
$room = $create.room_id
$p1 = $create.player_id
Write-Output "Created room $room p1 $p1"

# Join as player 2
$joinJson = php -r ('parse_str("action=join&room=' + $room + '", $_GET); include "rps_backend.php";') | Out-String
$join = $joinJson | ConvertFrom-Json
$p2 = $join.player_id
Write-Output "Joined as p2 $p2"

# Player 1 move
$mv1 = php -r ('parse_str("action=move&room=' + $room + '&player=' + $p1 + '&choice=rock", $_GET); include "rps_backend.php";') | Out-String
Write-Output "p1 move response: $mv1"

# Player 2 move
$mv2 = php -r ('parse_str("action=move&room=' + $room + '&player=' + $p2 + '&choice=scissors", $_GET); include "rps_backend.php";') | Out-String
Write-Output "p2 move response: $mv2"

Start-Sleep -Milliseconds 300

Write-Output '---- full log ----'
Get-Content -Raw (Join-Path -Path $PSScriptRoot -ChildPath "status/rps/$room.txt")
Write-Output '---- latest ----'
Get-Content -Raw (Join-Path -Path $PSScriptRoot -ChildPath "status/rps/${room}_latest.txt")

# End game
$endJson = php -r "parse_str('action=end_game&room=$room', \\$_GET); include 'rps_backend.php';" | Out-String
Write-Output "end_game response: $endJson"

Start-Sleep -Milliseconds 200

Write-Output '---- after end full ----'
Get-Content -Raw (Join-Path -Path $PSScriptRoot -ChildPath "status/rps/$room.txt")
Write-Output '---- after end latest ----'
Get-Content -Raw (Join-Path -Path $PSScriptRoot -ChildPath "status/rps/${room}_latest.txt")
