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
 * Testes de regressão dos handlers de evento (enrol_relationship_handler).
 *
 * Cada teste dispara um evento real (via CRUD do local_relationship ou um enrol
 * manual) com o plugin habilitado e verifica que o handler sincronizou o estado
 * incrementalmente — o mesmo caminho usado em produção quando os eventos chegam.
 * O último teste cobre o ramo de curto-circuito de todos os handlers quando o
 * plugin está desabilitado.
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
class enrol_relationship_handler_testcase extends enrol_relationship_helper_testcase {

    // ---------------------------------------------------------------------
    // member_added / member_removed
    // ---------------------------------------------------------------------

    public function test_member_added_event_enrols_user_and_adds_to_group() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS);

        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);

        // Dispara relationshipgroup_member_added.
        relationship_add_member($rgid, $rcid, $user->id);

        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupid);
        $this->assertTrue(groups_is_member($groupid, $user->id));
    }

    public function test_member_removed_event_removes_from_group_and_unenrols() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_UNENROL);

        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        relationship_add_member($rgid, $rcid, $user->id);
        $groupid = $this->moodle_group_id($rgid);
        $this->assertTrue(groups_is_member($groupid, $user->id));

        // Dispara relationshipgroup_member_removed.
        relationship_remove_member($rgid, $rcid, $user->id);

        $this->assertFalse(groups_is_member($groupid, $user->id));
        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
    }

    // ---------------------------------------------------------------------
    // updated (relationship renomeado)
    // ---------------------------------------------------------------------

    public function test_relationship_updated_event_renames_grouping() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        enrol_relationship_sync($this->trace(), $this->course->id);
        $this->assertNotEmpty($this->moodle_grouping_id());

        $relationship = relationship_get_relationship($this->relationshipid);
        $relationship->name = 'Relationship renomeado';
        relationship_update_relationship($relationship);

        $this->assertEquals('Relationship renomeado',
            $DB->get_field('groupings', 'name', array('id' => $this->moodle_grouping_id())));
    }

    // ---------------------------------------------------------------------
    // group_created / group_updated / group_deleted
    // ---------------------------------------------------------------------

    public function test_relationshipgroup_created_event_creates_moodle_group() {
        list($cohort, $rcid) = $this->link_cohort();
        $instance = $this->create_instance();
        enrol_relationship_sync($this->trace(), $this->course->id);

        // Dispara relationshipgroup_created depois da instância existir.
        $rgid = $this->add_group('Novo grupo');

        $this->assertNotEmpty($this->moodle_group_id($rgid));
    }

    public function test_relationshipgroup_updated_event_renames_moodle_group() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group('Original');
        $instance = $this->create_instance();
        enrol_relationship_sync($this->trace(), $this->course->id);

        $rg = $DB->get_record('relationship_groups', array('id' => $rgid));
        $rg->name = 'Atualizado';
        relationship_update_group($rg);

        $this->assertEquals('Atualizado',
            $DB->get_field('groups', 'name', array('id' => $this->moodle_group_id($rgid))));
    }

    public function test_relationshipgroup_deleted_event_removes_moodle_group() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        relationship_add_member($rgid, $rcid, $user->id);
        $groupid = $this->moodle_group_id($rgid);
        $this->assertTrue(groups_is_member($groupid, $user->id));

        // Dispara relationshipgroup_deleted.
        $rg = $DB->get_record('relationship_groups', array('id' => $rgid));
        relationship_delete_group($rg);

        $this->assertFalse($DB->record_exists('groups', array('id' => $groupid)));
    }

    // ---------------------------------------------------------------------
    // user_enrolment (modo ONLY_SYNC_GROUPS)
    // ---------------------------------------------------------------------

    public function test_user_enrolment_event_adds_member_to_group_in_only_sync_groups_mode() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_ONLY_SYNC_GROUPS);
        enrol_relationship_sync($this->trace(), $this->course->id);
        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupid);

        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);
        $this->assertFalse(groups_is_member($groupid, $user->id));

        // Enrol por OUTRO plugin dispara user_enrolment_created; o handler então
        // adiciona o usuário ao grupo do relationship.
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $this->studentroleid, 'manual');

        $this->assertTrue(groups_is_member($groupid, $user->id));
    }

    // ---------------------------------------------------------------------
    // Ramo desabilitado de todos os handlers
    // ---------------------------------------------------------------------

    public function test_handlers_short_circuit_when_plugin_disabled() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();

        $this->disable_plugin();

        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);

        // Cada operação dispara um evento; com o plugin desabilitado os handlers
        // retornam de imediato e nada é sincronizado.
        $memberid = relationship_add_member($rgid, $rcid, $user->id);   // member_added
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $this->studentroleid, 'manual'); // user_enrolment_created
        $relationship = relationship_get_relationship($this->relationshipid);
        $relationship->name = 'X';
        relationship_update_relationship($relationship);               // updated
        $rg = $DB->get_record('relationship_groups', array('id' => $rgid));
        $rg->name = 'Y';
        relationship_update_group($rg);                                // group_updated
        relationship_remove_member($rgid, $rcid, $user->id);           // member_removed
        $this->add_group('Outro');                                     // group_created
        relationship_delete_group($DB->get_record('relationship_groups', array('id' => $rgid))); // group_deleted

        // Nenhum grouping/group/role do plugin foi criado pelos handlers.
        $this->assertFalse($this->moodle_grouping_id());
        $this->assertFalse($DB->record_exists('groups_members', array('component' => 'enrol_relationship')));
        $this->assertFalse($DB->record_exists('role_assignments', array('component' => 'enrol_relationship')));
    }
}
