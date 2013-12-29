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
 * Local stuff for relationship enrolment plugin.
 *
 * @package    enrol_relationship
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/enrol/locallib.php');


/**
 * Event handler for relationship enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_relationship_handler {
    /**
     * Event processor - relationship member added.
     * @param \core\event\relationship_member_added $event
     * @return bool
     */
    public static function member_added(\core\event\relationship_member_added $event) {
        global $DB, $CFG;
        require_once("$CFG->dirroot/group/lib.php");

        if (!enrol_is_enabled('relationship')) {
            return true;
        }

        // Does any enabled relationship instance want to sync with this relationship?
        $sql = "SELECT e.*, r.id as roleexists
                  FROM {enrol} e
             LEFT JOIN {role} r ON (r.id = e.roleid)
                 WHERE e.customint1 = :relationshipid AND e.enrol = 'relationship'
              ORDER BY e.id ASC";
        if (!$instances = $DB->get_records_sql($sql, array('relationshipid'=>$event->objectid))) {
            return true;
        }

        $plugin = enrol_get_plugin('relationship');
        foreach ($instances as $instance) {
            if ($instance->status != ENROL_INSTANCE_ENABLED ) {
                // No roles for disabled instances.
                $instance->roleid = 0;
            } else if ($instance->roleid and !$instance->roleexists) {
                // Invalid role - let's just enrol, they will have to create new sync and delete this one.
                $instance->roleid = 0;
            }
            unset($instance->roleexists);
            // No problem if already enrolled.
            $plugin->enrol_user($instance, $event->relateduserid, $instance->roleid, 0, 0, ENROL_USER_ACTIVE);

            // Sync groups.
            //TODO: necessário ajustar para relationship
            if ($instance->customint2) {
                if (!groups_is_member($instance->customint2, $event->relateduserid)) {
                    if ($group = $DB->get_record('groups', array('id'=>$instance->customint2, 'courseid'=>$instance->courseid))) {
                        groups_add_member($group->id, $event->relateduserid, 'enrol_relationship', $instance->id);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Event processor - relationship member removed.
     * @param \core\event\relationship_member_removed $event
     * @return bool
     */
    public static function member_removed(\core\event\relationship_member_removed $event) {
        global $DB;

        // Does anything want to sync with this relationship?
        if (!$instances = $DB->get_records('enrol', array('customint1'=>$event->objectid, 'enrol'=>'relationship'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('relationship');
        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);

        foreach ($instances as $instance) {
            if (!$ue = $DB->get_record('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$event->relateduserid))) {
                continue;
            }
            if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                $plugin->unenrol_user($instance, $event->relateduserid);

            } else {
                if ($ue->status != ENROL_USER_SUSPENDED) {
                    $plugin->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                    $context = context_course::instance($instance->courseid);
                    role_unassign_all(array('userid'=>$ue->userid, 'contextid'=>$context->id, 'component'=>'enrol_relationship', 'itemid'=>$instance->id));
                }
            }
        }

        return true;
    }

    /**
     * Event processor - relationship deleted.
     * @param \core\event\relationship_deleted $event
     * @return bool
     */
    public static function deleted(\core\event\relationship_deleted $event) {
        global $DB;

        // Does anything want to sync with this relationship?
        if (!$instances = $DB->get_records('enrol', array('customint1'=>$event->objectid, 'enrol'=>'relationship'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('relationship');
        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);

        foreach ($instances as $instance) {
            if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                $context = context_course::instance($instance->courseid);
                role_unassign_all(array('contextid'=>$context->id, 'component'=>'enrol_relationship', 'itemid'=>$instance->id));
                $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
            } else {
                $plugin->delete_instance($instance);
            }
        }

        return true;
    }
}


/**
 * Sync all relationship course links.
 * @param progress_trace $trace
 * @param int $courseid one course, empty mean all
 * @return int 0 means ok, 1 means error, 2 means plugin disabled
 */
function enrol_relationship_sync(progress_trace $trace, $courseid = NULL) {
    global $CFG, $DB;

    require_once("$CFG->dirroot/group/lib.php");

    // Purge all roles if relationship sync disabled, those can be recreated later here by cron or CLI.
    if (!enrol_is_enabled('relationship')) {
        $trace->output('relationship sync plugin is disabled, unassigning all plugin roles and stopping.');
        role_unassign_all(array('component'=>'enrol_relationship'));
        return 2;
    }

    // Unfortunately this may take a long time, this script can be interrupted without problems.
    @set_time_limit(0);
    raise_memory_limit(MEMORY_HUGE);

    $trace->output('Starting user enrolment synchronisation...');

    $plugin = enrol_get_plugin('relationship');
    $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);

    $onecourse = $courseid ? "AND c.id = :courseid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['categorycontext'] = CONTEXT_COURSECAT;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['useractive']      = ENROL_USER_ACTIVE;
    $params['enrolcourseusers'] = 0;

    // ---------------------------------------------------------------------------------------

    $instances = array(); //cache

    // Iterate through all not enrolled yet users.
    $sql = "SELECT DISTINCT rm.userid, e.id AS enrolid, ue.status
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {user} u ON (u.id = rm.userid AND u.deleted = 0 AND u.suspended = 0)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND
                                 e.status = :statusenabled AND e.customint2 = :enrolcourseusers)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = rm.userid)
             WHERE ue.id IS NULL OR ue.status = :suspended";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];
        if ($ue->status == ENROL_USER_SUSPENDED) {
            $plugin->update_user_enrol($instance, $ue->userid, ENROL_USER_ACTIVE);
            $trace->output("unsuspending: $ue->userid ==> $instance->courseid via relationship $instance->customint1", 1);
        } else {
            $plugin->enrol_user($instance, $ue->userid);
            $trace->output("enrolling: {$ue->userid} ==> {$instance->courseid} via relationship {$instance->customint1}", 1);
        }
    }
    $rs->close();

    // Unenrol as necessary.
    $jonecourse = $courseid ? "AND c.id = :jcourseid" : "";
    $sql = "SELECT ue.*, e.courseid
              FROM {relationship} rl
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND
                                 e.status = :statusenabled AND e.customint2 = :enrolcourseusers)
              JOIN {user_enrolments} ue ON (ue.enrolid = e.id)
         LEFT JOIN (SELECT DISTINCT rg.relationshipid, rm.userid
                      FROM {relationship} rl
                      JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :jcategorycontext)
                      JOIN {course} c ON (c.category = ctx.instanceid $jonecourse)
                      JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
                      JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)) jrm
                ON (jrm.relationshipid = rl.id AND jrm.userid = ue.userid)
             WHERE jrm.userid IS NULL";
    $params['jcategorycontext'] = CONTEXT_COURSECAT;
    $params['jcourseid'] = $courseid;
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];
        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            // Remove enrolment together with group membership, grades, preferences, etc.
            $plugin->unenrol_user($instance, $ue->userid);
            $trace->output("unenrolling: {$ue->userid} ==> {$instance->courseid} via relationship {$instance->customint1}", 1);

        } else { // ENROL_EXT_REMOVED_SUSPENDNOROLES
            // Just disable and ignore any changes.
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                $context = context_course::instance($instance->courseid);
                role_unassign_all(array('userid'=>$ue->userid, 'contextid'=>$context->id, 'component'=>'enrol_relationship', 'itemid'=>$instance->id));
                $trace->output("suspending and unsassigning all roles: {$ue->userid} ==> {$instance->courseid}", 1);
            }
        }
    }
    $rs->close();

    unset($instances);

    // ---------------------------------------------------------------------------------------

    $allroles = get_all_roles();

    // Now assign all necessary roles to enrolled users - skip suspended instances and users.
    $sql = "SELECT rm.roleid, rm.userid, ctx.id AS contextid, e.id AS itemid, e.courseid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)
              JOIN {context} catx ON (catx.id = rl.contextid AND catx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = catx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
              JOIN {role} r ON (r.id = rm.roleid)
              JOIN {user} u ON (u.id = rm.userid AND u.deleted = 0)
              JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} ra ON (ra.contextid = ctx.id AND ra.userid = rm.userid AND ra.itemid = e.id AND
                                             ra.component = 'enrol_relationship' AND ra.roleid = rm.roleid)
             WHERE ra.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_relationship', $ra->itemid);
        $trace->output("assigning role: {$ra->userid} ==> {$ra->courseid} as {$allroles[$ra->roleid]->shortname}", 1);
    }
    $rs->close();

    // Remove unwanted roles - sync role can not be changed, we only remove role when unenrolled.
    $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid, e.courseid
              FROM {role_assignments} ra
              JOIN {context} ctx ON (ctx.id = ra.contextid AND ctx.contextlevel = :coursecontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.id = ra.itemid AND e.enrol = 'relationship' AND e.courseid = c.id)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :useractive)
             WHERE ra.component = 'enrol_relationship' AND (ue.id IS NULL OR e.status <> :statusenabled)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_relationship', $ra->itemid);
        $trace->output("unassigning role: {$ra->userid} ==> {$ra->courseid} as {$allroles[$ra->roleid]->shortname}", 1);
    }
    $rs->close();

    // ---------------------------------------------------------------------------------------

    // create new groupings
    $sql = "SELECT DISTINCT CONCAT('relationship_', rl.id) as idnumber, rl.name, e.courseid
              FROM {relationship} rl
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
         LEFT JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
             WHERE gr.id IS NULL
               AND EXISTS (SELECT 1 FROM {user_enrolments} ue WHERE ue.enrolid = e.id AND ue.status != :suspended)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grping) {
        $data = new stdclass();
        $data->courseid = $grping->courseid;
        $data->name     = $grping->name;
        $data->idnumber = $grping->idnumber;
        $data->description = '';
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        groups_create_grouping($data);
        $trace->output("creating grouping: '{$data->name}' ({$data->idnumber}) on course {$data->courseid}", 1);
    }

    // change grouping names when necessary
    $sql = "SELECT gr.id, rl.name, e.courseid, gr.idnumber
              FROM {relationship} rl
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
             WHERE gr.name != rl.name";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grping) {
        $data = new stdclass();
        $data->id       = $grping->id;
        $data->courseid = $grping->courseid;
        $data->name     = $grping->name;
        $data->timemodified = time();
        groups_update_grouping($data);
        $trace->output("changing name of grouping '{$gr->idnumber}' on course {$data->courseid} to '{$data->name}'", 1);
    }

    // ---------------------------------------------------------------------------------------

    // create new groups
    $sql = "SELECT CONCAT('relationship_', rl.id, '_', rg.id) as idnumber, rg.name, e.courseid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
         LEFT JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
             WHERE g.id IS NULL
               AND EXISTS (SELECT 1 FROM {user_enrolments} ue
                                    JOIN {relationship_members} rm ON (rm.userid = ue.userid)
                                   WHERE ue.enrolid = e.id AND ue.status != :suspended
                                     AND rm.relationshipgroupid = rg.id)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grp) {
        $data = new stdclass();
        $data->courseid = $grp->courseid;
        $data->name     = $grp->name;
        $data->idnumber = $grp->idnumber;
        $data->description = '';
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        groups_create_group($data);
        $trace->output("creating group: '{$data->name}' ({$data->idnumber}) on course {$data->courseid}", 1);
    }

    // change group names when necessary
    $sql = "SELECT g.id, rg.name, e.courseid, g.idnumber
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
              JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
             WHERE g.name != rg.name";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grp) {
        $data = new stdclass();
        $data->id       = $grp->id;
        $data->courseid = $grp->courseid;
        $data->name     = $grp->name;
        $data->timemodified = time();
        groups_update_group($data);
        $trace->output("changing name of grouping '{$grp->idnumber}' on course {$data->courseid} to '{$data->name}'", 1);
    }

    // assing new groups to groupings
    $sql = "SELECT gr.id as groupingid, gr.idnumber as grouping_idnumber, g.id as groupid, g.idnumber as group_idnumber
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
         LEFT JOIN {groupings_groups} gg ON (gg.groupingid = gr.id AND gg.groupid = g.id)
             WHERE gg.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $g) {
        groups_assign_grouping($g->groupingid, $g->groupid, time());
        $trace->output("assigning group '{$g->group_idnumber}' ({$g->groupid}) to grouping '{$g->grouping_idnumber}' ($g->groupingid)", 1);
    }

    // unassing old groups from groupings
    $sql = "SELECT gr.id as groupingid, gr.idnumber as grouping_idnumber, g.id as groupid, g.idnumber as group_idnumber
              FROM {relationship} rl
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id AND e.status = :statusenabled)
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid)
         LEFT JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id and CONCAT('relationship_', rl.id, '_', rg.id) = g.idnumber)
             WHERE rg.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $g) {
        groups_unassign_grouping($g->groupingid, $g->groupid, time());
        $trace->output("unassigning group '{$g->group_idnumber}' ({$g->groupid}) to grouping '{$g->grouping_idnumber}' ($g->groupingid)", 1);
    }

    // ---------------------------------------------------------------------------------------

    // Finally sync groups.

    // Remove as necessary
    $sql = "SELECT gm.*, e.courseid, g.name AS groupname
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id)
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
              JOIN {groups_members} gm ON (gm.groupid = g.id AND gm.component = 'enrol_relationship' AND gm.itemid = e.id)
         LEFT JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id AND rm.userid = gm.userid)
             WHERE rm.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $gm) {
        groups_remove_member($gm->groupid, $gm->userid);
        $trace->output("removing user from group: {$gm->userid} ==> {$gm->courseid} - {$gm->groupname}", 1);
    }
    $rs->close();

    // Add missing.
    $sql = "SELECT DISTINCT g.id AS groupid, rm.userid, e.courseid, g.name AS groupname, e.id as enrolid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)
              JOIN {context} ctx ON (ctx.id = rl.contextid AND ctx.contextlevel = :categorycontext)
              JOIN {course} c ON (c.category = ctx.instanceid $onecourse)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND e.courseid = c.id)
              JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = rm.userid AND ue.status = :useractive)
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
         LEFT JOIN {groups_members} gm ON (gm.groupid = g.id AND gm.userid = rm.userid)
             WHERE gm.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $gm) {
        groups_add_member($gm->groupid, $gm->userid, 'enrol_relationship', $gm->enrolid);
        $trace->output("adding user to group: {$gm->userid} ==> {$gm->courseid} - {$gm->groupname}", 1);
    }
    $rs->close();

    $trace->output('...user enrolment synchronisation finished.');

    return 0;
}

