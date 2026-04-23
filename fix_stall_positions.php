<?php

// Fix NULL positions for activity 3 stalls 5-10
$stalls = App\Models\EventStall::where('activity_id', 3)
    ->where('is_ambulant', false)
    ->whereIn('stall_number', ['5', '6', '7', '8', '9', '10'])
    ->get();

echo "Found " . $stalls->count() . " stalls to fix\n";

foreach ($stalls as $stall) {
    $stallNumber = (int)$stall->stall_number;
    $stall->row_position = 1;
    $stall->column_position = $stallNumber;
    $stall->save();
    echo "Updated stall {$stallNumber}: row=1, col={$stallNumber}\n";
}

echo "Done!\n";
