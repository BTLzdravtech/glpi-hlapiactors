<?php

/**
 * HL API Actors — filterable ticket actors for the GLPI 11 high-level API.
 *
 * The core API exposes requesters / observers / assignees only as the computed
 * `team` property, which cannot be used in RSQL filters (glpi-project/glpi#20743).
 * This plugin adds real SQL-join properties to the Ticket, Change and Problem
 * schemas so that e.g. `filter=assignees.id==42;status.id=out=(5,6)` works.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_HLAPIACTORS_VERSION', '1.2.0');
define('PLUGIN_HLAPIACTORS_MIN_GLPI', '11.0.0');
define('PLUGIN_HLAPIACTORS_MAX_GLPI', '11.0.99');

function plugin_init_hlapiactors(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['hlapiactors'] = true;
    $PLUGIN_HOOKS[Hooks::REDEFINE_API_SCHEMAS]['hlapiactors'] = 'plugin_hlapiactors_redefine_api_schemas';
}

function plugin_version_hlapiactors(): array
{
    return [
        'name'         => 'HL API Actors',
        'version'      => PLUGIN_HLAPIACTORS_VERSION,
        'author'       => 'hlapiactors contributors',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/BTLzdravtech/glpi-hlapiactors',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_HLAPIACTORS_MIN_GLPI,
                'max' => PLUGIN_HLAPIACTORS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_hlapiactors_check_prerequisites(): bool
{
    return true;
}

function plugin_hlapiactors_check_config($verbose = false): bool
{
    return true;
}
