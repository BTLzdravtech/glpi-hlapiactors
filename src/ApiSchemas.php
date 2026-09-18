<?php

namespace GlpiPlugin\Hlapiactors;

/**
 * Adds filterable join properties to schemas of the high-level API, on demand.
 *
 * Ticket / Change / Problem:
 *   requesters, observers, assignees           users  { id, username, realname, firstname }
 *   requester_groups, observer_groups,
 *   assignee_groups                            groups { id, name, completename }
 *   validations                                { id, status, approver_type, approver_id, requester_id, submission_date, validation_date }
 *   tasks                                      { id, state, technician_id, group_id, author_id, date, begin, end, duration, is_private, category_id }
 *   followups                                  { id, author_id, date, is_private, request_type_id }
 *   pending_reasons                            { id, reason_id, followup_frequency, bump_count, previous_status }
 * User:
 *   groups                                     { id, name, completename }
 *
 * Every property is an `x-join` (the construction the core uses for
 * `Project.tickets`), so RSQL filters become JOIN + WHERE. The core `team`
 * property is untouched.
 *
 * On demand: GLPI aggregates every selected column per row (GROUP_CONCAT) and a
 * one-to-many join multiplies the rows that aggregation processes (~2x per
 * list, measured). The schema hook runs per request, so the properties are only
 * added when the request's filter/sort mentions them, and for the OpenAPI
 * document. Force them with HLAPIACTORS_ALWAYS=1 (CLI, tests).
 *
 * Literals are used instead of Doc\Schema / CommonITILActor / CommonITILValidation
 * constants so the class runs outside GLPI (tools/check-schema.php); values match
 * ('array', 'object', 'integer', 'string', 'int64'; actor types 1/2/3; validation
 * status 1 none 2 waiting 3 accepted 4 refused; task state 0 info 1 todo 2 done).
 */
final class ApiSchemas
{
    public const ITIL_CONTROLLER  = 'Glpi\\Api\\HL\\Controller\\ITILController';
    public const ADMIN_CONTROLLER = 'Glpi\\Api\\HL\\Controller\\AdministrationController';

    private const ROLES = ['requester' => 1, 'assignee' => 2, 'observer' => 3];

    /** schema => [user link table, group link table, fkey of the main item, validations table|null, tasks table] */
    private const LINKS = [
        'Ticket'  => ['glpi_tickets_users',  'glpi_groups_tickets',  'tickets_id',  'glpi_ticketvalidations', 'glpi_tickettasks'],
        'Change'  => ['glpi_changes_users',  'glpi_changes_groups',  'changes_id',  'glpi_changevalidations', 'glpi_changetasks'],
        'Problem' => ['glpi_problems_users', 'glpi_groups_problems', 'problems_id', null,                     'glpi_problemtasks'],
    ];

    private const INT  = ['type' => 'integer', 'format' => 'int64'];
    private const STR  = ['type' => 'string'];
    private const BOOL = ['type' => 'boolean'];
    private const DT   = ['type' => 'string', 'format' => 'date-time'];

    /** All property names this plugin can add (used by the on-demand check). */
    public static function propertyNames(): array
    {
        $names = ['validations', 'tasks', 'followups', 'pending_reasons', 'groups'];
        foreach (array_keys(self::ROLES) as $prefix) {
            $names[] = self::userProperty($prefix);
            $names[] = $prefix . '_groups';
        }
        return $names;
    }

    private static function userProperty(string $prefix): string
    {
        return $prefix === 'assignee' ? 'assignees' : $prefix . 's';
    }

