<?php
/**
 * scripts/backfill_school_ids.php — set school_id from known seed roster (fast).
 * Run: php scripts/backfill_school_ids.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_user_school_id_column();
$pdo = db();

$map = [
    // Teachers
    'B_Delossantos' => '2026-00200',
    'C_Reyes' => '2026-00300',
    'D_Garcia' => '2026-00400',
    'E_Lopez' => '2026-00500',
    'F_Ramos' => '2026-00600',
    'G_Torres' => '2026-00700',
    'H_Villanueva' => '2026-00800',
];

// Students: same order as scripts/seed_teachers_students.php (schoolSeq starts at 101)
$rosters = [
    'BSIT-Block1' => [
        ['Alice', 'Mendoza'], ['John', 'Santos'], ['Maria', 'Cruz'], ['Nina', 'Bautista'], ['Oscar', 'Diaz'],
        ['Paula', 'Fernandez'], ['Quinn', 'Gomez'], ['Rita', 'Hernandez'], ['Sam', 'Ibarra'], ['Tina', 'Jimenez'],
    ],
    'BSIT-Block2' => [
        ['Kevin', 'Tan'], ['Liza', 'Ong'], ['Marco', 'Perez'], ['Nora', 'Quinto'], ['Owen', 'Reyes'],
        ['Pia', 'Salazar'], ['Rico', 'Torres'], ['Sara', 'Umandap'], ['Troy', 'Valdez'], ['Una', 'Wong'],
    ],
    'BSIT-Block3' => [
        ['Ana', 'Aquino'], ['Ben', 'Bernardo'], ['Cora', 'Castillo'], ['Dan', 'Domingo'], ['Eva', 'Espino'],
        ['Faye', 'Flores'], ['Gabe', 'Gutierrez'], ['Hana', 'Herrera'], ['Ian', 'Ignacio'], ['Jade', 'Javier'],
    ],
    'BSIT-Block4' => [
        ['Kara', 'Lim'], ['Leo', 'Manalo'], ['Mia', 'Navarro'], ['Ned', 'Ocampo'], ['Olive', 'Padilla'],
        ['Pete', 'Quiambao'], ['Rose', 'Rivera'], ['Sean', 'Santiago'], ['Tess', 'Tuazon'], ['Uri', 'Uy'],
    ],
    'BSIT-Block5' => [
        ['Vera', 'Abad'], ['Will', 'Bondoc'], ['Xena', 'Cortez'], ['Yuri', 'Delacruz'], ['Zara', 'Espiritu'],
        ['Aria', 'Fajardo'], ['Blake', 'Gonzales'], ['Cleo', 'Hopkinson'], ['Drew', 'Isidro'], ['Elle', 'Jacinto'],
    ],
    'BSIT-Block6' => [
        ['Finn', 'Katigbak'], ['Gina', 'Labrador'], ['Hugo', 'Mercado'], ['Ivy', 'Nolasco'], ['Joel', 'Ortega'],
        ['Kate', 'Pascual'], ['Luis', 'Quizon'], ['Mona', 'Ramos'], ['Nick', 'Soriano'], ['Opal', 'Tolentino'],
    ],
    'BSIT-Block7' => [
        ['Pam', 'Urbano'], ['Quin', 'Velasco'], ['Rae', 'Wagan'], ['Sid', 'Xavier'], ['Tia', 'Yap'],
        ['Ulys', 'Zamora'], ['Vee', 'Alonzo'], ['Wes', 'Briones'], ['Xio', 'Cabrera'], ['Yen', 'Dizon'],
    ],
];

function make_username(string $first, string $last): string
{
    $initial = strtoupper(substr(preg_replace('/\s+/', '', $first), 0, 1));
    $lastClean = ucfirst(strtolower(preg_replace('/\s+/', '', $last)));
    return $initial . '_' . $lastClean;
}

$seq = 101;
foreach ($rosters as $roster) {
    foreach ($roster as [$first, $last]) {
        $map[make_username($first, $last)] = '2026-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
        $seq++;
    }
}

// Database.sql seed students (if still present with original IDs)
$map['J_Santos'] = $map['J_Santos'] ?? '2026-00102';
$map['M_Cruz'] = $map['M_Cruz'] ?? '2026-00103';
$map['K_Tan'] = $map['K_Tan'] ?? '2026-00104';
$map['L_Ong'] = $map['L_Ong'] ?? '2026-00105';

$upd = $pdo->prepare('UPDATE users SET school_id = :s WHERE username = :u');
$n = 0;
foreach ($map as $username => $sid) {
    $upd->execute([':s' => $sid, ':u' => $username]);
    $n += $upd->rowCount();
}

echo "Updated {$n} row(s).\n";
$alice = $pdo->query("SELECT username, school_id FROM users WHERE username='A_Mendoza'")->fetch(PDO::FETCH_ASSOC);
print_r($alice);
