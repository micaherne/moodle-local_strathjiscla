<?php

/*
 * One-off script to upload historical Moodle log data as xAPI.
 *
 * Notes:
 *
 * - this is intended to run on versions of Moodle back to 2.4, so
 *   does not make use of much Moodle functionality.
 * - uses learninglocker libraries which appear to require PHP 5.4 or greater
 * - does not use xapi-recipe-emitter directly as this only supports a single
 *   statement per request which is not useful for batch processing
 * - does not use logstore_xapi settings as it is required to run on Moodle
 *   versions that logstore_xapi can't be installed on
 * - there is no data in the historical log which would let us accurately
 *   determine the actual assignment submission record, so we always return
 *   the latest
 */

use TinCan\RemoteLRS;
use TinCan\Statement;
use \MXTranslator\Controller as translator_controller;
use \LogExpander\Controller as moodle_controller;
use \LogExpander\Repository as moodle_repository;
use \XREmitter\Controller as xapi_controller;
use \XREmitter\Repository as xapi_repository;

define('CLI_SCRIPT', 1);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/strathjiscla/vendor/autoload.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$endpoint = 'http://localhost/learninglocker/public/data/xAPI/';
$version = '1.0.1';
$username = 'eb01a6631cda018f64beec8c473ad8e65fc01cc2';
$password = '3330d7329edec73f2b544d5541b1016da91ab091';

$batchsize = 20;

$lrs = new RemoteLRS($endpoint, $version, $username, $password);
$xapirepo = new xapi_repository($lrs);

// Check connection
$about = $lrs->about();
if (!$about->success) {
    die("Unable to connect to server");
}

class BatchController extends xapi_controller {

    /**
     * Creates a new event.
     * @param [String => Mixed] $opts
     * @return [String => Mixed]
     */
    public function createEvent(array $opts) {
        $route = isset($opts['recipe']) ? $opts['recipe'] : '';
        if (isset(static::$routes[$route])) {
            $event = '\XREmitter\Events\\'.static::$routes[$route];
            $service = new $event($this->repo);
            $opts['context_lang'] = $opts['context_lang'] ?: 'en';
            $statement = $service->read($opts);
            return $statement;
        } else {
            return null;
        }
    }

}

// Initialise repositories
$moodlecontroller = new moodle_controller(new moodle_repository($DB, $CFG));
$translatorcontroller = new translator_controller();
$xapicontroller = new BatchController($xapirepo);

// UserLoggedIn recipe
echo "Processing UserLoggedIn events\n";
$start = 0;
while($batch = $DB->get_records('log', array('module' => 'user', 'action' => 'login'), '', '*', $start, $batchsize)) {
    $statements = array();
    foreach ($batch as $id => $logrecord) {

        $event = array('eventname' => '\core\event\user_loggedin');
        $event['userid'] = $logrecord->userid;
        $event['relateduserid'] = null;
        $event['courseid'] = 1; // Should be $logrecord->courseid;
        $event['timecreated'] = $logrecord->time;

        // $this->error_log('');
        // $this->error_log_value('event', $event);
        $moodleevent = $moodlecontroller->createEvent($event);
        if (is_null($moodleevent)) {
            continue;
        }

        // $this->error_log_value('moodleevent', $moodleevent);
        $translatorevent = $translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            continue;
        }

        $xapievent = $xapicontroller->createEvent($translatorevent);

        if (!is_null($xapievent)) {
            $statements[] = new Statement($xapievent);
        } else {
            echo "Unable to create statement\n";
            print_r($logrecord);
        }
    }

    echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
    echo "Statement count: " . count($statements) . "\n";

    // Send statements as a batch
    $result = $lrs->saveStatements($statements);

    $start += count($batch);
}

// CourseViewed recipe
echo "Processing CourseViewed events\n";
$start = 0;
while($batch = $DB->get_records('log', array('module' => 'course', 'action' => 'view'), '', '*', $start, $batchsize)) {
    $statements = array();
    foreach ($batch as $id => $logrecord) {

        $event = array('eventname' => '\core\event\course_viewed');
        $event['userid'] = $logrecord->userid;
        $event['relateduserid'] = null;
        $event['courseid'] = $logrecord->course;
        $event['timecreated'] = $logrecord->time;

        // $this->error_log('');
        // $this->error_log_value('event', $event);
        $moodleevent = $moodlecontroller->createEvent($event);
        if (is_null($moodleevent)) {
            continue;
        }

        // $this->error_log_value('moodleevent', $moodleevent);
        $translatorevent = $translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            continue;
        }

        $xapievent = $xapicontroller->createEvent($translatorevent);

        if (!is_null($xapievent)) {
            $statements[] = new Statement($xapievent);
        } else {
            echo "Unable to create statement\n";
            print_r($logrecord);
        }
    }

    echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
    echo "Statement count: " . count($statements) . "\n";

    // Send statements as a batch
    $result = $lrs->saveStatements($statements);

    $start += count($batch);
}

