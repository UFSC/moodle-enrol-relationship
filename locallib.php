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

define('RELATIONSHIP_SYNC_USERS_AND_GROUPS', 0);
define('RELATIONSHIP_ONLY_SYNC_GROUPS', 1);
define('RELATIONSHIP_ONLY_SYNC_USERS', 2);

/*
customint2
    RELATIONSHIP_SYNC_USERS_AND_GROUPS
    RELATIONSHIP_ONLY_SYNC_GROUPS
    RELATIONSHIP_ONLY_SYNC_USERS

customint3
    ENROL_EXT_REMOVED_UNENROL
    ENROL_EXT_REMOVED_KEEP
    ENROL_EXT_REMOVED_SUSPEND
*/

require_once($CFG->dirroot . '/enrol/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');

/**
 * Event handler for relationship enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_relationship_handler {

    /**
     * Event processor - relationship updated.
     * @param \local_relationship\event\relationship_updated $event
     * @return bool
     */
    public static function updated(\local_relationship\event\relationship_updated $event) {
        if (!enrol_is_enabled('relationship')) {
            return true;
        }
        $trace = new null_progress_trace();
        enrol_relationship_rename_groupings($trace, NULL, $event->objectid);
        enrol_relationship_assign_roles($trace, NULL, NULL, $event->objectid);
        enrol_relationship_unassign_roles($trace, NULL, NULL, $event->objectid);
        return true;
    }

    /**
     * Event processor - relationshipgroup member added.
     * @param \local_relationship\event\relationshipgroup_member_added $event
     * @return bool
     */
    public static function member_added(\local_relationship\event\relationshipgroup_member_added $event) {
        if (!enrol_is_enabled('relationship')) {
            return true;
        }
        $trace = new null_progress_trace();
        enrol_relationship_enrol_users($trace, NULL, $event->relateduserid, $event->objectid);
        enrol_relationship_create_groupings_and_groups($trace, NULL, $event->relateduserid, $event->objectid);
        enrol_relationship_add_member_groups($trace, NULL, $event->relateduserid, $event->objectid);
        return true;
    }

    /**
     * Event processor - relationshipgroup member removed.
     * @param \local_relationship\event\relationshipgroup_member_removed $event
     * @return bool
     */
    public static function member_removed(\local_relationship\event\relationshipgroup_member_removed $event) {
        if (!enrol_is_enabled('relationship')) {
            return true;
        }
        $trace = new null_progress_trace();
        enrol_relationship_remove_member_groups($trace, NULL, $event->relateduserid, $event->objectid);
        enrol_relationship_unenrol_users($trace, NULL, $event->relateduserid, $event->objectid);
        return true;
    }

    /**
     * Event processor - relationshipgroup deleted.
     * @param \local_relationship\event\relationshipgroup_deleted $event
     * @return bool
     */
    public static function group_deleted(\local_relationship\event\relationshipgroup_deleted $event) {
        if (!enrol_is_enabled('relationship')) {
            return true;
        }
        $trace = new null_progress_trace();
        enrol_relationship_remove_member_groups($trace, NULL, NULL, $event->objectid);
        enrol_relationship_unassign_groups_from_groupings($trace, NULL);
        return true;
    }

    /**
     * Event processor - relationshipgroup updated.
     * @param \local_relationship\event\relationshipgroup_updated $event
     * @return bool
     */
    public static function group_updated(\local_relationship\event\relationshipgroup_updated $event) {
        if (!enrol_is_enabled('relationship')) {
            return true;
        }
        $trace = new null_progress_trace();
        enrol_relationship_rename_groups($trace, NULL, $event->objectid);
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

    // Purge all roles if relationship sync disabled, those can be recreated later here by cron or CLI.
    if (!enrol_is_enabled('relationship')) {
        $trace->output('?? Relationship sync plugin is disabled, unassigning all plugin roles and stopping.');
        role_unassign_all(array('component'=>'enrol_relationship'));
        return 2;
    }

    // Unfortunately this may take a long time, this script can be interrupted without problems.
    @set_time_limit(0);
    raise_memory_limit(MEMORY_HUGE);

    $trace->output('** Starting relationship synchronisation...');

    enrol_relationship_enrol_users($trace, $courseid);
    enrol_relationship_assign_roles($trace, $courseid);
    enrol_relationship_unenrol_users($trace, $courseid);
    enrol_relationship_unassign_roles($trace, $courseid);
    enrol_relationship_create_groupings_and_groups($trace, $courseid);
    enrol_relationship_rename_groupings($trace, $courseid);
    enrol_relationship_rename_groups($trace, $courseid);
    enrol_relationship_unassign_groups_from_groupings($trace, $courseid);
    enrol_relationship_add_member_groups($trace, $courseid);
    enrol_relationship_remove_member_groups($trace, $courseid);

    $trace->output('** Relationship synchronisation finished.');

    return 0;
}

