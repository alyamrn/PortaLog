<?php
header('Content-Type: application/json');
session_start();
require_once "../db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

/* ============================================================
   SCHEMA MAP — change column names here if they differ
   ============================================================ */

/** POBPersons (you provided exact schema) */
$T_POB = 'pobpersons';
$POB = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'full_name' => 'full_name',
    'nationality' => 'nationality',
    'category' => 'category', // ENUM('CREW','PASSENGER','VISITOR','CONTRACTOR')
    'crew_role' => 'crew_role',
    'embark_date' => 'embark_date',
    'disembark_date' => 'disembark_date',
];

/** runninghours (assumed columns; edit if needed) */
$T_RUN = 'runninghours';
$RUN = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'generator_name' => 'generator_name',
    'duration_min' => 'duration_min',   // total minutes for the day/entry
];

/** rob_records (assumed; edit if needed) */
$T_ROB = 'rob_records';
$ROB = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'category' => 'category',       // 'LIQUID'/'DRY'
    'product' => 'product',   // e.g., FUEL, FRESH WATER
    'previous_rob' => 'previous_rob',
    'loaded_today' => 'loaded_today',
    'current_rob' => 'current_rob',
    'max_capacity' => 'max_capacity',   // if not available, leave as is (util will return 0)
];

/** garbagelogs (assumed; edit if needed) */
$T_GARB = 'garbagelogs';
$GARB = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'qty_m3' => 'qty_m3',
    'method' => 'method',         // INCINERATED, LANDED, etc.
    'category' => 'category',       // PLASTICS, FOOD, etc.
];

/** navigationreports (assumed; edit if needed) */
$T_NAV = 'navigationreports';
$NAV = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'weather' => 'weather',
    'speed_kn' => 'speed_kn',
    'destination' => 'destination',
];

/** oilrecordbook (assumed; edit if needed) */
$T_ORB = 'oilrecordbook';
$ORB = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
    'operation' => 'operation',
    'qty_mt' => 'qty_mt',
];

/** users (assumed; edit if needed) */
$T_USERS = 'users';
$USERS = [
    'user_id' => 'user_id',
    'full_name' => 'full_name',
    'role' => 'role',
    'email' => 'email',
];

/** reminders (assumed; edit if needed) */
$T_REM = 'reminders';
$REM = [
    'vessel_id' => 'vessel_id',
    'log_date' => 'log_date',
];

/** auditlog (assumed; edit if needed) */
$T_AUDIT = 'auditlog';
$AUD = [
    'user_id' => 'user_id',
    'action' => 'action',        // e.g., 'CREATE'
    'created_at' => 'created_at',   // DATETIME
];

/* ============================================================
   Helpers
   ============================================================ */

$action = $_REQUEST['action'] ?? '';
$vessel_id = $_REQUEST['vessel_id'] ?? '';
$start_date = $_REQUEST['start_date'] ?? date('Y-m-01');
$end_date = $_REQUEST['end_date'] ?? date('Y-m-d');

function vWhere($col)
{
    global $vessel_id;
    return $vessel_id === '' ? '1=1' : "$col = :vessel_id";
}
function addVesselParam(&$params)
{
    global $vessel_id;
    if ($vessel_id !== '')
        $params['vessel_id'] = $vessel_id;
}

/* ============================================================
   Actions
   ============================================================ */