/**
 * Enrols all of the users in a relationship through a manual plugin instance.
 *
 * In order for this to succeed the course must contain a valid manual
 * enrolment plugin instance that the user has permission to enrol users through.
 *
 * @global moodle_database $DB
 * @param course_enrolment_manager $manager
 * @param int $relationshipid
 * @param int $roleid
 * @return int
 */
function enrol_relationship_enrol_all_users(course_enrolment_manager $manager, $relationshipid, $roleid) {
    global $DB;
    $context = $manager->get_context();
    require_capability('moodle/course:enrolconfig', $context);

    $instance = false;
    $instances = $manager->get_enrolment_instances();
    foreach ($instances as $i) {
        if ($i->enrol == 'manual') {
            $instance = $i;
            break;
        }
    }
    $plugin = enrol_get_plugin('manual');
    if (!$instance || !$plugin || !$plugin->allow_enrol($instance) || !has_capability('enrol/'.$plugin->get_name().':enrol', $context)) {
        return false;
    }
    $sql = "SELECT com.userid
              FROM {relationship_members} com
         LEFT JOIN (
                SELECT *
                  FROM {user_enrolments} ue
                 WHERE ue.enrolid = :enrolid
                 ) ue ON ue.userid=com.userid
             WHERE com.relationshipid = :relationshipid AND ue.id IS NULL";
    $params = array('relationshipid' => $relationshipid, 'enrolid' => $instance->id);
    $rs = $DB->get_recordset_sql($sql, $params);
    $count = 0;
    foreach ($rs as $user) {
        $count++;
        $plugin->enrol_user($instance, $user->userid, $roleid);
    }
    $rs->close();
    return $count;
}