function enrol_relationship_enrol_users(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Relationship enroling users...');

    $plugin = enrol_get_plugin('relationship');
    $instances = array(); //cache

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $oneuser = $userid ? "AND rm.userid = :userid" : "";
    $onegroup = $relationshipgroupid ? "AND rg.id = :relationshipgroupid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['userid']          = $userid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncgroups']  = RELATIONSHIP_ONLY_SYNC_GROUPS;

    // Iterate through all not enrolled yet users.
    $sql = "SELECT DISTINCT rm.userid, e.id AS enrolid, ue.status, ctx.id as contextid, rm.roleid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id {$oneuser})
              JOIN {user} u ON (u.id = rm.userid AND u.deleted = 0 AND u.suspended = 0)
              JOIN {role} r ON (r.id = rm.roleid)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncgroups)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :coursecontext)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = rm.userid)
             WHERE ue.id IS NULL OR ue.status = :suspended";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $r) {
        if (!isset($instances[$r->enrolid])) {
            $instances[$r->enrolid] = $DB->get_record('enrol', array('id'=>$r->enrolid));
        }
        $instance = $instances[$r->enrolid];
        if ($r->status == ENROL_USER_SUSPENDED) {
            $trace->output("unsuspending: {$r->userid} ==> {$instance->courseid} via relationship {$instance->customint1}", 1);
            $plugin->update_user_enrol($instance, $r->userid, ENROL_USER_ACTIVE);
            role_assign($r->roleid, $r->userid, $r->contextid, 'enrol_relationship', $r->enrolid);
        } else {
            $trace->output("enrolling: {$r->userid} ==> {$instance->courseid} via relationship {$instance->customint1}", 1);
            $plugin->enrol_user($instance, $r->userid, $r->roleid, time());
        }
    }
    $rs->close();

    unset($instances);
}

function enrol_relationship_assign_roles(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipid = NULL) {
    global $DB;

    $trace->output('-- Relationship assigning roles...');

    $plugin = enrol_get_plugin('relationship');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $oneuser = $userid ? "AND rm.userid = :userid" : "";
    $onerelat = $relationshipid ? "AND rl.id = :relationshipid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['userid']          = $userid;
    $params['relationshipid'] = $relationshipid;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncgroups']  = RELATIONSHIP_ONLY_SYNC_GROUPS;

    $sql = "SELECT DISTINCT ra.id, rm.userid, e.id AS enrolid, ctx.id as contextid, rm.roleid, e.courseid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id {$oneuser})
              JOIN {user} u ON (u.id = rm.userid AND u.deleted = 0 AND u.suspended = 0)
              JOIN {role} r ON (r.id = rm.roleid)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncgroups)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} ra ON (ra.contextid = ctx.id AND ra.userid = rm.userid AND
                                             ra.roleid = rm.roleid AND ra.itemid = e.id)
             WHERE ra.id IS NULL {$onerelat}";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        $trace->output("assigning role: {$ra->roleid} user: {$ra->userid} course: {$ra->courseid}", 1);
        role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_relationship', $ra->enrolid);
    }
    $rs->close();
}

