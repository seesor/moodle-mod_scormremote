<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Report page showing learner scores for SCORM Remote courses.
 *
 * @package     mod_scormremote
 * @copyright   2024
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/tablelib.php');

use mod_scormremote\client;

$courseid = optional_param('courseid', 0, PARAM_INT);
$clientid = optional_param('clientid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_NOTAGS));

require_login();
admin_externalpage_setup('scormremotereport');

$params = [];
if (!empty($courseid)) {
    $params['courseid'] = $courseid;
}
if (!empty($clientid)) {
    $params['clientid'] = $clientid;
}
if ($search !== '') {
    $params['search'] = $search;
}
$PAGE->set_url(new moodle_url('/mod/scormremote/report.php', $params));
$PAGE->set_title(get_string('report:heading', 'mod_scormremote'));
$PAGE->set_heading($PAGE->title);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report:heading', 'mod_scormremote'));

echo scormremote_render_report_filters($courseid, $clientid, $search);

$records = scormremote_get_score_records($courseid, $clientid, $search);

if (empty($records)) {
    echo $OUTPUT->notification(get_string('report:noresults', 'mod_scormremote'), 'notifymessage');
} else {
    echo scormremote_render_score_table($records);
}

echo $OUTPUT->footer();

/**
 * Render the filter form for the report.
 *
 * @param int $courseid
 * @param int $clientid
 * @param string $search
 * @return string
 */
function scormremote_render_report_filters(int $courseid, int $clientid, string $search): string {
    global $DB;

    $coursesql = "SELECT DISTINCT c.id, c.fullname
                     FROM {course} c
                     JOIN {course_modules} cm ON cm.course = c.id
                     JOIN {modules} m ON m.id = cm.module
                    WHERE m.name = :modname
                 ORDER BY c.fullname";
    $courses = $DB->get_records_sql($coursesql, ['modname' => 'scormremote']);

    $courseoptions = [0 => get_string('report:allcourses', 'mod_scormremote')];
    foreach ($courses as $course) {
        $courseoptions[(int)$course->id] = format_string($course->fullname);
    }

    $clientrecords = $DB->get_records('scormremote_clients', null, 'name ASC');
    $clientoptions = [0 => get_string('report:allclients', 'mod_scormremote')];
    foreach ($clientrecords as $clientrecord) {
        $clientoptions[(int)$clientrecord->id] = format_string($clientrecord->name);
    }

    $formurl = new moodle_url('/mod/scormremote/report.php');
    $output = html_writer::start_div('scormremote-report-filters mb-3');
    $output .= html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $formurl->out(false),
        'class' => 'form-inline align-items-end'
    ]);

    $output .= html_writer::start_div('form-group mr-2');
    $output .= html_writer::label(get_string('course'), 'id_courseid', false, ['class' => 'mr-2']);
    $output .= html_writer::select($courseoptions, 'courseid', $courseid, null, ['id' => 'id_courseid', 'class' => 'custom-select']);
    $output .= html_writer::end_div();

    $output .= html_writer::start_div('form-group mr-2');
    $output .= html_writer::label(get_string('client', 'mod_scormremote'), 'id_clientid', false, ['class' => 'mr-2']);
    $output .= html_writer::select($clientoptions, 'clientid', $clientid, null, ['id' => 'id_clientid', 'class' => 'custom-select']);
    $output .= html_writer::end_div();

    $output .= html_writer::start_div('form-group mr-2');
    $output .= html_writer::label(get_string('search'), 'id_search', false, ['class' => 'mr-2']);
    $output .= html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'search',
        'id' => 'id_search',
        'value' => s($search),
        'size' => 20,
        'class' => 'form-control',
        'placeholder' => get_string('report:searchplaceholder', 'mod_scormremote'),
    ]);
    $output .= html_writer::end_div();

    $output .= html_writer::start_div('form-group');
    $output .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('report:applyfilters', 'mod_scormremote'),
        'class' => 'btn btn-primary mr-2'
    ]);
    $output .= html_writer::link(new moodle_url('/mod/scormremote/report.php'),
        get_string('report:clearfilters', 'mod_scormremote'), ['class' => 'btn btn-secondary']);
    $output .= html_writer::end_div();

    $output .= html_writer::end_tag('form');
    $output .= html_writer::end_div();

    return $output;
}

/**
 * Fetch score data with optional filters.
 *
 * @param int $courseid
 * @param int $clientid
 * @param string $search
 * @return array
 */