/**
 * Gets all the relationships the user is able to view.
 *
 * @global moodle_database $DB
 * @param course_enrolment_manager $manager
 * @return array
 */
function enrol_relationship_get_relationships(course_enrolment_manager $manager) {
    global $DB;
    $context = $manager->get_context();
    $relationships = array();
    $instances = $manager->get_enrolment_instances();
    $enrolled = array();
    foreach ($instances as $instance) {
        if ($instance->enrol == 'relationship') {
            $enrolled[] = $instance->customint1;
        }
    }
    list($sqlparents, $params) = $DB->get_in_or_equal($context->get_parent_context_ids());
    $sql = "SELECT id, name, idnumber, contextid
              FROM {relationship}
             WHERE contextid $sqlparents
          ORDER BY name ASC, idnumber ASC";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $c) {
        $context = context::instance_by_id($c->contextid);
        if (!has_capability('local/relationship:view', $context)) {
            continue;
        }
        $relationships[$c->id] = array(
            'relationshipid'=>$c->id,
            'name'=>format_string($c->name, true, array('context'=>context::instance_by_id($c->contextid))),
            'users'=>$DB->count_records('relationship_members', array('relationshipid'=>$c->id)),
            'enrolled'=>in_array($c->id, $enrolled)
        );
    }
    $rs->close();
    return $relationships;
}