// ModuleViewed recipe
echo "Processing ModuleViewed events\n";
$start = 0;
// TODO: mod_forum has multiple view actions, e.g. "view forum" - check these
while($batch = $DB->get_records('log', array('action' => 'view'), '', '*', $start, $batchsize)) {
    $statements = array();
    foreach ($batch as $id => $logrecord) {

        // We define a module viewed as a view action with a cmid
        if (empty($logrecord->cmid)) {
            continue;
        }

        $event = array('eventname' => "\\mod_{$logrecord->module}\\event\\course_module_viewed");
        $event['userid'] = $logrecord->userid;
        $event['relateduserid'] = null;
        $event['courseid'] = $logrecord->course;
        $event['timecreated'] = $logrecord->time;

        $mod = get_fast_modinfo($logrecord->course)->get_cm($logrecord->cmid);
        $event['objectid'] = $mod->instance;
        $event['objecttable'] = $mod->modname;

        $moodleevent = $moodlecontroller->createEvent($event);
        if (is_null($moodleevent)) {
            continue;
        }

        $translatorevent = $translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            continue;
        }

        $xapievent = $xapicontroller->createEvent($translatorevent);

        if (!is_null($xapievent)) {
            $statements[] = new Statement($xapievent);
        } else {
            echo "Unable to create statement\n";
            print_r($logrecord);
        }
    }

    echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
    echo "Statement count: " . count($statements) . "\n";

    // Send statements as a batch
    $result = $lrs->saveStatements($statements);

    $start += count($batch);
}

// AssignmentSubmitted recipe
echo "Processing AssignmentSubmitted events\n";
$start = 0;
while($batch = $DB->get_records_select('log', "module = 'assign' AND (action = 'submit' OR action = 'submit for grading')", array(), '', '*', $start, $batchsize)) {
    $statements = array();

    foreach ($batch as $id => $logrecord) {

        $event = array('eventname' => '\mod_assign\event\assessable_submitted');
        $event['userid'] = $logrecord->userid;
        $event['relateduserid'] = null;
        $event['courseid'] = $logrecord->course;
        $event['timecreated'] = $logrecord->time;

        $courseinfo = get_fast_modinfo($logrecord->course);
        $mod = $courseinfo->get_cm($logrecord->cmid);
        $course = $courseinfo->get_course();

        // We don't have enough info to determine the actual submission
        // so always get the latest for that assignment
        $assign = new assign($mod->context, $mod, $course);
        $submission = $assign->get_user_submission($logrecord->userid, false);
        $event['objectid'] = $submission->id;
        $event['objecttable'] = 'assign_submission';

        // $this->error_log('');
        // $this->error_log_value('event', $event);
        $moodleevent = $moodlecontroller->createEvent($event);
        if (is_null($moodleevent)) {
            continue;
        }

        // $this->error_log_value('moodleevent', $moodleevent);
        $translatorevent = $translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            continue;
        }

        $xapievent = $xapicontroller->createEvent($translatorevent);

        if (!is_null($xapievent)) {
            $statements[] = new Statement($xapievent);
        } else {
            echo "Unable to create statement\n";
            print_r($logrecord);
        }
    }

    echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
    echo "Statement count: " . count($statements) . "\n";

    // Send statements as a batch
    $result = $lrs->saveStatements($statements);

    $start += count($batch);
}

// AssignmentGraded recipe
echo "Processing AssignmentGraded events\n";
$start = 0;
while($batch = $DB->get_records('log', array('module' => 'assign', 'action' => 'grade submission'), '', '*', $start, $batchsize)) {
    $statements = array();

    foreach ($batch as $id => $logrecord) {

        $event = array('eventname' => '\mod_assign\event\submission_graded');
        $event['userid'] = $logrecord->userid;
        $event['relateduserid'] = null;
        $event['courseid'] = $logrecord->course;
        $event['timecreated'] = $logrecord->time;

        $courseinfo = get_fast_modinfo($logrecord->course);
        $mod = $courseinfo->get_cm($logrecord->cmid);
        $course = $courseinfo->get_course();

        // We don't have enough info to determine the actual submission
        // so always get the latest grade for that assignment
        $assign = new assign($mod->context, $mod, $course);
        $grade = $assign->get_user_grade($logrecord->userid, false);

        $event['objectid'] = $grade->id;
        $event['objecttable'] = 'assign_grades';

        // $this->error_log('');
        // $this->error_log_value('event', $event);
        $moodleevent = $moodlecontroller->createEvent($event);
        if (is_null($moodleevent)) {
            continue;
        }

        // $this->error_log_value('moodleevent', $moodleevent);
        $translatorevent = $translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            continue;
        }

        $xapievent = $xapicontroller->createEvent($translatorevent);

        if (!is_null($xapievent)) {
            $statements[] = new Statement($xapievent);
        } else {
            echo "Unable to create statement\n";
            print_r($logrecord);
        }
    }

    echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
    echo "Statement count: " . count($statements) . "\n";

    // Send statements as a batch
    $result = $lrs->saveStatements($statements);

    $start += count($batch);
}