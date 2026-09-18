<?php
/**
 * Standalone check: run the hook against a minimal fake ITIL schema set and
 * print the flattened properties it adds. Usage: php tools/check-schema.php
 */
require __DIR__ . '/../src/ApiSchemas.php';

use GlpiPlugin\Hlapiactors\ApiSchemas;

putenv('HLAPIACTORS_ALWAYS=1');
$fake = ['controller' => ApiSchemas::ITIL_CONTROLLER, 'schemas' => [
    'Ticket'  => ['x-version-introduced' => '2.0', 'properties' => ['id' => ['type' => 'integer'], 'team' => ['type' => 'array']]],
    'Change'  => ['x-version-introduced' => '2.0', 'properties' => ['id' => ['type' => 'integer']]],
    'Problem' => ['x-version-introduced' => '2.0', 'properties' => ['id' => ['type' => 'integer']]],
    'TeamMember' => ['properties' => ['id' => ['type' => 'integer']]],
]];
$out = ApiSchemas::redefine($fake);
$untouched = ApiSchemas::redefine(['controller' => 'Other', 'schemas' => ['Ticket' => ['properties' => []]]]);
assert($untouched['schemas']['Ticket']['properties'] === [], 'other controllers must be untouched');

foreach (['Ticket', 'Change', 'Problem'] as $s) {
    $props = $out['schemas'][$s]['properties'];
    $added = array_diff(array_keys($props), array_keys($fake['schemas'][$s]['properties']));
    echo "$s: " . implode(', ', $added) . "\n";
}
$a = $out['schemas']['Ticket']['properties']['assignees'];
printf("Ticket.assignees join: %s.%s = _.%s AND type=%d, then glpi_users.id = link.%s; filterable paths: %s\n",
    $a['items']['x-join']['ref-join']['table'], $a['items']['x-join']['ref-join']['field'], $a['items']['x-join']['ref-join']['fkey'],
    $a['items']['x-join']['ref-join']['condition']['type'], $a['items']['x-join']['fkey'],
    implode(', ', array_map(fn($k) => "assignees.$k", array_keys($a['items']['properties']))));
assert(isset($out['schemas']['Ticket']['properties']['team']), 'core team property must stay');
foreach (['validations', 'tasks', 'followups', 'pending_reasons'] as $p) { assert(isset($out['schemas']['Ticket']['properties'][$p]), "$p missing"); }
assert(!isset($out['schemas']['Problem']['properties']['validations']), 'problems have no validations');
$u = ApiSchemas::redefine(['controller' => ApiSchemas::ADMIN_CONTROLLER, 'schemas' => ['User' => ['properties' => ['id' => []]]]]);
assert(isset($u['schemas']['User']['properties']['groups']), 'User.groups missing');
echo "OK\n";
