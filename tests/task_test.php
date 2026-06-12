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
 * Testes da tarefa agendada de sincronização (classes/task).
 *
 * @package    enrol_relationship
 * @copyright  2026 UFSC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      enrol_relationship
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/relationship/tests/helper_testcase.php');

/**
 * @group enrol_relationship
 */
class enrol_relationship_task_testcase extends enrol_relationship_helper_testcase {

    public function test_get_name_returns_localised_string() {
        $task = new \enrol_relationship\task\enrol_relationship_sync();
        $this->assertEquals(
            get_string('enrolrelationshipsynctask', 'enrol_relationship'),
            $task->get_name());
    }

    public function test_execute_runs_full_sync_and_enrols_members() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        $this->assertFalse($this->is_user_enrolled($instance, $user->id));

        $task = new \enrol_relationship\task\enrol_relationship_sync();
        $task->execute();

        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        $this->assertNotEmpty($this->moodle_group_id($rgid));
    }
}
