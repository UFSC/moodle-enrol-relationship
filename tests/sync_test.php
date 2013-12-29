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
 * relationship enrolment sync functional test.
 *
 * @package    enrol_relationship
 * @category   phpunit
 * @copyright  2012 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/enrol/relationship/locallib.php');
require_once($CFG->dirroot.'/local/relationship/lib.php');
require_once($CFG->dirroot.'/group/lib.php');

class enrol_relationship_testcase extends advanced_testcase {

    protected function enable_plugin() {
        $enabled = enrol_get_plugins(true);
        $enabled['relationship'] = true;
        $enabled = array_keys($enabled);
        set_config('enrol_plugins_enabled', implode(',', $enabled));
    }

    protected function disable_plugin() {
        $enabled = enrol_get_plugins(true);
        unset($enabled['relationship']);
        $enabled = array_keys($enabled);
        set_config('enrol_plugins_enabled', implode(',', $enabled));
    }

    public function test_handler_sync() {
        global $DB;

        $this->resetAfterTest();

        // Setup a few courses and categories.

        $relationshipplugin = enrol_get_plugin('relationship');
        $manualplugin = enrol_get_plugin('manual');

        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $teacherrole = $DB->get_record('role', array('shortname'=>'teacher'));
        $this->assertNotEmpty($teacherrole);
        $managerrole = $DB->get_record('role', array('shortname'=>'manager'));
        $this->assertNotEmpty($managerrole);

        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();

        $course1 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course2 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course3 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $course4 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $maninstance1 = $DB->get_record('enrol', array('courseid'=>$course1->id, 'enrol'=>'manual'), '*', MUST_EXIST);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $user4 = $this->getDataGenerator()->create_user();

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $relationship2 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat2->id)->id));
        $relationship3 = $this->getDataGenerator()->create_relationship();

        $this->enable_plugin();

        $manualplugin->enrol_user($maninstance1, $user4->id, $teacherrole->id);
        $manualplugin->enrol_user($maninstance1, $user3->id, $managerrole->id);

        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship2->id, 'roleid'=>$teacherrole->id));
        $relationshipinstance2 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course2, array('customint1'=>$relationship2->id, 'roleid'=>$studentrole->id));
        $relationshipinstance3 = $DB->get_record('enrol', array('id'=>$id));


        // Test relationship member add event.

        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user4->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user1->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user2->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user4->id)));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        relationship_add_member($relationship2->id, $user3->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance3->id, 'userid'=>$user3->id)));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course2->id)->id, 'userid'=>$user3->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance3->id)));

        relationship_add_member($relationship3->id, $user3->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));

        // Test relationship remove action.

        $this->assertEquals(ENROL_EXT_REMOVED_UNENROL, $relationshipplugin->get_config('unenrolaction'));
        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        relationship_remove_member($relationship1->id, $user2->id);
        relationship_remove_member($relationship1->id, $user4->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user4->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        relationship_remove_member($relationship1->id, $user2->id);
        relationship_remove_member($relationship1->id, $user4->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user2->id)));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user4->id)));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        relationship_remove_member($relationship2->id, $user3->id);
        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance3->id, 'userid'=>$user3->id)));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course2->id)->id, 'userid'=>$user3->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance3->id)));


        // Test relationship deleting.

        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user4->id);
        relationship_add_member($relationship2->id, $user3->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        relationship_delete_relationship($relationship2);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));

        $relationshipinstance2 = $DB->get_record('enrol', array('id'=>$relationshipinstance2->id), '*', MUST_EXIST);
        $relationshipinstance3 = $DB->get_record('enrol', array('id'=>$relationshipinstance3->id), '*', MUST_EXIST);

        $this->assertEquals(ENROL_INSTANCE_DISABLED, $relationshipinstance2->status);
        $this->assertEquals(ENROL_INSTANCE_DISABLED, $relationshipinstance3->status);
        $this->assertFalse($DB->record_exists('role_assignments', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance3->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        relationship_delete_relationship($relationship1);
        $this->assertEquals(4, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('enrol', array('id'=>$relationshipinstance1->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));


        // Test group sync.

        $id = groups_create_group((object)array('name'=>'Group 1', 'courseid'=>$course1->id));
        $group1 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);
        $id = groups_create_group((object)array('name'=>'Group 2', 'courseid'=>$course1->id));
        $group2 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id, 'customint2'=>$group1->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $this->assertEquals(4, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(2, $DB->count_records('role_assignments', array()));

        $this->assertTrue(is_enrolled(context_course::instance($course1->id), $user4));
        $this->assertTrue(groups_add_member($group1, $user4));
        $this->assertTrue(groups_add_member($group2, $user4));

        $this->assertFalse(groups_is_member($group1->id, $user1->id));
        relationship_add_member($relationship1->id, $user1->id);
        $this->assertTrue(groups_is_member($group1->id, $user1->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user1->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        relationship_add_member($relationship1->id, $user4->id);
        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);

        relationship_remove_member($relationship1->id, $user1->id);
        $this->assertFalse(groups_is_member($group1->id, $user1->id));

        relationship_remove_member($relationship1->id, $user4->id);
        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertTrue(groups_is_member($group2->id, $user4->id));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        relationship_add_member($relationship1->id, $user1->id);

        relationship_remove_member($relationship1->id, $user1->id);
        $this->assertTrue(groups_is_member($group1->id, $user1->id));


        // Test deleting of instances.

        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user3->id);

        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertEquals(5, $DB->count_records('groups_members', array()));
        $this->assertEquals(3, $DB->count_records('groups_members', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipplugin->delete_instance($relationshipinstance1);

        $this->assertEquals(4, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(0, $DB->count_records('role_assignments', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertEquals(2, $DB->count_records('groups_members', array()));
        $this->assertEquals(0, $DB->count_records('groups_members', array('component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
    }

    public function test_sync_course() {
        global $DB;
        $this->resetAfterTest();

        $trace = new null_progress_trace();

        // Setup a few courses and categories.

        $relationshipplugin = enrol_get_plugin('relationship');
        $manualplugin = enrol_get_plugin('manual');

        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $teacherrole = $DB->get_record('role', array('shortname'=>'teacher'));
        $this->assertNotEmpty($teacherrole);
        $managerrole = $DB->get_record('role', array('shortname'=>'manager'));
        $this->assertNotEmpty($managerrole);

        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();

        $course1 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course2 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course3 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $course4 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $maninstance1 = $DB->get_record('enrol', array('courseid'=>$course1->id, 'enrol'=>'manual'), '*', MUST_EXIST);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $user4 = $this->getDataGenerator()->create_user();

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $relationship2 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat2->id)->id));
        $relationship3 = $this->getDataGenerator()->create_relationship();

        $this->disable_plugin(); // Prevents event sync.

        $manualplugin->enrol_user($maninstance1, $user4->id, $teacherrole->id);
        $manualplugin->enrol_user($maninstance1, $user3->id, $managerrole->id);

        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship2->id, 'roleid'=>$teacherrole->id));
        $relationshipinstance2 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course2, array('customint1'=>$relationship2->id, 'roleid'=>$studentrole->id));
        $relationshipinstance3 = $DB->get_record('enrol', array('id'=>$id));

        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user4->id);
        relationship_add_member($relationship2->id, $user3->id);
        relationship_add_member($relationship3->id, $user3->id);

        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));


        // Test sync of one course only.

        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));


        $this->enable_plugin();
        enrol_relationship_sync($trace, $course2->id);
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));
        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship3->id)); // Use low level DB api to prevent events!
        $DB->delete_records('relationship', array('id'=>$relationship3->id)); // Use low level DB api to prevent events!

        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user1->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user2->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user4->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship2->id, 'userid'=>$user3->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(6, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship1->id, 'userid'=>$user1->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user1->id)));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship1->id)); // Use low level DB api to prevent events!
        $DB->delete_records('relationship', array('id'=>$relationship1->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));


        // Test group sync.

        $this->disable_plugin(); // No event sync

        $id = groups_create_group((object)array('name'=>'Group 1', 'courseid'=>$course1->id));
        $group1 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);
        $id = groups_create_group((object)array('name'=>'Group 2', 'courseid'=>$course1->id));
        $group2 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id, 'customint2'=>$group1->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $this->assertTrue(is_enrolled(context_course::instance($course1->id), $user4));
        $this->assertTrue(groups_add_member($group1, $user4));
        $this->assertTrue(groups_add_member($group2, $user4));

        $this->enable_plugin(); // No event sync

        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));

        $this->assertFalse(groups_is_member($group1->id, $user1->id));
        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user4->id);
        relationship_add_member($relationship2->id, $user4->id);

        enrol_relationship_sync($trace, $course1->id);

        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));

        $this->assertTrue(groups_is_member($group1->id, $user1->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user1->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipinstance1->customint2 = $group2->id;
        $DB->update_record('enrol', $relationshipinstance1);

        enrol_relationship_sync($trace, $course1->id);
        $this->assertFalse(groups_is_member($group1->id, $user1->id));
        $this->assertTrue(groups_is_member($group2->id, $user1->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group2->id, 'userid'=>$user1->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertTrue(groups_is_member($group2->id, $user4->id));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group2->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        relationship_remove_member($relationship1->id, $user1->id);
        $this->assertFalse(groups_is_member($group1->id, $user1->id));

        relationship_remove_member($relationship1->id, $user4->id);
        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertTrue(groups_is_member($group2->id, $user4->id));
    }

    public function test_sync_all_courses() {
        global $DB;

        $this->resetAfterTest();

        $trace = new null_progress_trace();

        // Setup a few courses and categories.

        $relationshipplugin = enrol_get_plugin('relationship');
        $manualplugin = enrol_get_plugin('manual');

        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $teacherrole = $DB->get_record('role', array('shortname'=>'teacher'));
        $this->assertNotEmpty($teacherrole);
        $managerrole = $DB->get_record('role', array('shortname'=>'manager'));
        $this->assertNotEmpty($managerrole);

        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();

        $course1 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course2 = $this->getDataGenerator()->create_course(array('category'=>$cat1->id));
        $course3 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $course4 = $this->getDataGenerator()->create_course(array('category'=>$cat2->id));
        $maninstance1 = $DB->get_record('enrol', array('courseid'=>$course1->id, 'enrol'=>'manual'), '*', MUST_EXIST);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $user4 = $this->getDataGenerator()->create_user();

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $relationship2 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat2->id)->id));
        $relationship3 = $this->getDataGenerator()->create_relationship();

        $this->disable_plugin(); // Prevents event sync.

        $manualplugin->enrol_user($maninstance1, $user4->id, $teacherrole->id);
        $manualplugin->enrol_user($maninstance1, $user3->id, $managerrole->id);

        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship2->id, 'roleid'=>$teacherrole->id));
        $relationshipinstance2 = $DB->get_record('enrol', array('id'=>$id));

        $id = $relationshipplugin->add_instance($course2, array('customint1'=>$relationship2->id, 'roleid'=>$studentrole->id));
        $relationshipinstance3 = $DB->get_record('enrol', array('id'=>$id));

        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user2->id);
        relationship_add_member($relationship1->id, $user4->id);
        relationship_add_member($relationship2->id, $user3->id);
        relationship_add_member($relationship3->id, $user3->id);

        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));


        // Test sync of one course only.

        enrol_relationship_sync($trace, null);
        $this->assertEquals(2, $DB->count_records('role_assignments', array()));
        $this->assertEquals(2, $DB->count_records('user_enrolments', array()));


        $this->enable_plugin();
        enrol_relationship_sync($trace, null);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user1->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user2->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user4->id)));
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertEquals(7, $DB->count_records('role_assignments', array()));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user4->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship2->id, 'userid'=>$user3->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(7, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(6, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship1->id, 'userid'=>$user1->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance2->id, 'userid'=>$user3->id)));
        $this->assertFalse($DB->record_exists('user_enrolments', array('enrolid'=>$relationshipinstance1->id, 'userid'=>$user1->id)));
        $this->assertEquals(5, $DB->count_records('role_assignments', array()));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user3->id, 'roleid'=>$teacherrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance2->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>context_course::instance($course1->id)->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
        $DB->delete_records('relationship_members', array('relationshipid'=>$relationship1->id)); // Use low level DB api to prevent events!
        $DB->delete_records('relationship', array('id'=>$relationship1->id)); // Use low level DB api to prevent events!
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(5, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));

        $relationshipplugin->set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);
        enrol_relationship_sync($trace, $course1->id);
        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));


        // Test group sync.

        $this->disable_plugin(); // No event sync

        $id = groups_create_group((object)array('name'=>'Group 1', 'courseid'=>$course1->id));
        $group1 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);
        $id = groups_create_group((object)array('name'=>'Group 2', 'courseid'=>$course1->id));
        $group2 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);
        $id = groups_create_group((object)array('name'=>'Group 2', 'courseid'=>$course2->id));
        $group3 = $DB->get_record('groups', array('id'=>$id), '*', MUST_EXIST);

        $relationship1 = $this->getDataGenerator()->create_relationship(array('contextid'=>context_coursecat::instance($cat1->id)->id));
        $id = $relationshipplugin->add_instance($course1, array('customint1'=>$relationship1->id, 'roleid'=>$studentrole->id, 'customint2'=>$group1->id));
        $relationshipinstance1 = $DB->get_record('enrol', array('id'=>$id));

        $this->assertTrue(groups_add_member($group1, $user4));
        $this->assertTrue(groups_add_member($group2, $user4));

        $this->assertEquals(3, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(3, $DB->count_records('role_assignments', array()));

        $this->assertFalse(groups_is_member($group1->id, $user1->id));
        relationship_add_member($relationship1->id, $user1->id);
        relationship_add_member($relationship1->id, $user4->id);
        relationship_add_member($relationship2->id, $user4->id);
        relationship_add_member($relationship2->id, $user3->id);

        $this->enable_plugin();

        enrol_relationship_sync($trace, null);

        $this->assertEquals(8, $DB->count_records('user_enrolments', array()));
        $this->assertEquals(8, $DB->count_records('role_assignments', array()));

        $this->assertTrue(groups_is_member($group1->id, $user1->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user1->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(is_enrolled(context_course::instance($course1->id), $user4));
        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(is_enrolled(context_course::instance($course2->id), $user3));
        $this->assertFalse(groups_is_member($group3->id, $user3->id));

        $relationshipinstance1->customint2 = $group2->id;
        $DB->update_record('enrol', $relationshipinstance1);
        $relationshipinstance3->customint2 = $group3->id;
        $DB->update_record('enrol', $relationshipinstance3);

        enrol_relationship_sync($trace, null);
        $this->assertFalse(groups_is_member($group1->id, $user1->id));
        $this->assertTrue(groups_is_member($group2->id, $user1->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group2->id, 'userid'=>$user1->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertTrue(groups_is_member($group2->id, $user4->id));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group1->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));
        $this->assertFalse($DB->record_exists('groups_members', array('groupid'=>$group2->id, 'userid'=>$user4->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance1->id)));

        $this->assertTrue(groups_is_member($group3->id, $user3->id));
        $this->assertTrue($DB->record_exists('groups_members', array('groupid'=>$group3->id, 'userid'=>$user3->id, 'component'=>'enrol_relationship', 'itemid'=>$relationshipinstance3->id)));

        relationship_remove_member($relationship1->id, $user1->id);
        $this->assertFalse(groups_is_member($group1->id, $user1->id));

        relationship_remove_member($relationship1->id, $user4->id);
        $this->assertTrue(groups_is_member($group1->id, $user4->id));
        $this->assertTrue(groups_is_member($group2->id, $user4->id));
    }
}
