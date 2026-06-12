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
 * Testes unitários das funções de sincronização em locallib.php.
 *
 * Estratégia de isolamento: o setup constrói a estrutura do relationship
 * (cohorts/grupos) ANTES de criar a instância de enrol, então os eventos
 * disparados nessa fase são no-op (não há instância para sincronizar). Os
 * relationship_members são inseridos diretamente no banco (add_member), sem
 * disparar eventos, de modo que a sincronização só acontece quando a função
 * sob teste é chamada explicitamente.
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
class enrol_relationship_locallib_testcase extends enrol_relationship_helper_testcase {

    // ---------------------------------------------------------------------
    // enrol_relationship_sync (orquestrador)
    // ---------------------------------------------------------------------

    public function test_sync_returns_2_and_unassigns_roles_when_plugin_disabled() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        // Com o plugin habilitado, o sync completo enrola e cria a role assignment.
        $this->assertEquals(0, enrol_relationship_sync($this->trace(), $this->course->id));
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));

        // Ao desabilitar, o sync purga TODAS as roles do componente e retorna 2.
        $this->disable_plugin();
        $this->assertEquals(2, enrol_relationship_sync($this->trace(), $this->course->id));
        $this->assertFalse($DB->record_exists('role_assignments', array('component' => 'enrol_relationship')));
    }

    public function test_full_sync_enrols_user_creates_grouping_group_and_membership() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS);
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        $this->assertEquals(0, enrol_relationship_sync($this->trace(), $this->course->id));

        // Usuário enrolado com papel de student.
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));

        // Grouping e group criados, e o usuário é membro do group.
        $this->assertNotEmpty($this->moodle_grouping_id());
        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupid);
        $this->assertTrue(groups_is_member($groupid, $user->id));
        $this->assertTrue($DB->record_exists('groups_members', array(
            'groupid' => $groupid, 'userid' => $user->id,
            'component' => 'enrol_relationship', 'itemid' => $instance->id)));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_enrol_users
    // ---------------------------------------------------------------------

    public function test_enrol_users_enrols_new_member() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
        enrol_relationship_enrol_users($this->trace());
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));
    }

    public function test_enrol_users_unsuspends_previously_suspended_member() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_enrol_users($this->trace());
        // Suspende e remove a role para forçar o ramo de "unsuspend".
        $this->plugin->update_user_enrol($instance, $user->id, ENROL_USER_SUSPENDED);
        role_unassign($this->studentroleid, $user->id, $this->coursecontext->id, 'enrol_relationship', $instance->id);

        enrol_relationship_enrol_users($this->trace());

        $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $user->id));
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status);
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_unenrol_users
    // ---------------------------------------------------------------------

    public function test_unenrol_users_unenrols_when_member_removed_and_action_is_unenrol() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_UNENROL);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_enrol_users($this->trace());
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));

        // Remove o vínculo e roda o unenrol.
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_unenrol_users($this->trace());

        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
        $this->assertFalse($this->has_role_assignment($user->id, $this->studentroleid, $instance));
    }

    public function test_unenrol_users_keeps_user_when_action_is_keep() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_KEEP);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_enrol_users($this->trace());
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_unenrol_users($this->trace());

        // Ação KEEP: continua enrolado mesmo sem o vínculo.
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
    }

    public function test_unenrol_users_suspends_when_action_is_suspend() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        // O form não expõe SUSPEND, mas a lógica existe: setamos customint3 direto.
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_SUSPEND);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_enrol_users($this->trace());
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_unenrol_users($this->trace());

        $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $user->id));
        $this->assertEquals(ENROL_USER_SUSPENDED, $ue->status);
    }

    public function test_unenrol_users_only_unassigns_role_when_user_has_other_role_via_same_instance() {
        global $DB;

        // Duas ligações de cohort com papéis distintos, mesmo usuário em ambas.
        list($cohorta, $rcstudent) = $this->link_cohort($this->studentroleid);
        list($cohortb, $rcteacher) = $this->link_cohort($this->teacherroleid);
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_UNENROL);

        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcstudent, $user->id);
        $memberteacher = $this->add_member($rgid, $rcteacher, $user->id);

        enrol_relationship_enrol_users($this->trace());
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));
        $this->assertTrue($this->has_role_assignment($user->id, $this->teacherroleid, $instance));

        // Remove só o vínculo de teacher: como há 2 role assignments no mesmo itemid,
        // o plugin apenas desfaz a role (role_unassign), sem desinscrever.
        $DB->delete_records('relationship_members', array('id' => $memberteacher));
        enrol_relationship_unenrol_users($this->trace());

        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        $this->assertTrue($this->has_role_assignment($user->id, $this->studentroleid, $instance));
        $this->assertFalse($this->has_role_assignment($user->id, $this->teacherroleid, $instance));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_create_groupings_and_groups
    // ---------------------------------------------------------------------

    public function test_create_groupings_and_groups_creates_and_links() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group('Turma 1');
        $instance = $this->create_instance();

        enrol_relationship_create_groupings_and_groups($this->trace());

        $groupingid = $this->moodle_grouping_id();
        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupingid);
        $this->assertNotEmpty($groupid);
        // O group está associado ao grouping.
        $this->assertTrue($DB->record_exists('groupings_groups',
            array('groupingid' => $groupingid, 'groupid' => $groupid)));
        $this->assertEquals('Turma 1', $DB->get_field('groups', 'name', array('id' => $groupid)));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_rename_groupings / rename_groups
    // ---------------------------------------------------------------------

    public function test_rename_groupings_propagates_relationship_name() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        enrol_relationship_create_groupings_and_groups($this->trace());

        $DB->set_field('relationship', 'name', 'Nome novo do relationship',
            array('id' => $this->relationshipid));
        enrol_relationship_rename_groupings($this->trace());

        $this->assertEquals('Nome novo do relationship',
            $DB->get_field('groupings', 'name', array('id' => $this->moodle_grouping_id())));
    }

    public function test_rename_groups_propagates_group_name() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group('Antigo');
        $instance = $this->create_instance();
        enrol_relationship_create_groupings_and_groups($this->trace());

        $DB->set_field('relationship_groups', 'name', 'Renomeado', array('id' => $rgid));
        enrol_relationship_rename_groups($this->trace());

        $this->assertEquals('Renomeado',
            $DB->get_field('groups', 'name', array('id' => $this->moodle_group_id($rgid))));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_unassign_groups_from_groupings
    // ---------------------------------------------------------------------

    public function test_unassign_groups_from_groupings_global_deletes_orphan_groups() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        enrol_relationship_create_groupings_and_groups($this->trace());
        $groupid = $this->moodle_group_id($rgid);
        $this->assertNotEmpty($groupid);

        // Apaga a origem (relationship_groups) sem evento; a função global limpa o orfão.
        $DB->delete_records('relationship_groups', array('id' => $rgid));
        enrol_relationship_unassign_groups_from_groupings($this->trace());

        $this->assertFalse($DB->record_exists('groups', array('id' => $groupid)));
    }

    public function test_unassign_groups_from_groupings_targeted_deletes_specific_group() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        enrol_relationship_create_groupings_and_groups($this->trace());
        $groupid = $this->moodle_group_id($rgid);

        // Ramo com relationshipgroupid explícito (usado pelo handler group_deleted).
        $DB->delete_records('relationship_groups', array('id' => $rgid));
        enrol_relationship_unassign_groups_from_groupings($this->trace(), null, $rgid);

        $this->assertFalse($DB->record_exists('groups', array('id' => $groupid)));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_remove_member_groups
    // ---------------------------------------------------------------------

    public function test_remove_member_groups_removes_user_no_longer_in_relationship() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);
        $groupid = $this->moodle_group_id($rgid);
        $this->assertTrue(groups_is_member($groupid, $user->id));

        // Remove o vínculo no relationship e roda só a remoção de membros do grupo.
        $DB->delete_records('relationship_members', array('relationshipgroupid' => $rgid, 'userid' => $user->id));
        enrol_relationship_remove_member_groups($this->trace());

        $this->assertFalse(groups_is_member($groupid, $user->id));
    }

    // ---------------------------------------------------------------------
    // enrol_relationship_add_member_groups
    // ---------------------------------------------------------------------

    public function test_add_member_groups_reclaims_preexisting_membership_with_itemid_zero() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        // Enrola e cria grouping/group, mas ainda sem membros de grupo.
        enrol_relationship_enrol_users($this->trace());
        enrol_relationship_create_groupings_and_groups($this->trace());
        $groupid = $this->moodle_group_id($rgid);

        // Membro pré-existente adicionado "à mão" (component padrão, itemid 0).
        groups_add_member($groupid, $user->id);
        $this->assertEquals('0', $DB->get_field('groups_members', 'itemid',
            array('groupid' => $groupid, 'userid' => $user->id)));

        enrol_relationship_add_member_groups($this->trace());

        // O plugin "adota" a linha: component/itemid passam a ser do enrol_relationship.
        $gm = $DB->get_record('groups_members', array('groupid' => $groupid, 'userid' => $user->id));
        $this->assertEquals('enrol_relationship', $gm->component);
        $this->assertEquals($instance->id, $gm->itemid);
    }

    // ---------------------------------------------------------------------
    // Modos de sync (customint2)
    // ---------------------------------------------------------------------

    public function test_only_sync_users_mode_enrols_but_creates_no_groups() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_ONLY_SYNC_USERS);
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);

        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
        // Nenhum grouping/group é criado nesse modo.
        $this->assertFalse($this->moodle_grouping_id());
        $this->assertFalse($this->moodle_group_id($rgid));
    }

    public function test_only_sync_groups_mode_creates_groups_but_does_not_enrol() {
        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_ONLY_SYNC_GROUPS);
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);

        // Grouping/group são criados...
        $this->assertNotEmpty($this->moodle_grouping_id());
        $this->assertNotEmpty($this->moodle_group_id($rgid));
        // ...mas o usuário não é enrolado por este plugin nesse modo.
        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
    }

    // ---------------------------------------------------------------------
    // Transições de "Ações para usuários removidos" (customint3)
    //
    // O comportamento é função do valor de customint3 NO MOMENTO do sync, então
    // estes testes provam o caminho "editar a instância e re-sincronizar" — não
    // apenas um valor fixo definido na criação.
    // ---------------------------------------------------------------------

    public function test_transition_unenrol_to_keep_stops_removing_users() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_UNENROL);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));

        // Admin troca a ação para KEEP (como faz o edit.php) e o vínculo some.
        $DB->set_field('enrol', 'customint3', ENROL_EXT_REMOVED_KEEP, array('id' => $instance->id));
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_sync($this->trace(), $this->course->id);

        // Com KEEP, o usuário removido do relationship continua enrolado.
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));
    }

    public function test_transition_keep_to_unenrol_removes_already_orphaned_user() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_KEEP);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_sync($this->trace(), $this->course->id);
        // Ainda enrolado: a ação era KEEP.
        $this->assertTrue($this->is_user_enrolled($instance, $user->id));

        // Admin muda para UNENROL: o próximo sync recolhe o usuário órfão.
        $DB->set_field('enrol', 'customint3', ENROL_EXT_REMOVED_UNENROL, array('id' => $instance->id));
        enrol_relationship_sync($this->trace(), $this->course->id);

        $this->assertFalse($this->is_user_enrolled($instance, $user->id));
        $this->assertFalse($this->has_role_assignment($user->id, $this->studentroleid, $instance));
    }

    public function test_transition_suspend_then_reactivate_via_membership() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_SUSPEND);
        $user = $this->getDataGenerator()->create_user();
        $memberid = $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);
        // Sai do relationship -> suspenso.
        $DB->delete_records('relationship_members', array('id' => $memberid));
        enrol_relationship_sync($this->trace(), $this->course->id);
        $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $user->id));
        $this->assertEquals(ENROL_USER_SUSPENDED, $ue->status);

        // Volta ao relationship -> o sync reativa (ramo de "unsuspend" em enrol_users).
        $this->add_member($rgid, $rcid, $user->id);
        enrol_relationship_sync($this->trace(), $this->course->id);
        $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $user->id));
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status);
    }

    // ---------------------------------------------------------------------
    // Idempotência — rodar o sync repetidamente não duplica nem altera nada.
    // ---------------------------------------------------------------------

    public function test_full_sync_is_idempotent() {
        global $DB;

        list($cohort, $rcid) = $this->link_cohort();
        $rgid = $this->add_group();
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS);
        $user = $this->getDataGenerator()->create_user();
        $this->add_member($rgid, $rcid, $user->id);

        enrol_relationship_sync($this->trace(), $this->course->id);
        $snapshot = $this->sync_state_counts($instance);

        // Uma segunda (e terceira) passada não pode criar/alterar nada.
        enrol_relationship_sync($this->trace(), $this->course->id);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $this->assertEquals($snapshot, $this->sync_state_counts($instance));
        // E os valores esperados continuam exatamente unitários.
        $this->assertEquals(array(
            'groupings' => 1, 'groups' => 1, 'enrolments' => 1,
            'groupmembers' => 1, 'roleassignments' => 1,
        ), $snapshot);
    }

    /**
     * Conta os artefatos que o sync gerencia, para comparações de idempotência.
     *
     * @param stdClass $instance
     * @return array
     */
    protected function sync_state_counts($instance) {
        global $DB;
        return array(
            'groupings' => $DB->count_records('groupings',
                array('courseid' => $this->course->id, 'idnumber' => "relationship_{$this->relationshipid}")),
            'groups' => $DB->count_records_select('groups',
                'courseid = :cid AND ' . $DB->sql_like('idnumber', ':pat'),
                array('cid' => $this->course->id, 'pat' => "relationship_{$this->relationshipid}_%")),
            'enrolments' => $DB->count_records('user_enrolments', array('enrolid' => $instance->id)),
            'groupmembers' => $DB->count_records('groups_members',
                array('component' => 'enrol_relationship', 'itemid' => $instance->id)),
            'roleassignments' => $DB->count_records('role_assignments',
                array('component' => 'enrol_relationship', 'itemid' => $instance->id)),
        );
    }
}
