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
 * Strings for component 'enrol_relationship', language 'en'.
 *
 * @package    enrol_relationship
 * @copyright  2010 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addgroup'] = 'Add to group';
$string['ajaxmore'] = 'More...';
$string['assignrole'] = 'Assign role';
$string['synctype'] = 'Type of sync';
$string['synctype_help'] = 'Select what type of syncronization must be done:<BR><UL>
    <LI><B>Only synchronize user enrolments</B>: all users from the relationship will be enrolled in the course with their respective roles.</LI>
    <LI><B>Only synchronize groups and their members</B>: all relationship groups will be created in the course and their members will be added 
    to the respective groups since they are already enrolled in the course. The groups will be added to a grouping.
    You must have <i>\'moodle/course:managegroups\'</i> capability in the course.</LI>
    <LI><B>Synchronize both user and groups</B>: both type of synchronization will take place.
    You must have <i>\'moodle/course:managegroups\'</i> capability in the course.</LI>
    </UL>';
$string['onlysyncgroups'] = 'Only synchronize groups and their members';
$string['onlysyncusers'] = 'Only synchronize user enrolments';
$string['syncusersandgroups'] = 'Synchronize both users and groups';
$string['relationship'] = 'Relationship';
$string['relationshipsearch'] = 'Search';
$string['relationship:config'] = 'Configure relationship instances';
$string['relationship:unenrol'] = 'Unenrol suspended users';
$string['unenrolaction'] = 'Action for removed users';
$string['instanceexists'] = 'relationship is already synchronised with selected role';
$string['pluginname'] = 'Relationship sync';
$string['pluginname_desc'] = 'Relationship enrolment plugin synchronises relationship members with course participants.';
$string['status'] = 'Active';
$string['same_names'] = 'There are already groups in the course with the same name as relationship group names: \'{$a}\'';
$string['no_enrol_permission'] = 'There are roles within the relationship you don\'t have permission to assing to users in this course: \'{$a}\'';
$string['unknown_role'] = 'Unknown role: {$a}';
$string['enrolrelationshipsynctask'] = 'Relationship enrolment sync task';
