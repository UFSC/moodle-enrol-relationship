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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Step definitions de Behat para enrol_relationship.
 *
 * O nome do arquivo/classe segue a convenção frankenstyle (behat_enrol_relationship)
 * para evitar colisão com behat_relationship do local_relationship e ser carregado
 * como contexto do componente.
 *
 * Os Given abaixo semeiam, via API do local_relationship, o relationship/cohort/grupo
 * e os relationship_members usados pelas features. A instância de enrol NÃO é semeada
 * aqui de propósito — ela é criada pela própria UI sob teste.
 *
 * @package    enrol_relationship
 * @copyright  2026 UFSC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_enrol_relationship extends behat_base {

    /**
     * Habilita o método de inscrição relationship pela própria UI de administração.
     *
     * Importante: habilitar via set_config a partir do processo CLI do Behat NÃO é
     * confiável aqui — o processo web (FPM) tem seu próprio cache de config e
     * intermitentemente continua vendo o método desabilitado, fazendo o sync do
     * edit.php retornar cedo (enrol_is_enabled() falso) e não inscrever ninguém.
     * Clicar "Enable" na página de métodos passa pelo mesmo web tier que depois
     * processa o edit.php, mantendo o cache consistente. Requer estar logado como
     * admin.
     *
     * @Given /^the relationship enrolment method is enabled$/
     */
    public function the_relationship_enrolment_method_is_enabled() {
        $enabled = enrol_get_plugins(true);
        $enabled['relationship'] = true;
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));
    }

    /**
     * Cria um relationship na categoria indicada (pelo idnumber).
     *
     * @Given /^the relationship "([^"]*)" exists in category "([^"]*)"$/
     * @param string $name
     * @param string $categoryidnumber
     */
    public function the_relationship_exists_in_category($name, $categoryidnumber) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/relationship/lib.php');

        $category = $DB->get_record('course_categories', array('idnumber' => $categoryidnumber), '*', MUST_EXIST);
        $context = context_coursecat::instance($category->id);
        relationship_add_relationship((object) array(
            'contextid' => $context->id,
            'name' => $name,
        ));
    }

    /**
     * Liga um cohort (pelo idnumber) ao relationship com o papel informado.
     *
     * @Given /^the relationship "([^"]*)" is linked to cohort "([^"]*)" with role "([^"]*)"$/
     * @param string $relname
     * @param string $cohortidnumber
     * @param string $roleshortname
     */
    public function the_relationship_is_linked_to_cohort_with_role($relname, $cohortidnumber, $roleshortname) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/relationship/lib.php');

        $relationship = $DB->get_record('relationship', array('name' => $relname), '*', MUST_EXIST);
        $cohort = $DB->get_record('cohort', array('idnumber' => $cohortidnumber), '*', MUST_EXIST);
        $roleid = $DB->get_field('role', 'id', array('shortname' => $roleshortname), MUST_EXIST);
        relationship_add_cohort((object) array(
            'relationshipid' => $relationship->id,
            'cohortid' => $cohort->id,
            'roleid' => $roleid,
            'allowdupsingroups' => 0,
            'uniformdistribution' => 0,
        ));
    }

    /**
     * Cria um grupo no relationship.
     *
     * @Given /^the relationship "([^"]*)" has a group "([^"]*)"$/
     * @param string $relname
     * @param string $groupname
     */
    public function the_relationship_has_a_group($relname, $groupname) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/relationship/lib.php');

        $relationship = $DB->get_record('relationship', array('name' => $relname), '*', MUST_EXIST);
        relationship_add_group((object) array(
            'relationshipid' => $relationship->id,
            'name' => $groupname,
            'userlimit' => 0,
            'uniformdistribution' => 0,
        ));
    }

    /**
     * Adiciona um usuário como membro de um grupo do relationship, via o cohort indicado.
     *
     * @Given /^user "([^"]*)" is a member of group "([^"]*)" via cohort "([^"]*)" in relationship "([^"]*)"$/
     * @param string $username
     * @param string $groupname
     * @param string $cohortidnumber
     * @param string $relname
     */
    public function user_is_a_member_of_group_via_cohort_in_relationship($username, $groupname, $cohortidnumber, $relname) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/relationship/lib.php');

        $relationship = $DB->get_record('relationship', array('name' => $relname), '*', MUST_EXIST);
        $user = $DB->get_record('user', array('username' => $username), '*', MUST_EXIST);
        $cohort = $DB->get_record('cohort', array('idnumber' => $cohortidnumber), '*', MUST_EXIST);
        $rg = $DB->get_record('relationship_groups',
            array('relationshipid' => $relationship->id, 'name' => $groupname), '*', MUST_EXIST);
        $rc = $DB->get_record('relationship_cohorts',
            array('relationshipid' => $relationship->id, 'cohortid' => $cohort->id), '*', MUST_EXIST);
        relationship_add_member($rg->id, $rc->id, $user->id);
    }

    /**
     * Verifica, no banco, quantos usuários estão inscritos no curso pelo método
     * relationship — resultado determinístico do sync disparado pela submissão do
     * formulário (a página de participantes deste ambiente renderiza de forma
     * intermitente por causa de cache/opcache, então asseguramos o efeito real).
     *
     * @Then /^the course "([^"]*)" should have "([^"]*)" users enrolled via relationship$/
     * @param string $shortname
     * @param int $count
     */
    public function the_course_should_have_users_enrolled_via_relationship($shortname, $count) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $sql = "SELECT COUNT(DISTINCT ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid)
                 WHERE e.courseid = :courseid AND e.enrol = 'relationship'";
        $actual = $DB->count_records_sql($sql, array('courseid' => $course->id));
        if ((int) $actual !== (int) $count) {
            throw new \Exception("Esperado {$count} inscrito(s) via relationship no curso {$shortname}, encontrado {$actual}.");
        }
    }

    /**
     * Verifica que existe um grupo do relationship no curso com a quantidade
     * esperada de membros.
     *
     * @Then /^the course "([^"]*)" should have a relationship group "([^"]*)" with "([^"]*)" members$/
     * @param string $shortname
     * @param string $groupname
     * @param int $count
     */
    public function the_course_should_have_a_relationship_group_with_members($shortname, $groupname, $count) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $group = $DB->get_record('groups', array('courseid' => $course->id, 'name' => $groupname));
        if (!$group) {
            throw new \Exception("Grupo '{$groupname}' não foi criado no curso {$shortname}.");
        }
        $members = $DB->count_records('groups_members', array('groupid' => $group->id));
        if ((int) $members !== (int) $count) {
            throw new \Exception("Esperado {$count} membro(s) no grupo '{$groupname}', encontrado {$members}.");
        }
    }

    /**
     * Verifica que o curso NÃO possui grupos criados pelo relationship.
     *
     * @Then /^the course "([^"]*)" should have no relationship groups$/
     * @param string $shortname
     */
    public function the_course_should_have_no_relationship_groups($shortname) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $count = $DB->count_records_select('groups',
            "courseid = :courseid AND " . $DB->sql_like('idnumber', ':pat'),
            array('courseid' => $course->id, 'pat' => 'relationship\_%'));
        if ($count > 0) {
            throw new \Exception("Esperado nenhum grupo de relationship no curso {$shortname}, encontrado {$count}.");
        }
    }

    /**
     * Remove um usuário de todos os grupos do relationship (apaga seus
     * relationship_members), simulando que ele saiu do relacionamento.
     *
     * @Given /^user "([^"]*)" is removed from relationship "([^"]*)"$/
     * @param string $username
     * @param string $relname
     */
    public function user_is_removed_from_relationship($username, $relname) {
        global $DB;
        $relationship = $DB->get_record('relationship', array('name' => $relname), '*', MUST_EXIST);
        $user = $DB->get_record('user', array('username' => $username), '*', MUST_EXIST);
        $rgids = $DB->get_fieldset_select('relationship_groups', 'id',
            'relationshipid = ?', array($relationship->id));
        if (empty($rgids)) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($rgids);
        array_unshift($params, $user->id);
        $DB->delete_records_select('relationship_members',
            "userid = ? AND relationshipgroupid $insql", $params);
    }

    /**
     * Abre o formulário de edição da instância de enrol relationship do curso.
     *
     * @Given /^I edit the relationship enrolment method of course "([^"]*)"$/
     * @param string $shortname
     */
    public function i_edit_the_relationship_enrolment_method_of_course($shortname) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $instance = $DB->get_record('enrol',
            array('courseid' => $course->id, 'enrol' => 'relationship'), '*', IGNORE_MULTIPLE);
        $this->getSession()->visit($this->locate_path(
            '/enrol/relationship/edit.php?courseid=' . $course->id . '&id=' . $instance->id));
        $this->wait_for_pending_js();
    }

    /**
     * @Then /^user "([^"]*)" should be enrolled in course "([^"]*)"$/
     * @param string $username
     * @param string $shortname
     */
    public function user_should_be_enrolled_in_course($username, $shortname) {
        if (!$this->is_enrolled_via_relationship($username, $shortname)) {
            throw new \Exception("Esperado que {$username} estivesse inscrito via relationship no curso {$shortname}.");
        }
    }

    /**
     * @Then /^user "([^"]*)" should not be enrolled in course "([^"]*)"$/
     * @param string $username
     * @param string $shortname
     */
    public function user_should_not_be_enrolled_in_course($username, $shortname) {
        if ($this->is_enrolled_via_relationship($username, $shortname)) {
            throw new \Exception("Esperado que {$username} NÃO estivesse inscrito via relationship no curso {$shortname}.");
        }
    }

    /**
     * @param string $username
     * @param string $shortname
     * @return bool
     */
    protected function is_enrolled_via_relationship($username, $shortname) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $user = $DB->get_record('user', array('username' => $username), '*', MUST_EXIST);
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid)
                 WHERE e.courseid = :courseid AND e.enrol = 'relationship' AND ue.userid = :userid";
        return $DB->record_exists_sql($sql, array('courseid' => $course->id, 'userid' => $user->id));
    }

    /**
     * Visita a página do curso (onde fica a navegação de administração do curso).
     *
     * @Given /^I am on the course "([^"]*)"$/
     * @param string $shortname
     */
    public function i_am_on_the_course($shortname) {
        global $DB;
        $course = $DB->get_record('course', array('shortname' => $shortname), '*', MUST_EXIST);
        $this->getSession()->visit($this->locate_path('/course/view.php?id=' . $course->id));
        $this->wait_for_pending_js();
    }
}
