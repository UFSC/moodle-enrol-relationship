<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Smoke test for enrol_relationship.
 *
 * This exists only to prove the PHPUnit pipeline is green. Replace it with real
 * unit tests for this plugin's data classes / business logic.
 *
 * @group enrol_relationship
 */
class relationship_smoke_testcase extends advanced_testcase {

    public function test_environment_is_ready() {
        $this->resetAfterTest(true);
        // If PHPUnit bootstrapped the Moodle test environment correctly, $DB and
        // the global $CFG are available. A trivial assertion keeps the suite green
        // until real tests replace this file.
        global $DB, $CFG;
        $this->assertNotEmpty($CFG->wwwroot);
        $this->assertInstanceOf('moodle_database', $DB);
    }
}
