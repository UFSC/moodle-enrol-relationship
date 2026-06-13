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
 * Cenário de integração realista para enrol_relationship.
 *
 * Um relationship com 3 papéis (estudante, professor, manager) e 5 cohorts
 * (1 professor, 1 manager, 3 de estudantes A/B/C), distribuídos em 3 grupos com
 * sobreposição (os dois managers participam de TODOS os grupos). Verifica, após
 * um sync completo (sincronizar usuários e grupos), que todos foram inscritos
 * com o papel correto e vinculados exatamente ao(s) grupo(s) esperado(s).
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
class enrol_relationship_scenario_testcase extends enrol_relationship_helper_testcase {

    public function test_three_groups_with_overlapping_managers_are_fully_synced() {
        global $DB;

        // Regressão: enrol_relationship_unenrol_users desinscrevia usuários
        // indevidamente quando o relationship tinha múltiplos cohorts com o MESMO
        // papel (aqui, 3 cohorts de estudante A/B/C). Corrigido com um NOT EXISTS
        // que checa membership em qualquer cohort de mesmo papel.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));

        // --- 5 cohorts ligados ao relationship, cada um com seu papel. ---
        list($cprof, $rcprof)   = $this->link_cohort($this->teacherroleid); // professores
        list($cmgr, $rcmgr)     = $this->link_cohort($managerroleid);       // managers
        list($cesta, $rcesta)   = $this->link_cohort($this->studentroleid); // estudantes A
        list($cestb, $rcestb)   = $this->link_cohort($this->studentroleid); // estudantes B
        list($cestc, $rcestc)   = $this->link_cohort($this->studentroleid); // estudantes C

        // --- 3 grupos. ---
        $rg1 = $this->add_group('Grupo 1');
        $rg2 = $this->add_group('Grupo 2');
        $rg3 = $this->add_group('Grupo 3');

        // --- Usuários. ---
        $prof = array();
        for ($i = 1; $i <= 3; $i++) {
            $prof[$i] = $this->getDataGenerator()->create_user(array('username' => "prof{$i}"));
        }
        $mgr = array();
        for ($i = 1; $i <= 2; $i++) {
            $mgr[$i] = $this->getDataGenerator()->create_user(array('username' => "mgr{$i}"));
        }
        $est = array();
        for ($i = 1; $i <= 9; $i++) {
            $est[$i] = $this->getDataGenerator()->create_user(array('username' => "est{$i}"));
        }

        // --- Vínculos (relationship_members): (grupo, cohort, usuário). ---
        // Grupo 1: professor1 + managers (1,2) + estudantes A (1,2,3).
        $this->add_member($rg1, $rcprof, $prof[1]->id);
        $this->add_member($rg1, $rcmgr, $mgr[1]->id);
        $this->add_member($rg1, $rcmgr, $mgr[2]->id);
        $this->add_member($rg1, $rcesta, $est[1]->id);
        $this->add_member($rg1, $rcesta, $est[2]->id);
        $this->add_member($rg1, $rcesta, $est[3]->id);

        // Grupo 2: professor2 + managers (1,2) + estudantes B (4,5,6).
        $this->add_member($rg2, $rcprof, $prof[2]->id);
        $this->add_member($rg2, $rcmgr, $mgr[1]->id);
        $this->add_member($rg2, $rcmgr, $mgr[2]->id);
        $this->add_member($rg2, $rcestb, $est[4]->id);
        $this->add_member($rg2, $rcestb, $est[5]->id);
        $this->add_member($rg2, $rcestb, $est[6]->id);

        // Grupo 3: professor3 + managers (1,2) + estudantes C (7,8,9).
        $this->add_member($rg3, $rcprof, $prof[3]->id);
        $this->add_member($rg3, $rcmgr, $mgr[1]->id);
        $this->add_member($rg3, $rcmgr, $mgr[2]->id);
        $this->add_member($rg3, $rcestc, $est[7]->id);
        $this->add_member($rg3, $rcestc, $est[8]->id);
        $this->add_member($rg3, $rcestc, $est[9]->id);

        // --- Matrícula pelo relationship, modo "sincronizar usuários e grupos". ---
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS);
        enrol_relationship_sync($this->trace(), $this->course->id);