    /**
     * @param array{controller: string, schemas: array<string, array>} $data
     * @return array{controller: string, schemas: array<string, array>}
     */
    public static function redefine(array $data): array
    {
        $controller = $data['controller'] ?? null;
        if ($controller !== self::ITIL_CONTROLLER && $controller !== self::ADMIN_CONTROLLER) {
            return $data;
        }
        if (!self::requested()) {
            return $data;
        }
        if ($controller === self::ADMIN_CONTROLLER) {
            if (isset($data['schemas']['User']['properties'])) {
                $data['schemas']['User']['properties']['groups'] ??= self::joined('glpi_groups', 'groups_id', 'glpi_groups_users', 'users_id', [
                    'id' => self::INT + ['description' => 'Group ID'],
                    'name' => self::STR,
                    'completename' => self::STR,
                ], 'Group', 'Groups the user belongs to (filterable: groups.id, groups.name). Added by the hlapiactors plugin.');
            }
            return $data;
        }
        foreach (self::LINKS as $schema => [$user_table, $group_table, $fkey, $validation_table, $task_table]) {
            if (!isset($data['schemas'][$schema]['properties'])) {
                continue;
            }
            $props = &$data['schemas'][$schema]['properties'];
            foreach (self::ROLES as $prefix => $type) {
                $props[self::userProperty($prefix)] ??= self::joined('glpi_users', 'users_id', $user_table, $fkey, [
                    'id' => self::INT + ['description' => 'User ID'],
                    'username' => self::STR + ['x-field' => 'name'],
                    'realname' => self::STR,
                    'firstname' => self::STR,
                ], 'User', "Users with the {$prefix} role. Added by the hlapiactors plugin.", ['type' => $type]);
                $props[$prefix . '_groups'] ??= self::joined('glpi_groups', 'groups_id', $group_table, $fkey, [
                    'id' => self::INT + ['description' => 'Group ID'],
                    'name' => self::STR,
                    'completename' => self::STR,
                ], 'Group', "Groups with the {$prefix} role. Added by the hlapiactors plugin.", ['type' => $type]);
            }
            if ($validation_table !== null) {
                $props['validations'] ??= self::rows($validation_table, $fkey, [
                    'id' => self::INT,
                    'status' => self::INT + ['description' => '1 none, 2 waiting, 3 accepted, 4 refused'],
                    'approver_type' => self::STR + ['x-field' => 'itemtype_target', 'description' => 'User or Group'],
                    'approver_id' => self::INT + ['x-field' => 'items_id_target'],
                    'requester_id' => self::INT + ['x-field' => 'users_id'],
                    'submission_date' => self::DT,
                    'validation_date' => self::DT,
                ], 'Approval requests (filterable: validations.status, validations.approver_id …). Added by the hlapiactors plugin.');
            }
            $props['tasks'] ??= self::rows($task_table, $fkey, [
                'id' => self::INT,
                'state' => self::INT + ['description' => '0 information, 1 to do, 2 done'],
                'technician_id' => self::INT + ['x-field' => 'users_id_tech'],
                'group_id' => self::INT + ['x-field' => 'groups_id_tech'],
                'author_id' => self::INT + ['x-field' => 'users_id'],
                'date' => self::DT,
                'begin' => self::DT,
                'end' => self::DT,
                'duration' => self::INT + ['x-field' => 'actiontime'],
                'is_private' => self::BOOL,
                'category_id' => self::INT + ['x-field' => 'taskcategories_id'],
            ], 'Tasks (filterable: tasks.technician_id, tasks.state, tasks.begin …). Added by the hlapiactors plugin.');
            $props['followups'] ??= self::rows('glpi_itilfollowups', 'items_id', [
                'id' => self::INT,
                'author_id' => self::INT + ['x-field' => 'users_id'],
                'date' => self::DT,
                'is_private' => self::BOOL,
                'request_type_id' => self::INT + ['x-field' => 'requesttypes_id'],
            ], 'Followups (filterable: followups.author_id, followups.date). Added by the hlapiactors plugin.', ['itemtype' => $schema]);
            $props['pending_reasons'] ??= self::rows('glpi_pendingreasons_items', 'items_id', [
                'id' => self::INT,
                'reason_id' => self::INT + ['x-field' => 'pendingreasons_id'],
                'followup_frequency' => self::INT,
                'bump_count' => self::INT,
                'previous_status' => self::INT,
            ], 'Pending reason (filterable: pending_reasons.reason_id). Added by the hlapiactors plugin.', ['itemtype' => $schema]);
            unset($props);
        }
        return $data;
    }

    /** Add the properties only when the current request uses them (see class doc). */
    public static function requested(): bool
    {
        if (getenv('HLAPIACTORS_ALWAYS') === '1') {
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('~/api\.php(/v[\d.]+)?/doc(\.json)?(\?|$)~', $uri)) {
            return true;
        }
        $haystack = ($_GET['filter'] ?? '') . ' ' . ($_GET['sort'] ?? '');
        if (trim($haystack) === '' && $uri !== '') {
            $haystack = rawurldecode($uri);
        }
        foreach (self::propertyNames() as $name) {
            if (str_contains($haystack, $name . '.')) {
                return true;
            }
        }
        return false;
    }

    /** Array of target-table rows reached through a link table (main → link → target). */
    private static function joined(string $table, string $link_col, string $link_table, string $fkey, array $fields, string $full_schema, string $description, array $condition = []): array
    {
        $ref = ['table' => $link_table, 'fkey' => 'id', 'field' => $fkey];
        if ($condition) {
            $ref['condition'] = $condition;
        }
        return [
            'x-version-introduced' => '2.0',
            'type' => 'array',
            'description' => $description,
            'items' => [
                'type' => 'object',
                'x-full-schema' => $full_schema,
                'x-join' => ['table' => $table, 'fkey' => $link_col, 'field' => 'id', 'primary-property' => 'id', 'ref-join' => $ref],
                'properties' => $fields,
            ],
        ];
    }

    /** Array of rows of a table that references the main item directly (main → rows). */
    private static function rows(string $table, string $fkey, array $fields, string $description, array $condition = []): array
    {
        $join = ['table' => $table, 'fkey' => 'id', 'field' => $fkey, 'primary-property' => 'id'];
        if ($condition) {
            $join['condition'] = $condition;
        }
        return [
            'x-version-introduced' => '2.0',
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'object', 'x-join' => $join, 'properties' => $fields],
        ];
    }
}
