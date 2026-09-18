<?php
require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(); $kernel->boot(); Session::start();
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = new User(); $auth->user->getFromDB(2); Session::init($auth);
global $DB;
$sql = fn(string $q) => (int) $DB->request(['FROM' => new \Glpi\DBAL\QueryExpression("($q) AS x"), 'SELECT' => ['n']])->current()['n'];
$cases = [
  // label => [schema, params, expected total via SQL (null = no check)]
  'A count all (limit=1)'                 => ['Ticket', ['limit' => 1], 40000],
  'B open page 200, date_mod desc'        => ['Ticket', ['filter' => 'status.id=out=(5,6)', 'limit' => 200, 'sort' => 'date_mod:desc'], 5400],
  'D mass-actor tickets (200 rows)'       => ['Ticket', ['filter' => 'id=le=200', 'limit' => 200], 200],
  'E one mass ticket'                     => ['Ticket', ['filter' => 'id==1', 'limit' => 1], 1],
  'U0 users page 50'                      => ['User', ['limit' => 50], null],
  'C open assigned to user 1005'          => ['Ticket', ['filter' => 'assignees.id==1005;status.id=out=(5,6)', 'limit' => 50, 'sort' => 'date_mod:desc'], $sql("SELECT COUNT(DISTINCT t.id) n FROM glpi_tickets t JOIN glpi_tickets_users tu ON tu.tickets_id=t.id AND tu.type=2 AND tu.users_id=1005 WHERE t.status NOT IN (5,6)")],
  'V open waiting approval by user 1040'  => ['Ticket', ['filter' => 'validations.status==2;validations.approver_id==1040;status.id=out=(5,6)', 'limit' => 50], $sql("SELECT COUNT(DISTINCT t.id) n FROM glpi_tickets t JOIN glpi_ticketvalidations v ON v.tickets_id=t.id AND v.status=2 AND v.items_id_target=1040 WHERE t.status NOT IN (5,6)")],
  'T tickets with todo tasks of tech 1005' => ['Ticket', ['filter' => 'tasks.technician_id==1005;tasks.state==1', 'limit' => 50], $sql("SELECT COUNT(DISTINCT t.id) n FROM glpi_tickets t JOIN glpi_tickettasks k ON k.tickets_id=t.id AND k.users_id_tech=1005 AND k.state=1")],
  'F tickets with followups by user 1005' => ['Ticket', ['filter' => 'followups.author_id==1005', 'limit' => 50], $sql("SELECT COUNT(DISTINCT t.id) n FROM glpi_tickets t JOIN glpi_itilfollowups f ON f.itemtype='Ticket' AND f.items_id=t.id AND f.users_id=1005")],
  'P tickets pending for reason 2'        => ['Ticket', ['filter' => 'pending_reasons.reason_id==2', 'limit' => 50], $sql("SELECT COUNT(DISTINCT t.id) n FROM glpi_tickets t JOIN glpi_pendingreasons_items p ON p.itemtype='Ticket' AND p.items_id=t.id AND p.pendingreasons_id=2")],
  'U users in group 5'                    => ['User', ['filter' => 'groups.id==5', 'limit' => 50], $sql("SELECT COUNT(DISTINCT users_id) n FROM glpi_groups_users WHERE groups_id=5")],
];
$controller = ['Ticket' => \Glpi\Api\HL\Controller\ITILController::class, 'User' => \Glpi\Api\HL\Controller\AdministrationController::class];
$anyPlugin = false;
foreach ($cases as $label => [$schemaName, $params, $expected]) {
  $_GET = ['filter' => $params['filter'] ?? '', 'sort' => $params['sort'] ?? ''];
  $_SERVER['REQUEST_URI'] = '/api.php/x';
  $schema = $controller[$schemaName]::getKnownSchemas('2.3')[$schemaName];
  $needs = preg_match('/(assignees|validations|tasks|followups|pending_reasons|groups)\./', $params['filter'] ?? '');
  $has = isset($schema['properties']['assignees']) || isset($schema['properties']['groups']);
  if ($needs && !$has) { printf("%-40s n/a (needs plugin)  expected total=%s\n", $label, $expected); continue; }
  $anyPlugin = $anyPlugin || $has;
  $times = []; $r = null;
  try {
    for ($i = 0; $i < 3; $i++) { $t0 = microtime(true); $r = \Glpi\Api\HL\Search::getSearchResultsBySchema($schema, array_merge(['start' => 0], $params)); $times[] = (microtime(true) - $t0) * 1000; }
  } catch (\Throwable $e) { printf("%-40s ERROR %s\n", $label, substr($e->getMessage(), 0, 160)); continue; }
  sort($times);
  $ok = $expected === null ? '' : (($r['total'] ?? -1) == $expected ? ' OK' : " MISMATCH(expected $expected)");
  printf("%-40s median %6.0f ms  total=%s%s  [%s]\n", $label, $times[1], $r['total'] ?? '?', $ok, $has ? 'plugin props' : 'core schema');
}
echo $anyPlugin ? "== plugin active ==\n" : "== plugin inactive ==\n";
