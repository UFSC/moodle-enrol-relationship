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
 * Adds instance form
 *
 * @package    enrol_relationship
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/enrol/relationship/locallib.php');

class enrol_relationship_edit_form extends moodleform {

    function definition() {
        global $DB;

        $mform  = $this->_form;

        list($instance, $plugin, $course) = $this->_customdata;
        $coursecontext = context_course::instance($course->id);

        $enrol = enrol_get_plugin('relationship');

        $mform->addElement('header','general', get_string('pluginname', 'enrol_relationship'));

        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);

        $options = array(ENROL_INSTANCE_ENABLED  => get_string('yes'),
                         ENROL_INSTANCE_DISABLED => get_string('no'));
        $mform->addElement('select', 'status', get_string('status', 'enrol_relationship'), $options);

        $options_group = array(RELATIONSHIP_ONLY_SYNC_GROUPS      => get_string('onlysyncgroups', 'enrol_relationship'),
                               RELATIONSHIP_ONLY_SYNC_USERS       => get_string('onlysyncusers', 'enrol_relationship'),
                               RELATIONSHIP_SYNC_USERS_AND_GROUPS => get_string('syncusersandgroups', 'enrol_relationship'));

        if (!has_capability('moodle/course:managegroups', $coursecontext)) {
            unset($options_group[RELATIONSHIP_ONLY_SYNC_GROUPS]);
            unset($options_group[RELATIONSHIP_SYNC_USERS_AND_GROUPS]);
        }

        if ($instance->id) {
            if ($relationship = $DB->get_record('relationship', array('id'=>$instance->customint1))) {
                $relcontext = context::instance_by_id($relationship->contextid);
                $relationships = array($instance->customint1=>format_string($relationship->name, true, array('context'=>$relcontext)));
            } else {
                $relationships = array($instance->customint1=>get_string('error'));
            }
            $mform->addElement('select', 'customint1', get_string('relationship', 'enrol_relationship'), $relationships);
            $mform->setConstant('customint1', $instance->customint1);
            $mform->hardFreeze('customint1', $instance->customint1);
        } else {
            $relationships = array('' => get_string('choosedots')) + $this->user_relationships($course->id);
            $mform->addElement('select', 'customint1', get_string('relationship', 'enrol_relationship'), $relationships);
            $mform->addRule('customint1', get_string('required'), 'required', null, 'client');
        }

        $mform->addElement('select', 'customint2', get_string('synctype', 'enrol_relationship'), $options_group);
        $mform->addHelpButton('customint2', 'synctype', 'enrol_relationship');
        $mform->setDefault('customint2', RELATIONSHIP_SYNC_USERS_AND_GROUPS);

        $options_unenrol = array(
            ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
            ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'));
        $mform->addElement('select', 'customint3', get_string('unenrolaction', 'enrol_relationship'), $options_unenrol);

        $mform->addElement('hidden', 'courseid', null);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        if ($instance->id) {
            $this->add_action_buttons(true);
        } else {
            $this->add_action_buttons(true, get_string('addinstance', 'enrol'));
        }

        $this->set_data($instance);
    }

    function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        $params = array('customint1'=>$data['customint1'], 'courseid'=>$data['courseid'], 'id'=>$data['id']);
        if ($DB->record_exists_select('enrol', "customint1 = :customint1 AND courseid = :courseid AND enrol = 'relationship' AND id <> :id", $params)) {
            $errors['customint1'] = get_string('instanceexists', 'enrol_cohort');
        }

        $groups = groups_get_all_groups($data['courseid']);
        $relationship_groups = $DB->get_records('relationship_groups', array('relationshipid'=>$data['customint1']));
        $same_names = array();
        foreach($relationship_groups AS $rg) {
            foreach($groups AS $gr) {
                $id_number = "relationship_{$rg->relationshipid}_{$rg->id}";
                if($gr->name == $rg->name && $gr->idnumber != $id_number) {
                    $same_names[] = $gr->name;
                }
            }
        }
        if(!empty($same_names)) {
            $errors['customint1'] = get_string('same_names', 'enrol_relationship', implode(', ', $same_names));
        }

        $coursecontext = context_course::instance($data['courseid']);

        if ($data['customint2'] == RELATIONSHIP_ONLY_SYNC_USERS || $data['customint2'] == RELATIONSHIP_SYNC_USERS_AND_GROUPS) {
            $relationship_cohorts = $DB->get_records('relationship_cohorts', array('relationshipid'=>$data['customint1']));
            $role_names = array();
            $roles = get_assignable_roles($coursecontext);
            foreach($relationship_cohorts AS $rc) {
                if(!isset($roles[$rc->roleid])) {
                    if($r = $DB->get_record('role', array('id'=>$rc->roleid))) {
                        $role_names[] = role_get_name($r);
                    } else {
                        $errors['customint1'] = get_string('unknown_role', 'enrol_relationship', $rc->roleid);
                        $role_names = array();
                        break;
                    }
                }
            }
            if(!empty($role_names)) {
                $errors['customint1'] = get_string('no_enrol_permission', 'enrol_relationship', implode(', ', $role_names));
            }
        }

        return $errors;
    }

    function user_relationships($courseid) {
        global $DB;

        $relationships = array();
        $pending = array();

        $coursecontext = context_course::instance($courseid);
        $roles = get_assignable_roles($coursecontext);
        $managegroups = has_capability('moodle/course:managegroups', $coursecontext);
        list($in_context, $params) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(), SQL_PARAMS_NAMED);

        $sql = "SELECT r.id, r.name, r.idnumber, r.contextid
                  FROM {relationship} r
                 WHERE r.contextid $in_context
              ORDER BY r.name, r.idnumber";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $c) {
            $context = context::instance_by_id($c->contextid);
            if (has_capability('local/relationship:view', $context)) {
                $relationships[$c->id] = format_string($c->name);
                if (!$managegroups) {
                    $pending[] = $c->id;
                }
            }
        }
        $rs->close();

        if (!empty($pending)) {
            list($in_relat, $params_relat) = $DB->get_in_or_equal($pending, SQL_PARAMS_NAMED);
            list($not_in_role, $params_role) = $DB->get_in_or_equal(array_keys($roles), SQL_PARAMS_NAMED, 'prole', false);
            $sql = "SELECT DISTINCT rc.relationshipid
                      FROM {relationship_cohorts} rc
                     WHERE rc.relationshipid $in_relat
                       AND rc.roleid $not_in_role";
            $params = array_merge($params_relat, $params_role);
            foreach ($DB->get_records_sql($sql, $params) AS $relatid=>$relat) {
                unset($relationships[$relatid]);
            }
        }

        return $relationships;
    }
}