// Unenrol as necessary.
function enrol_relationship_unenrol_users(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Relationship unenroling users...');

    $plugin = enrol_get_plugin('relationship');
    $instances = array(); //cache

    $onecourse  = $courseid ? "AND c.id = :courseid" : "";
    $jonecourse = $courseid ? "AND c.id = :jcourseid" : "";
    $oneuser = $userid ? "AND ue.userid = :userid" : "";
    $joneuser = $userid ? "AND rm.userid = :juserid" : "";
    $onegroup = $relationshipgroupid ? "AND rg.id = :relationshipgroupid" : "";
    $jonegroup = $relationshipgroupid ? "AND rg.id = :jrelationshipgroupid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['jcourseid']       = $courseid;
    $params['userid']          = $userid;
    $params['juserid']         = $userid;
    $params['juserid']         = $userid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['jrelationshipgroupid'] = $relationshipgroupid;
    $params['categorycontext'] = CONTEXT_COURSECAT;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncgroups']  = RELATIONSHIP_ONLY_SYNC_GROUPS;
    $params['enrolkeepremoved'] = ENROL_EXT_REMOVED_KEEP;

    // Unenrol as necessary.
    $sql = "SELECT ue.*, e.courseid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint3 != :enrolkeepremoved)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {user_enrolments} ue ON (ue.enrolid = e.id {$oneuser})
         LEFT JOIN (SELECT DISTINCT rg.relationshipid, rm.userid
                      FROM {relationship} rl
                      JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$jonegroup})
                      JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id {$joneuser})) jrm
                ON (jrm.relationshipid = rl.id AND jrm.userid = ue.userid)
             WHERE jrm.userid IS NULL OR e.customint2 = :onlysyncgroups";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];
        switch ($instance->customint3) {
            case ENROL_EXT_REMOVED_UNENROL:
                $trace->output("unenrolling: {$ue->userid} ==> {$instance->courseid} via relationship {$instance->customint1}", 1);
                $plugin->unenrol_user($instance, $ue->userid);
                break;
            case ENROL_EXT_REMOVED_SUSPEND:
                if ($ue->status != ENROL_USER_SUSPENDED) {
                    $trace->output("suspending: {$ue->userid} ==> {$instance->courseid}", 1);
                    $plugin->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                }
                break;
        }
    }
    $rs->close();

    unset($instances);
}

function enrol_relationship_unassign_roles(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipid = NULL) {
    global $DB;

    $trace->output('-- Relationship unassigning roles...');

    $plugin = enrol_get_plugin('relationship');

    $onecourse  = $courseid ? "AND c.id = :courseid" : "";
    $jonecourse = $courseid ? "AND c.id = :jcourseid" : "";
    $oneuser = $userid ? "AND ue.userid = :userid" : "";
    $joneuser = $userid ? "AND ue.userid = :juserid" : "";
    $jonecourse = $courseid ? "AND c.id = :jcourseid" : "";
    $onerelat = $relationshipid ? "AND rl.id = :relationshipid" : "";
    $jonerelat = $relationshipid ? "WHERE rl.id = :jrelationshipid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['jcourseid']       = $courseid;
    $params['userid']          = $userid;
    $params['juserid']         = $userid;
    $params['relationshipid']  = $relationshipid;
    $params['jrelationshipid'] = $relationshipid;
    $params['coursecontext']    = CONTEXT_COURSE;
    $params['statusenabled']    = ENROL_INSTANCE_ENABLED;
    $params['onlysyncgroups']   = RELATIONSHIP_ONLY_SYNC_GROUPS;
    $params['enrolkeepremoved'] = ENROL_EXT_REMOVED_KEEP;

    // Unenrol as necessary.
    $sql = "SELECT e.courseid, ra.contextid, ra.userid, ra.roleid, ra.itemid
              FROM {relationship} rl
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint3 != :enrolkeepremoved)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :coursecontext)
              JOIN {role_assignments} ra ON (ra.contextid = ctx.id AND ra.component = 'enrol_relationship' AND ra.itemid = e.id)
         LEFT JOIN (SELECT DISTINCT c.id as courseid, rm.userid, rm.roleid
                      FROM {relationship} rl
                      JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship')
                      JOIN {course} c ON (c.id = e.courseid {$onecourse})
                      JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)
                      JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id {$joneuser})
                      {$jonerelat}) jrm
                ON (jrm.courseid = e.courseid AND jrm.userid = ra.userid AND jrm.roleid = ra.roleid)
             WHERE (jrm.userid IS NULL OR e.customint2 = :onlysyncgroups) {$onerelat}";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        $trace->output("unassigning role: {$ra->roleid} user: {$ra->userid} course: {$ra->courseid}", 1);
        role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_relationship', $ra->itemid);
    }
    $rs->close();
}

