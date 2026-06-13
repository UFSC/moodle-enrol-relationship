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
 * Base de testes com helpers de fixtures para enrol_relationship.
 *
 * Este arquivo NÃO termina em _test.php de propósito: ele não é descoberto/executado
 * pelo PHPUnit como suíte. Cada *_test.php do plugin o inclui e estende a classe
 * abstrata abaixo para reaproveitar o setup (categoria, curso, relationship, cohort,
 * grupos e instância de enrol).
 *
 * @package    enrol_relationship
 * @copyright  2026 UFSC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/relationship/locallib.php');
require_once($CFG->dirroot . '/local/relationship/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

/**
 * Classe base com fixtures compartilhadas.
 *
 * @package    enrol_relationship
 */
abstract class enrol_relationship_helper_testcase extends advanced_testcase {

    /** @var stdClass */
    protected $category;
    /** @var context_coursecat */
    protected $catcontext;
    /** @var stdClass */
    protected $course;
    /** @var context_course */
    protected $coursecontext;
    /** @var int */
    protected $relationshipid;
    /** @var int */
    protected $studentroleid;
    /** @var int */
    protected $teacherroleid;
    /** @var enrol_relationship_plugin */
    protected $plugin;

    protected function setUp() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->category = $this->getDataGenerator()->create_category();
        $this->catcontext = context_coursecat::instance($this->category->id);
        $this->course = $this->getDataGenerator()->create_course(array('category' => $this->category->id));
        $this->coursecontext = context_course::instance($this->course->id);

        $this->studentroleid = $DB->get_field('role', 'id', array('shortname' => 'student'));
        $this->teacherroleid = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));

        $this->relationshipid = relationship_add_relationship((object) array(
            'contextid' => $this->catcontext->id,
            'name' => 'Relationship fixture',
        ));

        $this->plugin = enrol_get_plugin('relationship');
        $this->enable_plugin();
    }

    /**
     * Habilita o plugin de enrol relationship (necessário: enrol_relationship_sync
     * retorna 2 imediatamente quando o plugin está desabilitado).
     */
    protected function enable_plugin() {
        $enabled = enrol_get_plugins(true);
        $enabled['relationship'] = true;
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));
    }

    /**
     * Desabilita o plugin de enrol relationship.
     */
    protected function disable_plugin() {
        $enabled = enrol_get_plugins(true);
        unset($enabled['relationship']);
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));
    }

    /**
     * Cria um cohort no contexto da categoria e o liga ao relationship.
     *
     * @param int|null $roleid papel atribuído (default: student)
     * @param array $overrides campos extras do relationship_cohort
     * @return array array($cohort, $relationshipcohortid)
     */
    protected function link_cohort($roleid = null, array $overrides = array()) {
        $cohort = $this->getDataGenerator()->create_cohort(array('contextid' => $this->catcontext->id));
        $rcid = relationship_add_cohort((object) array_merge(array(
            'relationshipid' => $this->relationshipid,
            'cohortid' => $cohort->id,
            'roleid' => $roleid ? $roleid : $this->studentroleid,
            'allowdupsingroups' => 0,
            'uniformdistribution' => 0,
        ), $overrides));
        return array($cohort, $rcid);
    }

    /**
     * Cria um relationship_group.
     *
     * @param string $name
     * @param array $overrides
     * @return int relationshipgroupid
     */
    protected function add_group($name = 'Grupo A', array $overrides = array()) {
        return relationship_add_group((object) array_merge(array(
            'relationshipid' => $this->relationshipid,
            'name' => $name,
            'userlimit' => 0,
            'uniformdistribution' => 0,
        ), $overrides));
    }

    /**
     * Cria a instância de enrol_relationship no curso da fixture.
     *
     * @param int $mode customint2 (modo de sync)
     * @param int $removeaction customint3 (ação de remoção)
     * @return stdClass linha da tabela enrol
     */
    protected function create_instance($mode = RELATIONSHIP_SYNC_USERS_AND_GROUPS,
                                       $removeaction = ENROL_EXT_REMOVED_UNENROL) {
        global $DB;
        $id = $this->plugin->add_instance($this->course, array(
            'customint1' => $this->relationshipid,
            'customint2' => $mode,
            'customint3' => $removeaction,
        ));
        return $DB->get_record('enrol', array('id' => $id));
    }

    /**
     * Insere um relationship_member diretamente no banco (sem disparar eventos),
     * para isolar a função de sync que está sendo testada.
     *
     * @param int $rgid relationshipgroupid
     * @param int $rcid relationshipcohortid
     * @param int $userid
     * @return int id inserido
     */
    protected function add_member($rgid, $rcid, $userid) {
        global $DB;
        return $DB->insert_record('relationship_members', (object) array(
            'relationshipgroupid' => $rgid,
            'relationshipcohortid' => $rcid,
            'userid' => $userid,
            'timeadded' => time(),
        ));
    }

    /**
     * @return null_progress_trace
     */
    protected function trace() {
        return new null_progress_trace();
    }

    /**
     * @param stdClass $instance
     * @param int $userid
     * @return bool se há user_enrolment para o par instância/usuário
     */
    protected function is_user_enrolled($instance, $userid) {
        global $DB;
        return $DB->record_exists('user_enrolments', array('enrolid' => $instance->id, 'userid' => $userid));
    }

    /**
     * @param int $userid
     * @param int $roleid
     * @param stdClass $instance
     * @return bool se há role_assignment desse plugin no contexto do curso
     */
    protected function has_role_assignment($userid, $roleid, $instance) {
        global $DB;
        return $DB->record_exists('role_assignments', array(
            'userid' => $userid,
            'roleid' => $roleid,
            'contextid' => $this->coursecontext->id,
            'component' => 'enrol_relationship',
            'itemid' => $instance->id,
        ));
    }

    /**
     * @param int $relationshipgroupid
     * @return int|false id do grupo Moodle com o idnumber do relationship group, ou false
     */
    protected function moodle_group_id($relationshipgroupid) {
        global $DB;
        $idnumber = "relationship_{$this->relationshipid}_{$relationshipgroupid}";
        return $DB->get_field('groups', 'id', array('courseid' => $this->course->id, 'idnumber' => $idnumber));
    }

    /**
     * @return int|false id do grouping Moodle do relationship, ou false
     */
    protected function moodle_grouping_id() {
        global $DB;
        $idnumber = "relationship_{$this->relationshipid}";
        return $DB->get_field('groupings', 'id', array('courseid' => $this->course->id, 'idnumber' => $idnumber));
    }
}
