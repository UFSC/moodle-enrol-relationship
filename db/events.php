<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * relationship enrolment plugin event handler definition.
 *
 * @package enrol_relationship
 * @category event
 * @copyright 2010 Petr Skoda {@link http://skodak.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/*
relationship_deleted.php
*/

$observers = array(
    array(
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => 'enrol_relationship_handler::user_enrolment',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationship_updated',
        'callback' => 'enrol_relationship_handler::updated',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationshipgroup_member_added',
        'callback' => 'enrol_relationship_handler::member_added',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationshipgroup_member_removed',
        'callback' => 'enrol_relationship_handler::member_removed',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationshipgroup_created',
        'callback' => 'enrol_relationship_handler::group_created',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationshipgroup_deleted',
        'callback' => 'enrol_relationship_handler::group_deleted',
        'includefile' => '/enrol/relationship/locallib.php'
    ),

    array(
        'eventname' => '\local_relationship\event\relationshipgroup_updated',
        'callback' => 'enrol_relationship_handler::group_updated',
        'includefile' => '/enrol/relationship/locallib.php'
    ),
);