function enrol_relationship_create_groupings_and_groups(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Creating groupings and groups...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $oneuser = $userid ? "AND ue.userid = :userid" : "";
    $onegroup = $relationshipgroupid ? "AND rg.id = :relationshipgroupid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['userid']          = $userid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['jstatusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;

    $sql = "SELECT g.id, g.idnumber, g.name, g.courseid, CONCAT('relationship_', rl.id, '_', rg.id) AS new_idnumber
              FROM {relationship} rl
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groups} g ON (g.courseid = c.id AND g.idnumber LIKE CONCAT('relationship_', rl.id, '_%'))
         LEFT JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id AND rg.name = g.name AND
                                                g.idnumber != CONCAT('relationship_', rl.id, '_',rg.id))
             WHERE NOT rg.id IS NULL
               AND EXISTS (SELECT 1 FROM {user_enrolments} ue
                                    JOIN {relationship_members} rm ON (rm.userid = ue.userid)
                                   WHERE ue.enrolid = e.id AND ue.status != :suspended {$oneuser}
                                     AND rm.relationshipgroupid = rg.id)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grp) {
        $trace->output("Changing idnumber of group '{$grp->name}' ({$grp->idnumber}) on course {$grp->courseid} to '{$grp->new_idnumber}'", 1);
        $data = new stdclass();
        $data->id       = $grp->id;
        $data->courseid = $grp->courseid;
        $data->name     = $grp->name;
        $data->idnumber = $grp->new_idnumber;
        $data->timemodified = time();
        groups_update_group($data);
    }

    // create new groupings
    $sql = "SELECT CONCAT('relationship_', rl.id) as idnumber, rl.name, e.courseid
              FROM {relationship} rl
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
         LEFT JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
             WHERE gr.id IS NULL
               AND EXISTS (SELECT 1
                             FROM {relationship_groups} rg
                             JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)
                             JOIN {enrol} ew ON (ew.status = :jstatusenabled)
                             JOIN {user_enrolments} ue ON (ue.enrolid = ew.id AND ue.status != :suspended AND ue.userid = rm.userid)
                            WHERE rg.relationshipid = rl.id
                              AND ew.courseid = c.id)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grping) {
        $trace->output("creating grouping: '{$grping->name}' ({$grping->idnumber}) on course {$grping->courseid}", 1);
        $data = new stdclass();
        $data->courseid = $grping->courseid;
        $data->name     = $grping->name;
        $data->idnumber = $grping->idnumber;
        $data->description = '';
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        groups_create_grouping($data);
    }

    // create new groups
    $sql = "SELECT CONCAT('relationship_', rl.id, '_', rg.id) as idnumber, rg.name, e.courseid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
         LEFT JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
             WHERE g.id IS NULL
               AND EXISTS (SELECT 1
                             FROM {relationship_members} rm
                             JOIN {enrol} ew ON (ew.status = :jstatusenabled)
                             JOIN {user_enrolments} ue ON (ue.enrolid = ew.id AND ue.status != :suspended AND ue.userid = rm.userid)
                            WHERE rm.relationshipgroupid = rg.id
                              AND ew.courseid = c.id)";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grp) {
        $trace->output("creating group: '{$grp->name}' ({$grp->idnumber}) on course {$grp->courseid}", 1);
        $data = new stdclass();
        $data->courseid = $grp->courseid;
        $data->name     = $grp->name;
        $data->idnumber = $grp->idnumber;
        $data->description = '';
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        groups_create_group($data);
    }

    // assing new groups to groupings
    $sql = "SELECT gr.id as groupingid, gr.idnumber as grouping_idnumber, g.id as groupid, g.idnumber as group_idnumber
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
         LEFT JOIN {groupings_groups} gg ON (gg.groupingid = gr.id AND gg.groupid = g.id)
             WHERE gg.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $g) {
        $trace->output("assigning group '{$g->group_idnumber}' ({$g->groupid}) to grouping '{$g->grouping_idnumber}' ($g->groupingid)", 1);
        groups_assign_grouping($g->groupingid, $g->groupid, time());
    }
}

