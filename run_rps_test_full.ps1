Param(
    [string]$Server = "",
    [int]$Rounds = 3,
    [string[]]$P1Choices = @("rock","paper","scissors"),
    [string[]]$P2Choices = @("paper","scissors","rock")
)

function Run-PHPInline($query) {
    $temp = Join-Path $PSScriptRoot "_rps_exec.php"
    $phpStub = "<?php\n$\n$qs = isset($argv[1]) ? $argv[1] : '';\nparse_str($qs, \\$_GET);\ninclude 'rps_backend.php';\n"
    Set-Content -Path $temp -Value $phpStub -Encoding UTF8
    $proc = & php $temp $query 2>&1
    return $proc | Out-String | ConvertFrom-Json
}

function Call-Server($server, $query) {
    $url = "$server/rps_backend.php?$query"
    return Invoke-RestMethod -Uri $url -Method Get
}

$useHttp = ($Server -ne "")
Write-Output "Using HTTP mode: $useHttp"

if ($useHttp) {
    $create = Call-Server $Server "action=create"
} else {
    $create = Run-PHPInline "action=create"
}
$room = $create.room_id
$p1 = $create.player_id
Write-Output "Created room $room p1 $p1"

if ($useHttp) {
    $join = Call-Server $Server "action=join&room=$room"
} else {
    $join = Run-PHPInline "action=join&room=$room"
}
$p2 = $join.player_id
Write-Output "Joined as p2 $p2"

for ($r = 0; $r -lt $Rounds; $r++) {
    $roundNum = $r + 1
    $choice1 = $P1Choices[$r % $P1Choices.Count]
    $choice2 = $P2Choices[$r % $P2Choices.Count]

    Write-Output "--- Round $roundNum: p1=$choice1 p2=$choice2 ---"

    if ($useHttp) {
        $mv1 = Call-Server $Server "action=move&room=$room&player=$p1&choice=$choice1"
    } else {
        $mv1 = Run-PHPInline "action=move&room=$room&player=$p1&choice=$choice1"
    }
    Write-Output "p1 move response: $(($mv1 | ConvertTo-Json -Compress))"

    if ($useHttp) {
        $mv2 = Call-Server $Server "action=move&room=$room&player=$p2&choice=$choice2"
    } else {
        $mv2 = Run-PHPInline "action=move&room=$room&player=$p2&choice=$choice2"
    }
    Write-Output "p2 move response: $(($mv2 | ConvertTo-Json -Compress))"

    Start-Sleep -Milliseconds 200

    Write-Output '---- full log ----'
    $fullPath = Join-Path $PSScriptRoot "status/rps/$room.txt"
    if (Test-Path $fullPath) { Get-Content -Raw $fullPath } else { Write-Output "(no full log yet)" }

    Write-Output '---- latest ----'
    $latestPath = Join-Path $PSScriptRoot "status/rps/${room}_latest.txt"
    if (Test-Path $latestPath) { Get-Content -Raw $latestPath } else { Write-Output "(no latest snapshot yet)" }

    # Advance round if backend exposes next_round action
    if ($useHttp) {
        $nr = Call-Server $Server "action=next_round&room=$room"
    } else {
        $nr = Run-PHPInline "action=next_round&room=$room"
    }
    Write-Output "next_round response: $(($nr | ConvertTo-Json -Compress))"
}

# End match
if ($useHttp) {
    $end = Call-Server $Server "action=end_game&room=$room&player=$p1"
} else {
    $end = Run-PHPInline "action=end_game&room=$room&player=$p1"
}
Write-Output "end_game response: $(($end | ConvertTo-Json -Compress))"

Start-Sleep -Milliseconds 200

Write-Output '---- final full ----'
$fullPath = Join-Path $PSScriptRoot "status/rps/$room.txt"
if (Test-Path $fullPath) { Get-Content -Raw $fullPath } else { Write-Output "(no full log)" }

Write-Output '---- final latest ----'
$latestPath = Join-Path $PSScriptRoot "status/rps/${room}_latest.txt"
if (Test-Path $latestPath) { Get-Content -Raw $latestPath } else { Write-Output "(no latest snapshot)" }
