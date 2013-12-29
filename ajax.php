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
 * This file processes AJAX enrolment actions and returns JSON for the relationship plugin
 *
 * The general idea behind this file is that any errors should throw exceptions
 * which will be returned and acted upon by the calling AJAX script.
 *
 * @package    enrol_relationship
 * @copyright  2011 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../config.php');
require_once($CFG->dirroot.'/enrol/locallib.php');
require_once($CFG->dirroot.'/enrol/relationship/locallib.php');
require_once($CFG->dirroot.'/group/lib.php');

// Must have the sesskey.
$id      = required_param('id', PARAM_INT); // course id
$action  = required_param('action', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/enrol/relationship/ajax.php', array('id'=>$id, 'action'=>$action)));

$course = $DB->get_record('course', array('id'=>$id), '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);

if ($course->id == SITEID) {
    throw new moodle_exception('invalidcourse');
}

require_login($course);
require_capability('moodle/course:enrolreview', $context);
require_sesskey();

if (!enrol_is_enabled('relationship')) {
    // This should never happen, no need to invent new error strings.
    throw new enrol_ajax_exception('errorenrolrelationship');
}

echo $OUTPUT->header(); // Send headers.

$manager = new course_enrolment_manager($PAGE, $course);

$outcome = new stdClass();
$outcome->success = true;
$outcome->response = new stdClass();
$outcome->error = '';

switch ($action) {
    case 'getassignable':
        $otheruserroles = optional_param('otherusers', false, PARAM_BOOL);
        $outcome->response = array_reverse($manager->get_assignable_roles($otheruserroles), true);
        break;
    case 'getdefaultrelationshiprole': //TODO: use in ajax UI MDL-24280
        $relationshipenrol = enrol_get_plugin('relationship');
        $outcome->response = $relationshipenrol->get_config('roleid');
        break;
    case 'getrelationships':
        require_capability('moodle/course:enrolconfig', $context);
        $offset = optional_param('offset', 0, PARAM_INT);
        $search  = optional_param('search', '', PARAM_RAW);
        $outcome->response = enrol_relationship_search_relationships($manager, $offset, 25, $search);
        // Some browsers reorder collections by key.
        $outcome->response['relationships'] = array_values($outcome->response['relationships']);
        break;
    case 'enrolrelationship':
        require_capability('moodle/course:enrolconfig', $context);
        require_capability('enrol/relationship:config', $context);
        $roleid = required_param('roleid', PARAM_INT);
        $relationshipid = required_param('relationshipid', PARAM_INT);

        $roles = $manager->get_assignable_roles();
        if (!enrol_relationship_can_view_relationship($relationshipid) || !array_key_exists($roleid, $roles)) {
            throw new enrol_ajax_exception('errorenrolrelationship');
        }
        $enrol = enrol_get_plugin('relationship');
        $enrol->add_instance($manager->get_course(), array('customint1' => $relationshipid, 'roleid' => $roleid));
        $trace = new null_progress_trace();
        enrol_relationship_sync($trace, $manager->get_course()->id);
        $trace->finished();
        break;
    case 'enrolrelationshipusers':
        //TODO: this should be moved to enrol_manual, see MDL-35618.
        require_capability('enrol/manual:enrol', $context);
        $roleid = required_param('roleid', PARAM_INT);
        $relationshipid = required_param('relationshipid', PARAM_INT);

        $roles = $manager->get_assignable_roles();
        if (!enrol_relationship_can_view_relationship($relationshipid) || !array_key_exists($roleid, $roles)) {
            throw new enrol_ajax_exception('errorenrolrelationship');
        }

        $result = enrol_relationship_enrol_all_users($manager, $relationshipid, $roleid);
        if ($result === false) {
            throw new enrol_ajax_exception('errorenrolrelationshipusers');
        }

        $outcome->success = true;
        $outcome->response->users = $result;
        $outcome->response->title = get_string('success');
        $outcome->response->message = get_string('enrollednewusers', 'enrol', $result);
        $outcome->response->yesLabel = get_string('ok');
        break;
    default:
        throw new enrol_ajax_exception('unknowajaxaction');
}

echo json_encode($outcome);
die();