function enrol_relationship_rename_groupings(progress_trace $trace, $courseid = NULL, $relationshipid = NULL) {
    global $DB;

    $trace->output('-- Renaming groupings...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $onerel = $relationshipid ? "AND rl.id = :relationshipid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['relationshipid']  = $relationshipid;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;

    // change grouping names when necessary
    $sql = "SELECT gr.id, rl.name, e.courseid, gr.idnumber
              FROM {relationship} rl
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
             WHERE gr.name != rl.name {$onerel}";
    $rs = $DB->get_recordset_sql($sql, $params); 
    foreach($rs as $grping) {
        $trace->output("changing name of grouping '{$grping->idnumber}' on course {$grping->courseid} to '{$grping->name}'", 1);
        $data = new stdclass();
        $data->id       = $grping->id;
        $data->courseid = $grping->courseid;
        $data->name     = $grping->name;
        $data->timemodified = time();
        groups_update_grouping($data);
    }
}

function enrol_relationship_rename_groups(progress_trace $trace, $courseid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Renaming groups...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $onegroup = $relationshipgroupid ? "AND rg.id = :relationshipgroupid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;

    // change group names when necessary
    $sql = "SELECT g.id, rg.name, e.courseid, g.idnumber
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groups} g ON (g.courseid = c.id AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
             WHERE g.name != rg.name";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $grp) {
        $trace->output("changing name of group '{$grp->idnumber}' on course {$grp->courseid} to '{$grp->name}'", 1);
        $data = new stdclass();
        $data->id       = $grp->id;
        $data->courseid = $grp->courseid;
        $data->name     = $grp->name;
        $data->timemodified = time();
        groups_update_group($data);
    }
}

function enrol_relationship_unassign_groups_from_groupings(progress_trace $trace, $courseid = NULL) {
    global $DB;

    $trace->output('-- Unassingning groupings and groups...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;
    $params['enrolkeepremoved'] = ENROL_EXT_REMOVED_KEEP;

    // unassing old groups from groupings
    $sql = "SELECT gr.id as groupingid, gr.idnumber as grouping_idnumber, g.id as groupid, g.idnumber as group_idnumber
              FROM {relationship} rl
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers AND e.customint3 != :enrolkeepremoved)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid)
         LEFT JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id and CONCAT('relationship_', rl.id, '_', rg.id) = g.idnumber)
             WHERE rg.id IS NULL";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $g) {
        $trace->output("unassigning group '{$g->group_idnumber}' ({$g->groupid}) to grouping '{$g->grouping_idnumber}' ($g->groupingid)", 1);
        groups_unassign_grouping($g->groupingid, $g->groupid, time());
    }
}