        // =================================================================
        // 1) Todos os 14 usuários inscritos no curso.
        // =================================================================
        $allusers = array_merge($prof, $mgr, $est);
        $this->assertCount(14, $allusers);
        foreach ($allusers as $u) {
            $this->assertTrue($this->is_user_enrolled($instance, $u->id),
                "usuário {$u->username} deveria estar inscrito");
        }
        // Exatamente 14 inscrições, sem duplicatas (managers entram 1x apesar de 3 grupos).
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // =================================================================
        // 2) Papel correto de cada usuário.
        // =================================================================
        foreach ($prof as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $this->teacherroleid, $instance),
                "{$u->username} deveria ter papel de professor");
        }
        foreach ($mgr as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $managerroleid, $instance),
                "{$u->username} deveria ter papel de manager");
        }
        foreach ($est as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $this->studentroleid, $instance),
                "{$u->username} deveria ter papel de estudante");
        }

        // =================================================================
        // 3) Vínculo de grupo exato.
        // =================================================================
        $gid1 = $this->moodle_group_id($rg1);
        $gid2 = $this->moodle_group_id($rg2);
        $gid3 = $this->moodle_group_id($rg3);
        $this->assertNotEmpty($gid1);
        $this->assertNotEmpty($gid2);
        $this->assertNotEmpty($gid3);

        $expected = array(
            $gid1 => array($prof[1], $mgr[1], $mgr[2], $est[1], $est[2], $est[3]),
            $gid2 => array($prof[2], $mgr[1], $mgr[2], $est[4], $est[5], $est[6]),
            $gid3 => array($prof[3], $mgr[1], $mgr[2], $est[7], $est[8], $est[9]),
        );
        foreach ($expected as $gid => $members) {
            $actual = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', array($gid));
            sort($actual);
            $expectedids = array();
            foreach ($members as $u) {
                $expectedids[] = $u->id;
            }
            sort($expectedids);
            $this->assertEquals($expectedids, $actual,
                "composição do grupo {$gid} diferente do esperado");
        }

        // Os dois managers estão nos TRÊS grupos; cada professor em apenas um.
        foreach ($mgr as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id));
            $this->assertTrue(groups_is_member($gid2, $u->id));
            $this->assertTrue(groups_is_member($gid3, $u->id));
        }
        $this->assertTrue(groups_is_member($gid1, $prof[1]->id));
        $this->assertFalse(groups_is_member($gid2, $prof[1]->id));
        $this->assertFalse(groups_is_member($gid3, $prof[1]->id));

        // Estudante de A não vaza para os grupos B/C.
        $this->assertTrue(groups_is_member($gid1, $est[1]->id));
        $this->assertFalse(groups_is_member($gid2, $est[1]->id));
        $this->assertFalse(groups_is_member($gid3, $est[1]->id));

        // Todos os grupos pertencem ao grouping do relationship.
        $groupingid = $this->moodle_grouping_id();
        foreach (array($gid1, $gid2, $gid3) as $gid) {
            $this->assertTrue($DB->record_exists('groupings_groups',
                array('groupingid' => $groupingid, 'groupid' => $gid)));
        }
    }

    /**
     * Monta a topologia "cohorts distintos" compartilhada pelos cenários 2 e 3:
     * 8 cohorts (3 de professor, 2 de manager, 3 de estudante A/B/C), 3 grupos e
     * 14 usuários, com os dois managers participando dos TRÊS grupos. Não cria a
     * instância de enrol — cada cenário escolhe seu próprio modo/ação.
     *
     * @return stdClass campos: managerroleid, prof[1..3], mgr[1..2], est[1..9], rg1, rg2, rg3
     */
    protected function build_distinct_cohort_topology() {
        global $DB;

        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));

        // 8 cohorts: 1 por professor, 1 por manager, 3 de estudantes.
        list($cprof1, $rcprof1) = $this->link_cohort($this->teacherroleid);
        list($cprof2, $rcprof2) = $this->link_cohort($this->teacherroleid);
        list($cprof3, $rcprof3) = $this->link_cohort($this->teacherroleid);
        list($cmgr1, $rcmgr1)   = $this->link_cohort($managerroleid);
        list($cmgr2, $rcmgr2)   = $this->link_cohort($managerroleid);
        list($cesta, $rcesta)   = $this->link_cohort($this->studentroleid);
        list($cestb, $rcestb)   = $this->link_cohort($this->studentroleid);
        list($cestc, $rcestc)   = $this->link_cohort($this->studentroleid);

        // 3 grupos.
        $rg1 = $this->add_group('Grupo 1');
        $rg2 = $this->add_group('Grupo 2');
        $rg3 = $this->add_group('Grupo 3');

        // 14 usuários.
        $prof = array();
        for ($i = 1; $i <= 3; $i++) {
            $prof[$i] = $this->getDataGenerator()->create_user(array('username' => "prof{$i}"));
        }
        $mgr = array();
        for ($i = 1; $i <= 2; $i++) {
            $mgr[$i] = $this->getDataGenerator()->create_user(array('username' => "mgr{$i}"));
        }
        $est = array();
        for ($i = 1; $i <= 9; $i++) {
            $est[$i] = $this->getDataGenerator()->create_user(array('username' => "est{$i}"));
        }

        // Vínculos: cada professor/manager pelo seu próprio cohort; os dois managers
        // nos três grupos (sobreposição); estudantes A/B/C, um cohort por grupo.
        $this->add_member($rg1, $rcprof1, $prof[1]->id);
        $this->add_member($rg1, $rcmgr1, $mgr[1]->id);
        $this->add_member($rg1, $rcmgr2, $mgr[2]->id);
        $this->add_member($rg1, $rcesta, $est[1]->id);
        $this->add_member($rg1, $rcesta, $est[2]->id);
        $this->add_member($rg1, $rcesta, $est[3]->id);

        $this->add_member($rg2, $rcprof2, $prof[2]->id);
        $this->add_member($rg2, $rcmgr1, $mgr[1]->id);
        $this->add_member($rg2, $rcmgr2, $mgr[2]->id);
        $this->add_member($rg2, $rcestb, $est[4]->id);
        $this->add_member($rg2, $rcestb, $est[5]->id);
        $this->add_member($rg2, $rcestb, $est[6]->id);

        $this->add_member($rg3, $rcprof3, $prof[3]->id);
        $this->add_member($rg3, $rcmgr1, $mgr[1]->id);
        $this->add_member($rg3, $rcmgr2, $mgr[2]->id);
        $this->add_member($rg3, $rcestc, $est[7]->id);
        $this->add_member($rg3, $rcestc, $est[8]->id);
        $this->add_member($rg3, $rcestc, $est[9]->id);

        return (object) array(
            'managerroleid' => $managerroleid,
            'prof' => $prof,
            'mgr' => $mgr,
            'est' => $est,
            'rg1' => $rg1,
            'rg2' => $rg2,
            'rg3' => $rg3,
        );
    }

    /**
     * Cenário 2: variação do cenário 1 em que CADA professor e CADA manager está
     * em um cohort próprio. Agora os três papéis são compartilhados por vários
     * cohorts (3 de professor, 2 de manager, 3 de estudante), então o caminho
     * corrigido (NOT EXISTS por papel) é exercitado para todos os papéis. A
     * composição final de inscrições e grupos deve ser idêntica à do cenário 1.
     */
    public function test_distinct_cohorts_per_professor_and_manager_are_fully_synced() {
        global $DB;

        $t = $this->build_distinct_cohort_topology();
        $managerroleid = $t->managerroleid;
        $prof = $t->prof;
        $mgr = $t->mgr;
        $est = $t->est;
        $rg1 = $t->rg1;
        $rg2 = $t->rg2;
        $rg3 = $t->rg3;

        // Matrícula pelo relationship, modo "sincronizar usuários e grupos".
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS);
        enrol_relationship_sync($this->trace(), $this->course->id);

        // 1) Todos os 14 usuários inscritos, sem duplicatas.
        $allusers = array_merge($prof, $mgr, $est);
        $this->assertCount(14, $allusers);
        foreach ($allusers as $u) {
            $this->assertTrue($this->is_user_enrolled($instance, $u->id),
                "usuário {$u->username} deveria estar inscrito");
        }
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // 2) Papel correto de cada usuário.
        foreach ($prof as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $this->teacherroleid, $instance),
                "{$u->username} deveria ter papel de professor");
        }
        foreach ($mgr as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $managerroleid, $instance),
                "{$u->username} deveria ter papel de manager");
        }
        foreach ($est as $u) {
            $this->assertTrue($this->has_role_assignment($u->id, $this->studentroleid, $instance),
                "{$u->username} deveria ter papel de estudante");
        }

        // 3) Composição de grupo exata — idêntica ao cenário 1.
        $gid1 = $this->moodle_group_id($rg1);
        $gid2 = $this->moodle_group_id($rg2);
        $gid3 = $this->moodle_group_id($rg3);
        $expected = array(
            $gid1 => array($prof[1], $mgr[1], $mgr[2], $est[1], $est[2], $est[3]),
            $gid2 => array($prof[2], $mgr[1], $mgr[2], $est[4], $est[5], $est[6]),
            $gid3 => array($prof[3], $mgr[1], $mgr[2], $est[7], $est[8], $est[9]),
        );
        foreach ($expected as $gid => $members) {
            $actual = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', array($gid));
            sort($actual);
            $expectedids = array();
            foreach ($members as $u) {
                $expectedids[] = $u->id;
            }
            sort($expectedids);
            $this->assertEquals($expectedids, $actual,
                "composição do grupo {$gid} diferente do esperado");
        }

        // Managers nos três grupos; cada professor em apenas um.
        foreach ($mgr as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id));
            $this->assertTrue(groups_is_member($gid2, $u->id));
            $this->assertTrue(groups_is_member($gid3, $u->id));
        }
        $this->assertTrue(groups_is_member($gid1, $prof[1]->id));
        $this->assertFalse(groups_is_member($gid2, $prof[1]->id));
        $this->assertFalse(groups_is_member($gid3, $prof[1]->id));
    }

    /**
     * Cenário 3: a partir da topologia do cenário 2, inscreve com a ação para
     * usuários removidos = "Manter usuário inscrito" (KEEP), valida o estado, e
     * então altera a ação para "Desinscrever usuários do curso" (UNENROL) e
     * valida de novo.
     *
     * A diferença entre as ações só é observável sobre usuários que SAÍRAM do
     * relationship, então removemos três órfãos representativos:
     *   - prof3 (papel professor, estava só no grupo 3);
     *   - mgr2  (papel manager, estava nos três grupos);
     *   - est1  (papel estudante, estava no grupo 1, papel compartilhado com est2..9).
     * Com KEEP eles permanecem; ao trocar para UNENROL eles são desinscritos e
     * retirados dos grupos, sem afetar os demais.
     */
    public function test_keep_then_unenrol_action_for_removed_users() {
        global $DB;

        $t = $this->build_distinct_cohort_topology();
        $prof = $t->prof;
        $mgr = $t->mgr;
        $est = $t->est;

        // Inscrição com a ação "Manter usuário inscrito" (KEEP).
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_KEEP);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $gid1 = $this->moodle_group_id($t->rg1);
        $gid2 = $this->moodle_group_id($t->rg2);
        $gid3 = $this->moodle_group_id($t->rg3);

        // Estado inicial: 14 inscritos.
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // Tira prof3, mgr2 e est1 do relationship (apaga todos os seus vínculos).
        $DB->delete_records('relationship_members', array('userid' => $prof[3]->id));
        $DB->delete_records('relationship_members', array('userid' => $mgr[2]->id));
        $DB->delete_records('relationship_members', array('userid' => $est[1]->id));

        // =================================================================
        // FASE 1 — ação = KEEP: órfãos permanecem inscritos, com papel e grupo.
        // =================================================================
        enrol_relationship_sync($this->trace(), $this->course->id);

        $orphans = array($prof[3], $mgr[2], $est[1]);
        foreach ($orphans as $u) {
            $this->assertTrue($this->is_user_enrolled($instance, $u->id),
                "KEEP: órfão {$u->username} deveria continuar inscrito");
        }
        $this->assertTrue($this->has_role_assignment($prof[3]->id, $this->teacherroleid, $instance));
        $this->assertTrue($this->has_role_assignment($mgr[2]->id, $t->managerroleid, $instance));
        $this->assertTrue($this->has_role_assignment($est[1]->id, $this->studentroleid, $instance));
        // Sob KEEP os grupos também não são tocados.
        $this->assertTrue(groups_is_member($gid3, $prof[3]->id));
        $this->assertTrue(groups_is_member($gid1, $mgr[2]->id));
        $this->assertTrue(groups_is_member($gid2, $mgr[2]->id));
        $this->assertTrue(groups_is_member($gid3, $mgr[2]->id));
        $this->assertTrue(groups_is_member($gid1, $est[1]->id));
        // Ninguém foi removido: ainda 14 inscritos.
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // =================================================================
        // FASE 2 — muda a ação para UNENROL: órfãos são desinscritos e saem dos grupos.
        // =================================================================
        $DB->set_field('enrol', 'customint3', ENROL_EXT_REMOVED_UNENROL, array('id' => $instance->id));
        enrol_relationship_sync($this->trace(), $this->course->id);

        foreach ($orphans as $u) {
            $this->assertFalse($this->is_user_enrolled($instance, $u->id),
                "UNENROL: órfão {$u->username} deveria ter sido desinscrito");
        }
        $this->assertFalse($this->has_role_assignment($prof[3]->id, $this->teacherroleid, $instance));
        $this->assertFalse($this->has_role_assignment($mgr[2]->id, $t->managerroleid, $instance));
        $this->assertFalse($this->has_role_assignment($est[1]->id, $this->studentroleid, $instance));
        $this->assertFalse(groups_is_member($gid3, $prof[3]->id));
        $this->assertFalse(groups_is_member($gid1, $mgr[2]->id));
        $this->assertFalse(groups_is_member($gid2, $mgr[2]->id));
        $this->assertFalse(groups_is_member($gid3, $mgr[2]->id));
        $this->assertFalse(groups_is_member($gid1, $est[1]->id));

        // Restam 11 inscritos (14 - 3 órfãos).
        $this->assertEquals(11, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // Os usuários NÃO removidos seguem intactos e nos grupos corretos.
        $kept = array($prof[1], $prof[2], $mgr[1],
                      $est[2], $est[3], $est[4], $est[5], $est[6], $est[7], $est[8], $est[9]);
        foreach ($kept as $u) {
            $this->assertTrue($this->is_user_enrolled($instance, $u->id),
                "{$u->username} (não removido) deveria continuar inscrito");
        }
        $expected = array(
            $gid1 => array($prof[1], $mgr[1], $est[2], $est[3]),
            $gid2 => array($prof[2], $mgr[1], $est[4], $est[5], $est[6]),
            $gid3 => array($mgr[1], $est[7], $est[8], $est[9]),
        );
        foreach ($expected as $gid => $members) {
            $actual = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', array($gid));
            sort($actual);
            $expectedids = array();
            foreach ($members as $u) {
                $expectedids[] = $u->id;
            }
            sort($expectedids);
            $this->assertEquals($expectedids, $actual,
                "composição final do grupo {$gid} incorreta após desinscrição dos órfãos");
        }
    }

    /**
     * Cenário 4: variação do cenário 2 que verifica a reconciliação de associações
     * de grupo duplicadas/herdadas com a definição do relationship.
     *
     * Inscreve com ação = "Manter usuário inscrito" (KEEP) e valida o estado base.
     * Em seguida insere associações geridas pelo plugin que NÃO correspondem ao
     * relationship — est1-3 no grupo 2 e est4-6 no grupo 3. Com KEEP, a limpeza de
     * grupos (enrol_relationship_remove_member_groups) não roda, então as duplicatas
     * permanecem. Ao trocar a ação para "Desinscrever usuários do curso" (UNENROL),
     * o sync reconcilia: cada estudante volta a ficar SOMENTE no grupo definido pelo
     * relationship (est1-3 no grupo 1, est4-6 no grupo 2, est7-9 no grupo 3).
     */
    public function test_keep_then_unenrol_reconciles_duplicate_group_memberships() {
        global $DB;

        $t = $this->build_distinct_cohort_topology();
        $prof = $t->prof;
        $mgr = $t->mgr;
        $est = $t->est;

        // Inscrição com ação "Manter usuário inscrito" (KEEP).
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_KEEP);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $gid1 = $this->moodle_group_id($t->rg1);
        $gid2 = $this->moodle_group_id($t->rg2);
        $gid3 = $this->moodle_group_id($t->rg3);

        // Estado base: 14 inscritos; cada estudante apenas no seu grupo.
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));
        foreach (array($est[1], $est[2], $est[3]) as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id));
            $this->assertFalse(groups_is_member($gid2, $u->id));
            $this->assertFalse(groups_is_member($gid3, $u->id));
        }
        foreach (array($est[4], $est[5], $est[6]) as $u) {
            $this->assertTrue(groups_is_member($gid2, $u->id));
            $this->assertFalse(groups_is_member($gid1, $u->id));
            $this->assertFalse(groups_is_member($gid3, $u->id));
        }

        // Insere duplicatas geridas pelo plugin (estado que não corresponde ao
        // relationship): est1-3 no grupo 2 e est4-6 no grupo 3.
        foreach (array($est[1], $est[2], $est[3]) as $u) {
            groups_add_member($gid2, $u->id, 'enrol_relationship', $instance->id);
        }
        foreach (array($est[4], $est[5], $est[6]) as $u) {
            groups_add_member($gid3, $u->id, 'enrol_relationship', $instance->id);
        }

        // =================================================================
        // FASE 1 — ação KEEP: a limpeza de grupos não roda, duplicatas permanecem.
        // =================================================================
        enrol_relationship_sync($this->trace(), $this->course->id);

        foreach (array($est[1], $est[2], $est[3]) as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id),
                "KEEP: {$u->username} deveria continuar no grupo 1");
            $this->assertTrue(groups_is_member($gid2, $u->id),
                "KEEP: duplicata de {$u->username} no grupo 2 deveria permanecer");
        }
        foreach (array($est[4], $est[5], $est[6]) as $u) {
            $this->assertTrue(groups_is_member($gid2, $u->id),
                "KEEP: {$u->username} deveria continuar no grupo 2");
            $this->assertTrue(groups_is_member($gid3, $u->id),
                "KEEP: duplicata de {$u->username} no grupo 3 deveria permanecer");
        }
        // Grupos 2 e 3 com 9 membros cada (6 originais + 3 duplicatas).
        $this->assertEquals(9, $DB->count_records('groups_members', array('groupid' => $gid2)));
        $this->assertEquals(9, $DB->count_records('groups_members', array('groupid' => $gid3)));

        // =================================================================
        // FASE 2 — ação UNENROL: o sync reconcilia os grupos com o relationship.
        // =================================================================
        $DB->set_field('enrol', 'customint3', ENROL_EXT_REMOVED_UNENROL, array('id' => $instance->id));
        enrol_relationship_sync($this->trace(), $this->course->id);

        // est1-3 somente no grupo 1.
        foreach (array($est[1], $est[2], $est[3]) as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id));
            $this->assertFalse(groups_is_member($gid2, $u->id),
                "UNENROL: duplicata de {$u->username} no grupo 2 deveria ser removida");
            $this->assertFalse(groups_is_member($gid3, $u->id));
        }
        // est4-6 somente no grupo 2.
        foreach (array($est[4], $est[5], $est[6]) as $u) {
            $this->assertTrue(groups_is_member($gid2, $u->id));
            $this->assertFalse(groups_is_member($gid1, $u->id));
            $this->assertFalse(groups_is_member($gid3, $u->id),
                "UNENROL: duplicata de {$u->username} no grupo 3 deveria ser removida");
        }
        // est7-9 (intocados) somente no grupo 3.
        foreach (array($est[7], $est[8], $est[9]) as $u) {
            $this->assertTrue(groups_is_member($gid3, $u->id));
            $this->assertFalse(groups_is_member($gid1, $u->id));
            $this->assertFalse(groups_is_member($gid2, $u->id));
        }

        // Ninguém foi desinscrito: todos seguem membros do relationship.
        $this->assertEquals(14, $DB->count_records('user_enrolments', array('enrolid' => $instance->id)));

        // Composição final de cada grupo = base do cenário 2.
        $expected = array(
            $gid1 => array($prof[1], $mgr[1], $mgr[2], $est[1], $est[2], $est[3]),
            $gid2 => array($prof[2], $mgr[1], $mgr[2], $est[4], $est[5], $est[6]),
            $gid3 => array($prof[3], $mgr[1], $mgr[2], $est[7], $est[8], $est[9]),
        );
        foreach ($expected as $gid => $members) {
            $actual = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', array($gid));
            sort($actual);
            $expectedids = array();
            foreach ($members as $u) {
                $expectedids[] = $u->id;
            }
            sort($expectedids);
            $this->assertEquals($expectedids, $actual,
                "composição final do grupo {$gid} incorreta após reconciliação");
        }
    }

    /**
     * Cenário 5: com a ação para usuários removidos = "Desinscrever usuários do
     * curso" (UNENROL), move um usuário do grupo 1 para o grupo 2 (altera o grupo
     * do seu vínculo no relationship) e verifica que, após o sync, ele foi removido
     * do grupo 1 e inserido no grupo 2 no curso — permanecendo inscrito.
     */
    public function test_unenrol_action_moving_user_between_groups_updates_course_groups() {
        global $DB;

        $t = $this->build_distinct_cohort_topology();
        $est = $t->est;

        // Inscrição com ação "Desinscrever usuários do curso" (UNENROL).
        $instance = $this->create_instance(RELATIONSHIP_SYNC_USERS_AND_GROUPS, ENROL_EXT_REMOVED_UNENROL);
        enrol_relationship_sync($this->trace(), $this->course->id);

        $gid1 = $this->moodle_group_id($t->rg1);
        $gid2 = $this->moodle_group_id($t->rg2);

        // Estado inicial: est1 está no grupo 1 e fora do grupo 2.
        $this->assertTrue(groups_is_member($gid1, $est[1]->id));
        $this->assertFalse(groups_is_member($gid2, $est[1]->id));

        // Move est1 do grupo 1 para o grupo 2 no relationship (mantém o cohort,
        // troca apenas o relationshipgroupid do seu vínculo).
        $member = $DB->get_record('relationship_members',
            array('relationshipgroupid' => $t->rg1, 'userid' => $est[1]->id), '*', MUST_EXIST);
        $DB->set_field('relationship_members', 'relationshipgroupid', $t->rg2, array('id' => $member->id));

        enrol_relationship_sync($this->trace(), $this->course->id);

        // est1 saiu do grupo 1, entrou no grupo 2 e segue inscrito no curso.
        $this->assertFalse(groups_is_member($gid1, $est[1]->id),
            'est1 deveria ter sido removido do grupo 1');
        $this->assertTrue(groups_is_member($gid2, $est[1]->id),
            'est1 deveria ter sido inserido no grupo 2');
        $this->assertTrue($this->is_user_enrolled($instance, $est[1]->id),
            'est1 deveria continuar inscrito no curso');

        // A nova associação no grupo 2 é gerida pelo plugin.
        $this->assertTrue($DB->record_exists('groups_members', array(
            'groupid' => $gid2, 'userid' => $est[1]->id,
            'component' => 'enrol_relationship', 'itemid' => $instance->id)));

        // Os demais membros dos grupos 1 e 2 permanecem onde estavam.
        foreach (array($est[2], $est[3]) as $u) {
            $this->assertTrue(groups_is_member($gid1, $u->id));
        }
        foreach (array($est[4], $est[5], $est[6]) as $u) {
            $this->assertTrue(groups_is_member($gid2, $u->id));
        }
    }
}
