<?php
session_start();
require_once "db_connect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}

$vessel_id = $_SESSION['vessel_id'];
$date = $_POST['log_date'] ?? date('Y-m-d');
$category = $_POST['category'] ?? '';

if (empty($_POST['product']) || empty($category)) {
    die("Missing product data or category.");
}

try {
    $pdo->beginTransaction();

    for ($i = 0; $i < count($_POST['product']); $i++) {
        $product = trim($_POST['product'][$i]);
        if ($product === '') continue;

        $unit = $_POST['unit'][$i] ?? '';

        // sanitize numeric fields
        $prev = $_POST['previous_rob'][$i] ?? 0;
        $loaded = $_POST['loaded_today'][$i] ?? 0;
        $disch = $_POST['discharged_today'][$i] ?? 0;
        $produced = $_POST['produced_today'][$i] ?? 0;
        $density = $_POST['density'][$i] ?? 0;
        $daily = $_POST['daily_consumption'][$i] ?? 0;
        $adj = $_POST['adjustment'][$i] ?? 0;
        $current = $_POST['current_rob'][$i] ?? 0;
        $maxcap = $_POST['max_capacity'][$i] ?? 0;
        $remarks = $_POST['remarks'][$i] ?? '';

        // --- ensure product exists in rob_products ---
        $checkProd = $pdo->prepare("SELECT COUNT(*) FROM rob_products WHERE vessel_id=? AND product_name=?");
        $checkProd->execute([$vessel_id, $product]);
        if ($checkProd->fetchColumn() == 0) {
            // Use INSERT IGNORE to avoid duplicate primary/unique key errors if concurrent inserts happen
            $insProd = $pdo->prepare("INSERT IGNORE INTO rob_products (vessel_id, category, product_name, unit) VALUES (?, ?, ?, ?)");
            try {
                $insProd->execute([$vessel_id, $category, $product, $unit]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }

        // --- check if record exists for today ---
        $q = $pdo->prepare("SELECT rob_id FROM rob_records WHERE vessel_id=? AND log_date=? AND product=?");
        $q->execute([$vessel_id, $date, $product]);
        $id = $q->fetchColumn();

        if ($id) {
            // --- update existing ---
            $upd = $pdo->prepare(
                "UPDATE rob_records SET previous_rob=?, loaded_today=?, discharged_today=?, produced_today=?, density=?, daily_consumption=?, adjustment=?, current_rob=?, max_capacity=?, remarks=? WHERE rob_id=?"
            );
            $upd->execute([$prev, $loaded, $disch, $produced, $density, $daily, $adj, $current, $maxcap, $remarks, $id]);
        } else {
            // --- insert new with upsert behaviour ---
            $ins = $pdo->prepare(
                "INSERT INTO rob_records (
                    vessel_id, log_date, category, product, unit,
                    previous_rob, loaded_today, discharged_today, produced_today,
                    density, daily_consumption, adjustment, current_rob,
                    max_capacity, remarks
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    previous_rob=VALUES(previous_rob), loaded_today=VALUES(loaded_today),
                    discharged_today=VALUES(discharged_today), produced_today=VALUES(produced_today),
                    density=VALUES(density), daily_consumption=VALUES(daily_consumption),
                    adjustment=VALUES(adjustment), current_rob=VALUES(current_rob),
                    max_capacity=VALUES(max_capacity), remarks=VALUES(remarks)"
            );
            $ins->execute([
                $vessel_id,
                $date,
                $category,
                $product,
                $unit,
                $prev,
                $loaded,
                $disch,
                $produced,
                $density,
                $daily,
                $adj,
                $current,
                $maxcap,
                $remarks
            ]);
        }
    }

    // Safety: ensure any product listed in rob_products has a rob_records row for this date
    $fillStmt = $pdo->prepare(
        "INSERT IGNORE INTO rob_records (vessel_id, log_date, category, product, unit)
         SELECT p.vessel_id, :date, p.category, p.product_name, p.unit
         FROM rob_products p
         LEFT JOIN rob_records r ON r.vessel_id = p.vessel_id AND r.log_date = :date AND r.product = p.product_name
         WHERE p.vessel_id = :vessel_id AND r.rob_id IS NULL"
    );
    $fillStmt->execute([':date' => $date, ':vessel_id' => $vessel_id]);

    $pdo->commit();

    // If user clicked Confirm, ensure dailystatus row exists for this vessel/date
    if (!empty($_POST['confirm'])) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dailystatus WHERE vessel_id=? AND log_date=?");
        $chk->execute([$vessel_id, $date]);
        if ($chk->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO dailystatus (vessel_id, log_date, status) VALUES (?, ?, 'OPEN')");
            $ins->execute([$vessel_id, $date]);
        }
    }

    header("Location: engine-rob.php?date=" . urlencode($date) . "&success=1");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Error saving ROB data: " . $e->getMessage());
}
?>