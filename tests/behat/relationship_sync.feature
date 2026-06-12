@enrol @enrol_relationship @javascript
Feature: Sincronização de usuários e grupos pelo método de inscrição Relationship
  Para refletir os membros de um relationship no curso
  Como administrador
  Eu adiciono o método de inscrição "Relationship sync" pela UI e o sync disparado
  na submissão do formulário inscreve e (conforme o tipo de sync) agrupa os usuários.

  # Observação: a verificação do efeito é feita pelo estado resultante (inscrições e
  # grupos), porque as páginas de participantes/grupos deste ambiente renderizam de
  # forma intermitente (cache/opcache do site). A jornada testada — navegar até o
  # curso, escolher o método e preencher/submeter o formulário de enrolment — é a
  # fiação que o PHPUnit não cobre.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email          |
      | u1       | User      | One      | u1@example.com |
      | u2       | User      | Two      | u2@example.com |
    And the following "categories" exist:
      | name       | category | idnumber |
      | Category 1 | 0        | CAT1     |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | c1        | CAT1     |
    And the following "cohorts" exist:
      | name     | idnumber | contextlevel | reference |
      | Cohort 1 | COH1     | Category     | CAT1      |
    And the relationship "R1" exists in category "CAT1"
    And the relationship "R1" is linked to cohort "COH1" with role "student"
    And the relationship "R1" has a group "Grupo A"
    And user "u1" is a member of group "Grupo A" via cohort "COH1" in relationship "R1"
    And user "u2" is a member of group "Grupo A" via cohort "COH1" in relationship "R1"
    And the relationship enrolment method is enabled
    And I log in as "admin"

  @javascript
  Scenario: Sincronizar usuários e grupos inscreve e agrupa os membros do relationship
    Given I am on the course "c1"
    When I add "Relationship sync" enrolment method with:
      | Relationship             | R1                                |
      | Type of sync             | Synchronize both users and groups |
      | Action for removed users | Unenrol user from course          |
    Then the course "c1" should have "2" users enrolled via relationship
    And the course "c1" should have a relationship group "Grupo A" with "2" members

  @javascript
  Scenario: Sincronizar apenas usuários inscreve sem criar grupos
    Given I am on the course "c1"
    When I add "Relationship sync" enrolment method with:
      | Relationship             | R1                               |
      | Type of sync             | Only synchronize user enrolments |
      | Action for removed users | Unenrol user from course         |
    Then the course "c1" should have "2" users enrolled via relationship
    And the course "c1" should have no relationship groups

  @javascript
  Scenario: Editar a ação para "Desinscrever" remove quem saiu do relationship
    Given I am on the course "c1"
    And I add "Relationship sync" enrolment method with:
      | Relationship             | R1                                |
      | Type of sync             | Synchronize both users and groups |
      | Action for removed users | Keep user enrolled                |
    And the course "c1" should have "2" users enrolled via relationship
    When user "u2" is removed from relationship "R1"
    And I edit the relationship enrolment method of course "c1"
    And I set the field "Action for removed users" to "Unenrol user from course"
    And I press "Save changes"
    Then the course "c1" should have "1" users enrolled via relationship
    And user "u1" should be enrolled in course "c1"
    And user "u2" should not be enrolled in course "c1"

  @javascript
  Scenario: Vários cohorts com o mesmo papel inscrevem todos os membros
    Given the following "cohorts" exist:
      | name     | idnumber | contextlevel | reference |
      | Cohort 2 | COH2     | Category     | CAT1      |
    And the following "users" exist:
      | username | firstname | lastname | email          |
      | u3       | User      | Three    | u3@example.com |
    And the relationship "R1" is linked to cohort "COH2" with role "student"
    And user "u3" is a member of group "Grupo A" via cohort "COH2" in relationship "R1"
    And I am on the course "c1"
    When I add "Relationship sync" enrolment method with:
      | Relationship             | R1                                |
      | Type of sync             | Synchronize both users and groups |
      | Action for removed users | Unenrol user from course          |
    Then the course "c1" should have "3" users enrolled via relationship
    And the course "c1" should have a relationship group "Grupo A" with "3" members
