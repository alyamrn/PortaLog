<?php
// auto_cleanup_reminders.php
require_once "db_connect.php";

function autoCleanupReminders($pdo, $vessel_id)
{
    $cleanupQuery = $pdo->prepare("
        SELECT reminder_id, vessel_id, log_date 
        FROM reminders 
        WHERE vessel_id = ?
    ");
    $cleanupQuery->execute([$vessel_id]);
    $reminders = $cleanupQuery->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reminders as $r) {
        $vid = $r['vessel_id'];
        $date = $r['log_date'];
        $rid = $r['reminder_id'];

        // Check if day is locked
        $statusQ = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=? LIMIT 1");
        $statusQ->execute([$vid, $date]);
        $status = $statusQ->fetchColumn();

        // Count modules filled
        $modules = [
            'activitylogs',
            'pobpersons',
            'garbagelogs',
            'navigationreports',
            'oilrecordbook',
            'rob_records',
            'runninghours'
        ];

        $filled = 0;
        foreach ($modules as $t) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE vessel_id=? AND log_date=?");
            $q->execute([$vid, $date]);
            if ($q->fetchColumn() > 0)
                $filled++;
        }

        // Delete reminder if all forms filled & day locked
        if ($filled === count($modules) && $status === 'LOCKED') {
            $del = $pdo->prepare("DELETE FROM reminders WHERE reminder_id=?");
            $del->execute([$rid]);
        }
    }
}