/**
 * Check if relationship exists and user is allowed to enrol it.
 *
 * @global moodle_database $DB
 * @param int $relationshipid relationship ID
 * @return boolean
 */
function enrol_relationship_can_view_relationship($relationshipid) {
    global $DB;
    $relationship = $DB->get_record('relationship', array('id' => $relationshipid), 'id, contextid');
    if ($relationship) {
        $context = context::instance_by_id($relationship->contextid);
        if (has_capability('local/relationship:view', $context)) {
            return true;
        }
    }
    return false;
}

/**
 * Gets relationships the user is able to view.
 *
 * @global moodle_database $DB
 * @param course_enrolment_manager $manager
 * @param int $offset limit output from
 * @param int $limit items to output per load
 * @param string $search search string
 * @return array    Array(more => bool, offset => int, relationships => array)
 */
function enrol_relationship_search_relationships(course_enrolment_manager $manager, $offset = 0, $limit = 25, $search = '') {
    global $DB;
    $context = $manager->get_context();
    $relationships = array();
    $instances = $manager->get_enrolment_instances();
    $enrolled = array();
    foreach ($instances as $instance) {
        if ($instance->enrol == 'relationship') {
            $enrolled[] = $instance->customint1;
        }
    }

    list($sqlparents, $params) = $DB->get_in_or_equal($context->get_parent_context_ids());

    // Add some additional sensible conditions.
    $tests = array('contextid ' . $sqlparents);

    // Modify the query to perform the search if required.
    if (!empty($search)) {
        $conditions = array(
            'name',
            'idnumber',
            'description'
        );
        $searchparam = '%' . $DB->sql_like_escape($search) . '%';
        foreach ($conditions as $key=>$condition) {
            $conditions[$key] = $DB->sql_like($condition, "?", false);
            $params[] = $searchparam;
        }
        $tests[] = '(' . implode(' OR ', $conditions) . ')';
    }
    $wherecondition = implode(' AND ', $tests);

    $sql = "SELECT id, name, idnumber, contextid, description
              FROM {relationship}
             WHERE $wherecondition
          ORDER BY name ASC, idnumber ASC";
    $rs = $DB->get_recordset_sql($sql, $params, $offset);

    // Produce the output respecting parameters.
    foreach ($rs as $c) {
        // Track offset.
        $offset++;
        // Check capabilities.
        $context = context::instance_by_id($c->contextid);
        if (!has_capability('local/relationship:view', $context)) {
            continue;
        }
        if ($limit === 0) {
            // We have reached the required number of items and know that there are more, exit now.
            $offset--;
            break;
        }
        $relationships[$c->id] = array(
            'relationshipid' => $c->id,
            'name'     => shorten_text(format_string($c->name, true, array('context'=>context::instance_by_id($c->contextid))), 35),
            'users'    => $DB->count_records('relationship_members', array('relationshipid'=>$c->id)),
            'enrolled' => in_array($c->id, $enrolled)
        );
        // Count items.
        $limit--;
    }
    $rs->close();
    return array('more' => !(bool)$limit, 'offset' => $offset, 'relationships' => $relationships);
}
