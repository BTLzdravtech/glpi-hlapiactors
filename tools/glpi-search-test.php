<?php
require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = new User();
$auth->user->getFromDB(2); // glpi super-admin
Session::init($auth);

$u = new User();
$tech1 = $u->add(['name' => 'tech1', 'realname' => 'Smith', 'firstname' => 'Jane']);
$tech2 = $u->add(['name' => 'tech2', 'realname' => 'Novak', 'firstname' => 'Petr']);
$req   = $u->add(['name' => 'req1', 'realname' => 'Requester', 'firstname' => 'Rita']);
$g = new Group();
$grp = $g->add(['name' => 'Helpdesk', 'entities_id' => 0]);
$t = new Ticket();
$t1 = $t->add(['name' => 'A', 'content' => 'a', 'entities_id' => 0, '_users_id_assign' => $tech1, '_users_id_requester' => $req]);
$t2 = $t->add(['name' => 'B', 'content' => 'b', 'entities_id' => 0, '_users_id_assign' => $tech2, '_users_id_requester' => $req]);
$t3 = $t->add(['name' => 'C', 'content' => 'c', 'entities_id' => 0, '_users_id_assign' => $tech1, '_groups_id_assign' => $grp, '_users_id_observer' => $tech2]);
$t4 = $t->add(['name' => 'D', 'content' => 'd', 'entities_id' => 0, '_users_id_requester' => $tech1]);
$t5 = $t->add(['name' => 'E closed', 'content' => 'e', 'entities_id' => 0, '_users_id_assign' => $tech1, 'status' => Ticket::CLOSED]);
echo "users tech1=$tech1 tech2=$tech2 req=$req grp=$grp tickets t1=$t1 t2=$t2 t3=$t3 t4=$t4 t5=$t5\n";

putenv('HLAPIACTORS_ALWAYS=1');
$schemas = \Glpi\Api\HL\Controller\ITILController::getKnownSchemas('2.3');
foreach (['Ticket', 'Change', 'Problem'] as $s) {
    $added = array_intersect(array_keys($schemas[$s]['properties']), ['requesters', 'observers', 'assignees', 'requester_groups', 'observer_groups', 'assignee_groups']);
    echo "$s plugin props: " . implode(',', $added) . " | team present: " . (isset($schemas[$s]['properties']['team']) ? 'yes' : 'NO') . "\n";
}
$schema = $schemas['Ticket'];

$failures = 0;
function q(array $schema, string $filter, array $extra = []): array {
    $r = \Glpi\Api\HL\Search::getSearchResultsBySchema($schema, array_merge(['filter' => $filter, 'start' => 0, 'limit' => 50], $extra));
    return $r;
}
function check(string $label, array $schema, string $filter, array $expect, array $extra = []): void {
    global $failures;
    try {
        $r = q($schema, $filter, $extra);
        $ids = array_map('intval', array_column($r['results'] ?? [], 'id'));
        sort($ids); sort($expect);
        $ok = $ids === $expect;
        if (!$ok) $failures++;
        printf("%s %-46s -> ids=%s total=%s (expected %s)\n", $ok ? 'OK  ' : 'FAIL', $filter, json_encode($ids), $r['total'] ?? '?', json_encode($expect));
    } catch (\Throwable $e) {
        $failures++;
        printf("FAIL %-46s -> %s: %s\n", $filter, get_class($e), substr($e->getMessage(), 0, 200));
    }
}
check('assigned tech1', $schema, "assignees.id==$tech1", [$t1, $t3, $t5]);
check('assigned tech1 open', $schema, "assignees.id==$tech1;status.id=out=(5,6)", [$t1, $t3]);
check('requester tech1', $schema, "requesters.id==$tech1", [$t4]);
check('requester req', $schema, "requesters.id==$req", [$t1, $t2]);
check('observer tech2', $schema, "observers.id==$tech2", [$t3]);
check('assignee group', $schema, "assignee_groups.id==$grp", [$t3]);
check('username', $schema, "assignees.username==tech2", [$t2]);
check('realname ilike', $schema, "assignees.realname=ilike=*smith*", [$t1, $t3, $t5]);
check('in list', $schema, "assignees.id=in=($tech1,$tech2)", [$t1, $t2, $t3, $t5]);
check('nobody', $schema, "assignees.id==999999", []);
check('sort by assignee', $schema, "status.id=out=(5,6)", [$t1, $t2, $t3, $t4], ['sort' => 'assignees.username:desc']);

$r = q($schema, "id==$t3");
$row = $r['results'][0] ?? [];
echo "row t3 assignees=" . json_encode($row['assignees'] ?? null) . "\n";
echo "row t3 assignee_groups=" . json_encode($row['assignee_groups'] ?? null) . "\n";
echo "row t3 observers=" . json_encode($row['observers'] ?? null) . " team_count=" . count($row['team'] ?? []) . "\n";
if (($row['assignees'][0]['id'] ?? null) != $tech1) { $failures++; echo "FAIL row content\n"; }
echo $failures === 0 ? "ALL OK\n" : "FAILURES: $failures\n";