try {
    switch ($action) {

        /* ===================== POB (Exact to your schema) ===================== */

        case 'pob_category_share': {
            // derive category share from pob_stints (distinct persons overlapping the period)
            $sql = "
                SELECT COALESCE(s.category,'UNKNOWN') AS category, COUNT(DISTINCT s.person_id) AS cnt
                FROM pob_stints s
                WHERE " . vWhere('s.vessel_id') . "
                  AND NOT (s.disembark_date < :start OR s.embark_date > :end)
                GROUP BY s.category
                ORDER BY cnt DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'pob_daily_total': { // distinct persons per report day (join dailystatus dates)
            $sql = "
                SELECT DATE(d.log_date) AS log_date, COUNT(DISTINCT s.person_id) AS total
                FROM dailystatus d
                LEFT JOIN pob_stints s
                  ON s.vessel_id = d.vessel_id
                  AND s.embark_date <= d.log_date
                  AND (s.disembark_date IS NULL OR s.disembark_date >= d.log_date)
                WHERE " . vWhere('d.vessel_id') . " AND d.log_date BETWEEN :start AND :end
                GROUP BY DATE(d.log_date)
                ORDER BY DATE(d.log_date)
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'pob_turnover_weekly': { // embark/disembark events per ISO week from pob_stints
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);

            $sqlE = "
                SELECT DATE_FORMAT(embark_date, '%x-%v') AS yearweek, COUNT(*) AS embark_cnt
                FROM pob_stints
                WHERE " . vWhere('vessel_id') . " AND embark_date BETWEEN :start AND :end
                GROUP BY DATE_FORMAT(embark_date, '%x-%v')
                ORDER BY yearweek
            ";
            $stE = $pdo->prepare($sqlE);
            $stE->execute($params);
            $embark = $stE->fetchAll(PDO::FETCH_KEY_PAIR);

            $sqlD = "
                SELECT DATE_FORMAT(disembark_date, '%x-%v') AS yearweek, COUNT(*) AS disembark_cnt
                FROM pob_stints
                WHERE " . vWhere('vessel_id') . " AND disembark_date BETWEEN :start AND :end
                GROUP BY DATE_FORMAT(disembark_date, '%x-%v')
                ORDER BY yearweek
            ";
            $stD = $pdo->prepare($sqlD);
            $stD->execute($params);
            $disembark = $stD->fetchAll(PDO::FETCH_KEY_PAIR);

            $weeks = array_unique(array_merge(array_keys($embark), array_keys($disembark)));
            sort($weeks);
            $out = [];
            foreach ($weeks as $w) {
                $out[] = [
                    'yearweek' => $w,
                    'embark' => (int) ($embark[$w] ?? 0),
                    'disembark' => (int) ($disembark[$w] ?? 0)
                ];
            }
            echo json_encode($out);
            break;
        }

        case 'pob_top_nationalities': {
            // nationality is stored on the crewlist table; join to pob_stints via person_id
            $sql = "
                SELECT COALESCE(c.nationality,'UNKNOWN') AS nationality, COUNT(DISTINCT s.person_id) cnt
                FROM pob_stints s
                LEFT JOIN crewlist c ON c.id = s.person_id
                WHERE " . vWhere('s.vessel_id') . " AND NOT (s.disembark_date < :start OR s.embark_date > :end)
                GROUP BY COALESCE(c.nationality,'UNKNOWN')
                ORDER BY cnt DESC
                LIMIT 5
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        /* ===================== Engine & Power (runninghours) ===================== */

        case 'engine_utilization': {
            global $pdo, $T_RUN, $RUN;
            $sql = "
                SELECT {$RUN['generator_name']} AS generator_name,
                       {$RUN['log_date']} AS log_date,
                       AVG(COALESCE({$RUN['duration_min']},0))/60 AS hours
                FROM {$T_RUN}
                WHERE " . vWhere($RUN['vessel_id']) . " AND {$RUN['log_date']} BETWEEN :start AND :end
                GROUP BY {$RUN['generator_name']}, {$RUN['log_date']}
                ORDER BY {$RUN['log_date']}, {$RUN['generator_name']}
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'engine_total_today': {
            global $pdo, $T_RUN, $RUN;
            $sql = "
                SELECT SUM(COALESCE({$RUN['duration_min']},0)) AS total_min
                FROM {$T_RUN}
                WHERE " . vWhere($RUN['vessel_id']) . " AND {$RUN['log_date']} = CURDATE()
            ";
            $st = $pdo->prepare($sql);
            $params = [];
            addVesselParam($params);
            $st->execute($params);
            $min = (int) ($st->fetchColumn() ?: 0);
            echo json_encode(['total_min' => $min, 'total_hours' => round($min / 60, 2)]);
            break;
        }

        case 'engine_idle_active': {
            global $pdo, $T_RUN, $RUN;
            // Consider a machine "active" if it has >0 minutes today
            $sql = "
                SELECT 
                    SUM(CASE WHEN COALESCE({$RUN['duration_min']},0) > 0 THEN 1 ELSE 0 END) AS active_cnt,
                    SUM(CASE WHEN COALESCE({$RUN['duration_min']},0) = 0 OR {$RUN['duration_min']} IS NULL THEN 1 ELSE 0 END) AS idle_cnt
                FROM (
                    SELECT {$RUN['generator_name']}, MAX({$RUN['duration_min']}) AS {$RUN['duration_min']}
                    FROM {$T_RUN}
                    WHERE " . vWhere($RUN['vessel_id']) . " AND {$RUN['log_date']} = CURDATE()
                    GROUP BY {$RUN['generator_name']}
                ) t
            ";
            $st = $pdo->prepare($sql);
            $params = [];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetch(PDO::FETCH_ASSOC));
            break;
        }

        /* ===================== Fuel & Liquids (rob_records) ===================== */

        case 'fuel_usage_daily': {
            global $pdo, $T_ROB, $ROB;
            $sql = "
                SELECT DATE({$ROB['log_date']}) d,
                       SUM(GREATEST(COALESCE({$ROB['previous_rob']},0)+COALESCE({$ROB['loaded_today']},0)-COALESCE({$ROB['current_rob']},0),0)) AS used
                FROM {$T_ROB}
                WHERE " . vWhere($ROB['vessel_id']) . " AND {$ROB['log_date']} BETWEEN :start AND :end
                  AND UPPER(TRIM({$ROB['category']}))='LIQUID' 
                  AND UPPER(TRIM({$ROB['product']})) IN ('FUEL','MGO','DIESEL')
                GROUP BY DATE({$ROB['log_date']})
                ORDER BY d
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'water_usage_daily': {
            global $pdo, $T_ROB, $ROB;
            $sql = "
                SELECT DATE({$ROB['log_date']}) d,
                       SUM(GREATEST(COALESCE({$ROB['previous_rob']},0)+COALESCE({$ROB['loaded_today']},0)-COALESCE({$ROB['current_rob']},0),0)) AS used
                FROM {$T_ROB}
                WHERE " . vWhere($ROB['vessel_id']) . " AND {$ROB['log_date']} BETWEEN :start AND :end
                  AND UPPER(TRIM({$ROB['product']}))='FRESH WATER'
                GROUP BY DATE({$ROB['log_date']})
                ORDER BY d
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'top_liquids_consumed': {
            global $pdo, $T_ROB, $ROB;
            $sql = "
                SELECT {$ROB['product']} AS product,
                       SUM(GREATEST(COALESCE({$ROB['previous_rob']},0)+COALESCE({$ROB['loaded_today']},0)-COALESCE({$ROB['current_rob']},0),0)) AS consumed
                FROM {$T_ROB}
                WHERE " . vWhere($ROB['vessel_id']) . " AND {$ROB['log_date']} BETWEEN :start AND :end
                GROUP BY {$ROB['product']}
                ORDER BY consumed DESC
                LIMIT 5
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'stock_capacity_util': {
            global $pdo, $T_ROB, $ROB;
            $sql = "
                SELECT {$ROB['product']} AS product,
                       SUM(COALESCE({$ROB['current_rob']},0)) AS rob,
                       SUM(COALESCE({$ROB['max_capacity']},0)) AS cap
                FROM {$T_ROB}
                WHERE " . vWhere($ROB['vessel_id']) . " AND {$ROB['log_date']} BETWEEN :start AND :end
                GROUP BY {$ROB['product']}
                ORDER BY {$ROB['product']}
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $cap = (float) $r['cap'];
                $rob = (float) $r['rob'];
                $r['util_pct'] = $cap > 0 ? round(($rob / $cap) * 100, 1) : 0;
            }
            echo json_encode($rows);
            break;
        }

        /* ===================== Garbage (garbagelogs) ===================== */

        case 'garbage_by_method': {
            global $pdo, $T_GARB, $GARB;
            $sql = "
                SELECT UPPER(TRIM({$GARB['method']})) AS method, SUM(COALESCE({$GARB['qty_m3']},0)) qty
                FROM {$T_GARB}
                WHERE " . vWhere($GARB['vessel_id']) . " AND {$GARB['log_date']} BETWEEN :start AND :end
                GROUP BY {$GARB['method']}
                ORDER BY qty DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'garbage_by_category': {
            global $pdo, $T_GARB, $GARB;
            $sql = "
                SELECT UPPER(TRIM({$GARB['category']})) AS category, SUM(COALESCE({$GARB['qty_m3']},0)) qty
                FROM {$T_GARB}
                WHERE " . vWhere($GARB['vessel_id']) . " AND {$GARB['log_date']} BETWEEN :start AND :end
                GROUP BY {$GARB['category']}
                ORDER BY qty DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'garbage_weekly': {
            global $pdo, $T_GARB, $GARB;
            $sql = "
                SELECT DATE_FORMAT({$GARB['log_date']},'%x-%v') AS yearweek, SUM(COALESCE({$GARB['qty_m3']},0)) qty
                FROM {$T_GARB}
                WHERE " . vWhere($GARB['vessel_id']) . " AND {$GARB['log_date']} BETWEEN :start AND :end
                GROUP BY DATE_FORMAT({$GARB['log_date']},'%x-%v')
                ORDER BY yearweek
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        /* ===================== Navigation (navigationreports) ===================== */

        case 'nav_entries_daily': {
            global $pdo, $T_NAV, $NAV;
            $sql = "
                SELECT {$NAV['log_date']} AS log_date, COUNT(*) cnt
                FROM {$T_NAV}
                WHERE " . vWhere($NAV['vessel_id']) . " AND {$NAV['log_date']} BETWEEN :start AND :end
                GROUP BY {$NAV['log_date']}
                ORDER BY {$NAV['log_date']}
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'nav_weather_freq': {
            global $pdo, $T_NAV, $NAV;
            $sql = "
                SELECT UPPER(TRIM({$NAV['weather']})) AS weather, COUNT(*) cnt
                FROM {$T_NAV}
                WHERE " . vWhere($NAV['vessel_id']) . " AND {$NAV['log_date']} BETWEEN :start AND :end
                GROUP BY {$NAV['weather']}
                ORDER BY cnt DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'nav_avg_speed_daily': {
            global $pdo, $T_NAV, $NAV;
            $sql = "
                SELECT {$NAV['log_date']} AS log_date, AVG(COALESCE({$NAV['speed_kn']},0)) avg_speed
                FROM {$T_NAV}
                WHERE " . vWhere($NAV['vessel_id']) . " AND {$NAV['log_date']} BETWEEN :start AND :end
                GROUP BY {$NAV['log_date']}
                ORDER BY {$NAV['log_date']}
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'nav_top_dest': {
            global $pdo, $T_NAV, $NAV;
            $sql = "
                SELECT {$NAV['destination']} AS destination, COUNT(*) cnt
                FROM {$T_NAV}
                WHERE " . vWhere($NAV['vessel_id']) . " AND {$NAV['log_date']} BETWEEN :start AND :end
                GROUP BY {$NAV['destination']}
                ORDER BY cnt DESC
                LIMIT 5
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        /* ===================== Oil & Maintenance (oilrecordbook, activitylogs) ===================== */

        case 'oil_ops_count': {
            global $pdo, $T_ORB, $ORB;
            $sql = "
                SELECT UPPER(TRIM({$ORB['operation']})) AS operation, COUNT(*) cnt
                FROM {$T_ORB}
                WHERE " . vWhere($ORB['vessel_id']) . " AND {$ORB['log_date']} BETWEEN :start AND :end
                GROUP BY {$ORB['operation']}
                ORDER BY cnt DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'oil_qty_by_op': {
            global $pdo, $T_ORB, $ORB;
            $sql = "
                SELECT UPPER(TRIM({$ORB['operation']})) AS operation, SUM(COALESCE({$ORB['qty_mt']},0)) qty
                FROM {$T_ORB}
                WHERE " . vWhere($ORB['vessel_id']) . " AND {$ORB['log_date']} BETWEEN :start AND :end
                GROUP BY {$ORB['operation']}
                ORDER BY qty DESC
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'maint_trend_weekly': {
            // activitylogs table assumed:
            //   vessel_id, log_date, activity_type (e.g., 'MAINTENANCE')
            $T_ACT = 'activitylogs';
            $ACT = ['vessel_id' => 'vessel_id', 'log_date' => 'log_date', 'category' => 'category'];

            global $pdo;
            $sql = "
                SELECT DATE_FORMAT({$ACT['log_date']},'%x-%v') AS yearweek, COUNT(*) cnt
                FROM {$T_ACT}
                WHERE " . vWhere($ACT['vessel_id']) . " AND {$ACT['log_date']} BETWEEN :start AND :end
                  AND UPPER(TRIM({$ACT['category']}))='MAINTENANCE'
                GROUP BY DATE_FORMAT({$ACT['log_date']},'%x-%v')
                ORDER BY yearweek
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        /* ===================== Users & Reminders ===================== */

        case 'users_by_role': {
            global $pdo, $T_USERS, $USERS;
            $sql = "
                SELECT {$USERS['role']} AS role, COUNT(*) cnt
                FROM {$T_USERS}
                GROUP BY {$USERS['role']}
                ORDER BY cnt DESC
            ";
            echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'reminders_per_month': {
            global $pdo, $T_REM, $REM;
            $sql = "
                SELECT DATE_FORMAT({$REM['log_date']}, '%Y-%m') ym, COUNT(*) cnt
                FROM {$T_REM}
                WHERE " . vWhere($REM['vessel_id']) . " AND {$REM['log_date']} BETWEEN :start AND :end
                GROUP BY DATE_FORMAT({$REM['log_date']}, '%Y-%m')
                ORDER BY ym
            ";
            $st = $pdo->prepare($sql);
            $params = ['start' => $start_date, 'end' => $end_date];
            addVesselParam($params);
            $st->execute($params);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'vessels_activity': {
            // top vessels by number of logs in dailystatus
            $T_DS = 'dailystatus';
            $DS = ['vessel_id' => 'vessel_id', 'log_date' => 'log_date'];

            global $pdo;
            $sql = "
                SELECT {$DS['vessel_id']} AS vessel_id, COUNT(*) cnt
                FROM {$T_DS}
                WHERE {$DS['log_date']} BETWEEN :start AND :end
                GROUP BY {$DS['vessel_id']}
                ORDER BY cnt DESC
                LIMIT 10
            ";
            $st = $pdo->prepare($sql);
            $st->execute(['start' => $start_date, 'end' => $end_date]);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        case 'top_logged_users': {
            global $pdo, $T_AUDIT, $AUD, $T_USERS, $USERS;

            $sql = "
        SELECT u.{$USERS['full_name']} AS full_name, COUNT(*) actions
        FROM {$T_AUDIT} a
        JOIN {$T_USERS} u ON u.{$USERS['user_id']} = a.{$AUD['user_id']}
        WHERE a.{$AUD['action']} = 'CREATE'
          AND a.{$AUD['action_time']} BETWEEN :start AND DATE_ADD(:end, INTERVAL 1 DAY)
        GROUP BY u.{$USERS['full_name']}
        ORDER BY actions DESC
        LIMIT 10
    ";
            $st = $pdo->prepare($sql);
            $st->execute(['start' => $start_date, 'end' => $end_date]);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            break;
        }

        default:
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