function scormremote_get_score_records(int $courseid, int $clientid, string $search): array {
    global $DB;

    $params = [
        'modname' => 'scormremote',
        'usernamepattern' => $DB->sql_like_escape('enrol_scormremote_') . '%',
    ];

    $conditions = [];
    $conditions[] = 'u.deleted = 0';
    $conditions[] = $DB->sql_like('u.username', ':usernamepattern', false);

    if (!empty($courseid)) {
        $conditions[] = 'c.id = :courseid';
        $params['courseid'] = $courseid;
    }

    if (!empty($clientid)) {
        $conditions[] = $DB->sql_like('u.username', ':clientpattern', false);
        $params['clientpattern'] = $DB->sql_like_escape('enrol_scormremote_' . $clientid . '_') . '%';
    }

    if ($search !== '') {
        $searchpattern = '%' . $DB->sql_like_escape($search) . '%';
        $conditions[] = '(' .
            $DB->sql_like('u.firstname', ':searchfirstname', false) . ' OR ' .
            $DB->sql_like('u.lastname', ':searchlastname', false) . ' OR ' .
            $DB->sql_like('u.email', ':searchemail', false) . ' OR ' .
            $DB->sql_like('c.fullname', ':searchcourse', false) . ' OR ' .
            $DB->sql_like('u.username', ':searchusername', false) .
        ')';
        $params += [
            'searchfirstname' => $searchpattern,
            'searchlastname' => $searchpattern,
            'searchemail' => $searchpattern,
            'searchcourse' => $searchpattern,
            'searchusername' => $searchpattern,
        ];
    }

    $where = implode(' AND ', $conditions);

    $sql = "SELECT DISTINCT u.id AS userid,
                           u.firstname,
                           u.lastname,
                           u.email,
                           u.username,
                           c.id AS courseid,
                           c.fullname AS coursename,
                           gi.id AS gradeitemid,
                           gg.finalgrade
              FROM {course} c
              JOIN (SELECT DISTINCT cm.course
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                     WHERE m.name = :modname) rc ON rc.course = c.id
              JOIN {enrol} e ON e.courseid = c.id
              JOIN {user_enrolments} ue ON ue.enrolid = e.id
              JOIN {user} u ON u.id = ue.userid
         LEFT JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
         LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = u.id
             WHERE $where
          ORDER BY c.fullname, u.lastname, u.firstname, u.id";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Render the results table.
 *
 * @param array $records
 * @return string
 */
function scormremote_render_score_table(array $records): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable scormremote-report';
    $table->head = [
        get_string('user'),
        get_string('course'),
        get_string('report:score', 'mod_scormremote'),
        get_string('client', 'mod_scormremote'),
    ];

    $gradeitems = [];
    $clientcache = [];

    foreach ($records as $record) {
        $userurl = new moodle_url('/user/view.php', ['id' => $record->userid, 'course' => $record->courseid]);
        $courseurl = new moodle_url('/course/view.php', ['id' => $record->courseid]);
        $coursecontext = context_course::instance($record->courseid);

        $usercell = html_writer::link($userurl, fullname($record));
        $coursecell = html_writer::link($courseurl, format_string($record->coursename, true, ['context' => $coursecontext]));

        $scorecell = scormremote_format_score($record, $gradeitems);
        $clientcell = scormremote_client_name_from_username($record->username, $clientcache);

        $table->data[] = [$usercell, $coursecell, $scorecell, $clientcell];
    }

    return html_writer::table($table);
}

/**
 * Format the grade information for display.
 *
 * @param stdClass $record
 * @param array $gradeitems
 * @return string
 */
function scormremote_format_score(stdClass $record, array &$gradeitems): string {
    if (empty($record->gradeitemid) || $record->finalgrade === null) {
        return get_string('nograde');
    }

    if (!array_key_exists($record->gradeitemid, $gradeitems)) {
        $gradeitems[$record->gradeitemid] = grade_item::fetch(['id' => $record->gradeitemid]);
    }

    $gradeitem = $gradeitems[$record->gradeitemid];
    if (!$gradeitem) {
        return get_string('nograde');
    }

    $score = grade_format_gradevalue($record->finalgrade, $gradeitem, true);

    if (is_numeric($record->finalgrade) && $gradeitem->grademax > 0) {
        $percent = ($record->finalgrade / $gradeitem->grademax) * 100;
        $score .= html_writer::tag('span', ' (' . format_float($percent, 2) . '%)', ['class' => 'text-muted']);
    }

    return $score;
}

/**
 * Determine the client name based on the generated username.
 *
 * @param string $username
 * @param array $clientcache
 * @return string
 */
function scormremote_client_name_from_username(string $username, array &$clientcache): string {
    if (!preg_match('/^enrol_scormremote_(\d+)_/', $username, $matches)) {
        return get_string('report:noclient', 'mod_scormremote');
    }

    $clientid = (int)$matches[1];
    if (!array_key_exists($clientid, $clientcache)) {
        $clientcache[$clientid] = client::get_clientname_by_id($clientid);
    }

    return format_string($clientcache[$clientid]);
}
