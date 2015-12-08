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
use TinCan\LRSInterface;

define('CLI_SCRIPT', 1);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/strathjiscla/vendor/autoload.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$endpoint = 'http://localhost/learninglocker/public/data/xAPI/';
$version = '1.0.1';
$username = 'a78d196704728fe4ccb7984fa5f33632fdc7b827';
$password = '9e2b84b22076bce3269c517bf45c7abc313a3cbe';

$batchsize = 20;

$lrs = new RemoteLRS($endpoint, $version, $username, $password);

// Check connection
$about = $lrs->about();
if (!$about->success) {
    die("Unable to connect to server");
}

echo "xAPI versions: " . implode(', ', $about->content->getVersion()) . "\n";

// Can't use autoloading on old Moodle versions

/**
 * Modified controller that will create a statement without pushing it to the LRS.
 *
 * This is to enable pushing batches of statements.
 */
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

/**
 * Generator for xAPI statements from simple event data.
 */
class StatementGenerator {

    protected $lrs;
    protected $moodlecontroller;
    protected $translatorcontroller;
    protected $xapicontroller;

    /**
     * Create a new generator.

     * @param LRSInterface $lrs the intended target LRS
     */
    public function __construct(LRSInterface $lrs) {
        global $DB, $CFG;
        $this->lrs = $lrs;
        // Initialise repositories
        $this->moodlecontroller = new moodle_controller(new moodle_repository($DB, $CFG));
        $this->translatorcontroller = new translator_controller();
        $this->xapicontroller = new BatchController(new xapi_repository($lrs));
    }

    public function generateStatement(array $event) {
        $moodleevent = $this->moodlecontroller->createEvent($event);

        if (is_null($moodleevent)) {
            // This is acceptable - means Moodle event not supported by library
            return null;
        }

        $translatorevent = $this->translatorcontroller->createEvent($moodleevent);
        if (is_null($translatorevent)) {
            throw new Exception("Unable to create statement");
        }

        $xapievent = $this->xapicontroller->createEvent($translatorevent);

        if (is_null($xapievent)) {
            throw new Exception("Unable to create statement");
        }

        return new Statement($xapievent);

    }

    public function processBatches($select, $callback, $batchsize = 5000) {
        global $DB;

        $start = 0;
        while($batch = $DB->get_records_select('log', $select, array(), '', '*', $start, $batchsize)) {
            $statements = array();

            foreach ($batch as $id => $logrecord) {

                if ($event = $callback($logrecord)) {
                    if ($statement = $this->generateStatement($event)) {
                        $statements[] = $statement;
                    }
                }

            }

            echo "Sending statements $start to " . ($start + count($batch) - 1) . "\n";
            echo "Statement count: " . count($statements) . "\n";

            // Send statements as a batch
            $result = $this->lrs->saveStatements($statements);

            $start += count($batch);
        }
    }

}

$gen = new StatementGenerator($lrs);

// UserLoggedIn recipe
echo "Processing UserLoggedIn events\n";
$gen->processBatches("module = 'user' AND action = 'login'", function($logrecord) {
    $event = array('eventname' => '\core\event\user_loggedin');
    $event['userid'] = $logrecord->userid;
    $event['relateduserid'] = null;
    $event['courseid'] = 1; // Should be $logrecord->courseid;
    $event['timecreated'] = $logrecord->time;
    return $event;
}, $batchsize);

// CourseViewed recipe
echo "Processing CourseViewed events\n";
$gen->processBatches("module = 'course' AND action = 'view'", function($logrecord) {
    $event = array('eventname' => '\core\event\course_viewed');
    $event['userid'] = $logrecord->userid;
    $event['relateduserid'] = null;
    $event['courseid'] = $logrecord->course;
    $event['timecreated'] = $logrecord->time;
    return $event;
}, $batchsize);

// ModuleViewed recipe
echo "Processing ModuleViewed events\n";
$gen->processBatches("action = 'view'", function($logrecord) {
    // We define a module viewed as a view action with a cmid
    if (empty($logrecord->cmid)) {
        return;
    }

    $event = array('eventname' => "\\mod_{$logrecord->module}\\event\\course_module_viewed");
    $event['userid'] = $logrecord->userid;
    $event['relateduserid'] = null;
    $event['courseid'] = $logrecord->course;
    $event['timecreated'] = $logrecord->time;

    $mod = get_fast_modinfo($logrecord->course)->get_cm($logrecord->cmid);
    $event['objectid'] = $mod->instance;
    $event['objecttable'] = $mod->modname;
    return $event;
}, $batchsize);

// AssignmentSubmitted recipe
echo "Processing AssignmentSubmitted events\n";
$gen->processBatches("module = 'assign' AND (action = 'submit' OR action = 'submit for grading')", function($logrecord) {
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
    return $event;
}, $batchsize);

// AssignmentGraded recipe
echo "Processing AssignmentGraded events\n";
$gen->processBatches("module = 'assign' AND action = 'grade submission'", function($logrecord) {
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
    return $event;
}, $batchsize);