function enrol_relationship_remove_member_groups(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Removing group members ...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $oneuser = $userid ? "AND gm.userid = :userid" : "";
    if($relationshipgroupid) {
        $join = '';
        $jgroups = "g.idnumber LIKE CONCAT('relationship_', rl.id, '_', :relationshipgroupid)";
        $ljrm = ':jrelationshipgroupid';
    } else {
        $join = "JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id)";
        $jgroups = "g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id)";
        $ljrm = 'rg.id';
    }
    $onegroup = $relationshipgroupid ? ":relationshipgroupid" : "%";

    $params = array();
    $params['courseid']        = $courseid;
    $params['userid']          = $userid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['jrelationshipgroupid'] = $relationshipgroupid;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['useractive']      = ENROL_USER_ACTIVE;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;
    $params['unenrolremoved']  = ENROL_EXT_REMOVED_UNENROL;

    // Remove as necessary
    $sql = "SELECT gm.*, e.courseid, g.name AS groupname
              FROM {relationship} rl
              {$join}
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint3 = :unenrolremoved)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid AND {$jgroups})
              JOIN {groups_members} gm ON (gm.groupid = g.id AND gm.component = 'enrol_relationship' AND gm.itemid = e.id {$oneuser})
         LEFT JOIN {relationship_members} rm ON (rm.relationshipgroupid = {$ljrm} AND rm.userid = gm.userid)
             WHERE rm.id IS NULL OR e.customint2 = :onlysyncusers";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $gm) {
        $trace->output("removing user from group: {$gm->userid} ==> {$gm->courseid} - {$gm->groupname}", 1);
        groups_remove_member($gm->groupid, $gm->userid);
    }
    $rs->close();
}

function enrol_relationship_add_member_groups(progress_trace $trace, $courseid = NULL, $userid = NULL, $relationshipgroupid = NULL) {
    global $DB;

    $trace->output('-- Adding group members ...');

    $onecourse = $courseid ? "AND c.id = :courseid" : "";
    $oneuser = $userid ? "AND ue.userid = :userid" : "";
    $onegroup = $relationshipgroupid ? "AND rg.id = :relationshipgroupid" : "";

    $params = array();
    $params['courseid']        = $courseid;
    $params['userid']          = $userid;
    $params['relationshipgroupid'] = $relationshipgroupid;
    $params['coursecontext']   = CONTEXT_COURSE;
    $params['suspended']       = ENROL_USER_SUSPENDED;
    $params['statusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['jstatusenabled']   = ENROL_INSTANCE_ENABLED;
    $params['useractive']      = ENROL_USER_ACTIVE;
    $params['onlysyncusers']   = RELATIONSHIP_ONLY_SYNC_USERS;

    // Add missing.
    $sql = "SELECT DISTINCT g.id AS groupid, rm.userid, e.courseid, g.name AS groupname, e.id as enrolid, gm.itemid, gm.id as groupmemberid
              FROM {relationship} rl
              JOIN {relationship_groups} rg ON (rg.relationshipid = rl.id {$onegroup})
              JOIN {relationship_members} rm ON (rm.relationshipgroupid = rg.id)
              JOIN {enrol} e ON (e.customint1 = rl.id AND e.enrol = 'relationship' AND
                                 e.status = :statusenabled AND e.customint2 != :onlysyncusers)
              JOIN {course} c ON (c.id = e.courseid {$onecourse})
              JOIN {enrol} ew ON (ew.courseid = c.id AND ew.status = :jstatusenabled)
              JOIN {user_enrolments} ue ON (ue.enrolid = ew.id AND ue.userid = rm.userid AND ue.status = :useractive {$oneuser})
              JOIN {groupings} gr ON (gr.courseid = c.id AND gr.idnumber = CONCAT('relationship_', rl.id))
              JOIN {groupings_groups} gg ON (gg.groupingid = gr.id)
              JOIN {groups} g ON (g.courseid = c.id AND g.id = gg.groupid AND g.idnumber = CONCAT('relationship_', rl.id, '_', rg.id))
         LEFT JOIN {groups_members} gm ON (gm.groupid = g.id AND gm.userid = rm.userid)
             WHERE gm.id IS NULL OR gm.itemid = 0";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $gm) {
        if($gm->itemid === NULL) {
            $trace->output("adding user to group: {$gm->userid} ==> {$gm->courseid} - {$gm->groupname}", 1);
            groups_add_member($gm->groupid, $gm->userid, 'enrol_relationship', $gm->enrolid);
        } else {
            $trace->output("changing component from user to group: {$gm->userid} ==> {$gm->courseid} - {$gm->groupname}", 1);
            $group_member = new stdclass();
            $group_member->id = $gm->groupmemberid;
            $group_member->component = 'enrol_relationship';
            $group_member->itemid = $gm->enrolid;
            $DB->update_record('groups_members', $group_member);
        }
    }
    $rs->close();
}
