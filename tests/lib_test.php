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
 * Testes unitários da classe enrol_relationship_plugin (lib.php).
 *
 * @package    enrol_relationship
 * @copyright  2026 UFSC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      enrol_relationship
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/relationship/tests/helper_testcase.php');
require_once($CFG->dirroot . '/enrol/relationship/lib.php');

/**
 * @group enrol_relationship
 */
class enrol_relationship_lib_testcase extends enrol_relationship_helper_testcase {

    // ---------------------------------------------------------------------
    // can_delete_instance / can_hide_show_instance
    // ---------------------------------------------------------------------

    public function test_can_delete_instance_true_for_admin() {
        $instance = $this->create_instance();
        $this->assertTrue($this->plugin->can_delete_instance($instance));
    }

    public function test_can_hide_show_instance_true_for_admin() {
        $instance = $this->create_instance();
        $this->assertTrue($this->plugin->can_hide_show_instance($instance));
    }

    // ---------------------------------------------------------------------
    // get_instance_name
    // ---------------------------------------------------------------------

    public function test_get_instance_name_empty_instance_returns_plugin_name() {
        $expected = get_string('pluginname', 'enrol_relationship');
        $this->assertEquals($expected, $this->plugin->get_instance_name(null));
    }

    public function test_get_instance_name_uses_relationship_name_when_instance_unnamed() {
        $instance = $this->create_instance();
        $instance->name = null;
        $name = $this->plugin->get_instance_name($instance);
        $this->assertContains('Relationship fixture', $name);
    }

    public function test_get_instance_name_falls_back_to_plugin_name_when_relationship_missing() {
        $instance = $this->create_instance();
        $instance->name = null;
        $instance->customint1 = -1; // Relationship inexistente.
        $this->assertEquals(get_string('pluginname', 'enrol_relationship'),
            $this->plugin->get_instance_name($instance));
    }

    public function test_get_instance_name_uses_custom_name_when_set() {
        $instance = $this->create_instance();
        $instance->name = 'Meu nome customizado';
        $this->assertEquals('Meu nome customizado', $this->plugin->get_instance_name($instance));
    }

    // ---------------------------------------------------------------------
    // get_newinstance_link / can_add_new_instances
    // ---------------------------------------------------------------------

    public function test_get_newinstance_link_returns_url_when_relationship_available() {
        $link = $this->plugin->get_newinstance_link($this->course->id);
        $this->assertInstanceOf('moodle_url', $link);
    }

    public function test_get_newinstance_link_returns_null_without_available_relationship() {
        // Curso em outra categoria sem nenhum relationship visível no contexto pai.
        $othercat = $this->getDataGenerator()->create_category();
        $othercourse = $this->getDataGenerator()->create_course(array('category' => $othercat->id));
        $this->assertNull($this->plugin->get_newinstance_link($othercourse->id));
    }

    // ---------------------------------------------------------------------
    // get_action_icons
    // ---------------------------------------------------------------------

    public function test_get_action_icons_returns_edit_icon_for_admin() {
        $instance = $this->create_instance();
        $icons = $this->plugin->get_action_icons($instance);
        $this->assertCount(1, $icons);
    }

    public function test_get_action_icons_throws_for_non_relationship_instance() {
        $instance = $this->create_instance();
        $instance->enrol = 'manual';
        $this->setExpectedException('coding_exception');
        $this->plugin->get_action_icons($instance);
    }

    // ---------------------------------------------------------------------
    // cron / course_updated / update_status
    // ---------------------------------------------------------------------

    public function test_cron_runs_full_sync_and_enrols_members() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
        $this->plugin->cron();
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
    }

    public function test_course_updated_is_a_noop() {
        // Apenas garante que o hook existe e não lança exceção.
        $this->assertNull($this->plugin->course_updated(true, $this->course, new stdClass()));
    }

    public function test_update_status_changes_instance_status() {
        global $DB;
        $instance = $this->create_instance();
        $this->plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
        $this->assertEquals(ENROL_INSTANCE_DISABLED,
            $DB->get_field('enrol', 'status', array('id' => $instance->id)));
    }

    // ---------------------------------------------------------------------
    // delete_instance
    // ---------------------------------------------------------------------

    public function test_delete_instance_removes_plugin_groups() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupid);

        $this->plugin->delete_instance($instance);

        $this->assertFalse($DB->record_exists('groups', array('id' => $groupid)));
        $this->assertFalse($DB->record_exists('enrol', array('id' => $instance->id)));
    }

    // ---------------------------------------------------------------------
    // get_user_enrolment_actions
    // ---------------------------------------------------------------------

    public function test_get_user_enrolment_actions_returns_array() {
        global $PAGE, $CFG;
        require_once($CFG->dirroot . '/enrol/locallib.php');

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $PAGE->set_url('/enrol/users.php', array('id' => $this->course->id));
        $manager = new course_enrolment_manager($PAGE, $this->course);
        $ues = $manager->get_user_enrolments($user->id);
        $ue = reset($ues);

        $actions = $this->plugin->get_user_enrolment_actions($manager, $ue);
        $this->assertInternalType('array', $actions);
    }

    // ---------------------------------------------------------------------
    // restore_* (via mocks do restore step)
    // ---------------------------------------------------------------------

    public function test_restore_group_member_is_a_noop() {
        $instance = $this->create_instance();
        $this->assertNull($this->plugin->restore_group_member($instance, 0, 0));
    }

    public function test_restore_user_enrolment_enrols_user_when_not_yet_enrolled() {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();

        $step = $this->getMockBuilder('restore_enrolments_structure_step')
            ->disableOriginalConstructor()->getMock();
        $data = (object) array('timestart' => 0, 'timeend' => 0);

        $this->plugin->restore_user_enrolment($step, $data, $instance, $user->id, ENROL_INSTANCE_ENABLED);
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
    }

    public function test_restore_instance_creates_instance_when_samesite_and_relationship_exists() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Curso novo sem instância: restore deve criar uma.
        $course = $this->getDataGenerator()->create_course(array('category' => $this->category->id));

        $task = $this->getMockBuilder('stdClass')->setMethods(array('is_samesite'))->getMock();
        $task->method('is_samesite')->willReturn(true);
        $step = $this->getMockBuilder('restore_enrolments_structure_step')
            ->disableOriginalConstructor()->getMock();
        $step->method('get_task')->willReturn($task);

        $data = (object) array(
            'customint1' => $this->relationshipid,
            'customint2' => RELATIONSHIP_SYNC_USERS_AND_GROUPS,
            'customint3' => ENROL_EXT_REMOVED_UNENROL,
            'status' => ENROL_INSTANCE_ENABLED,
            'name' => null,
        );

        $this->plugin->restore_instance($step, $data, $course, 999);

        $this->assertTrue($DB->record_exists('enrol', array(
            'enrol' => 'relationship', 'courseid' => $course->id, 'customint1' => $this->relationshipid)));
    }

    public function test_restore_instance_skips_when_not_samesite() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $course = $this->getDataGenerator()->create_course(array('category' => $this->category->id));

        $task = $this->getMockBuilder('stdClass')->setMethods(array('is_samesite'))->getMock();
        $task->method('is_samesite')->willReturn(false);
        $step = $this->getMockBuilder('restore_enrolments_structure_step')
            ->disableOriginalConstructor()->getMock();
        $step->method('get_task')->willReturn($task);

        $data = (object) array('customint1' => $this->relationshipid);
        $this->plugin->restore_instance($step, $data, $course, 999);

        $this->assertFalse($DB->record_exists('enrol',
            array('enrol' => 'relationship', 'courseid' => $course->id)));
    }

    public function test_restore_instance_skips_when_relationship_missing() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $course = $this->getDataGenerator()->create_course(array('category' => $this->category->id));

        $task = $this->getMockBuilder('stdClass')->setMethods(array('is_samesite'))->getMock();
        $task->method('is_samesite')->willReturn(true);
        $step = $this->getMockBuilder('restore_enrolments_structure_step')
            ->disableOriginalConstructor()->getMock();
        $step->method('get_task')->willReturn($task);

        $data = (object) array('customint1' => -1); // Relationship inexistente.
        $this->plugin->restore_instance($step, $data, $course, 999);

        $this->assertFalse($DB->record_exists('enrol',
            array('enrol' => 'relationship', 'courseid' => $course->id)));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_allow_group_member_remove
    // ---------------------------------------------------------------------

    public function test_allow_group_member_remove_returns_false() {
        $this->assertFalse(enrol_relationship_allow_group_member_remove(1, 2, 3));
    }
}
